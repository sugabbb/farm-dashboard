<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ([
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_workflow_check',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_paid_shape_check',
            ] as $sql) DB::statement($sql);

            Schema::table('revenues', function (Blueprint $table): void {
                $table->string('payment_method')->nullable()->after('payment_status')->index();
                $table->date('direct_transfer_date')->nullable()->after('payment_method');
            });
            Schema::table('revenue_transfer_details', function (Blueprint $table): void {
                $table->decimal('gross_amount_idr', 18, 0)->nullable()->after('revenue_id');
                $table->decimal('payment_cost_idr', 18, 0)->nullable()->after('gross_amount_idr');
            });

            DB::table('revenues')->where('direction', 'INFLOW')->update(['payment_method' => 'CASH']);
            DB::statement('UPDATE revenue_transfer_details AS detail SET gross_amount_idr = revenue.amount_idr, payment_cost_idr = 0 FROM revenues AS revenue WHERE revenue.id = detail.revenue_id');
            DB::statement('ALTER TABLE revenue_transfer_details ALTER COLUMN gross_amount_idr SET NOT NULL');
            DB::statement('ALTER TABLE revenue_transfer_details ALTER COLUMN payment_cost_idr SET NOT NULL');

            foreach ([
                "ALTER TABLE revenues ADD CONSTRAINT revenues_payment_routing_check CHECK (
                    (revenue_type='MAIN_ACCOUNT_TRANSFER' AND direction='OUTFLOW' AND payment_method IS NULL AND direct_transfer_date IS NULL)
                    OR
                    (revenue_type<>'MAIN_ACCOUNT_TRANSFER' AND direction='INFLOW' AND (
                        (payment_method='CASH' AND direct_transfer_date IS NULL AND approval_status='NOT_REQUIRED')
                        OR
                        (payment_method='DIRECT_TRANSFER' AND direct_transfer_date IS NOT NULL AND (
                            (approval_status IN ('SUBMITTED','REJECTED') AND payment_status='UNPAID')
                            OR (approval_status='APPROVED' AND payment_status='PAID')
                        ))
                    ))
                )",
                "ALTER TABLE revenues ADD CONSTRAINT revenues_paid_shape_check CHECK (
                    (payment_status='UNPAID' AND paid_at IS NULL AND paid_by IS NULL)
                    OR
                    (payment_status='PAID' AND paid_at IS NOT NULL AND paid_by IS NOT NULL AND (
                        revenue_type<>'MAIN_ACCOUNT_TRANSFER' OR approval_status='APPROVED'
                    ))
                )",
                'ALTER TABLE revenue_transfer_details ADD CONSTRAINT revenue_transfer_amounts_check CHECK (gross_amount_idr >= 0 AND payment_cost_idr >= 0 AND payment_cost_idr <= gross_amount_idr)',
            ] as $sql) DB::statement($sql);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach ([
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_payment_routing_check',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_paid_shape_check',
                'ALTER TABLE revenue_transfer_details DROP CONSTRAINT IF EXISTS revenue_transfer_amounts_check',
            ] as $sql) DB::statement($sql);

            DB::statement('UPDATE revenues AS revenue SET amount_idr = detail.gross_amount_idr FROM revenue_transfer_details AS detail WHERE detail.revenue_id = revenue.id');
            DB::table('revenues')->where('direction', 'INFLOW')->update([
                'approval_status' => 'NOT_REQUIRED', 'submitted_by' => null, 'submitted_at' => null,
                'approved_by' => null, 'approved_at' => null, 'rejected_by' => null,
                'rejected_at' => null, 'rejection_reason' => null,
            ]);

            Schema::table('revenue_transfer_details', function (Blueprint $table): void {
                $table->dropColumn(['gross_amount_idr', 'payment_cost_idr']);
            });
            Schema::table('revenues', function (Blueprint $table): void {
                $table->dropIndex(['payment_method']);
                $table->dropColumn(['payment_method', 'direct_transfer_date']);
            });

            foreach ([
                "ALTER TABLE revenues ADD CONSTRAINT revenues_workflow_check CHECK ((direction='INFLOW' AND approval_status='NOT_REQUIRED') OR (direction='OUTFLOW' AND approval_status<>'NOT_REQUIRED'))",
                "ALTER TABLE revenues ADD CONSTRAINT revenues_paid_shape_check CHECK ((payment_status='UNPAID' AND paid_at IS NULL AND paid_by IS NULL) OR (payment_status='PAID' AND paid_at IS NOT NULL AND paid_by IS NOT NULL AND (direction='INFLOW' OR approval_status='APPROVED'))) ",
            ] as $sql) DB::statement($sql);
        });
    }
};
