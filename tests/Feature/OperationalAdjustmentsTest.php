<?php

namespace Tests\Feature;

use App\Models\{Employee,FeedType,User};
use App\Services\{Balances,FinanceService};
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationalAdjustmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $superadmin;
    private FinanceService $finance;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00','Asia/Jakarta'));
        $this->seed(DatabaseSeeder::class);
        User::query()->update(['must_change_password'=>false]);
        $this->admin=User::where('role','ADMIN')->firstOrFail();
        $this->superadmin=User::where('role','SUPERADMIN')->firstOrFail();
        $this->finance=app(FinanceService::class);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_approved_unpaid_only_resets_when_total_increases(): void
    {
        $row=$this->finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-10','amount_idr'=>100000,'details'=>['description'=>'Awal']],$this->admin);
        $this->finance->submitOperational($row,$this->admin);
        $this->finance->decideOperational($row,$this->superadmin,'APPROVED');

        $lower=$this->finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-11','amount_idr'=>80000,'details'=>['description'=>'Lebih rendah']],$this->admin,$row);
        $this->assertSame('APPROVED',$lower->approval_status);
        $this->assertNotNull($lower->approved_at);
        $this->assertDatabaseHas('audit_events',['entity_id'=>$row->id,'action'=>'cashflow_approved_edit_preserved']);

        $equal=$this->finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-12','amount_idr'=>80000,'details'=>['description'=>'Jumlah sama']],$this->admin,$lower);
        $this->assertSame('APPROVED',$equal->approval_status);

        $higher=$this->finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-12','amount_idr'=>90000,'details'=>['description'=>'Naik']],$this->admin,$equal);
        $this->assertSame('DRAFT',$higher->approval_status);
        $this->assertSame('UNPAID',$higher->payment_status);
        $this->assertNull($higher->approved_at);
        $this->assertNull($higher->submitted_at);
        $this->assertDatabaseHas('audit_events',['entity_id'=>$row->id,'action'=>'cashflow_approval_increase_reset']);
    }

    public function test_paid_reduction_is_rejected_and_increase_preserves_existing_payment(): void
    {
        $this->finance->saveOperationalCashflow(['cashflow_type'=>'MAIN_ACCOUNT_TOPUP','transaction_date'=>'2026-10-01','amount_idr'=>1000000,'details'=>['notes'=>'Modal']],$this->admin);
        $row=$this->finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-10','amount_idr'=>100000,'details'=>['description'=>'Awal']],$this->admin);
        $this->finance->submitOperational($row,$this->admin); $this->finance->decideOperational($row,$this->superadmin,'APPROVED'); $this->finance->payOperational($row,$this->superadmin,'2026-10-10');

        try {
            $this->finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-10','amount_idr'=>80000,'details'=>['description'=>'Koreksi']],$this->superadmin,$row,true,'Nilai bukti lebih rendah');
            $this->fail('A correction below recorded payments must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount_idr',$exception->errors());
        }

        $higher=$this->finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-10','amount_idr'=>120000,'details'=>['description'=>'Koreksi naik']],$this->superadmin,$row,true,'Nilai bukti lebih tinggi');
        $this->assertSame('DRAFT',$higher->approval_status); $this->assertSame('PARTIALLY_PAID',$higher->payment_status);
        $this->assertNull($higher->paid_at); $this->assertNull($higher->paid_by);
        $this->assertSame('900000',app(Balances::class)->operationalCash('2026-10-14'));
        $this->assertDatabaseHas('audit_events',['entity_id'=>$row->id,'action'=>'cashflow_paid_increase_reset']);
    }

    public function test_sack_purchase_keeps_original_unit_and_uses_canonical_kg_for_stock(): void
    {
        $feed=FeedType::where('species','AYAM')->firstOrFail();
        $row=$this->finance->saveOperationalCashflow(['cashflow_type'=>'FEED_AYAM','transaction_date'=>'2026-10-10','details'=>[[
            'feed_type_id'=>$feed->id,'purchase_unit'=>'SAK','purchase_quantity'=>2,'kg_per_sak'=>'25.000','price_per_purchase_unit_idr'=>'300000',
        ]]],$this->admin);
        $line=$row->feedDetails()->firstOrFail();
        $this->assertSame('SAK',$line->purchase_unit); $this->assertSame('2.000',$line->purchase_quantity);
        $this->assertSame('50.000',$line->quantity_kg); $this->assertSame('12000.000',$line->price_per_kg_idr);
        $this->assertSame('600000',$line->line_amount_idr); $this->assertSame('600000',$row->amount_idr);

        $this->finance->submitOperational($row,$this->admin); $this->finance->decideOperational($row,$this->superadmin,'APPROVED');
        $this->finance->receive($line,$this->admin,'2026-10-10');
        $this->assertSame('50.000',app(Balances::class)->feed($feed->id,'2026-10-10'));

        $legacy=$this->finance->saveOperationalCashflow(['cashflow_type'=>'FEED_AYAM','transaction_date'=>'2026-10-11','details'=>[['feed_type_id'=>$feed->id,'quantity_kg'=>'3.500','price_per_kg_idr'=>'10000']]],$this->admin);
        $legacyLine=$legacy->feedDetails()->firstOrFail();
        $this->assertSame('KG',$legacyLine->purchase_unit); $this->assertSame('3.500',$legacyLine->purchase_quantity);

        $legacyLine->delete();
        $legacyId=DB::table('operational_cashflow_feed_details')->insertGetId(['operational_cashflow_id'=>$legacy->id,'feed_type_id'=>$feed->id,'quantity_kg'=>'4.000','price_per_kg_idr'=>'9000','line_amount_idr'=>36000,'received_at'=>null,'received_by'=>null,'created_at'=>now(),'updated_at'=>now()]);
        $this->assertDatabaseHas('operational_cashflow_feed_details',['id'=>$legacyId,'purchase_unit'=>'KG','purchase_quantity'=>'4.000','price_per_purchase_unit_idr'=>'9000.000']);

        $this->post('/operational-cashflow',['cashflow_type'=>'FEED_AYAM','transaction_date'=>'2026-10-12','details'=>[['feed_type_id'=>$feed->id,'purchase_unit'=>'SAK','purchase_quantity'=>'1.5','kg_per_sak'=>25,'price_per_purchase_unit_idr'=>300000]]])->assertSessionHasErrors('details.0.purchase_quantity');
    }

    public function test_salary_rows_include_inactive_employee_name(): void
    {
        $employee=Employee::create(['name'=>'Budi Laut','job_title'=>'ABK','joined_at'=>'2026-01-01','status'=>'ACTIVE','notes'=>null,'abk_type'=>'BOTH']);
        $row=$this->finance->saveOperationalCashflow(['cashflow_type'=>'SALARY','transaction_date'=>'2026-10-10','amount_idr'=>500000,'details'=>['employee_id'=>$employee->id,'salary_period'=>'Oktober 2026']],$this->admin);
        $employee->update(['status'=>'INACTIVE']);

        $this->get('/operational-cashflow')->assertInertia(fn(Assert $page)=>$page
            ->where('cashflows.data.0.id',$row->id)
            ->where('cashflows.data.0.details.employee_name','Budi Laut')
            ->where('employees',fn($employees)=>collect($employees)->contains('id',$employee->id)));
    }
}
