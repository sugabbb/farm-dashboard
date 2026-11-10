<?php

namespace Tests\Feature;

use App\Models\{FeedType,InventoryAdjustment,LivestockGroup,User};
use App\Services\{FarmOperations,FeedStockLedger,FeedStockProjection,FinanceService};
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class FeedStockMovementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $superadmin;
    private FeedType $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        User::query()->update(['must_change_password'=>false]);
        $this->admin=User::where('role','ADMIN')->firstOrFail();
        $this->superadmin=User::where('role','SUPERADMIN')->firstOrFail();
        $this->feed=FeedType::where('species','AYAM')->firstOrFail();
        $this->actingAs($this->superadmin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_feed_ledger_unifies_opening_receipt_adjustment_and_usage_with_running_balance(): void
    {
        DB::table('inventory_opening_balances')->insert([
            'item_kind'=>'FEED','feed_type_id'=>$this->feed->id,'egg_category'=>null,'quantity_kg'=>'10','quantity_pieces'=>null,
            'effective_date'=>'2026-10-01','created_by'=>$this->superadmin->id,'created_at'=>'2026-10-01 01:00:00+00','updated_at'=>'2026-10-01 01:00:00+00',
        ]);

        $finance=app(FinanceService::class);
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:00','Asia/Jakarta'));
        $cashflow=$finance->saveOperationalCashflow(['cashflow_type'=>'FEED_AYAM','transaction_date'=>'2026-10-02','details'=>[['feed_type_id'=>$this->feed->id,'quantity_kg'=>'5','price_per_kg_idr'=>'1000']]],$this->admin);
        $finance->submitOperational($cashflow,$this->admin);
        $finance->decideOperational($cashflow,$this->superadmin,'APPROVED');
        $finance->receive($cashflow->feedDetails()->firstOrFail(),$this->admin,'2026-10-02');

        Carbon::setTestNow(Carbon::parse('2026-10-03 09:00:00','Asia/Jakarta'));
        app(FarmOperations::class)->adjustInventory(['item_kind'=>'FEED','feed_type_id'=>$this->feed->id,'delta_kg'=>'-2','effective_date'=>'2026-10-03','reason'=>'Koreksi fisik'],$this->superadmin);

        $group=LivestockGroup::create(['species'=>'AYAM','code'=>'AYAM-LEDGER','name'=>'Kandang Ledger','location'=>'Lokasi 1','start_date'=>'2026-10-01','initial_population'=>100,'status'=>'ACTIVE']);
        Carbon::setTestNow(Carbon::parse('2026-10-04 09:00:00','Asia/Jakarta'));
        app(FarmOperations::class)->saveDaily('AYAM',[['record_date'=>'2026-10-04','group_id'=>$group->id,'mortality'=>0,'feeds'=>[['feed_type_id'=>$this->feed->id,'morning_kg'=>'10','noon_kg'=>'10']],'eggs'=>[]]],$this->admin);

        $movements=app(FeedStockLedger::class)->movements('AYAM','2026-10-04',$this->feed->id);
        $this->assertSame(['OPENING','RECEIPT','ADJUSTMENT','USAGE'],$movements->pluck('movement_type')->all());
        $this->assertSame(['10.000','15.000','13.000','-7.000'],$movements->pluck('balance_after')->all());
        $this->assertSame('20.000',$movements->last()['quantity_out']);

        $this->get('/ayam/feed?date=2026-10-03&feed_type_id='.$this->feed->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Feed')->where('species','AYAM')->has('movements.data',3)
            ->where('movements.data.0.movement_type','ADJUSTMENT')
            ->where('movements.data.0.balance_after','13.000')
            ->missing('usage')->missing('adjustments'));
    }

    public function test_zero_movements_and_other_species_are_excluded(): void
    {
        $lele=FeedType::where('species','LELE')->firstOrFail();
        foreach ([[$this->feed->id,'0'],[$lele->id,'5']] as [$feedId,$quantity]) DB::table('inventory_opening_balances')->insert([
            'item_kind'=>'FEED','feed_type_id'=>$feedId,'egg_category'=>null,'quantity_kg'=>$quantity,'quantity_pieces'=>null,
            'effective_date'=>'2026-10-01','created_by'=>$this->superadmin->id,'created_at'=>now(),'updated_at'=>now(),
        ]);

        $this->assertCount(0,app(FeedStockLedger::class)->movements('AYAM','2026-10-01'));
        $this->assertSame([$lele->id],app(FeedStockLedger::class)->movements('LELE','2026-10-01')->pluck('feed_id')->all());
    }

    public function test_running_balance_is_calculated_before_pagination(): void
    {
        DB::table('inventory_opening_balances')->insert([
            'item_kind'=>'FEED','feed_type_id'=>$this->feed->id,'egg_category'=>null,'quantity_kg'=>'1','quantity_pieces'=>null,
            'effective_date'=>'2026-10-01','created_by'=>$this->superadmin->id,'created_at'=>'2026-10-01 01:00:00+00','updated_at'=>'2026-10-01 01:00:00+00',
        ]);
        for ($index=1; $index<=45; $index++) DB::table('inventory_adjustments')->insert([
            'item_kind'=>'FEED','feed_type_id'=>$this->feed->id,'egg_category'=>null,'delta_kg'=>'1','delta_pieces'=>null,
            'effective_date'=>'2026-10-02','reason'=>'Adjustment '.$index,'created_by'=>$this->superadmin->id,
            'created_at'=>'2026-10-02 01:00:00+00','updated_at'=>'2026-10-02 01:00:00+00',
        ]);

        $this->get('/ayam/feed?date=2026-10-02&feed_type_id='.$this->feed->id.'&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('movements.total',46)->where('movements.current_page',2)->has('movements.data',6)
            ->where('movements.data.0.balance_after','6.000')->where('movements.data.5.balance_after','1.000'));
    }

    public function test_feed_adjustments_are_editable_audited_and_authorized(): void
    {
        $replacement=FeedType::create(['species'=>'AYAM','name'=>'Replacement Feed','is_active'=>false]);
        $lele=FeedType::where('species','LELE')->firstOrFail();
        $adjustment=app(FarmOperations::class)->adjustInventory([
            'item_kind'=>'FEED','feed_type_id'=>$this->feed->id,'delta_kg'=>'2.5',
            'effective_date'=>'2026-10-02','reason'=>'Awal',
        ],$this->superadmin);
        $creator=$adjustment->created_by;

        $this->patch(route('inventory.adjustments.update',$adjustment),[
            'feed_type_id'=>$replacement->id,'delta_kg'=>'-4.25','effective_date'=>'2026-10-03','reason'=>'Koreksi fisik',
        ])->assertSessionHasNoErrors();
        $adjustment->refresh();
        $this->assertSame($creator,$adjustment->created_by);
        $this->assertSame('FEED',$adjustment->item_kind);
        $this->assertSame($replacement->id,$adjustment->feed_type_id);
        $this->assertSame('-4.250',$adjustment->delta_kg);
        $audit=DB::table('audit_events')->where('entity_type','inventory_adjustments')->where('entity_id',$adjustment->id)->where('action','inventory_adjustment_updated')->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->feed->id,json_decode($audit->before,true)['feed_type_id']);
        $movement=app(FeedStockLedger::class)->movements('AYAM','2026-10-03',$replacement->id)->first();
        $this->assertSame($adjustment->id,$movement['adjustment']['id']);
        $this->assertSame('Koreksi fisik',$movement['adjustment']['reason']);

        $this->patch(route('inventory.adjustments.update',$adjustment),[
            'feed_type_id'=>$lele->id,'delta_kg'=>'1','effective_date'=>'2026-10-03','reason'=>'Salah spesies',
        ])->assertSessionHasErrors('feed_type_id');
        $this->patch(route('inventory.adjustments.update',$adjustment),[
            'feed_type_id'=>$replacement->id,'delta_kg'=>'0','effective_date'=>'2026-10-03','reason'=>'Nol',
        ])->assertSessionHasErrors('delta_kg');
        $this->actingAs($this->admin)->patch(route('inventory.adjustments.update',$adjustment),[
            'feed_type_id'=>$replacement->id,'delta_kg'=>'1','effective_date'=>'2026-10-03','reason'=>'Tidak berhak',
        ])->assertForbidden();

        $this->actingAs($this->superadmin);
        $egg=InventoryAdjustment::create(['item_kind'=>'EGG','egg_category'=>'UTUH','delta_kg'=>'1','delta_pieces'=>1,'effective_date'=>'2026-10-03','reason'=>'Telur','created_by'=>$this->superadmin->id]);
        $this->patch(route('inventory.adjustments.update',$egg),[
            'feed_type_id'=>$replacement->id,'delta_kg'=>'1','effective_date'=>'2026-10-03','reason'=>'Bukan pakan',
        ])->assertNotFound();
    }

    public function test_stock_projection_uses_each_feeds_latest_aggregated_day_and_selected_date(): void
    {
        $second=FeedType::create(['species'=>'AYAM','name'=>'Second Projection Feed','is_active'=>false]);
        $unused=FeedType::create(['species'=>'AYAM','name'=>'Unused Projection Feed','is_active'=>true]);
        $zero=FeedType::create(['species'=>'AYAM','name'=>'Zero Projection Feed','is_active'=>true]);
        $groups=collect([1,2])->map(fn($index)=>LivestockGroup::create(['species'=>'AYAM','code'=>'PROJ-'.$index,'name'=>'Projection '.$index,'location'=>'Kandang '.$index,'start_date'=>'2026-10-01','initial_population'=>100,'status'=>'ACTIVE']));
        foreach($groups as $index=>$group) {
            $recordId=DB::table('daily_records')->insertGetId(['group_id'=>$group->id,'record_date'=>'2026-10-03','mortality'=>0,'created_by'=>$this->admin->id,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('feed_usages')->insert(['daily_record_id'=>$recordId,'feed_type_id'=>$this->feed->id,'morning_kg'=>'30.5','noon_kg'=>'0','evening_kg'=>'0','quantity_kg'=>'30.5','created_at'=>now(),'updated_at'=>now()]);
            if($index===0) DB::table('feed_usages')->insert(['daily_record_id'=>$recordId,'feed_type_id'=>$zero->id,'morning_kg'=>'0','noon_kg'=>'0','evening_kg'=>'0','quantity_kg'=>'0','created_at'=>now(),'updated_at'=>now()]);
        }
        $olderId=DB::table('daily_records')->insertGetId(['group_id'=>$groups[0]->id,'record_date'=>'2026-10-02','mortality'=>0,'created_by'=>$this->admin->id,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('feed_usages')->insert(['daily_record_id'=>$olderId,'feed_type_id'=>$second->id,'morning_kg'=>'2','noon_kg'=>'0','evening_kg'=>'0','quantity_kg'=>'2','created_at'=>now(),'updated_at'=>now()]);
        $futureId=DB::table('daily_records')->insertGetId(['group_id'=>$groups[0]->id,'record_date'=>'2026-10-05','mortality'=>0,'created_by'=>$this->admin->id,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('feed_usages')->insert(['daily_record_id'=>$futureId,'feed_type_id'=>$second->id,'morning_kg'=>'8','noon_kg'=>'0','evening_kg'=>'0','quantity_kg'=>'8','created_at'=>now(),'updated_at'=>now()]);

        $types=collect([$this->feed,$second,$unused,$zero]);
        $estimates=app(FeedStockProjection::class)->estimate($types,'2026-10-04',[
            $this->feed->id=>'390',$second->id=>'8',$unused->id=>'5',$zero->id=>'4',
        ]);
        $this->assertSame(['ESTIMATED','ESTIMATED','NO_USAGE','ZERO_USAGE'],collect($types)->map(fn($feed)=>$estimates[$feed->id]['status'])->all());
        $this->assertSame('61.000',$estimates[$this->feed->id]['daily_usage_kg']);
        $this->assertSame('2026-10-03',$estimates[$this->feed->id]['usage_date']);
        $this->assertSame(6,$estimates[$this->feed->id]['days_remaining']);
        $this->assertSame('2026-10-10',$estimates[$this->feed->id]['estimated_out_of_stock_date']);
        $this->assertSame('2026-10-02',$estimates[$second->id]['usage_date']);
        $this->assertSame('2.000',$estimates[$second->id]['daily_usage_kg']);
        $this->assertSame('0.000',$estimates[$zero->id]['daily_usage_kg']);

        $out=app(FeedStockProjection::class)->estimate(collect([$unused]),'2026-10-04',[$unused->id=>'-1']);
        $this->assertSame('OUT_OF_STOCK',$out[$unused->id]['status']);
        $this->assertSame('2026-10-04',$out[$unused->id]['estimated_out_of_stock_date']);
    }
}
