<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('phone', 50)->index();
            $table->string('customer_type')->index();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::table('revenues', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('payment_status')->constrained()->restrictOnDelete();
        });

        Schema::create('lele_samplings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('livestock_groups')->restrictOnDelete();
            $table->date('sampling_date')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['group_id', 'sampling_date']);
        });

        Schema::create('lele_sampling_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sampling_id')->constrained('lele_samplings')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->unsignedSmallInteger('fish_count')->default(10);
            $table->decimal('total_weight_kg', 14, 3);
            $table->timestampsTz();
            $table->unique(['sampling_id', 'sequence']);
        });

        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_type_check CHECK (customer_type IN ('WARUNG','AGEN'))");
        DB::statement('ALTER TABLE lele_sampling_measurements ADD CONSTRAINT lele_sampling_sequence_check CHECK (sequence BETWEEN 1 AND 3)');
        DB::statement('ALTER TABLE lele_sampling_measurements ADD CONSTRAINT lele_sampling_fish_count_check CHECK (fish_count = 10)');
        DB::statement('ALTER TABLE lele_sampling_measurements ADD CONSTRAINT lele_sampling_weight_positive CHECK (total_weight_kg > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('lele_sampling_measurements');
        Schema::dropIfExists('lele_samplings');
        Schema::table('revenues', fn (Blueprint $table) => $table->dropConstrainedForeignId('customer_id'));
        Schema::dropIfExists('customers');
    }
};
