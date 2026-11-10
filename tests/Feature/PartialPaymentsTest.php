<?php

namespace Tests\Feature;

use App\Models\{OperationalCashflow,Revenue,User};
use App\Services\{Balances,FinanceChartReport,FinancePayments,FinanceService};
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PartialPaymentsTest extends TestCase
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
        config(['filesystems.disks.gcs.bucket'=>'test-proof-bucket']);
        Storage::fake('gcs');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_revenue_installments_drive_cash_and_voids_recompute_status(): void
    {
        $revenue=$this->finance->saveRevenue(['revenue_type'=>'OTHER','transaction_date'=>'2026-10-03','amount_idr'=>100000,'payment_method'=>'CASH','details'=>['notes'=>'Installments']],$this->admin);
        $this->finance->payRevenue($revenue,$this->admin,'2026-10-05',false,'PARTIAL',40000);
        $revenue->refresh();

        $this->assertSame('PARTIALLY_PAID',$revenue->payment_status);
        $this->assertNull($revenue->paid_at);
        $this->assertSame('40000',app(FinancePayments::class)->revenuePaidAmount($revenue));
        $this->assertSame('60000',app(FinancePayments::class)->revenueRemaining($revenue));
        $this->assertSame('0',app(Balances::class)->revenueCash('2026-10-04'));
        $this->assertSame('40000',app(Balances::class)->revenueCash('2026-10-05'));

        try {
            $this->finance->payRevenue($revenue,$this->admin,'2026-10-06',false,'PARTIAL',60000);
            $this->fail('A partial payment equal to the remainder must use Full mode.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount_idr',$exception->errors());
        }

        $this->finance->payRevenue($revenue,$this->admin,'2026-10-10',false,'FULL');
        $revenue->refresh();
        $this->assertSame('PAID',$revenue->payment_status);
        $this->assertSame('2026-10-10',$revenue->paid_at->toDateString());
        $this->assertSame('100000',app(Balances::class)->revenueCash('2026-10-10'));
        $this->assertSame(['40000','60000'],$revenue->payments()->orderBy('id')->pluck('amount_idr')->map(fn($amount)=>(string)$amount)->all());

        $final=$revenue->payments()->reorder()->latest('id')->firstOrFail();
        $this->finance->voidRevenuePayment($revenue,$final,$this->superadmin,'Tanggal pembayaran salah');
        $revenue->refresh();
        $this->assertSame('PARTIALLY_PAID',$revenue->payment_status);
        $this->assertSame('40000',app(Balances::class)->revenueCash('2026-10-14'));
        $this->assertDatabaseHas('revenue_payments',['id'=>$final->id,'voided_by'=>$this->superadmin->id,'void_reason'=>'Tanggal pembayaran salah']);
    }

    public function test_payment_endpoints_accept_optional_proof_reject_future_dates_and_keep_transaction_proof_separate(): void
    {
        $revenue=$this->finance->saveRevenue(['revenue_type'=>'OTHER','transaction_date'=>'2026-10-20','amount_idr'=>50000,'payment_method'=>'CASH','details'=>['notes'=>'Future transaction']],$this->admin);
        $this->actingAs($this->admin)->post(route('revenues.pay',$revenue),[
            'payment_mode'=>'PARTIAL','paid_date'=>'2026-10-14','amount_idr'=>20000,
            'proof'=>UploadedFile::fake()->image('installment.png',30,30),
        ])->assertSessionHasNoErrors();

        $payment=$revenue->payments()->firstOrFail();
        Storage::disk('gcs')->assertExists($payment->proof_path);
        $this->assertNull($revenue->fresh()->proof_path);
        $this->assertStringContainsString('/proofs/revenue-payments/',$payment->proof_url);
        $this->post(route('revenues.pay',$revenue),['payment_mode'=>'FULL','paid_date'=>'2026-10-15'])->assertSessionHasErrors('paid_date');

        $this->post(route('revenues.store'),[
            'revenue_type'=>'OTHER','transaction_date'=>'2026-10-20','amount_idr'=>1000,'payment_method'=>'CASH',
            'details'=>['notes'=>'Initial paid'],'payment_status'=>'PAID','paid_date'=>'2026-10-15',
        ])->assertSessionHasErrors('paid_date');

        $this->finance->saveOperationalCashflow(['cashflow_type'=>'MAIN_ACCOUNT_TOPUP','transaction_date'=>'2026-10-20','amount_idr'=>25000,'details'=>['notes'=>'Future topup']],$this->admin);
        $topup=OperationalCashflow::latest('id')->firstOrFail();
        $this->assertSame('2026-10-14',$topup->paid_at->toDateString());

        try {
            $this->finance->saveRevenue(['revenue_type'=>'OTHER','transaction_date'=>'2026-10-14','amount_idr'=>1000,'payment_method'=>'DIRECT_TRANSFER','direct_transfer_date'=>'2026-10-15','details'=>['notes'=>'Future transfer']],$this->admin);
            $this->fail('Future direct-transfer dates must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('direct_transfer_date',$exception->errors());
        }
    }

    public function test_operational_installments_and_report_type_filters_use_recognized_payment_dates(): void
    {
        DB::table('financial_opening_balances')->insert(['account'=>'OPERATIONAL','amount_idr'=>500000,'effective_date'=>'2026-10-01','created_by'=>$this->superadmin->id,'created_at'=>now(),'updated_at'=>now()]);
        $expense=$this->finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-04','amount_idr'=>100000,'details'=>['description'=>'Installments']],$this->admin);
        $this->finance->submitOperational($expense,$this->admin);
        $this->finance->decideOperational($expense,$this->superadmin,'APPROVED');
        $this->finance->payOperational($expense,$this->superadmin,'2026-10-06','PARTIAL',25000);
        $this->finance->payOperational($expense,$this->superadmin,'2026-10-12','FULL');

        $revenue=$this->finance->saveRevenue(['revenue_type'=>'OTHER','transaction_date'=>'2026-10-05','amount_idr'=>80000,'payment_method'=>'CASH','details'=>['notes'=>'Chart']],$this->admin);
        $this->finance->payRevenue($revenue,$this->admin,'2026-10-07',false,'PARTIAL',30000);

        $report=app(FinanceChartReport::class)->build(1,'WEEKLY',['OTHER'],[]);
        $this->assertSame(['OTHER'],$report['filters']['revenue_types']);
        $this->assertSame([],$report['filters']['operational_types']);
        $this->assertContains('OTHER',array_column($report['available_types']['operational'],'value'));
        foreach($report['series']['weekly'] as $chart) {
            $this->assertSame(0.0,collect($chart['points'])->sum('operational'));
        }
        $paid=collect($report['series']['weekly'])->firstWhere('key','paid_paid');
        $this->assertSame(30000.0,collect($paid['points'])->sum('revenue'));

        $this->actingAs($this->admin)->get('/reports?months=1&granularity=WEEKLY&revenue_types=&operational_types=OTHER')->assertInertia(fn(Assert $page)=>$page
            ->where('filters.revenue_types',[])
            ->where('filters.operational_types',['OTHER'])
            ->has('series.weekly',4));
        $this->get('/reports?revenue_types=INVALID')->assertSessionHasErrors('revenue_types');
    }
}
