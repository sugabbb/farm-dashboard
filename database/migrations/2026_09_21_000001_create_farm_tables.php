<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('ADMIN')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('must_change_password')->default(true);
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('job_title')->nullable();
            $table->date('joined_at'); $table->string('status')->default('ACTIVE')->index();
            $table->text('notes')->nullable(); $table->timestampsTz();
        });
        Schema::create('livestock_groups', function (Blueprint $table) {
            $table->id(); $table->string('species')->index(); $table->string('code')->unique();
            $table->string('name'); $table->date('start_date')->index(); $table->unsignedBigInteger('initial_population');
            $table->string('location'); $table->string('status')->default('ACTIVE')->index();
            $table->date('closed_at')->nullable(); $table->text('notes')->nullable(); $table->timestampsTz();
        });
        Schema::create('feed_types', function (Blueprint $table) {
            $table->id(); $table->string('species')->index(); $table->string('name');
            $table->boolean('is_active')->default(true)->index(); $table->timestampsTz();
            $table->unique(['species', 'name']);
        });
        Schema::create('daily_records', function (Blueprint $table) {
            $table->id(); $table->foreignId('group_id')->constrained('livestock_groups')->restrictOnDelete();
            $table->date('record_date')->index(); $table->unsignedBigInteger('mortality')->default(0);
            $table->text('notes')->nullable(); $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz(); $table->unique(['group_id', 'record_date']);
        });
        Schema::create('feed_usages', function (Blueprint $table) {
            $table->id(); $table->foreignId('daily_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_type_id')->constrained()->restrictOnDelete();
            $table->decimal('morning_kg', 14, 3)->default(0); $table->decimal('noon_kg', 14, 3)->default(0);
            $table->decimal('evening_kg', 14, 3)->default(0); $table->decimal('quantity_kg', 14, 3); $table->timestampsTz();
            $table->unique(['daily_record_id', 'feed_type_id']);
        });
        Schema::create('egg_harvests', function (Blueprint $table) {
            $table->id(); $table->foreignId('daily_record_id')->constrained()->cascadeOnDelete();
            $table->string('egg_category')->index(); $table->decimal('weight_kg', 14, 3)->default(0);
            $table->unsignedBigInteger('quantity_pieces')->default(0); $table->timestampsTz();
            $table->unique(['daily_record_id', 'egg_category']);
        });
        Schema::create('inventory_opening_balances', function (Blueprint $table) {
            $table->id(); $table->string('item_kind')->index();
            $table->foreignId('feed_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('egg_category')->nullable()->index(); $table->decimal('quantity_kg', 14, 3)->default(0);
            $table->unsignedBigInteger('quantity_pieces')->nullable(); $table->date('effective_date')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete(); $table->timestampsTz();
            $table->unique('feed_type_id'); $table->unique('egg_category');
        });
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id(); $table->string('item_kind')->index();
            $table->foreignId('feed_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('egg_category')->nullable()->index(); $table->decimal('delta_kg', 14, 3);
            $table->bigInteger('delta_pieces')->nullable(); $table->date('effective_date')->index();
            $table->text('reason'); $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        Schema::create('population_adjustments', function (Blueprint $table) {
            $table->id(); $table->foreignId('group_id')->constrained('livestock_groups')->restrictOnDelete();
            $table->bigInteger('delta_ekor'); $table->date('effective_date')->index(); $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete(); $table->timestampsTz();
        });
        Schema::create('cash_opening_balances', function (Blueprint $table) {
            $table->id(); $table->decimal('amount_idr', 18, 0); $table->date('effective_date')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete(); $table->timestampsTz();
        });
        Schema::create('expenses', function (Blueprint $table) {
            $table->id(); $table->string('expense_type')->index(); $table->date('expense_date')->index();
            $table->decimal('amount_idr', 18, 0); $table->string('approval_status')->default('DRAFT')->index();
            $table->string('payment_status')->default('UNPAID')->index(); $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('proof_path')->nullable(); $table->text('proof_url')->nullable();
            $table->timestampTz('submitted_at')->nullable(); $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('rejected_at')->nullable(); $table->timestampTz('paid_at')->nullable()->index();
            $table->timestampsTz(); $table->index(['expense_type', 'approval_status', 'expense_date']);
        });
        Schema::create('expense_feed_details', function (Blueprint $table) {
            $table->id(); $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_type_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_kg', 14, 3); $table->decimal('price_per_kg_idr', 18, 3);
            $table->decimal('line_amount_idr', 18, 0);
            $table->timestampTz('received_at')->nullable()->index();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete(); $table->timestampsTz();
        });
        Schema::create('expense_salary_details', function (Blueprint $table) {
            $table->id(); $table->foreignId('expense_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete(); $table->string('salary_period'); $table->timestampsTz();
        });
        Schema::create('expense_utility_details', function (Blueprint $table) {
            $table->id(); $table->foreignId('expense_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('description'); $table->timestampsTz();
        });
        Schema::create('expense_health_details', function (Blueprint $table) {
            $table->id(); $table->foreignId('expense_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('species'); $table->text('description'); $table->timestampsTz();
        });
        Schema::create('expense_other_details', function (Blueprint $table) {
            $table->id(); $table->foreignId('expense_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('description'); $table->timestampsTz();
        });
        Schema::create('expense_transfer_details', function (Blueprint $table) {
            $table->id(); $table->foreignId('expense_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('notes')->nullable(); $table->timestampsTz();
        });
        Schema::create('revenues', function (Blueprint $table) {
            $table->id(); $table->string('revenue_type')->index(); $table->date('transaction_date')->index();
            $table->decimal('amount_idr', 18, 0); $table->string('payment_status')->default('UNPAID')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('proof_path')->nullable(); $table->text('proof_url')->nullable();
            $table->timestampTz('paid_at')->nullable()->index(); $table->timestampsTz();
            $table->index(['revenue_type', 'payment_status', 'transaction_date']);
        });
        Schema::create('revenue_egg_details', function (Blueprint $table) {
            $table->id(); $table->foreignId('revenue_id')->constrained()->cascadeOnDelete();
            $table->string('egg_category')->index(); $table->decimal('weight_kg', 14, 3);
            $table->unsignedBigInteger('quantity_pieces'); $table->decimal('line_amount_idr', 18, 0);
            $table->text('notes')->nullable(); $table->timestampsTz(); $table->unique(['revenue_id', 'egg_category']);
        });
        Schema::create('revenue_fish_details', function (Blueprint $table) {
            $table->id(); $table->foreignId('revenue_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('weight_kg', 14, 3); $table->decimal('price_per_kg_idr', 18, 3);
            $table->text('notes')->nullable(); $table->timestampsTz();
        });
        Schema::create('revenue_topup_details', function (Blueprint $table) {
            $table->id(); $table->foreignId('revenue_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('notes')->nullable(); $table->timestampsTz();
        });
        Schema::create('revenue_other_details', function (Blueprint $table) {
            $table->id(); $table->foreignId('revenue_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('notes')->nullable(); $table->timestampsTz();
        });
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id(); $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('entity_type')->index(); $table->unsignedBigInteger('entity_id')->index();
            $table->string('action')->index(); $table->timestampTz('happened_at')->index();
            $table->text('reason')->nullable(); $table->jsonb('before')->nullable(); $table->jsonb('after')->nullable();
            $table->timestampsTz();
        });

        foreach ([
            "ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('ADMIN','SUPERADMIN'))",
            "ALTER TABLE employees ADD CONSTRAINT employees_status_check CHECK (status IN ('ACTIVE','INACTIVE'))",
            "ALTER TABLE livestock_groups ADD CONSTRAINT groups_species_check CHECK (species IN ('AYAM','LELE'))",
            "ALTER TABLE livestock_groups ADD CONSTRAINT groups_status_check CHECK (status IN ('ACTIVE','CLOSED'))",
            "ALTER TABLE feed_types ADD CONSTRAINT feed_types_species_check CHECK (species IN ('AYAM','LELE'))",
            "ALTER TABLE egg_harvests ADD CONSTRAINT harvest_category_check CHECK (egg_category IN ('UTUH','PECAH','REJECT'))",
            "ALTER TABLE inventory_opening_balances ADD CONSTRAINT opening_shape_check CHECK ((item_kind='FEED' AND feed_type_id IS NOT NULL AND egg_category IS NULL AND quantity_pieces IS NULL) OR (item_kind='EGG' AND feed_type_id IS NULL AND egg_category IN ('UTUH','PECAH','REJECT') AND quantity_pieces IS NOT NULL))",
            "ALTER TABLE inventory_adjustments ADD CONSTRAINT adjustment_shape_check CHECK ((item_kind='FEED' AND feed_type_id IS NOT NULL AND egg_category IS NULL AND delta_pieces IS NULL) OR (item_kind='EGG' AND feed_type_id IS NULL AND egg_category IN ('UTUH','PECAH','REJECT') AND delta_pieces IS NOT NULL))",
            "ALTER TABLE expenses ADD CONSTRAINT expenses_type_check CHECK (expense_type IN ('FEED_AYAM','FEED_LELE','SALARY','UTILITY','HEALTH','OTHER','MAIN_ACCOUNT_TRANSFER'))",
            "ALTER TABLE expenses ADD CONSTRAINT expenses_approval_check CHECK (approval_status IN ('DRAFT','SUBMITTED','APPROVED','REJECTED'))",
            "ALTER TABLE expenses ADD CONSTRAINT expenses_payment_check CHECK (payment_status IN ('UNPAID','PAID'))",
            "ALTER TABLE expenses ADD CONSTRAINT expenses_paid_shape_check CHECK ((payment_status='UNPAID' AND paid_at IS NULL AND paid_by IS NULL) OR (payment_status='PAID' AND approval_status='APPROVED' AND paid_at IS NOT NULL AND paid_by IS NOT NULL))",
            "ALTER TABLE expense_health_details ADD CONSTRAINT expense_health_species_check CHECK (species IN ('AYAM','LELE'))",
            "ALTER TABLE revenues ADD CONSTRAINT revenues_type_check CHECK (revenue_type IN ('EGG_SALE','FISH_SALE','MAIN_ACCOUNT_TOPUP','OTHER'))",
            "ALTER TABLE revenues ADD CONSTRAINT revenues_payment_check CHECK (payment_status IN ('UNPAID','PAID'))",
            "ALTER TABLE revenues ADD CONSTRAINT revenues_paid_shape_check CHECK ((payment_status='UNPAID' AND paid_at IS NULL AND paid_by IS NULL) OR (payment_status='PAID' AND paid_at IS NOT NULL AND paid_by IS NOT NULL))",
            "ALTER TABLE revenue_egg_details ADD CONSTRAINT revenue_egg_category_check CHECK (egg_category IN ('UTUH','PECAH','REJECT'))",
            'ALTER TABLE expenses ADD CONSTRAINT expenses_amount_positive CHECK (amount_idr > 0)',
            'ALTER TABLE revenues ADD CONSTRAINT revenues_amount_positive CHECK (amount_idr > 0)',
            'ALTER TABLE cash_opening_balances ADD CONSTRAINT cash_opening_nonnegative CHECK (amount_idr >= 0)',
            'ALTER TABLE inventory_opening_balances ADD CONSTRAINT inventory_opening_nonnegative CHECK (quantity_kg >= 0 AND (quantity_pieces IS NULL OR quantity_pieces >= 0))',
            'ALTER TABLE egg_harvests ADD CONSTRAINT egg_harvest_nonnegative CHECK (weight_kg >= 0 AND quantity_pieces >= 0)',
            'ALTER TABLE inventory_adjustments ADD CONSTRAINT inventory_adjustment_nonzero CHECK (delta_kg <> 0 OR COALESCE(delta_pieces, 0) <> 0)',
            'ALTER TABLE population_adjustments ADD CONSTRAINT population_adjustment_nonzero CHECK (delta_ekor <> 0)',
            'ALTER TABLE feed_usages ADD CONSTRAINT feed_usage_positive CHECK (morning_kg >= 0 AND noon_kg >= 0 AND evening_kg >= 0 AND quantity_kg > 0 AND quantity_kg = morning_kg + noon_kg + evening_kg)',
            'ALTER TABLE expense_feed_details ADD CONSTRAINT feed_expense_positive CHECK (quantity_kg > 0 AND price_per_kg_idr > 0 AND line_amount_idr > 0)',
            'ALTER TABLE revenue_fish_details ADD CONSTRAINT fish_sale_positive CHECK (weight_kg > 0 AND price_per_kg_idr > 0)',
            'ALTER TABLE revenue_egg_details ADD CONSTRAINT egg_sale_positive CHECK (weight_kg >= 0 AND quantity_pieces >= 0 AND line_amount_idr > 0 AND (weight_kg > 0 OR quantity_pieces > 0))',
            'CREATE UNIQUE INDEX one_cash_opening_balance ON cash_opening_balances ((true))',
        ] as $sql) DB::statement($sql);
    }

    public function down(): void
    {
        foreach (['audit_events','revenue_other_details','revenue_topup_details','revenue_fish_details','revenue_egg_details','revenues','expense_transfer_details','expense_other_details','expense_health_details','expense_utility_details','expense_salary_details','expense_feed_details','expenses','cash_opening_balances','population_adjustments','inventory_adjustments','inventory_opening_balances','egg_harvests','feed_usages','daily_records','feed_types','livestock_groups','employees'] as $table) Schema::dropIfExists($table);
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role','is_active','must_change_password']));
    }
};
