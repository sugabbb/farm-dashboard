<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::table('livestock_groups', function (Blueprint $table) {
            $table->decimal('initial_average_weight_g', 12, 3)->nullable()->after('initial_population');
            $table->decimal('planned_adg_g_per_day', 10, 3)->nullable()->after('initial_average_weight_g');
        });

        Schema::create('lele_growth_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->unique()->constrained('livestock_groups')->cascadeOnDelete();
            $table->decimal('target_weight_g', 12, 6)->default(142.857143);
            $table->decimal('planned_adg_g_per_day', 10, 3)->nullable();
            $table->decimal('calculated_adg_g_per_day', 12, 6)->nullable();
            $table->date('planned_harvest_date')->nullable();
            $table->date('chart_end_date');
            $table->string('calculation_status')->index();
            $table->jsonb('source_sampling_ids')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestampTz('rebuilt_at');
            $table->timestampsTz();
        });

        Schema::create('lele_growth_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('lele_growth_profiles')->cascadeOnDelete();
            $table->date('point_date')->index();
            $table->decimal('planned_weight_g', 12, 3)->nullable();
            $table->decimal('calculated_weight_g', 12, 3)->nullable();
            $table->decimal('actual_weight_g', 12, 3)->nullable();
            $table->jsonb('point_kinds');
            $table->timestampsTz();
            $table->unique(['profile_id', 'point_date']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('abk_type')->default('BOTH')->index()->after('job_title');
            $table->string('api_username')->nullable()->after('abk_type');
            $table->text('api_pin_hash')->nullable()->after('api_username');
            $table->unsignedSmallInteger('api_failed_attempts')->default(0)->after('api_pin_hash');
            $table->timestampTz('api_locked_until')->nullable()->after('api_failed_attempts');
            $table->timestampTz('credentials_updated_at')->nullable()->after('api_locked_until');
        });

        Schema::create('abk_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind')->index();
            $table->string('species')->nullable()->index();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('business_key')->index();
            $table->string('canonical_type')->nullable();
            $table->unsignedBigInteger('canonical_id')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('base_updated_at')->nullable();
            $table->jsonb('payload');
            $table->string('status')->default('DRAFT')->index();
            $table->text('proof_path')->nullable();
            $table->text('proof_url')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestampsTz();
            $table->index(['canonical_type', 'canonical_id']);
            $table->unique(['employee_id', 'idempotency_key']);
        });

        Schema::table('audit_events', function (Blueprint $table) {
            $table->foreignId('actor_employee_id')->nullable()->after('actor_id')->constrained('employees')->restrictOnDelete();
        });

        DB::table('app_settings')->insert([
            'key' => 'default_lele_adg_g_per_day', 'value' => '1.300',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('livestock_groups')->where('species', 'LELE')->update(['planned_adg_g_per_day' => '1.300']);
        DB::table('employees')->update(['abk_type' => 'BOTH']);

        foreach ([
            "ALTER TABLE livestock_groups ADD CONSTRAINT groups_growth_shape_check CHECK ((species='AYAM' AND initial_average_weight_g IS NULL AND planned_adg_g_per_day IS NULL) OR (species='LELE' AND (initial_average_weight_g IS NULL OR initial_average_weight_g >= 0) AND planned_adg_g_per_day > 0))",
            "ALTER TABLE employees ADD CONSTRAINT employees_abk_type_check CHECK (abk_type IN ('AYAM','LELE','BOTH','NO_SYSTEM_ACCESS'))",
            "CREATE UNIQUE INDEX employees_api_username_unique ON employees (LOWER(api_username)) WHERE api_username IS NOT NULL",
            "ALTER TABLE abk_submissions ADD CONSTRAINT abk_submission_kind_check CHECK (kind IN ('DAILY_AYAM','DAILY_LELE','EGG_SALE'))",
            "ALTER TABLE abk_submissions ADD CONSTRAINT abk_submission_status_check CHECK (status IN ('DRAFT','APPROVED','REJECTED'))",
            "CREATE UNIQUE INDEX one_draft_per_abk_business_key ON abk_submissions (kind, business_key) WHERE status='DRAFT'",
        ] as $sql) DB::statement($sql);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT IF EXISTS audit_events_actor_employee_id_foreign');
        Schema::table('audit_events', fn (Blueprint $table) => $table->dropColumn('actor_employee_id'));
        Schema::dropIfExists('abk_submissions');
        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_abk_type_check');
        DB::statement('DROP INDEX IF EXISTS employees_api_username_unique');
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['abk_type','api_username','api_pin_hash','api_failed_attempts','api_locked_until','credentials_updated_at']));
        Schema::dropIfExists('lele_growth_points');
        Schema::dropIfExists('lele_growth_profiles');
        DB::statement('ALTER TABLE livestock_groups DROP CONSTRAINT IF EXISTS groups_growth_shape_check');
        Schema::table('livestock_groups', fn (Blueprint $table) => $table->dropColumn(['initial_average_weight_g','planned_adg_g_per_day']));
        Schema::dropIfExists('app_settings');
    }
};
