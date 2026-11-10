<?php
namespace Tests\Feature;

use App\Models\{Customer,FeedType,LivestockGroup,User};
use App\Services\{FarmOperations,FinanceMetrics,FinanceService};
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerBiomassMetricsTest extends TestCase {
    use RefreshDatabase;
    private User $admin; private User $superadmin;

    protected function setUp(): void {
        parent::setUp(); Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00','Asia/Jakarta')); $this->seed(DatabaseSeeder::class);
        User::query()->update(['must_change_password'=>false]); $this->admin=User::where('role','ADMIN')->firstOrFail(); $this->superadmin=User::where('role','SUPERADMIN')->firstOrFail(); $this->actingAs($this->admin);
    }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_customer_crud_inline_creation_mapping_and_filtering(): void {
        $this->post('/customers',['name'=>'Warung Sari','phone'=>'0812345','customer_type'=>'WARUNG','notes'=>'Pelanggan pagi'])->assertSessionHasNoErrors();
        $customer=Customer::firstOrFail(); $this->patch('/customers/'.$customer->id,['name'=>'Warung Sari Baru','phone'=>'0812345','customer_type'=>'AGEN','notes'=>null])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customers',['id'=>$customer->id,'name'=>'Warung Sari Baru','customer_type'=>'AGEN']);

        DB::table('inventory_opening_balances')->insert(['item_kind'=>'EGG','egg_category'=>'UTUH','quantity_kg'=>10,'quantity_pieces'=>100,'effective_date'=>'2026-10-01','created_by'=>$this->superadmin->id,'created_at'=>now(),'updated_at'=>now()]);
        $sale=['revenue_type'=>'EGG_SALE','transaction_date'=>'2026-10-10','payment_method'=>'CASH','details'=>[['egg_category'=>'UTUH','weight_kg'=>1,'quantity_pieces'=>10,'price_per_kg_idr'=>100000]],'payment_status'=>'UNPAID'];
        $this->post('/revenues',$sale)->assertSessionHasErrors('customer_id');
        $this->post('/revenues',$sale+['new_customer'=>['name'=>'Agen Makmur','phone'=>'082222','customer_type'=>'AGEN','notes'=>'Antar sore']])->assertSessionHasNoErrors();
        $inline=Customer::where('name','Agen Makmur')->firstOrFail();
        $this->assertDatabaseHas('revenues',['customer_id'=>$inline->id,'revenue_type'=>'EGG_SALE']);
        $this->get('/revenues?customer_id='.$inline->id)->assertInertia(fn(Assert $page)=>$page->component('Revenues')->has('revenues.data',1)->where('revenues.data.0.customer.name','Agen Makmur'));
        $invalid=$sale; $invalid['details'][0]['id']=999999; $invalid['new_customer']=['name'=>'Rollback Customer','phone'=>'083333','customer_type'=>'WARUNG'];
        $this->post('/revenues',$invalid)->assertSessionHasErrors('details.0.id');
        $this->assertDatabaseMissing('customers',['name'=>'Rollback Customer']);
        $this->post('/revenues',['revenue_type'=>'OTHER','transaction_date'=>'2026-10-10','amount_idr'=>1000,'payment_method'=>'CASH','details'=>['notes'=>'Lain'],'customer_id'=>$inline->id,'payment_status'=>'UNPAID'])->assertSessionHasErrors('customer_id');
    }

    public function test_operating_summaries_exclude_topups_transfers_and_unapproved_expenses(): void {
        $finance=app(FinanceService::class);
        $finance->saveRevenue(['revenue_type'=>'OTHER','transaction_date'=>'2026-10-05','amount_idr'=>200000,'details'=>['notes'=>null]],$this->admin);
        $finance->saveRevenue(['revenue_type'=>'OTHER','transaction_date'=>'2026-09-15','amount_idr'=>100000,'details'=>['notes'=>null]],$this->admin);
        $finance->saveOperationalCashflow(['cashflow_type'=>'MAIN_ACCOUNT_TOPUP','transaction_date'=>'2026-10-05','amount_idr'=>900000,'details'=>['notes'=>null]],$this->admin);
        $submitted=$finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-05','amount_idr'=>50000,'details'=>['description'=>'Submitted']],$this->admin); $finance->submitOperational($submitted,$this->admin);
        $approved=$finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-06','amount_idr'=>80000,'details'=>['description'=>'Approved']],$this->admin); $finance->submitOperational($approved,$this->admin); $finance->decideOperational($approved,$this->superadmin,'APPROVED');
        $transfer=$finance->saveRevenue(['revenue_type'=>'MAIN_ACCOUNT_TRANSFER','transaction_date'=>'2026-10-06','amount_idr'=>700000,'details'=>['notes'=>null]],$this->admin); $finance->submitRevenueTransfer($transfer,$this->admin);
        $metrics=app(FinanceMetrics::class);
        $this->assertSame('200000',$metrics->revenueSummary()['current_month']); $this->assertSame('100000',$metrics->revenueSummary()['previous_month']);
        $this->assertSame('100.00',$metrics->revenueSummary()['change_percent']);
        $this->assertSame('50000',$metrics->expenseSummary()['waiting_approval']); $this->assertSame('80000',$metrics->expenseSummary()['approved_unpaid']); $this->assertSame('80000',$metrics->expenseSummary()['total']);
    }

    public function test_egg_totals_and_current_month_utuh_weighted_average_are_server_calculated(): void {
        $finance=app(FinanceService::class);
        $first=$finance->saveRevenue(['revenue_type'=>'EGG_SALE','transaction_date'=>'2026-10-05','details'=>[
            ['egg_category'=>'UTUH','weight_kg'=>'2.5','quantity_pieces'=>40,'price_per_kg_idr'=>'10000.2'],
            ['egg_category'=>'PECAH','weight_kg'=>'10','quantity_pieces'=>160,'price_per_kg_idr'=>'1000'],
        ]],$this->admin);
        $finance->saveRevenue(['revenue_type'=>'EGG_SALE','transaction_date'=>'2026-10-06','details'=>[
            ['egg_category'=>'UTUH','weight_kg'=>'3','quantity_pieces'=>50,'price_per_kg_idr'=>'30000'],
        ]],$this->admin);
        $finance->saveRevenue(['revenue_type'=>'EGG_SALE','transaction_date'=>'2026-09-30','details'=>[
            ['egg_category'=>'UTUH','weight_kg'=>'10','quantity_pieces'=>170,'price_per_kg_idr'=>'100000'],
        ]],$this->admin);

        $this->assertSame('35001',$first->amount_idr);
        $this->assertSame('25001',$first->eggDetails()->where('egg_category','UTUH')->firstOrFail()->line_amount_idr);
        $this->assertSame('20909',app(FinanceMetrics::class)->revenueSummary()['egg_avg_price_current_month']);

        $customer=Customer::create(['name'=>'Tamper Test','phone'=>'0800','customer_type'=>'AGEN']);
        $this->post('/revenues',['revenue_type'=>'EGG_SALE','transaction_date'=>'2026-10-07','payment_method'=>'CASH','customer_id'=>$customer->id,'details'=>[[
            'egg_category'=>'UTUH','weight_kg'=>1,'quantity_pieces'=>10,'price_per_kg_idr'=>20000,'line_amount_idr'=>1,
        ]]])->assertSessionHasErrors('details.0.line_amount_idr');
    }

    public function test_sampling_calculation_validation_audit_and_dashboard_current_biomass(): void {
        $group=LivestockGroup::create(['species'=>'LELE','code'=>'LELE-1','name'=>'Lele Satu','location'=>'Kolam 1','start_date'=>'2026-10-01','initial_population'=>1000,'status'=>'ACTIVE']);
        $payload=['group_id'=>$group->id,'sampling_date'=>'2026-10-10','notes'=>'Sampling awal','measurements'=>[
            ['sequence'=>1,'total_weight_kg'=>'1.20'],['sequence'=>2,'total_weight_kg'=>'1.35'],['sequence'=>3,'total_weight_kg'=>'1.25'],
        ]];
        $this->post('/lele/biomass',$payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('lele_samplings',1); $this->assertDatabaseCount('lele_sampling_measurements',3);
        $this->assertDatabaseHas('audit_events',['entity_type'=>'lele_samplings','action'=>'lele_sampling_created']);
        $this->get('/lele/biomass')->assertInertia(fn(Assert $page)=>$page->component('LeleBiomass')->where('samplings.data.0.total_weight_kg','3.800')->where('samplings.data.0.population_at_sampling',1000)->where('samplings.data.0.average_weight_g',fn($value)=>abs((float)$value-126.667)<0.01)->where('samplings.data.0.estimated_biomass_kg',fn($value)=>abs((float)$value-126.667)<0.01));
        $dailyFeed=FeedType::where('species','LELE')->firstOrFail();
        app(FarmOperations::class)->saveDaily('LELE',[['group_id'=>$group->id,'record_date'=>'2026-10-11','mortality'=>100,'feeds'=>[['feed_type_id'=>$dailyFeed->id,'morning_kg'=>0,'noon_kg'=>0,'evening_kg'=>0]]]],$this->admin);
        $this->get('/lele/biomass')->assertInertia(fn(Assert $page)=>$page->component('LeleBiomass')->where('summaries.0.population',900)->where('summaries.0.current_target_kg',fn($value)=>abs((float)$value-128.571)<0.02)->where('summaries.0.cycle_target_kg',fn($value)=>abs((float)$value-121.429)<0.02));
        $this->get('/dashboard')->assertInertia(fn(Assert $page)=>$page->component('Dashboard')->where('biomass.0.group_code','LELE-1')->where('biomass.0.population',900)->where('biomass.0.estimated_biomass_kg',fn($value)=>abs((float)$value-114)<0.01));
        $small=LivestockGroup::create(['species'=>'LELE','code'=>'LELE-SMALL','name'=>'Lele Kecil','location'=>'Kolam 2','start_date'=>'2026-10-01','initial_population'=>29,'status'=>'ACTIVE']);
        $this->post('/lele/biomass',array_merge($payload,['group_id'=>$small->id,'sampling_date'=>'2026-10-09']))->assertSessionHasErrors('group_id');
        $this->assertDatabaseCount('lele_samplings',1);
    }

    public function test_dashboard_uses_event_dates_and_operational_cards_stay_current(): void {
        DB::table('financial_opening_balances')->insert(['amount_idr'=>1000000,'effective_date'=>'2026-09-01','created_by'=>$this->superadmin->id,'created_at'=>now(),'updated_at'=>now()]);
        $finance=app(FinanceService::class); $revenue=$finance->saveRevenue(['revenue_type'=>'OTHER','transaction_date'=>'2026-09-29','amount_idr'=>300000,'details'=>['notes'=>null]],$this->admin); $finance->payRevenue($revenue,$this->admin,'2026-10-03');
        $expense=$finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-09-28','amount_idr'=>100000,'details'=>['description'=>'Biaya']],$this->admin); $finance->submitOperational($expense,$this->admin); $finance->decideOperational($expense,$this->superadmin,'APPROVED'); $finance->payOperational($expense,$this->admin,'2026-10-04');
        $this->get('/dashboard')->assertInertia(fn(Assert $page)=>$page->component('Dashboard')->where('performance.period_revenue','0')->where('performance.period_expenses','0')->where('current.operational_cash','900000')->where('current.revenue_cash','300000')->where('summaryRange.from','2026-10-01')->where('summaryRange.to','2026-10-12'));
        $this->get('/dashboard?summary_from=2026-09-01&summary_to=2026-09-30')->assertInertia(fn(Assert $page)=>$page->component('Dashboard')->where('summaryRange.from','2026-09-01')->where('summaryRange.to','2026-09-30')->where('performance.period_expenses','100000')->where('performance.period_revenue','300000')->where('current.operational_cash','900000')->where('current.revenue_cash','300000'));
        $this->get('/dashboard?summary_from=2026-09-01&summary_to=2026-09-30&harvest_from=2026-10-10&harvest_to=2026-10-12&finance_from=2026-09-15&finance_to=2026-10-12')->assertInertia(fn(Assert $page)=>$page
            ->component('Dashboard')->where('summaryRange.from','2026-09-01')->where('harvestRange.from','2026-10-10')->where('financeRange.from','2026-09-15')
            ->has('harvestSeries',3)->where('harvestSeries.0.key','2026-10-10')->where('harvestSeries.0.total',0)
            ->has('financeSeries',2)->where('financeSeries.0.key','2026-09')->where('financeSeries.1.key','2026-10'));
        $this->get('/dashboard?summary_from=2026-10-01')->assertSessionHasErrors('summary_to');
        $this->get('/dashboard?harvest_from=2026-10-15&harvest_to=2026-10-15')->assertSessionHasErrors(['harvest_from','harvest_to']);
    }
}
