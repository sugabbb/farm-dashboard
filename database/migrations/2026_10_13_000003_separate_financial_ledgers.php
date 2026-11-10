<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            $preCount=DB::table('expenses')->count()+DB::table('revenues')->count();
            $preAmount=(int)DB::table('expenses')->sum('amount_idr')+(int)DB::table('revenues')->sum('amount_idr');
            $transferExpected=DB::table('expenses')->where('expense_type','MAIN_ACCOUNT_TRANSFER')
                ->selectRaw('approval_status, payment_status, COUNT(*) rows, SUM(amount_idr) amount')->groupBy('approval_status','payment_status')->orderBy('approval_status')->orderBy('payment_status')->get()->toJson();
            $topupExpected=DB::table('revenues')->where('revenue_type','MAIN_ACCOUNT_TOPUP')
                ->selectRaw('payment_status, COUNT(*) rows, SUM(amount_idr) amount')->groupBy('payment_status')->orderBy('payment_status')->get()->toJson();
            foreach ([
                'ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_type_check',
                'ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_approval_check',
                'ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_payment_check',
                'ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_paid_shape_check',
                'ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_amount_positive',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_type_check',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_payment_check',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_paid_shape_check',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_amount_positive',
                'DROP INDEX IF EXISTS one_cash_opening_balance',
            ] as $sql) DB::statement($sql);

            Schema::rename('cash_opening_balances', 'financial_opening_balances');
            Schema::table('financial_opening_balances', function (Blueprint $table) {
                $table->string('account')->default('OPERATIONAL')->index();
            });

            Schema::rename('expenses', 'operational_cashflows');
            DB::statement('ALTER TABLE operational_cashflows RENAME COLUMN expense_type TO cashflow_type');
            DB::statement('ALTER TABLE operational_cashflows RENAME COLUMN expense_date TO transaction_date');
            Schema::table('operational_cashflows', function (Blueprint $table) {
                $table->string('direction')->default('OUTFLOW')->index();
            });

            foreach (['feed', 'salary', 'utility', 'health', 'other', 'transfer'] as $detail) {
                Schema::rename("expense_{$detail}_details", "operational_cashflow_{$detail}_details");
                DB::statement("ALTER TABLE operational_cashflow_{$detail}_details RENAME COLUMN expense_id TO operational_cashflow_id");
            }

            Schema::create('operational_cashflow_topup_details', function (Blueprint $table) {
                $table->id();
                $table->foreignId('operational_cashflow_id')->unique()->constrained('operational_cashflows')->cascadeOnDelete();
                $table->text('notes')->nullable();
                $table->timestampsTz();
            });

            Schema::table('revenues', function (Blueprint $table) {
                $table->string('direction')->default('INFLOW')->index();
                $table->string('approval_status')->default('NOT_REQUIRED')->index();
                $table->text('rejection_reason')->nullable();
                $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestampTz('submitted_at')->nullable();
                $table->timestampTz('approved_at')->nullable();
                $table->timestampTz('rejected_at')->nullable();
            });
            Schema::create('revenue_transfer_details', function (Blueprint $table) {
                $table->id();
                $table->foreignId('revenue_id')->unique()->constrained('revenues')->cascadeOnDelete();
                $table->text('notes')->nullable();
                $table->timestampsTz();
            });

            DB::table('audit_events')->where('entity_type', 'expenses')->update(['entity_type' => 'operational_cashflows']);
            foreach (['feed', 'salary', 'utility', 'health', 'other', 'transfer'] as $detail) {
                DB::table('audit_events')->where('entity_type', "expense_{$detail}_details")
                    ->update(['entity_type' => "operational_cashflow_{$detail}_details"]);
            }

            $transfers = DB::table('operational_cashflows')->where('cashflow_type', 'MAIN_ACCOUNT_TRANSFER')->orderBy('id')->get();
            foreach ($transfers as $transfer) {
                $detail = DB::table('operational_cashflow_transfer_details')->where('operational_cashflow_id', $transfer->id)->first();
                $newId = DB::table('revenues')->insertGetId([
                    'revenue_type' => 'MAIN_ACCOUNT_TRANSFER', 'direction' => 'OUTFLOW',
                    'transaction_date' => $transfer->transaction_date, 'amount_idr' => $transfer->amount_idr,
                    'approval_status' => $transfer->approval_status, 'payment_status' => $transfer->payment_status,
                    'rejection_reason' => $transfer->rejection_reason, 'customer_id' => null,
                    'created_by' => $transfer->created_by, 'submitted_by' => $transfer->submitted_by,
                    'approved_by' => $transfer->approved_by, 'rejected_by' => $transfer->rejected_by,
                    'paid_by' => $transfer->paid_by, 'proof_path' => $transfer->proof_path, 'proof_url' => $transfer->proof_url,
                    'submitted_at' => $transfer->submitted_at, 'approved_at' => $transfer->approved_at,
                    'rejected_at' => $transfer->rejected_at, 'paid_at' => $transfer->paid_at,
                    'created_at' => $transfer->created_at, 'updated_at' => $transfer->updated_at,
                ]);
                DB::table('revenue_transfer_details')->insert([
                    'revenue_id' => $newId, 'notes' => $detail?->notes,
                    'created_at' => $detail?->created_at ?? $transfer->created_at,
                    'updated_at' => $detail?->updated_at ?? $transfer->updated_at,
                ]);
                DB::table('audit_events')->where('entity_type', 'operational_cashflows')->where('entity_id', $transfer->id)
                    ->update(['entity_type' => 'revenues', 'entity_id' => $newId]);
            }
            DB::table('operational_cashflows')->where('cashflow_type', 'MAIN_ACCOUNT_TRANSFER')->delete();

            $topups = DB::table('revenues')->where('revenue_type', 'MAIN_ACCOUNT_TOPUP')->orderBy('id')->get();
            foreach ($topups as $topup) {
                $detail = DB::table('revenue_topup_details')->where('revenue_id', $topup->id)->first();
                $newId = DB::table('operational_cashflows')->insertGetId([
                    'cashflow_type' => 'MAIN_ACCOUNT_TOPUP', 'direction' => 'INFLOW',
                    'transaction_date' => $topup->transaction_date, 'amount_idr' => $topup->amount_idr,
                    'approval_status' => 'NOT_REQUIRED', 'payment_status' => $topup->payment_status,
                    'rejection_reason' => null, 'created_by' => $topup->created_by,
                    'submitted_by' => null, 'approved_by' => null, 'rejected_by' => null,
                    'paid_by' => $topup->paid_by, 'proof_path' => $topup->proof_path, 'proof_url' => $topup->proof_url,
                    'submitted_at' => null, 'approved_at' => null, 'rejected_at' => null,
                    'paid_at' => $topup->paid_at, 'created_at' => $topup->created_at, 'updated_at' => $topup->updated_at,
                ]);
                DB::table('operational_cashflow_topup_details')->insert([
                    'operational_cashflow_id' => $newId, 'notes' => $detail?->notes,
                    'created_at' => $detail?->created_at ?? $topup->created_at,
                    'updated_at' => $detail?->updated_at ?? $topup->updated_at,
                ]);
                DB::table('audit_events')->where('entity_type', 'revenues')->where('entity_id', $topup->id)
                    ->update(['entity_type' => 'operational_cashflows', 'entity_id' => $newId]);
            }
            DB::table('revenues')->where('revenue_type', 'MAIN_ACCOUNT_TOPUP')->delete();

            $transferActual=DB::table('revenues')->where('revenue_type','MAIN_ACCOUNT_TRANSFER')
                ->selectRaw('approval_status, payment_status, COUNT(*) rows, SUM(amount_idr) amount')->groupBy('approval_status','payment_status')->orderBy('approval_status')->orderBy('payment_status')->get()->toJson();
            $topupActual=DB::table('operational_cashflows')->where('cashflow_type','MAIN_ACCOUNT_TOPUP')
                ->selectRaw('payment_status, COUNT(*) rows, SUM(amount_idr) amount')->groupBy('payment_status')->orderBy('payment_status')->get()->toJson();
            $postCount=DB::table('operational_cashflows')->count()+DB::table('revenues')->count();
            $postAmount=(int)DB::table('operational_cashflows')->sum('amount_idr')+(int)DB::table('revenues')->sum('amount_idr');
            if($preCount!==$postCount || $preAmount!==$postAmount || $transferExpected!==$transferActual || $topupExpected!==$topupActual) {
                throw new RuntimeException('Financial ledger migration reconciliation failed; the transaction has been rolled back.');
            }

            Schema::drop('operational_cashflow_transfer_details');
            Schema::drop('revenue_topup_details');

            foreach ([
                "ALTER TABLE operational_cashflows ADD CONSTRAINT op_cashflow_type_check CHECK (cashflow_type IN ('FEED_AYAM','FEED_LELE','SALARY','UTILITY','HEALTH','OTHER','MAIN_ACCOUNT_TOPUP'))",
                "ALTER TABLE operational_cashflows ADD CONSTRAINT op_cashflow_direction_check CHECK ((cashflow_type='MAIN_ACCOUNT_TOPUP' AND direction='INFLOW') OR (cashflow_type<>'MAIN_ACCOUNT_TOPUP' AND direction='OUTFLOW'))",
                "ALTER TABLE operational_cashflows ADD CONSTRAINT op_cashflow_approval_check CHECK (approval_status IN ('NOT_REQUIRED','DRAFT','SUBMITTED','APPROVED','REJECTED'))",
                "ALTER TABLE operational_cashflows ADD CONSTRAINT op_cashflow_workflow_check CHECK ((direction='INFLOW' AND approval_status='NOT_REQUIRED') OR (direction='OUTFLOW' AND approval_status<>'NOT_REQUIRED'))",
                "ALTER TABLE operational_cashflows ADD CONSTRAINT op_cashflow_payment_check CHECK (payment_status IN ('UNPAID','PAID'))",
                "ALTER TABLE operational_cashflows ADD CONSTRAINT op_cashflow_paid_shape_check CHECK ((payment_status='UNPAID' AND paid_at IS NULL AND paid_by IS NULL) OR (payment_status='PAID' AND paid_at IS NOT NULL AND paid_by IS NOT NULL AND (direction='INFLOW' OR approval_status='APPROVED'))) ",
                'ALTER TABLE operational_cashflows ADD CONSTRAINT op_cashflow_amount_positive CHECK (amount_idr > 0)',
                "ALTER TABLE revenues ADD CONSTRAINT revenues_type_check CHECK (revenue_type IN ('EGG_SALE','FISH_SALE','OTHER','MAIN_ACCOUNT_TRANSFER'))",
                "ALTER TABLE revenues ADD CONSTRAINT revenues_direction_check CHECK ((revenue_type='MAIN_ACCOUNT_TRANSFER' AND direction='OUTFLOW') OR (revenue_type<>'MAIN_ACCOUNT_TRANSFER' AND direction='INFLOW'))",
                "ALTER TABLE revenues ADD CONSTRAINT revenues_approval_check CHECK (approval_status IN ('NOT_REQUIRED','DRAFT','SUBMITTED','APPROVED','REJECTED'))",
                "ALTER TABLE revenues ADD CONSTRAINT revenues_workflow_check CHECK ((direction='INFLOW' AND approval_status='NOT_REQUIRED') OR (direction='OUTFLOW' AND approval_status<>'NOT_REQUIRED'))",
                "ALTER TABLE revenues ADD CONSTRAINT revenues_payment_check CHECK (payment_status IN ('UNPAID','PAID'))",
                "ALTER TABLE revenues ADD CONSTRAINT revenues_paid_shape_check CHECK ((payment_status='UNPAID' AND paid_at IS NULL AND paid_by IS NULL) OR (payment_status='PAID' AND paid_at IS NOT NULL AND paid_by IS NOT NULL AND (direction='INFLOW' OR approval_status='APPROVED'))) ",
                'ALTER TABLE revenues ADD CONSTRAINT revenues_amount_positive CHECK (amount_idr > 0)',
                "ALTER TABLE financial_opening_balances ADD CONSTRAINT financial_opening_account_check CHECK (account IN ('OPERATIONAL','REVENUE'))",
                'CREATE UNIQUE INDEX one_financial_opening_per_account ON financial_opening_balances (account)',
            ] as $sql) DB::statement($sql);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach ([
                'ALTER TABLE operational_cashflows DROP CONSTRAINT IF EXISTS op_cashflow_type_check',
                'ALTER TABLE operational_cashflows DROP CONSTRAINT IF EXISTS op_cashflow_direction_check',
                'ALTER TABLE operational_cashflows DROP CONSTRAINT IF EXISTS op_cashflow_approval_check',
                'ALTER TABLE operational_cashflows DROP CONSTRAINT IF EXISTS op_cashflow_workflow_check',
                'ALTER TABLE operational_cashflows DROP CONSTRAINT IF EXISTS op_cashflow_payment_check',
                'ALTER TABLE operational_cashflows DROP CONSTRAINT IF EXISTS op_cashflow_paid_shape_check',
                'ALTER TABLE operational_cashflows DROP CONSTRAINT IF EXISTS op_cashflow_amount_positive',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_type_check',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_direction_check',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_approval_check',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_workflow_check',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_payment_check',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_paid_shape_check',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_amount_positive',
                'ALTER TABLE financial_opening_balances DROP CONSTRAINT IF EXISTS financial_opening_account_check',
                'DROP INDEX IF EXISTS one_financial_opening_per_account',
            ] as $sql) DB::statement($sql);

            Schema::create('operational_cashflow_transfer_details', function (Blueprint $table) {
                $table->id();
                $table->foreignId('operational_cashflow_id')->unique()->constrained('operational_cashflows')->cascadeOnDelete();
                $table->text('notes')->nullable();
                $table->timestampsTz();
            });
            Schema::create('revenue_topup_details', function (Blueprint $table) {
                $table->id();
                $table->foreignId('revenue_id')->unique()->constrained('revenues')->cascadeOnDelete();
                $table->text('notes')->nullable();
                $table->timestampsTz();
            });

            foreach (DB::table('operational_cashflows')->where('cashflow_type', 'MAIN_ACCOUNT_TOPUP')->orderBy('id')->get() as $topup) {
                $detail = DB::table('operational_cashflow_topup_details')->where('operational_cashflow_id', $topup->id)->first();
                $newId = DB::table('revenues')->insertGetId([
                    'revenue_type' => 'MAIN_ACCOUNT_TOPUP', 'direction' => 'INFLOW',
                    'transaction_date' => $topup->transaction_date, 'amount_idr' => $topup->amount_idr,
                    'approval_status' => 'NOT_REQUIRED', 'payment_status' => $topup->payment_status,
                    'rejection_reason' => null, 'customer_id' => null, 'created_by' => $topup->created_by,
                    'submitted_by' => null, 'approved_by' => null, 'rejected_by' => null,
                    'paid_by' => $topup->paid_by, 'proof_path' => $topup->proof_path, 'proof_url' => $topup->proof_url,
                    'submitted_at' => null, 'approved_at' => null, 'rejected_at' => null, 'paid_at' => $topup->paid_at,
                    'created_at' => $topup->created_at, 'updated_at' => $topup->updated_at,
                ]);
                DB::table('revenue_topup_details')->insert(['revenue_id' => $newId, 'notes' => $detail?->notes, 'created_at' => $detail?->created_at ?? $topup->created_at, 'updated_at' => $detail?->updated_at ?? $topup->updated_at]);
                DB::table('audit_events')->where('entity_type', 'operational_cashflows')->where('entity_id', $topup->id)->update(['entity_type' => 'revenues', 'entity_id' => $newId]);
            }
            DB::table('operational_cashflows')->where('cashflow_type', 'MAIN_ACCOUNT_TOPUP')->delete();

            foreach (DB::table('revenues')->where('revenue_type', 'MAIN_ACCOUNT_TRANSFER')->orderBy('id')->get() as $transfer) {
                $detail = DB::table('revenue_transfer_details')->where('revenue_id', $transfer->id)->first();
                $newId = DB::table('operational_cashflows')->insertGetId([
                    'cashflow_type' => 'MAIN_ACCOUNT_TRANSFER', 'direction' => 'OUTFLOW',
                    'transaction_date' => $transfer->transaction_date, 'amount_idr' => $transfer->amount_idr,
                    'approval_status' => $transfer->approval_status, 'payment_status' => $transfer->payment_status,
                    'rejection_reason' => $transfer->rejection_reason, 'created_by' => $transfer->created_by,
                    'submitted_by' => $transfer->submitted_by, 'approved_by' => $transfer->approved_by,
                    'rejected_by' => $transfer->rejected_by, 'paid_by' => $transfer->paid_by,
                    'proof_path' => $transfer->proof_path, 'proof_url' => $transfer->proof_url,
                    'submitted_at' => $transfer->submitted_at, 'approved_at' => $transfer->approved_at,
                    'rejected_at' => $transfer->rejected_at, 'paid_at' => $transfer->paid_at,
                    'created_at' => $transfer->created_at, 'updated_at' => $transfer->updated_at,
                ]);
                DB::table('operational_cashflow_transfer_details')->insert(['operational_cashflow_id' => $newId, 'notes' => $detail?->notes, 'created_at' => $detail?->created_at ?? $transfer->created_at, 'updated_at' => $detail?->updated_at ?? $transfer->updated_at]);
                DB::table('audit_events')->where('entity_type', 'revenues')->where('entity_id', $transfer->id)->update(['entity_type' => 'operational_cashflows', 'entity_id' => $newId]);
            }
            DB::table('revenues')->where('revenue_type', 'MAIN_ACCOUNT_TRANSFER')->delete();

            Schema::drop('operational_cashflow_topup_details');
            Schema::drop('revenue_transfer_details');
            Schema::table('revenues', function (Blueprint $table) {
                $table->dropConstrainedForeignId('submitted_by');
                $table->dropConstrainedForeignId('approved_by');
                $table->dropConstrainedForeignId('rejected_by');
                $table->dropColumn(['direction', 'approval_status', 'rejection_reason', 'submitted_at', 'approved_at', 'rejected_at']);
            });

            foreach (['feed', 'salary', 'utility', 'health', 'other', 'transfer'] as $detail) {
                DB::table('audit_events')->where('entity_type', "operational_cashflow_{$detail}_details")->update(['entity_type' => "expense_{$detail}_details"]);
                DB::statement("ALTER TABLE operational_cashflow_{$detail}_details RENAME COLUMN operational_cashflow_id TO expense_id");
                Schema::rename("operational_cashflow_{$detail}_details", "expense_{$detail}_details");
            }
            DB::table('audit_events')->where('entity_type', 'operational_cashflows')->update(['entity_type' => 'expenses']);
            Schema::table('operational_cashflows', fn (Blueprint $table) => $table->dropColumn('direction'));
            DB::statement('ALTER TABLE operational_cashflows RENAME COLUMN cashflow_type TO expense_type');
            DB::statement('ALTER TABLE operational_cashflows RENAME COLUMN transaction_date TO expense_date');
            Schema::rename('operational_cashflows', 'expenses');

            Schema::table('financial_opening_balances', fn (Blueprint $table) => $table->dropColumn('account'));
            Schema::rename('financial_opening_balances', 'cash_opening_balances');
            foreach ([
                "ALTER TABLE expenses ADD CONSTRAINT expenses_type_check CHECK (expense_type IN ('FEED_AYAM','FEED_LELE','SALARY','UTILITY','HEALTH','OTHER','MAIN_ACCOUNT_TRANSFER'))",
                "ALTER TABLE expenses ADD CONSTRAINT expenses_approval_check CHECK (approval_status IN ('DRAFT','SUBMITTED','APPROVED','REJECTED'))",
                "ALTER TABLE expenses ADD CONSTRAINT expenses_payment_check CHECK (payment_status IN ('UNPAID','PAID'))",
                "ALTER TABLE expenses ADD CONSTRAINT expenses_paid_shape_check CHECK ((payment_status='UNPAID' AND paid_at IS NULL AND paid_by IS NULL) OR (payment_status='PAID' AND approval_status='APPROVED' AND paid_at IS NOT NULL AND paid_by IS NOT NULL))",
                "ALTER TABLE revenues ADD CONSTRAINT revenues_type_check CHECK (revenue_type IN ('EGG_SALE','FISH_SALE','OTHER','MAIN_ACCOUNT_TOPUP'))",
                "ALTER TABLE revenues ADD CONSTRAINT revenues_payment_check CHECK (payment_status IN ('UNPAID','PAID'))",
                "ALTER TABLE revenues ADD CONSTRAINT revenues_paid_shape_check CHECK ((payment_status='UNPAID' AND paid_at IS NULL AND paid_by IS NULL) OR (payment_status='PAID' AND paid_at IS NOT NULL AND paid_by IS NOT NULL))",
                'ALTER TABLE expenses ADD CONSTRAINT expenses_amount_positive CHECK (amount_idr > 0)',
                'ALTER TABLE revenues ADD CONSTRAINT revenues_amount_positive CHECK (amount_idr > 0)',
                'CREATE UNIQUE INDEX one_cash_opening_balance ON cash_opening_balances ((true))',
            ] as $sql) DB::statement($sql);
        });
    }
};
