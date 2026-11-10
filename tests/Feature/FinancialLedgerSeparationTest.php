<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\{Balances,FinanceService};
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class FinancialLedgerSeparationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $superadmin;
    private FinanceService $finance;
    private Balances $balances;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        User::query()->update(['must_change_password'=>false]);
        $this->admin=User::where('role','ADMIN')->firstOrFail();
        $this->superadmin=User::where('role','SUPERADMIN')->firstOrFail();
        $this->finance=app(FinanceService::class);
        $this->balances=app(Balances::class);
        DB::table('financial_opening_balances')->insert([
            ['account'=>'OPERATIONAL','amount_idr'=>10000000,'effective_date'=>'2026-10-01','created_by'=>$this->superadmin->id,'created_at'=>now(),'updated_at'=>now()],
            ['account'=>'REVENUE','amount_idr'=>0,'effective_date'=>'2026-10-01','created_by'=>$this->superadmin->id,'created_at'=>now(),'updated_at'=>now()],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_acceptance_sequence_keeps_the_ledgers_independent(): void
    {
        $sale=$this->finance->saveRevenue(['revenue_type'=>'OTHER','transaction_date'=>'2026-10-02','amount_idr'=>2750000,'details'=>['notes'=>'Sale']],$this->admin);
        $this->finance->payRevenue($sale,$this->admin,'2026-10-02');
        $this->assertSame('10000000',$this->balances->operationalCash('2026-10-02'));
        $this->assertSame('2750000',$this->balances->revenueCash('2026-10-02'));

        $transfer=$this->finance->saveRevenue(['revenue_type'=>'MAIN_ACCOUNT_TRANSFER','transaction_date'=>'2026-10-03','amount_idr'=>1000000,'details'=>['notes'=>'Setor']],$this->admin);
        $this->finance->submitRevenueTransfer($transfer,$this->admin);
        Carbon::setTestNow(Carbon::parse('2026-10-03 10:30:00','Asia/Jakarta'));
        $this->finance->decideRevenueTransfer($transfer,$this->superadmin,'APPROVED');
        $this->assertSame('PAID',$transfer->fresh()->payment_status);
        $this->assertSame($this->superadmin->id,$transfer->fresh()->paid_by);
        $this->assertSame('10000000',$this->balances->operationalCash('2026-10-03'));
        $this->assertSame('1750000',$this->balances->revenueCash('2026-10-03'));

        $topup=$this->finance->saveOperationalCashflow(['cashflow_type'=>'MAIN_ACCOUNT_TOPUP','transaction_date'=>'2026-10-04','amount_idr'=>500000,'details'=>['notes'=>'Modal']],$this->admin);
        $this->assertSame('NOT_REQUIRED',$topup->approval_status);
        $this->assertSame('PAID',$topup->payment_status);
        $this->assertSame('2026-10-03',$topup->paid_at->toDateString());
        $this->assertSame('10500000',$this->balances->operationalCash('2026-10-04'));
        $this->assertSame('1750000',$this->balances->revenueCash('2026-10-04'));

        Carbon::setTestNow(Carbon::parse('2026-10-14 10:30:00','Asia/Jakarta'));
        $expense=$this->finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-05','amount_idr'=>2000000,'details'=>['description'=>'Operasional']],$this->admin);
        $this->finance->submitOperational($expense,$this->admin);
        $this->finance->decideOperational($expense,$this->superadmin,'APPROVED');
        $this->finance->payOperational($expense,$this->admin,'2026-10-05');
        $this->assertSame('8500000',$this->balances->operationalCash('2026-10-05'));
        $this->assertSame('1750000',$this->balances->revenueCash('2026-10-05'));

        $this->finance->saveRevenue(['revenue_type'=>'OTHER','transaction_date'=>'2026-10-06','amount_idr'=>900000,'details'=>['notes'=>'Unpaid']],$this->admin);
        $this->finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-06','amount_idr'=>900000,'details'=>['description'=>'Unpaid']],$this->admin);
        $this->assertSame('8500000',$this->balances->operationalCash('2026-10-06'));
        $this->assertSame('1750000',$this->balances->revenueCash('2026-10-06'));
    }

    public function test_negative_transfer_requires_acknowledgement_and_records_override(): void
    {
        $sale=$this->finance->saveRevenue(['revenue_type'=>'OTHER','transaction_date'=>'2026-10-02','amount_idr'=>100000,'details'=>['notes'=>null]],$this->admin);
        $this->finance->payRevenue($sale,$this->admin,'2026-10-02');
        $transfer=$this->finance->saveRevenue(['revenue_type'=>'MAIN_ACCOUNT_TRANSFER','transaction_date'=>'2026-10-03','amount_idr'=>150000,'details'=>['notes'=>null]],$this->admin);
        $this->finance->submitRevenueTransfer($transfer,$this->admin);
        Carbon::setTestNow(Carbon::parse('2026-10-03 11:00:00','Asia/Jakarta'));

        try {
            $this->finance->decideRevenueTransfer($transfer,$this->superadmin,'APPROVED');
            $this->fail('A balance warning was expected.');
        } catch (ValidationException $exception) {
            $warning=$exception->errors()['balance_warning'][0];
            $this->assertStringContainsString('50,000',$warning);
            $this->assertStringContainsString('2026-10-03',$warning);
        }

        $this->assertSame('SUBMITTED',$transfer->fresh()->approval_status);
        $this->assertSame('UNPAID',$transfer->fresh()->payment_status);
        $this->finance->decideRevenueTransfer($transfer,$this->superadmin,'APPROVED',null,true);
        $this->assertSame('APPROVED',$transfer->fresh()->approval_status);
        $this->assertSame('PAID',$transfer->fresh()->payment_status);
        $this->assertSame('-50000',$this->balances->revenueCash('2026-10-03'));
        $this->assertDatabaseHas('audit_events',['entity_type'=>'revenues','entity_id'=>$transfer->id,'action'=>'negative_balance_override']);
        $auditCount=DB::table('audit_events')->where('entity_type','revenues')->where('entity_id',$transfer->id)->count();
        $paidAt=$transfer->fresh()->paid_at;
        $this->finance->decideRevenueTransfer($transfer,$this->superadmin,'APPROVED');
        $this->assertSame($auditCount,DB::table('audit_events')->where('entity_type','revenues')->where('entity_id',$transfer->id)->count());
        $this->assertTrue($paidAt->equalTo($transfer->fresh()->paid_at));
    }

    public function test_automatic_types_cannot_use_manual_payment_endpoints(): void
    {
        $topup=$this->finance->saveOperationalCashflow(['cashflow_type'=>'MAIN_ACCOUNT_TOPUP','transaction_date'=>'2026-10-03','amount_idr'=>1000,'details'=>['notes'=>null]],$this->admin);
        try { $this->finance->payOperational($topup,$this->admin,'2026-10-03'); $this->fail('Manual top-up payment should fail.'); }
        catch(ValidationException $exception) { $this->assertArrayHasKey('transaction',$exception->errors()); }

        $transfer=$this->finance->saveRevenue(['revenue_type'=>'MAIN_ACCOUNT_TRANSFER','transaction_date'=>'2026-10-03','amount_idr'=>1000,'details'=>['notes'=>null]],$this->admin);
        try { $this->finance->payRevenue($transfer,$this->admin,'2026-10-03'); $this->fail('Manual transfer payment should fail.'); }
        catch(ValidationException $exception) { $this->assertArrayHasKey('transaction',$exception->errors()); }
    }

    public function test_operational_expense_can_be_paid_once_even_when_cash_becomes_negative(): void
    {
        $expense=$this->finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-03','amount_idr'=>10000001,'details'=>['description'=>'Pengeluaran besar']],$this->admin);
        $this->finance->submitOperational($expense,$this->admin);
        $this->finance->decideOperational($expense,$this->superadmin,'APPROVED');

        $this->actingAs($this->admin)->post(route('operational-cashflow.pay',$expense),['paid_date'=>'2026-10-03'])->assertForbidden();
        $this->actingAs($this->superadmin)->post(route('operational-cashflow.pay',$expense),['paid_date'=>'2026-10-03'])
            ->assertSessionHasNoErrors();

        $this->assertSame('PAID',$expense->fresh()->payment_status);
        $this->assertSame('-1',$this->balances->operationalCash('2026-10-03'));
        $this->assertDatabaseMissing('audit_events',['entity_type'=>'operational_cashflows','entity_id'=>$expense->id,'action'=>'negative_balance_override']);
    }

    public function test_operational_payment_and_correction_may_create_negative_current_and_historical_balances(): void
    {
        $topup=$this->finance->saveOperationalCashflow(['cashflow_type'=>'MAIN_ACCOUNT_TOPUP','transaction_date'=>'2026-10-02','amount_idr'=>1000000,'details'=>['notes'=>'Modal']],$this->admin);
        $expense=$this->finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-03','amount_idr'=>10500000,'details'=>['description'=>'Operasional']],$this->admin);
        $this->finance->submitOperational($expense,$this->admin);
        $this->finance->decideOperational($expense,$this->superadmin,'APPROVED');
        $this->finance->payOperational($expense,$this->admin,'2026-10-03');

        $payload=['cashflow_type'=>'MAIN_ACCOUNT_TOPUP','transaction_date'=>'2026-10-04','amount_idr'=>1000000,'details'=>['notes'=>'Tanggal dikoreksi']];
        $corrected=$this->finance->saveOperationalCashflow($payload,$this->superadmin,$topup,true,'Tanggal bukti salah');
        $this->assertSame('2026-10-04',$corrected->paid_at->toDateString());
        $this->assertSame('-500000',$this->balances->operationalCash('2026-10-03'));
        $this->assertDatabaseHas('audit_events',['entity_type'=>'operational_cashflows','entity_id'=>$topup->id,'action'=>'cashflow_corrected','actor_id'=>$this->superadmin->id]);
        $this->assertDatabaseMissing('audit_events',['entity_type'=>'operational_cashflows','entity_id'=>$topup->id,'action'=>'negative_balance_override']);
    }

    public function test_direction_cannot_be_changed_by_editing_the_type(): void
    {
        $transfer=$this->finance->saveRevenue(['revenue_type'=>'MAIN_ACCOUNT_TRANSFER','transaction_date'=>'2026-10-03','amount_idr'=>1000,'details'=>['notes'=>null]],$this->admin);
        $this->expectException(ValidationException::class);
        $this->finance->saveRevenue(['revenue_type'=>'OTHER','transaction_date'=>'2026-10-03','amount_idr'=>1000,'details'=>['notes'=>null]],$this->admin,$transfer);
    }

    public function test_direct_transfer_requires_superadmin_verification_and_never_changes_cash_on_hand(): void
    {
        $direct=$this->finance->saveRevenue([
            'revenue_type'=>'OTHER','transaction_date'=>'2026-10-04','amount_idr'=>450000,
            'payment_method'=>'DIRECT_TRANSFER','direct_transfer_date'=>'2026-10-03','details'=>['notes'=>'Transfer pelanggan'],
        ],$this->admin);

        $this->assertSame('SUBMITTED',$direct->approval_status);
        $this->assertSame('UNPAID',$direct->payment_status);
        $this->assertSame('0',$this->balances->revenueCash('2026-10-04'));

        try { $this->finance->decideRevenueTransfer($direct,$this->admin,'APPROVED'); $this->fail('Only a Superadmin may approve Direct Transfer.'); }
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(403,$exception->getStatusCode()); }

        try { $this->finance->payRevenue($direct,$this->admin,'2026-10-04'); $this->fail('Direct Transfer must not use the regular Paid action.'); }
        catch(ValidationException $exception) { $this->assertArrayHasKey('transaction',$exception->errors()); }

        Carbon::setTestNow(Carbon::parse('2026-10-05 14:20:00','Asia/Jakarta'));
        $approved=$this->finance->decideRevenueTransfer($direct,$this->superadmin,'APPROVED');
        $this->assertSame('APPROVED',$approved->approval_status);
        $this->assertSame('PAID',$approved->payment_status);
        $this->assertSame('2026-10-03',$approved->paid_at->toDateString());
        $this->assertSame($this->superadmin->id,$approved->approved_by);
        $this->assertSame($this->superadmin->id,$approved->paid_by);
        $this->assertSame('0',$this->balances->revenueCash('2026-10-05'));
        $auditCount=DB::table('audit_events')->where('entity_type','revenues')->where('entity_id',$direct->id)->count();
        $this->finance->decideRevenueTransfer($direct,$this->superadmin,'APPROVED');
        $this->assertSame($auditCount,DB::table('audit_events')->where('entity_type','revenues')->where('entity_id',$direct->id)->count());
    }

    public function test_rejected_direct_transfer_keeps_the_sale_and_edit_resubmits_it(): void
    {
        $direct=$this->finance->saveRevenue([
            'revenue_type'=>'OTHER','transaction_date'=>'2026-10-04','amount_idr'=>125000,
            'payment_method'=>'DIRECT_TRANSFER','direct_transfer_date'=>'2026-10-04','details'=>['notes'=>'Referensi belum jelas'],
        ],$this->admin);
        $this->finance->decideRevenueTransfer($direct,$this->superadmin,'REJECTED','Bukti perlu diperjelas');
        $this->assertDatabaseHas('revenues',['id'=>$direct->id,'amount_idr'=>125000,'approval_status'=>'REJECTED','payment_status'=>'UNPAID']);

        $resubmitted=$this->finance->saveRevenue([
            'revenue_type'=>'OTHER','transaction_date'=>'2026-10-04','amount_idr'=>125000,
            'payment_method'=>'DIRECT_TRANSFER','direct_transfer_date'=>'2026-10-04','details'=>['notes'=>'Referensi diperbarui'],
        ],$this->admin,$direct);
        $this->assertSame('SUBMITTED',$resubmitted->approval_status);
        $this->assertNull($resubmitted->rejection_reason);
        $this->assertSame('125000',(string)$resubmitted->amount_idr);
    }

    public function test_manual_transfer_stores_net_amount_but_reduces_cash_by_gross_amount(): void
    {
        $sale=$this->finance->saveRevenue(['revenue_type'=>'OTHER','transaction_date'=>'2026-10-02','amount_idr'=>1000000,'payment_method'=>'CASH','details'=>['notes'=>'Cash']],$this->admin);
        $this->finance->payRevenue($sale,$this->admin,'2026-10-02');
        $transfer=$this->finance->saveRevenue([
            'revenue_type'=>'MAIN_ACCOUNT_TRANSFER','transaction_date'=>'2026-10-03',
            'details'=>['gross_amount_idr'=>1000000,'payment_cost_idr'=>5000,'notes'=>'Biaya transfer'],
        ],$this->admin);

        $this->assertSame('995000',(string)$transfer->amount_idr);
        $this->assertDatabaseHas('revenue_transfer_details',['revenue_id'=>$transfer->id,'gross_amount_idr'=>1000000,'payment_cost_idr'=>5000]);
        $this->finance->submitRevenueTransfer($transfer,$this->admin);
        Carbon::setTestNow(Carbon::parse('2026-10-03 10:00:00','Asia/Jakarta'));
        $this->finance->decideRevenueTransfer($transfer,$this->superadmin,'APPROVED');
        $this->assertSame('0',$this->balances->revenueCash('2026-10-03'));
        $this->assertDatabaseCount('operational_cashflows',0);

        $this->admin->update(['must_change_password'=>false]);
        $this->actingAs($this->admin)->post(route('revenues.store'),[
            'revenue_type'=>'MAIN_ACCOUNT_TRANSFER','transaction_date'=>'2026-10-04',
            'details'=>['gross_amount_idr'=>1000,'payment_cost_idr'=>1001,'notes'=>null],
        ])->assertSessionHasErrors('details.payment_cost_idr');
    }
}
