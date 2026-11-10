<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const NEW_TYPES = ['LELE_SEED_PURCHASE', 'PULLET_PURCHASE', 'BANK_ADMIN_FEE'];

    public function up(): void
    {
        Schema::create('operational_cashflow_livestock_purchase_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operational_cashflow_id');
            $table->unique('operational_cashflow_id', 'op_livestock_cashflow_unique');
            $table->foreign('operational_cashflow_id', 'op_livestock_cashflow_fk')
                ->references('id')->on('operational_cashflows')->cascadeOnDelete();
            $table->string('species')->index();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('operational_cashflow_bank_admin_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operational_cashflow_id');
            $table->unique('operational_cashflow_id', 'op_bank_admin_cashflow_unique');
            $table->foreign('operational_cashflow_id', 'op_bank_admin_cashflow_fk')
                ->references('id')->on('operational_cashflows')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE operational_cashflows DROP CONSTRAINT IF EXISTS op_cashflow_type_check');
        DB::statement("ALTER TABLE operational_cashflows ADD CONSTRAINT op_cashflow_type_check CHECK (cashflow_type IN ('FEED_AYAM','FEED_LELE','SALARY','UTILITY','HEALTH','OTHER','LELE_SEED_PURCHASE','PULLET_PURCHASE','BANK_ADMIN_FEE','MAIN_ACCOUNT_TOPUP'))");
        DB::statement("ALTER TABLE operational_cashflow_livestock_purchase_details ADD CONSTRAINT op_livestock_purchase_species_check CHECK (species IN ('AYAM','LELE'))");
    }

    public function down(): void
    {
        if (DB::table('operational_cashflows')->whereIn('cashflow_type', self::NEW_TYPES)->exists()) {
            throw new \RuntimeException('Migration tidak dapat dibatalkan selama transaksi jenis baru masih tersimpan.');
        }

        DB::statement('ALTER TABLE operational_cashflows DROP CONSTRAINT IF EXISTS op_cashflow_type_check');
        DB::statement("ALTER TABLE operational_cashflows ADD CONSTRAINT op_cashflow_type_check CHECK (cashflow_type IN ('FEED_AYAM','FEED_LELE','SALARY','UTILITY','HEALTH','OTHER','MAIN_ACCOUNT_TOPUP'))");
        Schema::dropIfExists('operational_cashflow_bank_admin_details');
        Schema::dropIfExists('operational_cashflow_livestock_purchase_details');
    }
};
