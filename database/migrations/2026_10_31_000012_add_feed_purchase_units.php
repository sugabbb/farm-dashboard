<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('operational_cashflow_feed_details', function (Blueprint $table) {
            $table->string('purchase_unit')->nullable();
            $table->decimal('purchase_quantity', 14, 3)->nullable();
            $table->decimal('kg_per_sak', 14, 3)->nullable();
            $table->decimal('price_per_purchase_unit_idr', 18, 3)->nullable();
        });

        DB::table('operational_cashflow_feed_details')->update([
            'purchase_unit' => 'KG',
            'purchase_quantity' => DB::raw('quantity_kg'),
            'price_per_purchase_unit_idr' => DB::raw('price_per_kg_idr'),
            'kg_per_sak' => null,
        ]);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fill_legacy_feed_purchase_fields()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.purchase_unit IS NULL THEN NEW.purchase_unit := 'KG'; END IF;
                IF NEW.purchase_quantity IS NULL THEN NEW.purchase_quantity := NEW.quantity_kg; END IF;
                IF NEW.price_per_purchase_unit_idr IS NULL THEN NEW.price_per_purchase_unit_idr := NEW.price_per_kg_idr; END IF;
                IF NEW.purchase_unit = 'KG' THEN NEW.kg_per_sak := NULL; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER operational_feed_legacy_purchase_defaults
            BEFORE INSERT OR UPDATE ON operational_cashflow_feed_details
            FOR EACH ROW EXECUTE FUNCTION fill_legacy_feed_purchase_fields();
        SQL);

        foreach ([
            'ALTER TABLE operational_cashflow_feed_details ALTER COLUMN purchase_unit SET NOT NULL',
            'ALTER TABLE operational_cashflow_feed_details ALTER COLUMN purchase_quantity SET NOT NULL',
            'ALTER TABLE operational_cashflow_feed_details ALTER COLUMN price_per_purchase_unit_idr SET NOT NULL',
            "ALTER TABLE operational_cashflow_feed_details ADD CONSTRAINT feed_purchase_unit_check CHECK (purchase_unit IN ('KG','SAK'))",
            "ALTER TABLE operational_cashflow_feed_details ADD CONSTRAINT feed_purchase_shape_check CHECK ((purchase_unit='KG' AND purchase_quantity >= 0 AND kg_per_sak IS NULL) OR (purchase_unit='SAK' AND purchase_quantity > 0 AND purchase_quantity = TRUNC(purchase_quantity) AND kg_per_sak > 0))",
            'ALTER TABLE operational_cashflow_feed_details ADD CONSTRAINT feed_purchase_price_check CHECK (price_per_purchase_unit_idr >= 0)',
        ] as $sql) DB::statement($sql);
    }

    public function down(): void
    {
        if (DB::table('operational_cashflow_feed_details')->where('purchase_unit', 'SAK')->exists()) {
            throw new RuntimeException('Rollback ditolak: data pembelian SAK akan kehilangan kuantitas, harga per sak, dan konversi kg. Rollback kode aplikasi saja dan pertahankan migration ini.');
        }

        DB::statement('ALTER TABLE operational_cashflow_feed_details DROP CONSTRAINT IF EXISTS feed_purchase_unit_check');
        DB::statement('ALTER TABLE operational_cashflow_feed_details DROP CONSTRAINT IF EXISTS feed_purchase_shape_check');
        DB::statement('ALTER TABLE operational_cashflow_feed_details DROP CONSTRAINT IF EXISTS feed_purchase_price_check');
        DB::statement('DROP TRIGGER IF EXISTS operational_feed_legacy_purchase_defaults ON operational_cashflow_feed_details');
        DB::statement('DROP FUNCTION IF EXISTS fill_legacy_feed_purchase_fields()');
        Schema::table('operational_cashflow_feed_details', fn (Blueprint $table) => $table->dropColumn([
            'purchase_unit', 'purchase_quantity', 'kg_per_sak', 'price_per_purchase_unit_idr',
        ]));
    }
};
