<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            foreach ([
                'ALTER TABLE operational_cashflows DROP CONSTRAINT IF EXISTS op_cashflow_amount_positive',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_amount_positive',
                'ALTER TABLE operational_cashflow_feed_details DROP CONSTRAINT IF EXISTS feed_expense_positive',
                'ALTER TABLE revenue_fish_details DROP CONSTRAINT IF EXISTS fish_sale_positive',
                'ALTER TABLE revenue_egg_details DROP CONSTRAINT IF EXISTS egg_sale_positive',
                'ALTER TABLE feed_usages DROP CONSTRAINT IF EXISTS feed_usage_positive',
                'ALTER TABLE operational_cashflows ADD CONSTRAINT op_cashflow_amount_nonnegative CHECK (amount_idr >= 0)',
                'ALTER TABLE revenues ADD CONSTRAINT revenues_amount_nonnegative CHECK (amount_idr >= 0)',
                'ALTER TABLE operational_cashflow_feed_details ADD CONSTRAINT feed_expense_nonnegative CHECK (quantity_kg >= 0 AND price_per_kg_idr >= 0 AND line_amount_idr >= 0)',
                'ALTER TABLE revenue_fish_details ADD CONSTRAINT fish_sale_nonnegative CHECK (weight_kg >= 0 AND price_per_kg_idr >= 0)',
                'ALTER TABLE revenue_egg_details ADD CONSTRAINT egg_sale_nonnegative CHECK (weight_kg >= 0 AND quantity_pieces >= 0 AND line_amount_idr >= 0)',
                'ALTER TABLE feed_usages ADD CONSTRAINT feed_usage_nonnegative CHECK (morning_kg >= 0 AND noon_kg >= 0 AND evening_kg >= 0 AND quantity_kg >= 0 AND quantity_kg = morning_kg + noon_kg + evening_kg)',
            ] as $sql) DB::statement($sql);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $incompatible = [
                'operational cashflows' => DB::table('operational_cashflows')->where('amount_idr', '<=', 0)->exists(),
                'revenues' => DB::table('revenues')->where('amount_idr', '<=', 0)->exists(),
                'operational feed details' => DB::table('operational_cashflow_feed_details')->where(fn ($query) => $query
                    ->where('quantity_kg', '<=', 0)->orWhere('price_per_kg_idr', '<=', 0)->orWhere('line_amount_idr', '<=', 0))->exists(),
                'fish sale details' => DB::table('revenue_fish_details')->where(fn ($query) => $query
                    ->where('weight_kg', '<=', 0)->orWhere('price_per_kg_idr', '<=', 0))->exists(),
                'egg sale details' => DB::table('revenue_egg_details')->where(fn ($query) => $query
                    ->where('line_amount_idr', '<=', 0)->orWhere(fn ($quantity) => $quantity->where('weight_kg', '<=', 0)->where('quantity_pieces', '<=', 0)))->exists(),
                'daily feed usage' => DB::table('feed_usages')->where('quantity_kg', '<=', 0)->exists(),
            ];
            $blocked = collect($incompatible)->filter()->keys();
            if ($blocked->isNotEmpty()) {
                throw new RuntimeException('Cannot restore positive-value constraints while zero-valued rows exist in: '.$blocked->join(', ').'.');
            }

            foreach ([
                'ALTER TABLE operational_cashflows DROP CONSTRAINT IF EXISTS op_cashflow_amount_nonnegative',
                'ALTER TABLE revenues DROP CONSTRAINT IF EXISTS revenues_amount_nonnegative',
                'ALTER TABLE operational_cashflow_feed_details DROP CONSTRAINT IF EXISTS feed_expense_nonnegative',
                'ALTER TABLE revenue_fish_details DROP CONSTRAINT IF EXISTS fish_sale_nonnegative',
                'ALTER TABLE revenue_egg_details DROP CONSTRAINT IF EXISTS egg_sale_nonnegative',
                'ALTER TABLE feed_usages DROP CONSTRAINT IF EXISTS feed_usage_nonnegative',
                'ALTER TABLE operational_cashflows ADD CONSTRAINT op_cashflow_amount_positive CHECK (amount_idr > 0)',
                'ALTER TABLE revenues ADD CONSTRAINT revenues_amount_positive CHECK (amount_idr > 0)',
                'ALTER TABLE operational_cashflow_feed_details ADD CONSTRAINT feed_expense_positive CHECK (quantity_kg > 0 AND price_per_kg_idr > 0 AND line_amount_idr > 0)',
                'ALTER TABLE revenue_fish_details ADD CONSTRAINT fish_sale_positive CHECK (weight_kg > 0 AND price_per_kg_idr > 0)',
                'ALTER TABLE revenue_egg_details ADD CONSTRAINT egg_sale_positive CHECK (weight_kg >= 0 AND quantity_pieces >= 0 AND line_amount_idr > 0 AND (weight_kg > 0 OR quantity_pieces > 0))',
                'ALTER TABLE feed_usages ADD CONSTRAINT feed_usage_positive CHECK (morning_kg >= 0 AND noon_kg >= 0 AND evening_kg >= 0 AND quantity_kg > 0 AND quantity_kg = morning_kg + noon_kg + evening_kg)',
            ] as $sql) DB::statement($sql);
        });
    }
};
