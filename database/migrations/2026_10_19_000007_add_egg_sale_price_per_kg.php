<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revenue_egg_details', function (Blueprint $table) {
            $table->decimal('price_per_kg_idr', 18, 3)->nullable()->after('quantity_pieces');
        });

        DB::table('revenue_egg_details')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $price = null;
                if (is_string($row->notes) && $row->notes !== '') {
                    $metadata = json_decode($row->notes, true);
                    $candidate = is_array($metadata) ? ($metadata['harga_per_kg'] ?? null) : null;
                    if (is_numeric($candidate) && (float) $candidate >= 0) $price = (string) $candidate;
                }
                if ($price === null) {
                    $price = bccomp((string) $row->weight_kg, '0', 3) === 1
                        ? bcadd(bcdiv((string) $row->line_amount_idr, (string) $row->weight_kg, 4), '0.0005', 3)
                        : '0';
                }
                DB::table('revenue_egg_details')->where('id', $row->id)->update(['price_per_kg_idr' => $price]);
            }
        });

        DB::statement('ALTER TABLE revenue_egg_details ALTER COLUMN price_per_kg_idr SET NOT NULL');
        DB::statement('ALTER TABLE revenue_egg_details ADD CONSTRAINT egg_sale_price_nonnegative CHECK (price_per_kg_idr >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE revenue_egg_details DROP CONSTRAINT IF EXISTS egg_sale_price_nonnegative');
        Schema::table('revenue_egg_details', fn (Blueprint $table) => $table->dropColumn('price_per_kg_idr'));
    }
};
