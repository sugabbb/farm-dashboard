<?php
namespace Tests\Feature;

use App\Models\{DailyRecord,OperationalCashflow,FeedType,LivestockGroup,Revenue,User};
use App\Services\FinanceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardUpdatesTest extends TestCase {
    use RefreshDatabase;

    private User $admin;
    private User $superadmin;

    protected function setUp(): void {
        parent::setUp(); $this->seed(DatabaseSeeder::class);
        User::query()->update(['must_change_password'=>false]);
        $this->admin=User::where('role','ADMIN')->firstOrFail();
        $this->superadmin=User::where('role','SUPERADMIN')->firstOrFail();
        $this->actingAs($this->admin);
    }

    private function group(string $species,string $code,string $location='Kandang 1'): LivestockGroup {
        return LivestockGroup::create(['species'=>$species,'code'=>$code,'name'=>'Grup '.$code,'location'=>$location,'start_date'=>'2026-10-01','initial_population'=>100,'status'=>'ACTIVE']);
    }

    private function opening(FeedType $feed,string $quantity='50'): void {
        DB::table('inventory_opening_balances')->insert(['item_kind'=>'FEED','feed_type_id'=>$feed->id,'quantity_kg'=>$quantity,'effective_date'=>'2026-10-01','created_by'=>$this->superadmin->id,'created_at'=>now(),'updated_at'=>now()]);
    }

    private function ayamPayload(LivestockGroup $group,FeedType $feed,string $date='2026-10-02'): array {
        return ['rows'=>[['record_date'=>$date,'group_id'=>$group->id,'mortality'=>1,'notes'=>'Monitoring','feeds'=>[['feed_type_id'=>$feed->id,'morning_kg'=>'1.250','noon_kg'=>'0.750']],'eggs'=>['UTUH'=>['weight_kg'=>'1','quantity_pieces'=>10],'PECAH'=>['weight_kg'=>'0','quantity_pieces'=>0],'REJECT'=>['weight_kg'=>'0','quantity_pieces'=>0]]]]];
    }

    public function test_shift_feed_totals_create_only_uniqueness_and_edit_contract(): void {
        $ayam=$this->group('AYAM','AYAM-A'); $ayamTwo=$this->group('AYAM','AYAM-B','Kandang 2');
        $ayamFeed=FeedType::where('species','AYAM')->firstOrFail(); $this->opening($ayamFeed);
        $this->post(route('ayam.daily.store'),$this->ayamPayload($ayam,$ayamFeed))->assertRedirect()->assertSessionHasNoErrors();
        $record=DailyRecord::where('group_id',$ayam->id)->firstOrFail();
        $this->assertDatabaseHas('feed_usages',['daily_record_id'=>$record->id,'morning_kg'=>'1.250','noon_kg'=>'0.750','evening_kg'=>'0.000','quantity_kg'=>'2.000']);

        $this->post(route('ayam.daily.store'),$this->ayamPayload($ayam,$ayamFeed))->assertSessionHasErrors('rows.0.record_date');
        $this->assertDatabaseCount('daily_records',1);
        $this->post(route('ayam.daily.store'),$this->ayamPayload($ayamTwo,$ayamFeed))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('daily_records',2);

        $duplicate=$this->ayamPayload($ayam,$ayamFeed,'2026-10-03'); $duplicate['rows'][]=$duplicate['rows'][0];
        $this->post(route('ayam.daily.store'),$duplicate)->assertSessionHasErrors('rows.1.record_date');
        $this->assertDatabaseMissing('daily_records',['group_id'=>$ayam->id,'record_date'=>'2026-10-03']);

        $this->patch(route('ayam.daily.update',['record'=>$record]),['mortality'=>2,'notes'=>'Koreksi','feeds'=>[['feed_type_id'=>$ayamFeed->id,'morning_kg'=>'2','noon_kg'=>'1']],'eggs'=>['UTUH'=>['weight_kg'=>'2','quantity_pieces'=>20],'PECAH'=>['weight_kg'=>0,'quantity_pieces'=>0],'REJECT'=>['weight_kg'=>0,'quantity_pieces'=>0]]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('feed_usages',['daily_record_id'=>$record->id,'quantity_kg'=>'3.000']);
        $this->assertSame('2026-10-02',$record->fresh()->record_date->toDateString());
        $this->patch(route('ayam.daily.update',['record'=>$record]),['mortality'=>2,'notes'=>'Duplikat','feeds'=>[['feed_type_id'=>$ayamFeed->id,'morning_kg'=>'2','noon_kg'=>'1'],['feed_type_id'=>$ayamFeed->id,'morning_kg'=>'1','noon_kg'=>'1']],'eggs'=>['UTUH'=>['weight_kg'=>'2','quantity_pieces'=>20],'PECAH'=>['weight_kg'=>0,'quantity_pieces'=>0],'REJECT'=>['weight_kg'=>0,'quantity_pieces'=>0]]])->assertSessionHasErrors('feeds.1.feed_type_id');
        $this->assertDatabaseHas('feed_usages',['daily_record_id'=>$record->id,'quantity_kg'=>'3.000']);
        $this->patch(route('ayam.daily.update',['record'=>$record]),['mortality'=>2,'notes'=>'Stok boleh minus','feeds'=>[['feed_type_id'=>$ayamFeed->id,'morning_kg'=>'40','noon_kg'=>'20']],'eggs'=>['UTUH'=>['weight_kg'=>'2','quantity_pieces'=>20],'PECAH'=>['weight_kg'=>0,'quantity_pieces'=>0],'REJECT'=>['weight_kg'=>0,'quantity_pieces'=>0]]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('feed_usages',['daily_record_id'=>$record->id,'quantity_kg'=>'60.000']);
        $this->assertSame('-12.000',app(\App\Services\Balances::class)->feed($ayamFeed->id,'2026-10-02'));

        $lele=$this->group('LELE','LELE-A','Kolam 1'); $leleFeed=FeedType::where('species','LELE')->firstOrFail(); $this->opening($leleFeed);
        $this->post(route('lele.daily.store'),['rows'=>[['record_date'=>'2026-10-02','group_id'=>$lele->id,'mortality'=>0,'feeds'=>[['feed_type_id'=>$leleFeed->id,'morning_kg'=>1,'noon_kg'=>2,'evening_kg'=>3]]]]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('feed_usages',['feed_type_id'=>$leleFeed->id,'morning_kg'=>'1.000','noon_kg'=>'2.000','evening_kg'=>'3.000','quantity_kg'=>'6.000']);
    }

    public function test_new_daily_reports_require_at_least_one_selected_feed(): void {
        $group=$this->group('AYAM','AYAM-REQUIRED');
        $feed=FeedType::where('species','AYAM')->firstOrFail();
        $missing=$this->ayamPayload($group,$feed); unset($missing['rows'][0]['feeds']);
        $this->post(route('ayam.daily.store'),$missing)->assertSessionHasErrors('rows.0.feeds');
        $empty=$this->ayamPayload($group,$feed); $empty['rows'][0]['feeds']=[];
        $this->post(route('ayam.daily.store'),$empty)->assertSessionHasErrors('rows.0.feeds');
        $this->assertDatabaseCount('daily_records',0);
    }

    public function test_group_code_generation_collision_customization_and_immutability(): void {
        $payload=['name'=>'Layer Utama','location'=>'Kandang Barat','start_date'=>'2026-10-01','initial_population'=>100];
        $this->post(route('ayam.groups.store'),$payload)->assertSessionHasNoErrors();
        $this->post(route('ayam.groups.store'),$payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('livestock_groups',['code'=>'LAYER-UTAMA-KANDANG-BARAT']);
        $this->assertDatabaseHas('livestock_groups',['code'=>'LAYER-UTAMA-KANDANG-BARAT-2']);
        $this->post(route('ayam.groups.store'),$payload+['code'=>'  batch khusus  '])->assertSessionHasNoErrors();
        $custom=LivestockGroup::where('code','BATCH-KHUSUS')->firstOrFail();
        $this->patch(route('ayam.groups.update',['group'=>$custom]),$payload+['name'=>'Nama Baru','code'=>'KODE-BARU'])->assertSessionHasErrors('code');
        $this->assertSame('BATCH-KHUSUS',$custom->fresh()->code);
    }

    public function test_feed_expense_amounts_are_calculated_and_rounded_server_side(): void {
        $feed=FeedType::where('species','AYAM')->firstOrFail();
        $this->post(route('operational-cashflow.store'),['cashflow_type'=>'FEED_AYAM','transaction_date'=>'2026-10-03','details'=>[['feed_type_id'=>$feed->id,'quantity_kg'=>'2.500','price_per_kg_idr'=>'10000.200']],'save_action'=>'draft'])->assertSessionHasNoErrors();
        $expense=OperationalCashflow::latest('id')->firstOrFail(); $line=$expense->feedDetails()->firstOrFail();
        $this->assertSame('25001',$expense->amount_idr); $this->assertSame('10000.200',$line->price_per_kg_idr); $this->assertSame('25001',$line->line_amount_idr);

        $this->post(route('operational-cashflow.store'),['cashflow_type'=>'FEED_AYAM','transaction_date'=>'2026-10-04','details'=>[['feed_type_id'=>$feed->id,'quantity_kg'=>1,'price_per_kg_idr'=>10000,'line_amount_idr'=>1]],'save_action'=>'draft'])->assertSessionHasErrors('details.0.line_amount_idr');
        $this->assertDatabaseCount('operational_cashflows',1);
    }

    public function test_optional_proofs_upload_replace_cleanup_and_audit(): void {
        Storage::fake('gcs'); config(['filesystems.disks.gcs.bucket'=>'farm-proof-test','filesystems.disks.gcs.path_prefix'=>'tenant-a']);
        $this->post(route('operational-cashflow.store'),['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-03','amount_idr'=>1000,'details'=>['description'=>'Peralatan'],'proof'=>UploadedFile::fake()->image('expense.jpg',20,20),'save_action'=>'draft'])->assertSessionHasNoErrors();
        $expense=OperationalCashflow::latest('id')->firstOrFail(); $oldExpensePath=$expense->proof_path;
        $this->assertStringStartsWith('https://storage.googleapis.com/farm-proof-test/tenant-a/proofs/operational-cashflow/',$expense->proof_url);
        Storage::disk('gcs')->assertExists($oldExpensePath);

        $this->patch(route('operational-cashflow.update',$expense),['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-03','amount_idr'=>1200,'details'=>['description'=>'Peralatan revisi'],'proof'=>UploadedFile::fake()->image('replacement.png',20,20)])->assertSessionHasNoErrors();
        $expense->refresh(); Storage::disk('gcs')->assertMissing($oldExpensePath); Storage::disk('gcs')->assertExists($expense->proof_path);
        $this->get(route('operational-cashflow.index'))->assertInertia(fn(Assert $page)=>$page
            ->component('OperationalCashflow')->where('cashflows.data.0.proof_url',$expense->proof_url));

        $this->post(route('revenues.store'),['revenue_type'=>'OTHER','transaction_date'=>'2026-10-04','amount_idr'=>5000,'payment_method'=>'CASH','details'=>['notes'=>'Lainnya'],'proof'=>UploadedFile::fake()->image('revenue.jpg',20,20),'payment_status'=>'UNPAID'])->assertSessionHasNoErrors();
        $revenue=Revenue::latest('id')->firstOrFail(); $oldRevenuePath=$revenue->proof_path;
        app(FinanceService::class)->payRevenue($revenue,$this->admin,'2026-10-04');

        $this->patch(route('revenues.update',$revenue),['revenue_type'=>'OTHER','transaction_date'=>'2026-10-04','amount_idr'=>6000,'payment_method'=>'CASH','details'=>['notes'=>'Tidak boleh'],'proof'=>UploadedFile::fake()->image('blocked.png',20,20)])->assertSessionHasErrors('transaction');
        Storage::disk('gcs')->assertExists($oldRevenuePath); $this->assertCount(2,Storage::disk('gcs')->allFiles());

        $this->actingAs($this->superadmin)->patch(route('revenues.correct',$revenue),['revenue_type'=>'OTHER','transaction_date'=>'2026-10-04','amount_idr'=>6000,'payment_method'=>'CASH','details'=>['notes'=>'Koreksi'],'proof'=>UploadedFile::fake()->image('corrected.png',20,20),'correction_reason'=>'Bukti salah'])->assertSessionHasNoErrors();
        $revenue->refresh(); Storage::disk('gcs')->assertMissing($oldRevenuePath); Storage::disk('gcs')->assertExists($revenue->proof_path);
        $audit=DB::table('audit_events')->where('entity_type','revenues')->where('entity_id',$revenue->id)->where('action','revenue_corrected')->latest('id')->first();
        $this->assertSame($oldRevenuePath,json_decode($audit->before,true)['proof_path']);
        $this->assertSame($revenue->proof_path,json_decode($audit->after,true)['proof_path']);

        $this->post(route('revenues.store'),['revenue_type'=>'OTHER','transaction_date'=>'2026-10-05','amount_idr'=>1000,'payment_method'=>'CASH','details'=>['notes'=>'Invalid'],'proof'=>UploadedFile::fake()->create('proof.pdf',10,'application/pdf'),'payment_status'=>'UNPAID'])->assertSessionHasErrors('proof');
        $this->post(route('operational-cashflow.store'),['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-05','amount_idr'=>1000,'details'=>['description'=>'Terlalu besar'],'proof'=>UploadedFile::fake()->image('large.jpg',20,20)->size(6000),'save_action'=>'draft'])->assertSessionHasErrors('proof');
    }

    public function test_paid_operational_cashflow_can_be_corrected_and_audited_by_superadmin(): void {
        DB::table('financial_opening_balances')->insert(['account'=>'OPERATIONAL','amount_idr'=>100000,'effective_date'=>'2026-10-01','created_by'=>$this->superadmin->id,'created_at'=>now(),'updated_at'=>now()]);
        $finance=app(FinanceService::class);
        $cashflow=$finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-03','amount_idr'=>1000,'details'=>['description'=>'Operasional']],$this->admin);
        $finance->submitOperational($cashflow,$this->admin);
        $finance->decideOperational($cashflow,$this->superadmin,'APPROVED');
        $finance->payOperational($cashflow,$this->admin,'2026-10-03');

        $this->actingAs($this->superadmin)->patch(route('operational-cashflow.correct',['cashflow'=>$cashflow]),[
            'cashflow_type'=>'OTHER','transaction_date'=>'2026-10-03','amount_idr'=>1200,
            'details'=>['description'=>'Operasional terkoreksi'],'correction_reason'=>'Nominal bukti diperbarui',
        ])->assertRedirect(route('operational-cashflow.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('operational_cashflows',['id'=>$cashflow->id,'amount_idr'=>1200,'payment_status'=>'PARTIALLY_PAID','approval_status'=>'DRAFT']);
        $this->assertDatabaseHas('audit_events',['entity_type'=>'operational_cashflows','entity_id'=>$cashflow->id,'action'=>'cashflow_paid_increase_reset','actor_id'=>$this->superadmin->id]);
    }

    public function test_automatic_payments_and_audit_timelines_are_exposed_on_finance_pages(): void {
        DB::table('financial_opening_balances')->insert(['account'=>'REVENUE','amount_idr'=>100000,'effective_date'=>'2026-10-01','created_by'=>$this->superadmin->id,'created_at'=>now(),'updated_at'=>now()]);
        $this->post(route('operational-cashflow.store'),[
            'cashflow_type'=>'MAIN_ACCOUNT_TOPUP','transaction_date'=>'2026-10-03','amount_idr'=>50000,'details'=>['notes'=>'Modal'],
        ])->assertRedirect(route('operational-cashflow.index'))->assertSessionHasNoErrors();
        $topup=OperationalCashflow::latest('id')->firstOrFail();
        $this->assertSame('PAID',$topup->payment_status);
        $this->assertSame($this->admin->id,$topup->paid_by);
        $this->assertSame('2026-10-03',$topup->paid_at->toDateString());
        $this->get(route('operational-cashflow.index'))->assertInertia(fn(Assert $page)=>$page
            ->where('cashflows.data.0.id',$topup->id)
            ->where('cashflows.data.0.audit_logs',fn($logs)=>collect($logs)->contains('action','cashflow_created') && collect($logs)->contains('action','cashflow_paid')));

        $finance=app(FinanceService::class);
        $transfer=$finance->saveRevenue(['revenue_type'=>'MAIN_ACCOUNT_TRANSFER','transaction_date'=>'2026-10-02','amount_idr'=>25000,'details'=>['notes'=>'Setoran']],$this->admin);
        $finance->submitRevenueTransfer($transfer,$this->admin);
        $this->actingAs($this->superadmin)->post(route('revenues.decision',$transfer),['decision'=>'APPROVED'])->assertSessionHasNoErrors();
        $transfer->refresh();
        $this->assertSame('PAID',$transfer->payment_status);
        $this->assertSame($this->superadmin->id,$transfer->paid_by);
        $this->assertTrue($transfer->approved_at->equalTo($transfer->paid_at));
        $this->get(route('revenues.index'))->assertInertia(fn(Assert $page)=>$page
            ->where('revenues.data.0.id',$transfer->id)
            ->where('revenues.data.0.audit_logs',fn($logs)=>collect($logs)->contains('action','transfer_approved') && collect($logs)->contains('action','revenue_paid')));
    }

    public function test_paid_feed_cashflow_correction_accepts_only_editable_line_fields(): void {
        DB::table('financial_opening_balances')->insert(['account'=>'OPERATIONAL','amount_idr'=>1000000,'effective_date'=>'2026-10-01','created_by'=>$this->superadmin->id,'created_at'=>now(),'updated_at'=>now()]);
        $feed=FeedType::where('species','AYAM')->firstOrFail();
        $finance=app(FinanceService::class);
        $cashflow=$finance->saveOperationalCashflow(['cashflow_type'=>'FEED_AYAM','transaction_date'=>'2026-10-03','details'=>[['feed_type_id'=>$feed->id,'quantity_kg'=>'10','price_per_kg_idr'=>'10000']]],$this->admin);
        $finance->submitOperational($cashflow,$this->admin);
        $finance->decideOperational($cashflow,$this->superadmin,'APPROVED');
        $line=$cashflow->fresh()->feedDetails()->firstOrFail();
        $finance->receive($line,$this->admin,'2026-10-03');
        $finance->payOperational($cashflow,$this->admin,'2026-10-03');

        $this->actingAs($this->superadmin)->patch(route('operational-cashflow.correct',['cashflow'=>$cashflow]),[
            'cashflow_type'=>'FEED_AYAM','transaction_date'=>'2026-10-03',
            'details'=>[['id'=>$line->id,'feed_type_id'=>$feed->id,'quantity_kg'=>'11','price_per_kg_idr'=>'10000']],
            'correction_reason'=>'Kuantitas pada bukti diperbarui',
        ])->assertSessionHasNoErrors();

        $corrected=$cashflow->fresh();
        $this->assertSame('110000',$corrected->amount_idr);
        $this->assertSame('DRAFT',$corrected->approval_status);
        $this->assertSame('PARTIALLY_PAID',$corrected->payment_status);
        $this->assertNotNull($corrected->feedDetails()->firstOrFail()->received_at);
        $this->assertDatabaseHas('audit_events',['entity_type'=>'operational_cashflows','entity_id'=>$cashflow->id,'action'=>'cashflow_paid_increase_reset']);
        $this->get(route('operational-cashflow.index'))->assertInertia(fn(Assert $page)=>$page
            ->where('cashflows.data.0.audit_logs',fn($logs)=>collect($logs)->contains('action','feed_received') && collect($logs)->contains('action','cashflow_paid_increase_reset')));
    }
}
