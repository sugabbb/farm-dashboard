<?php

namespace Tests\Feature;

use App\Models\{DailyRecord,FeedType,LivestockGroup,OperationalCashflow,Revenue,User};
use App\Services\{Balances,FinanceService};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

final class ZeroValueRecordsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $superadmin;
    private FinanceService $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        User::query()->update(['must_change_password'=>false]);
        $this->admin=User::where('role','ADMIN')->firstOrFail();
        $this->superadmin=User::where('role','SUPERADMIN')->firstOrFail();
        $this->finance=app(FinanceService::class);
        $this->actingAs($this->admin);
    }

    public function test_zero_finance_records_keep_their_workflows_and_balances(): void
    {
        $expense=$this->finance->saveOperationalCashflow([
            'cashflow_type'=>'OTHER','transaction_date'=>'2026-10-02','amount_idr'=>0,
            'details'=>['description'=>'Baris rekonsiliasi'],
        ],$this->admin);
        $this->finance->submitOperational($expense,$this->admin);
        $this->finance->decideOperational($expense,$this->superadmin,'APPROVED');
        $this->finance->payOperational($expense,$this->admin,'2026-10-02');

        $revenue=$this->finance->saveRevenue([
            'revenue_type'=>'OTHER','transaction_date'=>'2026-10-02','amount_idr'=>0,'payment_method'=>'CASH',
            'details'=>['notes'=>'Baris rekonsiliasi'],
        ],$this->admin);
        $this->finance->payRevenue($revenue,$this->admin,'2026-10-02');

        $this->assertSame('PAID',$expense->fresh()->payment_status);
        $this->assertSame('APPROVED',$expense->fresh()->approval_status);
        $this->assertSame('PAID',$revenue->fresh()->payment_status);
        $this->assertSame('0',app(Balances::class)->operationalCash('2026-10-02'));
        $this->assertSame('0',app(Balances::class)->revenueCash('2026-10-02'));
        $this->assertDatabaseMissing('audit_events',['action'=>'negative_balance_override']);

        $this->get(route('operational-cashflow.index'))->assertInertia(fn (Assert $page) => $page
            ->where('cashflows.data',fn ($rows) => collect($rows)->contains(fn ($row) => $row['id']===$expense->id && $row['amount_idr']==='0')));
        $this->get(route('revenues.index'))->assertInertia(fn (Assert $page) => $page
            ->where('revenues.data',fn ($rows) => collect($rows)->contains(fn ($row) => $row['id']===$revenue->id && $row['amount_idr']==='0')));
    }

    public function test_zero_detail_values_and_zero_daily_inputs_are_persisted(): void
    {
        $feed=FeedType::where('species','AYAM')->firstOrFail();
        $expense=$this->finance->saveOperationalCashflow([
            'cashflow_type'=>'FEED_AYAM','transaction_date'=>'2026-10-03',
            'details'=>[['feed_type_id'=>$feed->id,'quantity_kg'=>0,'price_per_kg_idr'=>0]],
        ],$this->admin);
        $this->assertSame('0',$expense->amount_idr);
        $this->assertDatabaseHas('operational_cashflow_feed_details',['operational_cashflow_id'=>$expense->id,'quantity_kg'=>'0.000','price_per_kg_idr'=>'0.000','line_amount_idr'=>0]);

        $group=LivestockGroup::create(['species'=>'AYAM','code'=>'ZERO-AYAM','name'=>'Zero Ayam','location'=>'Kandang 0','start_date'=>'2026-10-01','initial_population'=>100,'status'=>'ACTIVE']);
        $this->post(route('ayam.daily.store'),['rows'=>[[
            'record_date'=>'2026-10-03','group_id'=>$group->id,'mortality'=>0,'notes'=>'Rekonsiliasi',
            'feeds'=>[['feed_type_id'=>$feed->id,'morning_kg'=>0,'noon_kg'=>0]],
            'eggs'=>['UTUH'=>['weight_kg'=>0,'quantity_pieces'=>0],'PECAH'=>['weight_kg'=>0,'quantity_pieces'=>0],'REJECT'=>['weight_kg'=>0,'quantity_pieces'=>0]],
        ]]])->assertSessionHasNoErrors();
        $record=DailyRecord::where('group_id',$group->id)->firstOrFail();
        $this->assertDatabaseHas('feed_usages',['daily_record_id'=>$record->id,'quantity_kg'=>'0.000']);
        $this->assertSame(3,$record->eggHarvests()->where('weight_kg',0)->where('quantity_pieces',0)->count());

        $this->post(route('revenues.store'),['revenue_type'=>'OTHER','transaction_date'=>'2026-10-03','amount_idr'=>-1,'payment_method'=>'CASH','details'=>['notes'=>'Invalid']])->assertSessionHasErrors('amount_idr');
        $this->post(route('operational-cashflow.store'),['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-03','amount_idr'=>'','details'=>['description'=>'Invalid']])->assertSessionHasErrors('amount_idr');
    }

    public function test_superadmin_correction_audits_removed_feed_and_egg_details(): void
    {
        $feeds=FeedType::where('species','AYAM')->limit(2)->get();
        $cashflow=$this->finance->saveOperationalCashflow([
            'cashflow_type'=>'FEED_AYAM','transaction_date'=>'2026-10-04',
            'details'=>$feeds->map(fn ($feed) => ['feed_type_id'=>$feed->id,'quantity_kg'=>0,'price_per_kg_idr'=>0])->all(),
        ],$this->admin);
        $oldFeed=$cashflow->feedDetails()->orderBy('id')->get();
        $this->finance->submitOperational($cashflow,$this->admin);
        $this->finance->decideOperational($cashflow,$this->superadmin,'APPROVED');
        $this->finance->receive($oldFeed[0],$this->admin,'2026-10-04');
        $this->finance->receive($oldFeed[1],$this->admin,'2026-10-04');
        $this->finance->payOperational($cashflow,$this->admin,'2026-10-04');
        $this->finance->saveOperationalCashflow([
            'cashflow_type'=>'FEED_AYAM','transaction_date'=>'2026-10-04',
            'details'=>[['id'=>$oldFeed[0]->id,'feed_type_id'=>$oldFeed[0]->feed_type_id,'quantity_kg'=>0,'price_per_kg_idr'=>0]],
        ],$this->superadmin,$cashflow,true,'Rincian duplikat dihapus');
        $this->assertSame('PAID',$cashflow->fresh()->payment_status);
        $this->assertNotNull($cashflow->fresh()->feedDetails()->firstOrFail()->received_at);

        $feedAudit=DB::table('audit_events')->where('action','cashflow_feed_detail_deleted')->first();
        $this->assertNotNull($feedAudit);
        $this->assertSame($this->superadmin->id,$feedAudit->actor_id);
        $this->assertSame('Rincian duplikat dihapus',$feedAudit->reason);
        $this->assertSame($cashflow->id,json_decode($feedAudit->before,true)['operational_cashflow_id']);
        $this->assertSame([],json_decode($feedAudit->after,true));

        $revenue=$this->finance->saveRevenue([
            'revenue_type'=>'EGG_SALE','transaction_date'=>'2026-10-04',
            'details'=>[
                ['egg_category'=>'UTUH','weight_kg'=>0,'quantity_pieces'=>0,'price_per_kg_idr'=>0],
                ['egg_category'=>'REJECT','weight_kg'=>0,'quantity_pieces'=>0,'price_per_kg_idr'=>0],
            ],
        ],$this->admin);
        $oldEgg=$revenue->eggDetails()->orderBy('id')->get();
        $this->finance->payRevenue($revenue,$this->admin,'2026-10-04');
        $this->finance->saveRevenue([
            'revenue_type'=>'EGG_SALE','transaction_date'=>'2026-10-04',
            'details'=>[['id'=>$oldEgg[0]->id,'egg_category'=>'UTUH','weight_kg'=>0,'quantity_pieces'=>0,'price_per_kg_idr'=>0]],
        ],$this->superadmin,$revenue,true,'Kategori salah dihapus');
        $this->assertSame('PAID',$revenue->fresh()->payment_status);

        $eggAudit=DB::table('audit_events')->where('action','revenue_egg_detail_deleted')->first();
        $this->assertNotNull($eggAudit);
        $this->assertSame('REJECT',json_decode($eggAudit->before,true)['egg_category']);
        $this->assertSame([],json_decode($eggAudit->after,true));

        $this->get(route('operational-cashflow.index'))->assertInertia(fn (Assert $page) => $page
            ->where('cashflows.data.0.audit_logs',fn ($logs) => collect($logs)->contains(fn ($log) => $log['action']==='cashflow_feed_detail_deleted' && str_starts_with($log['subject_label'],'Pakan '))));
        $this->get(route('revenues.index'))->assertInertia(fn (Assert $page) => $page
            ->where('revenues.data.0.audit_logs',fn ($logs) => collect($logs)->contains(fn ($log) => $log['action']==='revenue_egg_detail_deleted' && $log['subject_label']==='Telur RIJEK')));
    }

    public function test_detail_ids_from_another_transaction_are_rejected(): void
    {
        $feed=FeedType::where('species','AYAM')->firstOrFail();
        $first=$this->finance->saveOperationalCashflow(['cashflow_type'=>'FEED_AYAM','transaction_date'=>'2026-10-05','details'=>[['feed_type_id'=>$feed->id,'quantity_kg'=>0,'price_per_kg_idr'=>0]]],$this->admin);
        $second=$this->finance->saveOperationalCashflow(['cashflow_type'=>'FEED_AYAM','transaction_date'=>'2026-10-05','details'=>[['feed_type_id'=>$feed->id,'quantity_kg'=>0,'price_per_kg_idr'=>0]]],$this->admin);

        try {
            $this->finance->saveOperationalCashflow(['cashflow_type'=>'FEED_AYAM','transaction_date'=>'2026-10-05','details'=>[['id'=>$second->feedDetails()->firstOrFail()->id,'feed_type_id'=>$feed->id,'quantity_kg'=>0,'price_per_kg_idr'=>0]]],$this->superadmin,$first,true,'Tes ID asing');
            $this->fail('A foreign detail ID should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('details.0.id',$exception->errors());
        }
        $this->assertSame(1,$first->feedDetails()->count());
        $this->assertDatabaseMissing('audit_events',['action'=>'cashflow_feed_detail_deleted','reason'=>'Tes ID asing']);
    }

    public function test_migration_rollback_refuses_zero_rows(): void
    {
        $this->finance->saveRevenue(['revenue_type'=>'OTHER','transaction_date'=>'2026-10-06','amount_idr'=>0,'details'=>['notes'=>'Tetap disimpan']],$this->admin);
        $migration=require database_path('migrations/2026_10_16_000006_allow_zero_finance_and_daily_values.php');

        try {
            $migration->down();
            $this->fail('Rollback should refuse rows that violate strict-positive constraints.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('zero-valued rows',$exception->getMessage());
            $this->assertStringContainsString('revenues',$exception->getMessage());
        }
    }
}
