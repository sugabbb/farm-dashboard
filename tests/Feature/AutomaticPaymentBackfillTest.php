<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FinanceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AutomaticPaymentBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_pays_eligible_rows_and_audits_historical_deficit(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin=User::where('role','ADMIN')->firstOrFail();
        $superadmin=User::where('role','SUPERADMIN')->firstOrFail();
        $finance=app(FinanceService::class);

        $topup=$finance->saveOperationalCashflow(['cashflow_type'=>'MAIN_ACCOUNT_TOPUP','transaction_date'=>'2026-10-03','amount_idr'=>50000,'details'=>['notes'=>'Legacy']],$admin);
        DB::table('operational_cashflows')->where('id',$topup->id)->update(['payment_status'=>'UNPAID','paid_at'=>null,'paid_by'=>null]);
        $transfer=$finance->saveRevenue(['revenue_type'=>'MAIN_ACCOUNT_TRANSFER','transaction_date'=>'2026-10-02','amount_idr'=>75000,'details'=>['notes'=>'Legacy']],$admin);
        $finance->submitRevenueTransfer($transfer,$admin);
        DB::table('revenues')->where('id',$transfer->id)->update(['approval_status'=>'APPROVED','approved_at'=>'2026-10-04 10:00:00+07','approved_by'=>$superadmin->id]);

        $migration=require database_path('migrations/2026_10_13_000004_backfill_automatic_finance_payments.php');
        $migration->up();

        $this->assertDatabaseHas('operational_cashflows',['id'=>$topup->id,'payment_status'=>'PAID','paid_by'=>$admin->id]);
        $this->assertDatabaseHas('revenues',['id'=>$transfer->id,'payment_status'=>'PAID','paid_by'=>$superadmin->id]);
        $this->assertSame('2026-10-03',substr((string)DB::table('operational_cashflows')->where('id',$topup->id)->value('paid_at'),0,10));
        $this->assertSame('2026-10-04',substr((string)DB::table('revenues')->where('id',$transfer->id)->value('paid_at'),0,10));
        $this->assertSame(2,DB::table('audit_events')->where('action','automatic_payment_backfilled')->count());
        $override=DB::table('audit_events')->where('entity_type','revenues')->where('entity_id',$transfer->id)->where('action','negative_balance_override')->first();
        $this->assertNotNull($override);
        $payload=json_decode($override->after,true);
        $this->assertSame('2026-10-04',$payload['earliest_negative_date']);
        $this->assertSame('-75000',$payload['projected_balance']);
        $this->assertSame([$transfer->id],$payload['affected_transfer_ids']);
    }
}
