<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::create('operational_cashflow_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('operational_cashflow_id')->constrained('operational_cashflows')->cascadeOnDelete();
                $table->date('paid_date')->index();
                $table->decimal('amount_idr', 18, 0);
                $table->string('payment_mode');
                $table->text('proof_path')->nullable();
                $table->text('proof_url')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestampTz('voided_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->text('void_reason')->nullable();
                $table->timestampsTz();
                $table->index(['operational_cashflow_id', 'voided_at']);
            });
            Schema::create('revenue_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('revenue_id')->constrained('revenues')->cascadeOnDelete();
                $table->date('paid_date')->index();
                $table->decimal('amount_idr', 18, 0);
                $table->string('payment_mode');
                $table->text('proof_path')->nullable();
                $table->text('proof_url')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestampTz('voided_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->text('void_reason')->nullable();
                $table->timestampsTz();
                $table->index(['revenue_id', 'voided_at']);
            });

            foreach (DB::table('operational_cashflows')->where('direction', 'OUTFLOW')->where('payment_status', 'PAID')->orderBy('id')->get() as $row) {
                DB::table('operational_cashflow_payments')->insert([
                    'operational_cashflow_id'=>$row->id, 'paid_date'=>substr((string)$row->paid_at, 0, 10),
                    'amount_idr'=>$row->amount_idr, 'payment_mode'=>'FULL', 'created_by'=>$row->paid_by,
                    'created_at'=>$row->paid_at, 'updated_at'=>$row->paid_at,
                ]);
            }
            foreach (DB::table('revenues')->where('direction', 'INFLOW')->where('payment_method', 'CASH')->where('payment_status', 'PAID')->orderBy('id')->get() as $row) {
                DB::table('revenue_payments')->insert([
                    'revenue_id'=>$row->id, 'paid_date'=>substr((string)$row->paid_at, 0, 10),
                    'amount_idr'=>$row->amount_idr, 'payment_mode'=>'FULL', 'created_by'=>$row->paid_by,
                    'created_at'=>$row->paid_at, 'updated_at'=>$row->paid_at,
                ]);
            }

            foreach ([
                'ALTER TABLE operational_cashflows DROP CONSTRAINT IF EXISTS op_cashflow_payment_check',
                'ALTER TABLE operational_cashflows DROP CONSTRAINT IF EXISTS op_cashflow_paid_shape_check',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_payment_check',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_paid_shape_check',
                "ALTER TABLE operational_cashflows ADD CONSTRAINT op_cashflow_payment_check CHECK (payment_status IN ('UNPAID','PARTIALLY_PAID','PAID'))",
                "ALTER TABLE operational_cashflows ADD CONSTRAINT op_cashflow_paid_shape_check CHECK ((payment_status IN ('UNPAID','PARTIALLY_PAID') AND paid_at IS NULL AND paid_by IS NULL) OR (payment_status='PAID' AND paid_at IS NOT NULL AND paid_by IS NOT NULL AND (direction='INFLOW' OR approval_status='APPROVED'))) ",
                "ALTER TABLE revenues ADD CONSTRAINT revenues_payment_check CHECK (payment_status IN ('UNPAID','PARTIALLY_PAID','PAID'))",
                "ALTER TABLE revenues ADD CONSTRAINT revenues_paid_shape_check CHECK ((payment_status IN ('UNPAID','PARTIALLY_PAID') AND paid_at IS NULL AND paid_by IS NULL) OR (payment_status='PAID' AND paid_at IS NOT NULL AND paid_by IS NOT NULL AND (direction='INFLOW' OR approval_status='APPROVED'))) ",
                "ALTER TABLE operational_cashflow_payments ADD CONSTRAINT op_payment_mode_check CHECK (payment_mode IN ('PARTIAL','FULL'))",
                'ALTER TABLE operational_cashflow_payments ADD CONSTRAINT op_payment_amount_check CHECK (amount_idr >= 0)',
                "ALTER TABLE operational_cashflow_payments ADD CONSTRAINT op_payment_void_check CHECK ((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL))",
                "ALTER TABLE revenue_payments ADD CONSTRAINT revenue_payment_mode_check CHECK (payment_mode IN ('PARTIAL','FULL'))",
                'ALTER TABLE revenue_payments ADD CONSTRAINT revenue_payment_amount_check CHECK (amount_idr >= 0)',
                "ALTER TABLE revenue_payments ADD CONSTRAINT revenue_payment_void_check CHECK ((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL))",
            ] as $sql) DB::statement($sql);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (DB::table('operational_cashflows')->where('payment_status', 'PARTIALLY_PAID')->exists() || DB::table('revenues')->where('payment_status', 'PARTIALLY_PAID')->exists()) {
                throw new RuntimeException('Cannot roll back partial payments while partially paid transactions exist.');
            }
            foreach ([
                'ALTER TABLE operational_cashflows DROP CONSTRAINT IF EXISTS op_cashflow_payment_check',
                'ALTER TABLE operational_cashflows DROP CONSTRAINT IF EXISTS op_cashflow_paid_shape_check',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_payment_check',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_paid_shape_check',
            ] as $sql) DB::statement($sql);
            Schema::dropIfExists('revenue_payments');
            Schema::dropIfExists('operational_cashflow_payments');
            foreach ([
                "ALTER TABLE operational_cashflows ADD CONSTRAINT op_cashflow_payment_check CHECK (payment_status IN ('UNPAID','PAID'))",
                "ALTER TABLE operational_cashflows ADD CONSTRAINT op_cashflow_paid_shape_check CHECK ((payment_status='UNPAID' AND paid_at IS NULL AND paid_by IS NULL) OR (payment_status='PAID' AND paid_at IS NOT NULL AND paid_by IS NOT NULL AND (direction='INFLOW' OR approval_status='APPROVED'))) ",
                "ALTER TABLE revenues ADD CONSTRAINT revenues_payment_check CHECK (payment_status IN ('UNPAID','PAID'))",
                "ALTER TABLE revenues ADD CONSTRAINT revenues_paid_shape_check CHECK ((payment_status='UNPAID' AND paid_at IS NULL AND paid_by IS NULL) OR (payment_status='PAID' AND paid_at IS NOT NULL AND paid_by IS NOT NULL AND (direction='INFLOW' OR approval_status='APPROVED'))) ",
            ] as $sql) DB::statement($sql);
        });
    }
};
