<?php

namespace App\Console\Commands;

use App\Services\LegacyWorkbookImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class ImportLegacyFarmWorkbook extends Command
{
    protected $signature = 'farm:import-legacy-workbook
        {workbook : Path workbook XLSX legacy}
        {--actor-email= : Email SUPERADMIN aktif yang menjalankan import}
        {--commit : Tulis hasil ke database; tanpa opsi ini hanya dry-run}
        {--replace : Hapus dan ganti seluruh data domain lama; wajib bersama --commit}';

    protected $description = 'Validasi dan impor workbook keuangan/operasional legacy Pondok Ayam Bertelur';

    public function handle(LegacyWorkbookImporter $importer): int
    {
        $commit = (bool) $this->option('commit');
        try {
            if ($commit && ! (bool) $this->option('replace')) {
                throw new \RuntimeException('Commit penggantian data wajib menggunakan opsi --replace.');
            }
            $actor = $importer->resolveActor($this->option('actor-email'));
            $plan = $importer->buildPlan((string) $this->argument('workbook'));
            $preTruncateCounts = $importer->tableCounts();

            $this->newLine();
            $this->info($commit ? 'Mode: COMMIT' : 'Mode: DRY-RUN (database tidak diubah)');
            $this->line('Workbook SHA-256: '.$plan['workbook_hash']);
            $this->line('Cutoff inklusif: '.$plan['cutoff']);
            $this->line("Actor: {$actor->name} <{$actor->email}>");
            $this->renderSummary($plan['summary']);
            $this->renderReplacementCounts($preTruncateCounts);

            $result = $commit
                ? $importer->commit($plan, $actor, true)
                : ['executed_at' => now()->toIso8601String(), 'mappings' => $this->dryRunMappings($plan), 'balance_checks' => [
                    'cash_balance_idr' => $plan['summary']['cash_balance_idr'],
                    'feed_balances_kg' => ['Grower' => '0.000', 'Layer' => '461.604', '781-2' => '11.800', '781-3' => '0.000', 'Alternative' => '46.200'],
                    'expected_egg_balances' => [
                        'UTUH' => ['pieces' => 620, 'kg' => '37.636'],
                        'PECAH' => ['pieces' => 0, 'kg' => '0.045'],
                        'REJECT' => ['pieces' => 0, 'kg' => '1.394'],
                    ],
                    'expected_populations' => [
                        'AYAM-PETELUR' => 504, 'LELE-1-C1' => 4851, 'LELE-1-C2' => 4426,
                        'LELE-2-C1' => 4607, 'LELE-2-C1-KERAMBA' => 4844, 'LELE-2-C2' => 8148,
                    ],
                ], 'pre_truncate_counts' => $preTruncateCounts];

            $report = [
                'mode' => $commit ? 'commit' : 'dry-run',
                'workbook' => realpath((string) $this->argument('workbook')) ?: (string) $this->argument('workbook'),
                'workbook_hash' => $plan['workbook_hash'], 'cutoff' => $plan['cutoff'],
                'actor' => ['id' => $actor->id, 'email' => $actor->email],
                'executed_at' => $result['executed_at'], 'summary' => $plan['summary'],
                'corrections' => $plan['corrections'], 'ignored' => $plan['ignored'], 'warnings' => $plan['warnings'],
                'sampling_source' => $plan['samplings'], 'pre_truncate_counts' => $result['pre_truncate_counts'],
                'balance_checks' => $result['balance_checks'], 'source_to_target' => $result['mappings'],
            ];
            $reportPath = $this->writeReport($report, $commit);

            foreach ($plan['warnings'] as $warning) {
                $this->warn($warning);
            }
            $this->newLine();
            $this->info($commit ? 'Import berhasil dan transaction telah di-commit.' : 'Dry-run berhasil; tidak ada perubahan database.');
            $this->line('Report JSON: '.$reportPath);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error('Import dibatalkan: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @param array<string,mixed> $summary */
    private function renderSummary(array $summary): void
    {
        $this->newLine();
        $this->table(['Target', 'Count', 'Total'], [
            ['livestock_groups', $summary['livestock_groups'], '-'],
            ['employees', $summary['employees'], '-'],
            ['customers', $summary['customers'], '-'],
            ['feed_types', $summary['feed_types'], '-'],
            ['daily_records', $summary['daily_records'], '-'],
            ['feed_usages', $summary['feed_usages'], '-'],
            ['egg_harvests', $summary['egg_harvests'], '-'],
            ['expenses', $summary['expenses'], $this->rupiah($summary['expense_total_idr'])],
            ['revenues', $summary['revenues'], $this->rupiah($summary['revenue_total_idr'])],
            ['paid expenses', $summary['paid_expenses'], $this->rupiah($summary['paid_expense_total_idr'])],
            ['unpaid expenses', $summary['unpaid_expenses'], $this->rupiah($summary['unpaid_expense_total_idr'])],
            ['paid revenues', $summary['paid_revenues'], $this->rupiah($summary['paid_revenue_total_idr'])],
            ['unpaid revenues', $summary['unpaid_revenues'], $this->rupiah($summary['unpaid_revenue_total_idr'])],
            ['inventory_opening_balances', $summary['inventory_opening_balances'], '-'],
            ['inventory_adjustments', $summary['inventory_adjustments'], '-'],
            ['population_adjustments', $summary['population_adjustments'], '-'],
            ['saldo kas akhir', '-', $this->rupiah($summary['cash_balance_idr'])],
        ]);

        $categoryRows = [];
        foreach ($summary['expense_categories'] as $category => $values) {
            $categoryRows[] = [$category, $values['rows'], $this->rupiah($values['total_idr'])];
        }
        $this->table(['Expense mapping', 'Rows', 'Total'], $categoryRows);
    }

    /** @param array<string,int> $counts */
    private function renderReplacementCounts(array $counts): void
    {
        $occupied = array_filter($counts, fn (int $count): bool => $count > 0);
        $this->newLine();
        if ($occupied === []) {
            $this->line('Data domain lama: kosong.');

            return;
        }
        $this->warn('Data domain yang akan diganti saat --commit --replace:');
        $this->table(['Table', 'Rows'], array_map(
            fn (string $table, int $count): array => [$table, $count],
            array_keys($occupied), array_values($occupied),
        ));
    }

    /** @param array<string,mixed> $plan
     *  @return list<array<string,mixed>>
     */
    private function dryRunMappings(array $plan): array
    {
        $mappings = [];
        foreach (['expenses' => 'operational_cashflows / revenues', 'revenues' => 'revenues / operational_cashflows', 'daily_records' => 'daily_records', 'adjustments' => 'inventory_adjustments', 'population_adjustments' => 'population_adjustments'] as $key => $target) {
            foreach ($plan[$key] as $item) {
                $mappings[] = [
                    'sheet' => $item['sheet'] ?? 'synthetic', 'row' => $item['row'] ?? null,
                    'target' => $target, 'target_id' => null,
                ];
            }
        }
        foreach ($plan['openings'] as $opening) {
            $mappings[] = ['sheet' => 'synthetic opening', 'row' => null, 'target' => 'inventory_opening_balances', 'target_id' => null, 'item' => $opening['item']];
        }

        return $mappings;
    }

    /** @param array<string,mixed> $report */
    private function writeReport(array $report, bool $commit): string
    {
        $directory = storage_path('app/imports');
        File::ensureDirectoryExists($directory);
        $name = sprintf('legacy-workbook-%s-%s.json', $commit ? 'commit' : 'dry-run', now()->format('Ymd-His-u'));
        $path = $directory.DIRECTORY_SEPARATOR.$name;
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (File::put($path, $json."\n", true) === false) {
            throw new \RuntimeException("Report tidak dapat ditulis ke {$path}.");
        }

        return $path;
    }

    private function rupiah(int $amount): string
    {
        return ($amount < 0 ? '-Rp' : 'Rp').number_format(abs($amount), 0, '.', ',');
    }
}
