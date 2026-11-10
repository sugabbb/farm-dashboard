<?php

namespace Tests\Feature;

use App\Services\Balances;
use App\Services\LegacyWorkbookImporter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LegacyWorkbookImportTest extends TestCase
{
    use RefreshDatabase;

    private function workbook(): string
    {
        $path = base_path('../Laporan Keuangan - Pondok Ayam Bertelur - Final 12 oct - CODEX.xlsx');
        if (! is_file($path)) {
            $this->markTestSkipped('Workbook final 12 Oktober tidak tersedia di parent dashboard/.');
        }

        return $path;
    }

    public function test_dry_run_is_read_only_and_replace_commit_reconciles_complete_workbook(): void
    {
        $this->seed(DatabaseSeeder::class);
        $userCount = DB::table('users')->count();
        $seedFeedCount = DB::table('feed_types')->count();

        $this->artisan('farm:import-legacy-workbook', [
            'workbook' => $this->workbook(), '--actor-email' => 'superadmin@pondokberkah.test',
        ])->assertSuccessful()->expectsOutputToContain('DRY-RUN')->expectsOutputToContain('Rp305,231,115');

        $this->assertSame($userCount, DB::table('users')->count());
        $this->assertSame($seedFeedCount, DB::table('feed_types')->count());
        $this->assertSame(0, DB::table('operational_cashflows')->count());

        $this->artisan('farm:import-legacy-workbook', [
            'workbook' => $this->workbook(), '--actor-email' => 'superadmin@pondokberkah.test',
            '--commit' => true, '--replace' => true,
        ])->assertSuccessful()->expectsOutputToContain('Import berhasil');

        $expectedCounts = [
            'employees' => 2, 'customers' => 25, 'feed_types' => 5, 'livestock_groups' => 6,
            'daily_records' => 443, 'feed_usages' => 435, 'egg_harvests' => 213,
            'operational_cashflows' => 485, 'revenues' => 182, 'inventory_opening_balances' => 4,
            'inventory_adjustments' => 34, 'population_adjustments' => 1,
            'lele_samplings' => 0, 'lele_sampling_measurements' => 0, 'financial_opening_balances' => 0,
        ];
        foreach ($expectedCounts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
        $this->assertSame($userCount, DB::table('users')->count(), 'users harus dipertahankan');

        $this->assertSame(523625968, (int) DB::table('operational_cashflows')->sum('amount_idr'));
        $this->assertSame(519015968, (int) DB::table('operational_cashflows')->where('payment_status', 'PAID')->sum('amount_idr'));
        $this->assertSame(4, DB::table('operational_cashflows')->where('payment_status', 'UNPAID')->count());
        $this->assertSame(4610000, (int) DB::table('operational_cashflows')->where('payment_status', 'UNPAID')->sum('amount_idr'));
        $this->assertSame(87148011, (int) DB::table('revenues')->sum('amount_idr'));
        $this->assertSame(82523011, (int) DB::table('revenues')->where('payment_status', 'PAID')->sum('amount_idr'));
        $this->assertSame(8, DB::table('revenues')->where('payment_status', 'UNPAID')->count());
        $this->assertSame(4625000, (int) DB::table('revenues')->where('payment_status', 'UNPAID')->sum('amount_idr'));

        $this->assertDatabaseHas('operational_cashflows', ['transaction_date' => '2026-10-10', 'amount_idr' => 860000, 'cashflow_type' => 'FEED_LELE', 'payment_status' => 'UNPAID']);
        $this->assertDatabaseHas('operational_cashflows', ['transaction_date' => '2026-10-10', 'amount_idr' => 3600000, 'cashflow_type' => 'FEED_AYAM', 'payment_status' => 'UNPAID']);
        $this->assertDatabaseHas('revenues', ['transaction_date' => '2026-10-06', 'amount_idr' => 45000, 'revenue_type' => 'EGG_SALE', 'payment_status' => 'UNPAID']);
        $this->assertDatabaseHas('revenues', ['transaction_date' => '2026-10-06', 'revenue_type' => 'EGG_SALE', 'payment_method' => 'CASH', 'direct_transfer_date' => null]);
        $importedEggPrice = DB::table('revenue_egg_details as d')->join('revenues as r', 'r.id', '=', 'd.revenue_id')
            ->where('r.transaction_date', '2026-10-06')->where('r.amount_idr', 45000)->value('d.price_per_kg_idr');
        $this->assertSame('22500.000', $importedEggPrice);
        $this->assertDatabaseHas('revenues', ['amount_idr' => 425000, 'revenue_type' => 'OTHER', 'payment_status' => 'PAID']);
        $this->assertDatabaseHas('revenues', ['amount_idr' => 9504000, 'revenue_type' => 'FISH_SALE', 'payment_status' => 'PAID']);
        $transferId=DB::table('revenues')->where('revenue_type','MAIN_ACCOUNT_TRANSFER')->value('id');
        $this->assertDatabaseHas('revenue_transfer_details',['revenue_id'=>$transferId,'payment_cost_idr'=>0]);
        $this->assertDatabaseHas('customers', ['name' => 'Kuwat', 'phone' => '-', 'customer_type' => 'AGEN']);

        $unreceived = DB::table('operational_cashflow_feed_details as d')->join('operational_cashflows as e', 'e.id', '=', 'd.operational_cashflow_id')
            ->where('e.amount_idr', 830000)->where('d.quantity_kg', '60.000')->first();
        $this->assertNotNull($unreceived);
        $this->assertNull($unreceived->received_at);

        $this->assertSame(0, DB::table('daily_records')->where('record_date', '>', '2026-10-12')->count());
        $this->assertSame(0, DB::table('operational_cashflows')->where('transaction_date', '>', '2026-10-12')->count());
        $this->assertSame(0, DB::table('daily_records as d')->join('livestock_groups as g', 'g.id', '=', 'd.group_id')
            ->whereIn('g.code', ['LELE-1-C1', 'LELE-1-C2'])->where('d.record_date', '>', '2026-09-25')->count());

        $balances = app(Balances::class);
        $feedIds = DB::table('feed_types')->pluck('id', 'name');
        foreach (['Grower' => '0.000', 'Layer' => '461.604', '781-2' => '11.800', '781-3' => '0.000', 'Alternative' => '46.200'] as $feed => $expected) {
            $this->assertSame($expected, $balances->feed($feedIds[$feed], '2026-10-12'), $feed);
        }
        $this->assertSame(['kg' => '37.636', 'pieces' => 620], $balances->egg('UTUH', '2026-10-12'));
        $this->assertSame(['kg' => '0.045', 'pieces' => 0], $balances->egg('PECAH', '2026-10-12'));
        $this->assertSame(['kg' => '1.394', 'pieces' => 0], $balances->egg('REJECT', '2026-10-12'));
        $this->assertSame('-578100', $balances->operationalCash('2026-10-12'));
        $this->assertSame('874849', $balances->revenueCash('2026-10-12'));

        $groups = DB::table('livestock_groups')->pluck('id', 'code');
        foreach (['AYAM-PETELUR' => 504, 'LELE-1-C1' => 4851, 'LELE-1-C2' => 4426, 'LELE-2-C1' => 4607, 'LELE-2-C1-KERAMBA' => 4844, 'LELE-2-C2' => 8148] as $code => $expected) {
            $this->assertSame($expected, $balances->population($groups[$code], '2026-10-12'), $code);
        }
    }

    public function test_commit_without_replace_is_rejected_without_changes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $feedCount = DB::table('feed_types')->count();

        $this->artisan('farm:import-legacy-workbook', [
            'workbook' => $this->workbook(), '--actor-email' => 'superadmin@pondokberkah.test', '--commit' => true,
        ])->assertFailed()->expectsOutputToContain('wajib menggunakan opsi --replace');

        $this->assertSame($feedCount, DB::table('feed_types')->count());
        $this->assertSame(0, DB::table('operational_cashflows')->count());
    }

    public function test_replace_rerun_is_deterministic_and_does_not_duplicate(): void
    {
        $this->seed(DatabaseSeeder::class);
        $arguments = [
            'workbook' => $this->workbook(), '--actor-email' => 'superadmin@pondokberkah.test',
            '--commit' => true, '--replace' => true,
        ];
        $this->artisan('farm:import-legacy-workbook', $arguments)->assertSuccessful();
        $first = ['operational_cashflows' => DB::table('operational_cashflows')->count(), 'revenues' => DB::table('revenues')->count(), 'daily' => DB::table('daily_records')->count()];
        $this->artisan('farm:import-legacy-workbook', $arguments)->assertSuccessful();
        $this->assertSame($first, ['operational_cashflows' => DB::table('operational_cashflows')->count(), 'revenues' => DB::table('revenues')->count(), 'daily' => DB::table('daily_records')->count()]);
    }

    public function test_hash_mismatch_is_rejected_without_database_changes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $path = tempnam(sys_get_temp_dir(), 'legacy-invalid-');
        file_put_contents($path, 'bukan workbook yang disetujui');
        try {
            $this->artisan('farm:import-legacy-workbook', [
                'workbook' => $path, '--actor-email' => 'superadmin@pondokberkah.test',
            ])->assertFailed()->expectsOutputToContain('SHA-256 workbook tidak sesuai');
        } finally {
            @unlink($path);
        }
        $this->assertSame(0, DB::table('operational_cashflows')->count());
    }

    public function test_mid_import_error_rolls_back_truncate_and_keeps_old_domain_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        DB::table('employees')->insert([
            'name' => 'Existing', 'job_title' => 'Old', 'joined_at' => '2026-01-01', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $importer = app(LegacyWorkbookImporter::class);
        $actor = $importer->resolveActor('superadmin@pondokberkah.test');
        $plan = $importer->buildPlan($this->workbook());
        $plan['expenses'][] = [
            'sheet' => 'rollback-test', 'row' => 1, 'date' => '2026-10-12', 'type' => 'INVALID_TYPE',
            'amount' => 1, 'status' => 'PAID', 'bucket' => 'INVALID', 'detail' => ['description' => 'force rollback'], 'source' => [],
        ];

        try {
            $importer->commit($plan, $actor, true);
            $this->fail('Constraint error expected.');
        } catch (\Throwable) {
            // PostgreSQL transaction must restore the pre-truncate state.
        }

        $this->assertDatabaseHas('employees', ['name' => 'Existing']);
        $this->assertSame(3, DB::table('feed_types')->count());
        $this->assertSame(0, DB::table('operational_cashflows')->count());
    }
}
