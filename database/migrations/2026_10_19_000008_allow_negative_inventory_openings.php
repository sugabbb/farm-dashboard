<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE inventory_opening_balances DROP CONSTRAINT IF EXISTS inventory_opening_nonnegative');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventory_opening_balances ADD CONSTRAINT inventory_opening_nonnegative CHECK (quantity_kg >= 0 AND (quantity_pieces IS NULL OR quantity_pieces >= 0))');
    }
};
