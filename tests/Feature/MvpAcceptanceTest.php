<?php
namespace Tests\Feature;

use App\Models\{Employee,OperationalCashflow,FeedType,LivestockGroup,Revenue,User};
use App\Services\{Balances,FarmOperations,FinanceService};
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,RateLimiter};
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MvpAcceptanceTest extends TestCase {
    use RefreshDatabase;
    private User $admin; private User $superadmin; private FarmOperations $operations; private FinanceService $finance; private Balances $balances;
    protected function setUp(): void {
        parent::setUp(); $this->seed(DatabaseSeeder::class);
        $this->admin=User::where('role','ADMIN')->firstOrFail(); $this->superadmin=User::where('role','SUPERADMIN')->firstOrFail();
        User::query()->update(['must_change_password'=>false]); $this->superadmin->refresh(); $this->admin->refresh(); $this->actingAs($this->superadmin);
        $this->operations=app(FarmOperations::class); $this->finance=app(FinanceService::class); $this->balances=app(Balances::class);
    }
    private function group(string $species='AYAM',int $population=200): LivestockGroup { return LivestockGroup::create(['species'=>$species,'code'=>$species.uniqid(),'name'=>'Grup '.$species,'location'=>'Lokasi 1','start_date'=>'2026-10-01','initial_population'=>$population,'status'=>'ACTIVE']); }
    private function opening(string $kind,int|string $key,string $kg,int $pieces=0,string $date='2026-10-01'): void { DB::table('inventory_opening_balances')->insert(['item_kind'=>$kind,'feed_type_id'=>$kind==='FEED'?$key:null,'egg_category'=>$kind==='EGG'?$key:null,'quantity_kg'=>$kg,'quantity_pieces'=>$kind==='EGG'?$pieces:null,'effective_date'=>$date,'created_by'=>$this->superadmin->id,'created_at'=>now(),'updated_at'=>now()]); }
    private function approvedFeedExpense(FeedType $feed): OperationalCashflow { $e=$this->finance->saveOperationalCashflow(['cashflow_type'=>'FEED_'.$feed->species,'transaction_date'=>'2026-10-05','details'=>[['feed_type_id'=>$feed->id,'quantity_kg'=>'100','price_per_kg_idr'=>'20000']]],$this->admin); $this->finance->submitOperational($e,$this->admin); return $this->finance->decideOperational($e,$this->superadmin,'APPROVED'); }

    public function test_at_01_to_03_daily_parent_children_population_and_egg_stock(): void {
        $group=$this->group(); $feeds=FeedType::where('species','AYAM')->take(2)->get(); foreach($feeds as $feed) $this->opening('FEED',$feed->id,'50');
        $this->operations->saveDaily('AYAM',[['record_date'=>'2026-10-05','group_id'=>$group->id,'mortality'=>3,'feeds'=>$feeds->map(fn($f)=>['feed_type_id'=>$f->id,'morning_kg'=>'1.25','noon_kg'=>'0.75'])->all(),'eggs'=>['UTUH'=>['weight_kg'=>'10','quantity_pieces'=>160],'PECAH'=>['weight_kg'=>'0.5','quantity_pieces'=>8],'REJECT'=>['weight_kg'=>0,'quantity_pieces'=>0]]]],$this->admin);
        $this->assertSame(197,$this->balances->population($group,'2026-10-05'));
        $this->assertDatabaseCount('daily_records',1); $this->assertDatabaseCount('feed_usages',2); $this->assertDatabaseCount('egg_harvests',3);
        $this->assertSame(['kg'=>'10.000','pieces'=>160],$this->balances->egg('UTUH','2026-10-05'));
        $this->assertSame(['kg'=>'0.500','pieces'=>8],$this->balances->egg('PECAH','2026-10-05'));
    }

    public function test_at_04_to_08_approval_receipt_payment_and_egg_sale_are_independent(): void {
        DB::table('financial_opening_balances')->insert(['amount_idr'=>5000000,'effective_date'=>'2026-10-01','created_by'=>$this->superadmin->id,'created_at'=>now(),'updated_at'=>now()]);
        $feed=FeedType::where('species','AYAM')->first(); $expense=$this->approvedFeedExpense($feed);
        $this->assertSame('0.000',$this->balances->feed($feed->id,'2026-10-05')); $this->assertSame('5000000',$this->balances->operationalCash('2026-10-05'));
        $line=$expense->feedDetails()->first(); $this->finance->receive($line,$this->admin,'2026-10-05'); $this->finance->receive($line,$this->admin,'2026-10-05');
        $this->assertSame('100.000',$this->balances->feed($feed->id,'2026-10-05')); $this->assertSame('5000000',$this->balances->operationalCash('2026-10-05'));
        $this->finance->payOperational($expense,$this->admin,'2026-10-06'); $this->finance->payOperational($expense,$this->admin,'2026-10-06');
        $this->assertSame('3000000',$this->balances->operationalCash('2026-10-06')); $this->assertSame('100.000',$this->balances->feed($feed->id,'2026-10-06'));
        $this->opening('EGG','UTUH','10',160);
        $sale=$this->finance->saveRevenue(['revenue_type'=>'EGG_SALE','transaction_date'=>'2026-10-07','details'=>[['egg_category'=>'UTUH','weight_kg'=>'4','quantity_pieces'=>60,'price_per_kg_idr'=>125000,'notes'=>null]]],$this->admin);
        $this->assertSame(['kg'=>'6.000','pieces'=>100],$this->balances->egg('UTUH','2026-10-07')); $this->assertSame('3000000',$this->balances->operationalCash('2026-10-07'));
        $this->finance->payRevenue($sale,$this->admin,'2026-10-08'); $this->assertSame('3000000',$this->balances->operationalCash('2026-10-08')); $this->assertSame('500000',$this->balances->revenueCash('2026-10-08')); $this->assertSame(['kg'=>'6.000','pieces'=>100],$this->balances->egg('UTUH','2026-10-08'));
    }

    public function test_at_09_to_12_fish_sale_mortality_transfer_and_topup(): void {
        DB::table('financial_opening_balances')->insert(['amount_idr'=>10000000,'effective_date'=>'2026-10-01','created_by'=>$this->superadmin->id,'created_at'=>now(),'updated_at'=>now()]); $group=$this->group('LELE',1000); $dailyFeed=FeedType::where('species','LELE')->firstOrFail();
        $fish=$this->finance->saveRevenue(['revenue_type'=>'FISH_SALE','transaction_date'=>'2026-10-05','details'=>['weight_kg'=>'125','price_per_kg_idr'=>'22000','notes'=>'Panen']],$this->admin);
        $this->assertSame('2750000',$fish->amount_idr); $this->assertSame(1000,$this->balances->population($group,'2026-10-05'));
        $this->operations->saveDaily('LELE',[['record_date'=>'2026-10-06','group_id'=>$group->id,'mortality'=>10,'feeds'=>[['feed_type_id'=>$dailyFeed->id,'morning_kg'=>0,'noon_kg'=>0,'evening_kg'=>0]]]],$this->admin); $this->assertSame(990,$this->balances->population($group,'2026-10-06'));
        $this->finance->payRevenue($fish,$this->admin,'2026-10-05');
        $transfer=$this->finance->saveRevenue(['revenue_type'=>'MAIN_ACCOUNT_TRANSFER','transaction_date'=>'2026-10-06','amount_idr'=>1000000,'details'=>['notes'=>'Setoran']],$this->admin); $this->finance->submitRevenueTransfer($transfer,$this->admin); Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00','Asia/Jakarta')); $this->finance->decideRevenueTransfer($transfer,$this->superadmin,'APPROVED'); Carbon::setTestNow();
        $this->finance->saveOperationalCashflow(['cashflow_type'=>'MAIN_ACCOUNT_TOPUP','transaction_date'=>'2026-10-07','amount_idr'=>500000,'details'=>['notes'=>'Modal']],$this->admin);
        $this->assertSame('10500000',$this->balances->operationalCash('2026-10-07')); $this->assertSame('1750000',$this->balances->revenueCash('2026-10-07'));
        $this->get('/dashboard?summary_from=2026-10-01&summary_to=2026-10-12')->assertOk()->assertInertia(fn(Assert $page)=>$page
            ->component('Dashboard')->where('current.unpaid_revenue','0')->where('current.operational_cash','10500000')->where('current.revenue_cash','1750000'));
    }

    public function test_at_13_to_15_reapproval_received_lock_and_backdated_sale_guard(): void {
        $feed=FeedType::where('species','AYAM')->first(); $expense=$this->approvedFeedExpense($feed);
        $edited=$this->finance->saveOperationalCashflow(['cashflow_type'=>'FEED_AYAM','transaction_date'=>'2026-10-05','details'=>[['feed_type_id'=>$feed->id,'quantity_kg'=>'90','price_per_kg_idr'=>'20000']]],$this->admin,$expense);
        $this->assertSame('APPROVED',$edited->approval_status); $this->assertDatabaseHas('audit_events',['entity_type'=>'operational_cashflows','action'=>'cashflow_approved_edit_preserved']);
        $this->finance->receive($edited->feedDetails()->first(),$this->admin,'2026-10-06');
        $this->expectException(ValidationException::class); $this->finance->saveOperationalCashflow(['cashflow_type'=>'FEED_AYAM','transaction_date'=>'2026-10-05','details'=>[['feed_type_id'=>$feed->id,'quantity_kg'=>'80','price_per_kg_idr'=>'20000']]],$this->admin,$edited);
    }

    public function test_backdated_egg_sale_can_make_stock_negative(): void {
        $this->opening('EGG','UTUH','5',50,'2026-10-01');
        $this->finance->saveRevenue(['revenue_type'=>'EGG_SALE','transaction_date'=>'2026-10-10','details'=>[['egg_category'=>'UTUH','weight_kg'=>'4','quantity_pieces'=>40,'price_per_kg_idr'=>25000]]],$this->admin);
        $this->finance->saveRevenue(['revenue_type'=>'EGG_SALE','transaction_date'=>'2026-10-05','details'=>[['egg_category'=>'UTUH','weight_kg'=>'2','quantity_pieces'=>20,'price_per_kg_idr'=>25000]]],$this->admin);
        $this->assertSame(['kg'=>'-1.000','pieces'=>-10],$this->balances->egg('UTUH','2026-10-10'));
    }

    public function test_inventory_adjustments_can_make_feed_and_egg_stock_negative(): void {
        $feed=FeedType::where('species','AYAM')->firstOrFail();
        $this->operations->adjustInventory(['item_kind'=>'FEED','feed_type_id'=>$feed->id,'delta_kg'=>'-5','effective_date'=>'2026-10-05','reason'=>'Stok fisik minus'],$this->superadmin);
        $this->operations->adjustInventory(['item_kind'=>'EGG','egg_category'=>'PECAH','delta_kg'=>'-2','delta_pieces'=>-20,'effective_date'=>'2026-10-05','reason'=>'Stok fisik minus'],$this->superadmin);
        $this->assertSame('-5.000',$this->balances->feed($feed->id,'2026-10-05'));
        $this->assertSame(['kg'=>'-2.000','pieces'=>-20],$this->balances->egg('PECAH','2026-10-05'));
        $this->assertDatabaseCount('inventory_adjustments',2);
    }

    public function test_inventory_opening_balance_can_start_negative(): void {
        $feed=FeedType::where('species','AYAM')->firstOrFail();
        $this->post('/settings/opening/inventory',['item_kind'=>'FEED','feed_type_id'=>$feed->id,'quantity_kg'=>'-3.5','effective_date'=>'2026-10-01'])->assertSessionHasNoErrors();
        $this->post('/settings/opening/inventory',['item_kind'=>'EGG','egg_category'=>'REJECT','quantity_kg'=>'-1.25','quantity_pieces'=>-12,'effective_date'=>'2026-10-01'])->assertSessionHasNoErrors();
        $this->assertSame('-3.500',$this->balances->feed($feed->id,'2026-10-01'));
        $this->assertSame(['kg'=>'-1.250','pieces'=>-12],$this->balances->egg('REJECT','2026-10-01'));
    }

    public function test_at_16_calendar_day_average_and_at_17_role_authorization(): void {
        $group=$this->group(); $dailyFeed=FeedType::where('species','AYAM')->firstOrFail(); foreach([0,1,2,4,6] as $offset) $this->operations->saveDaily('AYAM',[['record_date'=>now()->subDays($offset)->toDateString(),'group_id'=>$group->id,'mortality'=>0,'feeds'=>[['feed_type_id'=>$dailyFeed->id,'morning_kg'=>0,'noon_kg'=>0]],'eggs'=>['UTUH'=>['weight_kg'=>7,'quantity_pieces'=>70],'PECAH'=>['weight_kg'=>0,'quantity_pieces'=>0],'REJECT'=>['weight_kg'=>0,'quantity_pieces'=>0]]]],$this->admin);
        $this->get('/dashboard')->assertOk()->assertInertia(fn(Assert $page)=>$page
            ->component('Dashboard')->where('eggStock.UTUH.kg','35.000')->where('eggStock.UTUH.pieces',350));
        $expense=$this->finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>today()->toDateString(),'amount_idr'=>1000,'details'=>['description'=>'Tes']],$this->admin); $this->finance->submitOperational($expense,$this->admin);
        $this->actingAs($this->admin)->post(route('operational-cashflow.decision',['cashflow'=>$expense]),['decision'=>'APPROVED'])->assertForbidden();
    }

    public function test_at_18_inactive_employee_keeps_salary_history_but_is_not_selectable(): void {
        $employee=Employee::create(['name'=>'ABK Satu','joined_at'=>'2026-01-01','status'=>'ACTIVE']);
        $expense=$this->finance->saveOperationalCashflow(['cashflow_type'=>'SALARY','transaction_date'=>'2026-10-01','amount_idr'=>2000000,'details'=>['employee_id'=>$employee->id,'salary_period'=>'Oktober 2026']],$this->admin);
        $employee->update(['status'=>'INACTIVE']); $this->assertDatabaseHas('operational_cashflow_salary_details',['operational_cashflow_id'=>$expense->id,'employee_id'=>$employee->id]);
        $this->get('/operational-cashflow')->assertOk()->assertInertia(fn(Assert $page)=>$page->component('OperationalCashflow')->has('employees',1)->where('employees.0.name','ABK Satu'));
    }

    public function test_required_page_map_renders_and_legacy_routes_are_gone(): void {
        $pages=['/dashboard'=>'Dashboard','/ayam/groups'=>'Groups','/ayam/feed'=>'Feed','/ayam/eggs'=>'Eggs','/ayam/daily-entry'=>'DailyEntry','/lele/groups'=>'Groups','/lele/feed'=>'Feed','/lele/daily-entry'=>'DailyEntry','/lele/biomass'=>'LeleBiomass','/employees'=>'Employees','/operational-cashflow'=>'OperationalCashflow','/revenues'=>'Revenues','/reports'=>'Report','/customers'=>'Customers','/settings'=>'Settings'];
        foreach($pages as $url=>$component) $this->get($url)->assertOk()->assertInertia(fn(Assert $page)=>$page->component($component));
        $this->get('/expenses?payment_status=PAID')->assertRedirect('/operational-cashflow?payment_status=PAID');
        $this->get('/revenue?payment_status=PAID')->assertRedirect('/revenues?payment_status=PAID');
        foreach(['/imports','/reconciliation','/feed-plans','/transactions/budget'] as $url) $this->get($url)->assertNotFound();
        $this->get('/ayam/daily-entry')->assertInertia(fn(Assert $page)=>$page->component('DailyEntry')->where('species','AYAM')->has('records.data'));
        $this->get('/lele/groups')->assertInertia(fn(Assert $page)=>$page->component('Groups')->where('species','LELE')->has('groups.data'));
    }

    public function test_login_throttling_remains_available_with_the_file_cache_contract(): void {
        auth()->logout();
        $key='blocked@example.test|127.0.0.1'; RateLimiter::clear($key);
        for($attempt=0;$attempt<5;$attempt++) $this->post('/login',['email'=>'blocked@example.test','password'=>'wrong-password']);
        $this->post('/login',['email'=>'blocked@example.test','password'=>'wrong-password'])
            ->assertSessionHasErrors(['email'=>'Terlalu banyak percobaan. Coba lagi dalam satu menit.']);
        RateLimiter::clear($key);
    }

    public function test_deactivating_a_user_invalidates_their_database_sessions(): void {
        DB::table('sessions')->insert(['id'=>'admin-session','user_id'=>$this->admin->id,'ip_address'=>'127.0.0.1','user_agent'=>'PHPUnit','payload'=>'','last_activity'=>time()]);
        $this->post(route('users.update',$this->admin),['action'=>'toggle'])->assertRedirect();
        $this->assertDatabaseMissing('sessions',['id'=>'admin-session']);
        $this->assertFalse($this->admin->fresh()->is_active);
    }

    public function test_schema_contains_only_the_mvp_and_required_runtime_tables(): void {
        $expected=[
            'abk_submissions','app_settings','audit_events','customers','daily_records','egg_harvests','employees','feed_types','feed_usages','financial_opening_balances',
            'inventory_adjustments','inventory_opening_balances','lele_growth_points','lele_growth_profiles','lele_sampling_measurements','lele_samplings','livestock_groups','migrations',
            'operational_cashflow_bank_admin_details','operational_cashflow_feed_details','operational_cashflow_health_details','operational_cashflow_livestock_purchase_details','operational_cashflow_other_details','operational_cashflow_payments','operational_cashflow_salary_details',
            'operational_cashflow_topup_details','operational_cashflow_utility_details','operational_cashflows','population_adjustments',
            'revenue_egg_details','revenue_fish_details','revenue_other_details','revenue_payments','revenue_transfer_details','revenues','sessions','users',
        ];
        $actual=collect(DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY table_name"))->pluck('table_name')->all();
        $this->assertSame($expected,$actual);
        $this->assertCount(37,$actual);
    }
}
