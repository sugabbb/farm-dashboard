<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LegacyWorkbookImporter
{
    public const CUTOFF = '2026-10-12';

    private const TRUNCATE_TABLES = [
        'audit_events', 'abk_submissions', 'lele_growth_points', 'lele_growth_profiles', 'lele_sampling_measurements', 'lele_samplings',
        'revenue_payments', 'revenue_transfer_details', 'revenue_other_details', 'revenue_fish_details', 'revenue_egg_details', 'revenues',
        'operational_cashflow_topup_details', 'operational_cashflow_bank_admin_details', 'operational_cashflow_livestock_purchase_details', 'operational_cashflow_other_details', 'operational_cashflow_health_details', 'operational_cashflow_utility_details',
        'operational_cashflow_salary_details', 'operational_cashflow_feed_details', 'operational_cashflow_payments', 'operational_cashflows', 'financial_opening_balances',
        'population_adjustments', 'inventory_adjustments', 'inventory_opening_balances',
        'egg_harvests', 'feed_usages', 'daily_records', 'customers', 'feed_types', 'livestock_groups', 'employees',
    ];

    private const FEEDS = [
        ['species' => 'AYAM', 'name' => 'Grower', 'date' => '2026-06-04'],
        ['species' => 'AYAM', 'name' => 'Layer', 'date' => '2026-07-05'],
        ['species' => 'LELE', 'name' => '781-2', 'date' => '2026-05-28'],
        ['species' => 'LELE', 'name' => '781-3', 'date' => '2026-09-20'],
        ['species' => 'LELE', 'name' => 'Alternative', 'date' => '2026-10-11'],
    ];

    private const GROUPS = [
        ['species' => 'AYAM', 'code' => 'AYAM-PETELUR', 'name' => 'Ayam Petelur', 'start_date' => '2026-06-04', 'initial_population' => 511, 'location' => 'Kandang Ayam', 'status' => 'ACTIVE', 'closed_at' => null],
        ['species' => 'LELE', 'code' => 'LELE-1-C1', 'name' => 'Lele #1 C1', 'start_date' => '2026-05-28', 'initial_population' => 6000, 'location' => 'Kolam C1', 'status' => 'CLOSED', 'closed_at' => '2026-09-25'],
        ['species' => 'LELE', 'code' => 'LELE-1-C2', 'name' => 'Lele #1 C2', 'start_date' => '2026-05-28', 'initial_population' => 6000, 'location' => 'Kolam C2', 'status' => 'CLOSED', 'closed_at' => '2026-09-25'],
        ['species' => 'LELE', 'code' => 'LELE-2-C1', 'name' => 'Lele #2 C1', 'start_date' => '2026-09-17', 'initial_population' => 10000, 'location' => 'Kolam C1', 'status' => 'ACTIVE', 'closed_at' => null],
        ['species' => 'LELE', 'code' => 'LELE-2-C1-KERAMBA', 'name' => 'Lele #2 C1 Keramba', 'start_date' => '2026-09-25', 'initial_population' => 4845, 'location' => 'Keramba C1', 'status' => 'ACTIVE', 'closed_at' => null],
        ['species' => 'LELE', 'code' => 'LELE-2-C2', 'name' => 'Lele #2 C2', 'start_date' => '2026-09-17', 'initial_population' => 8500, 'location' => 'Kolam C2', 'status' => 'ACTIVE', 'closed_at' => null],
    ];

    private const CUSTOMERS = [
        'alex' => 'Alex', 'andi' => 'Andi', 'anya' => 'Anya', 'avi' => 'Avi', 'barokah' => 'Barokah',
        'erland' => 'Erland', 'eva' => 'Eva', 'inara' => 'Inara', 'isti' => 'Isti', 'kuwat' => 'Kuwat',
        'lucky' => 'Lucky', 'mat' => 'Mat', 'megana' => 'Megana', 'najwa' => 'Najwa', 'nasgor' => 'Nasgor',
        'rega' => 'Rega', 'ryy' => 'Ryy', 'sabar' => 'Sabar', 'siti' => 'Siti', 'slamet' => 'Slamet',
        'tangin' => 'Tangin', 'tia' => 'Tia', 'umi' => 'Umi', 'unknown' => 'Unknown', 'yuli' => 'Yuli',
    ];

    private const EXPECTED = [
        'employees' => 2, 'customers' => 25, 'feed_types' => 5, 'livestock_groups' => 6,
        'daily_records' => 443, 'feed_usages' => 435, 'egg_harvests' => 213,
        'expenses' => 439, 'expense_total_idr' => 305231115, 'paid_expenses' => 435,
        'paid_expense_total_idr' => 300621115, 'unpaid_expenses' => 4, 'unpaid_expense_total_idr' => 4610000,
        'revenues' => 228, 'revenue_total_idr' => 305542864, 'paid_revenues' => 220,
        'paid_revenue_total_idr' => 300917864, 'unpaid_revenues' => 8, 'unpaid_revenue_total_idr' => 4625000,
        'inventory_opening_balances' => 4, 'inventory_adjustments' => 34, 'population_adjustments' => 1,
        'cash_balance_idr' => 296749,
    ];

    public function __construct(private readonly LegacyWorkbookReader $reader, private readonly Balances $balances) {}

    /** @return array<string,mixed> */
    public function buildPlan(string $path): array
    {
        $workbook = $this->reader->read($path);
        $plan = [
            'workbook_hash' => $workbook['hash'], 'cutoff' => self::CUTOFF,
            'expenses' => [], 'revenues' => [], 'daily_records' => [],
            'openings' => $this->openings(), 'adjustments' => $this->fixedAdjustments(),
            'population_adjustments' => [[
                'group' => 'LELE-2-C1', 'date' => '2026-09-25', 'delta' => -4845,
                'reason' => 'Pemindahan 4.845 ekor dari C1 ke grup C1 Keramba.', 'sheet' => 'Record Lele #2', 'row' => 12,
            ]],
            'ignored' => [], 'corrections' => $this->fixedCorrections(), 'samplings' => [],
            'warnings' => [
                'Cash Flow - Pemasukan row 190 tidak memiliki nilai J; importer memakai koreksi eksplisit Rp45,000 dari I × F.',
                'Pembelian pakan lele row 491 tetap tercatat sebagai expense, tetapi received_at null karena belum muncul pada ledger stok sampai cutoff.',
                'Sampling lele disimpan dalam report/audit saja karena bentuk source tidak memenuhi schema tiga measurement × 10 ekor.',
                'Ledger sumber pakan memang sempat negatif sebelum pembelian berikutnya: Layer pada 30–31 Agustus dan 6 September; 781-2 pada 17 Agustus, 1 September, dan 5 September. Import mempertahankan tanggal source dan memvalidasi saldo akhir.',
            ],
        ];

        $this->mapExpenses($workbook, $plan);
        $this->mapIncome($workbook, $plan);
        $this->mapChicken($workbook, $plan);
        $this->mapOldCatfish($workbook, $plan);
        $this->mapNewCatfish($workbook, $plan);
        $this->allocateCatfishFeed($plan);
        $this->assertCustomers($plan);
        $plan['summary'] = $this->summarize($plan);
        $this->assertExpected($plan['summary']);

        return $plan;
    }

    public function resolveActor(?string $email): User
    {
        $query = User::query()->where('role', 'SUPERADMIN')->where('is_active', true);
        if ($email !== null && trim($email) !== '') {
            $actor = $query->where('email', trim($email))->first();
            if (! $actor) {
                throw new RuntimeException("SUPERADMIN aktif dengan email {$email} tidak ditemukan.");
            }

            return $actor;
        }

        $actors = $query->get();
        if ($actors->count() !== 1) {
            throw new RuntimeException('Tanpa --actor-email harus ada tepat satu SUPERADMIN aktif; ditemukan '.$actors->count().'.');
        }

        return $actors->first();
    }

    /** @return array<string,int> */
    public function tableCounts(): array
    {
        $counts = [];
        foreach (self::TRUNCATE_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /** @param array<string,mixed> $plan
     *  @return array<string,mixed>
     */
    public function commit(array $plan, User $actor, bool $replace = false): array
    {
        if (! $replace) {
            throw new RuntimeException('Commit penggantian data wajib menggunakan opsi --replace.');
        }

        $executedAt = now();
        $mappings = [];
        $databaseChecks = [];
        $preTruncateCounts = [];

        DB::transaction(function () use ($plan, $actor, $executedAt, &$mappings, &$databaseChecks, &$preTruncateCounts): void {
            DB::select('select pg_advisory_xact_lock(?)', [2026101201]);
            $preTruncateCounts = $this->tableCounts();
            DB::statement('TRUNCATE TABLE '.implode(', ', self::TRUNCATE_TABLES).' RESTART IDENTITY');

            $feedIds = $this->insertFeedTypes($actor, $executedAt, $mappings);
            $employeeIds = $this->insertEmployees($actor, $executedAt, $mappings);
            $groupIds = $this->insertGroups($actor, $executedAt, $mappings);
            $customerIds = $this->insertCustomers($actor, $executedAt, $mappings);

            $this->insertOpenings($plan, $actor, $executedAt, $feedIds, $mappings);
            $this->insertAdjustments($plan, $actor, $executedAt, $feedIds, $mappings);
            $this->insertPopulationAdjustments($plan, $actor, $executedAt, $groupIds, $mappings);
            $this->insertExpenses($plan, $actor, $executedAt, $feedIds, $employeeIds, $mappings);
            $this->insertRevenues($plan, $actor, $executedAt, $customerIds, $mappings);
            $this->insertDailyRecords($plan, $actor, $executedAt, $feedIds, $groupIds, $mappings);

            $this->audit($actor->id, 'legacy_import_run', 0, $executedAt, 'Ringkasan ignored rows, sampling, koreksi, dan workbook final 12 Oktober.', [
                'workbook_hash' => $plan['workbook_hash'], 'cutoff' => $plan['cutoff'],
                'ignored' => $plan['ignored'], 'samplings' => $plan['samplings'],
                'corrections' => $plan['corrections'], 'summary' => $plan['summary'],
            ]);

            $databaseChecks = $this->databaseChecks($feedIds, $groupIds);
            $this->assertDatabaseChecks($databaseChecks);
        }, 1);

        return [
            'executed_at' => $executedAt->toIso8601String(), 'mappings' => $mappings,
            'balance_checks' => $databaseChecks, 'pre_truncate_counts' => $preTruncateCounts,
        ];
    }

    /** @param array<string,mixed> $workbook @param array<string,mixed> $plan */
    private function mapExpenses(array $workbook, array &$plan): void
    {
        $sheet = 'Cash Flow - Pengeluaran';
        $rows = $workbook['sheets'][$sheet];
        if (trim($rows[281]['A'] ?? '') !== 'Operational Started') {
            throw new RuntimeException('Marker Operational Started harus berada pada Cash Flow - Pengeluaran row 281.');
        }

        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber < 4 || $rowNumber === 281) {
                continue;
            }
            $incoming = $this->money($row['B'] ?? '');
            $outgoing = $this->number($row['C'] ?? '');
            if ($incoming === 0 && abs($outgoing) < 0.0000001) {
                continue;
            }
            $date = $this->excelDate($row['A'] ?? '', "{$sheet} row {$rowNumber}");
            if ($date > self::CUTOFF) {
                $plan['ignored'][] = $this->ignored($sheet, $rowNumber, $date, 'Tanggal setelah cutoff inklusif.', $row);
                continue;
            }

            $description = $this->clean($row['F'] ?? '');
            $source = $this->source($workbook, $sheet, $rowNumber, ['A', 'B', 'C', 'E', 'F', 'G', 'H']);
            if ($incoming > 0) {
                $plan['revenues'][] = [
                    'sheet' => $sheet, 'row' => $rowNumber, 'date' => $date, 'type' => 'MAIN_ACCOUNT_TOPUP',
                    'amount' => $incoming, 'status' => 'PAID', 'customer' => null,
                    'detail' => ['notes' => $this->sourceNote($sheet, $rowNumber, $description)], 'source' => $source,
                ];
            }

            if ($outgoing < 0) {
                if ($rowNumber !== 274 || $this->money((string) abs($outgoing)) !== 425000) {
                    throw new RuntimeException("Cash-out negatif tidak dikenal pada {$sheet} row {$rowNumber}.");
                }
                $plan['revenues'][] = [
                    'sheet' => $sheet, 'row' => $rowNumber, 'date' => $date, 'type' => 'OTHER',
                    'amount' => 425000, 'status' => 'PAID', 'customer' => null,
                    'detail' => ['notes' => $this->sourceNote($sheet, $rowNumber, 'kembalikan toren')], 'source' => $source,
                ];
                continue;
            }

            $amount = $this->money((string) $outgoing);
            if ($amount <= 0) {
                continue;
            }
            if ($rowNumber < 281) {
                $type = 'OTHER';
                $detail = ['description' => $description !== '' ? $description : "Transaksi legacy row {$rowNumber}"];
                $bucket = 'OTHER PRE_OPERATIONAL';
            } else {
                [$type, $detail] = $this->classifyOperationalExpense($description, $row['E'] ?? '', $amount, $rowNumber, $date);
                $bucket = $type === 'OTHER' ? 'OTHER POST_OPERATIONAL' : $type.($type === 'HEALTH' ? ' '.$detail['species'] : '');
            }
            $status = str_contains(mb_strtolower($this->clean($row['G'] ?? '')), 'unpaid') ? 'UNPAID' : 'PAID';
            $plan['expenses'][] = [
                'sheet' => $sheet, 'row' => $rowNumber, 'date' => $date, 'type' => $type, 'amount' => $amount,
                'status' => $status, 'bucket' => $bucket, 'detail' => $detail, 'source' => $source,
            ];
        }
    }

    /** @return array{string,array<string,mixed>} */
    private function classifyOperationalExpense(string $description, string $quantity, int $amount, int $row, string $date): array
    {
        $normalized = mb_strtolower($description);
        if (str_contains($normalized, 'pakan ayam') || $normalized === 'pakan 5 sak') {
            $feed = $row <= 356 || str_contains($normalized, 'grower') ? 'Grower' : 'Layer';
            $receivedDate = $row === 357 ? '2026-07-05' : $date;

            return ['FEED_AYAM', $this->feedExpenseDetail($feed, $quantity, $amount, 50, false, $row, $receivedDate)];
        }
        if (str_contains($normalized, 'pakan lele') || str_contains($normalized, 'pelet lele')) {
            $feed = str_contains($normalized, 'alternative') ? 'Alternative' : (str_contains($normalized, '781-3') ? '781-3' : '781-2');
            $receivedDate = match ($row) { 488 => '2026-10-11', 491 => null, default => $date };

            return ['FEED_LELE', $this->feedExpenseDetail($feed, $quantity, $amount, 30, true, $row, $receivedDate)];
        }
        if (str_starts_with($normalized, 'gaji ') && (str_contains($normalized, 'alex') || str_contains($normalized, 'tangin'))) {
            return ['SALARY', [
                'employee' => str_contains($normalized, 'alex') ? 'Alex' : 'Tangin',
                'salary_period' => mb_substr($description, 0, 40),
            ]];
        }
        if ((str_contains($normalized, 'listrik') && ! str_contains($normalized, 'material listrik')) || str_contains($normalized, 'wifi')) {
            return ['UTILITY', ['description' => $description]];
        }
        if ($this->containsAny($normalized, ['em4', 'introfolk', 'inroflox', 'infroflox', 'blue core'])) {
            return ['HEALTH', ['species' => 'LELE', 'description' => $description]];
        }
        if (! $this->containsAny($normalized, ['fee', 'cemilan', 'rokok']) && $this->containsAny($normalized, ['vaksin', 'levamid', 'fortevit', 'vitastress', 'vita stress', 'obat ayam', 'obat cacing'])) {
            return ['HEALTH', ['species' => 'AYAM', 'description' => $description]];
        }

        return ['OTHER', ['description' => $description !== '' ? $description : "Transaksi legacy row {$row}"]];
    }

    /** @return array<string,mixed> */
    private function feedExpenseDetail(string $feed, string $rawQuantity, int $amount, int $sackKg, bool $defaultOneSack, int $row, ?string $receivedDate): array
    {
        $rawQuantity = trim($rawQuantity);
        if ($row === 488) {
            $kg = 60.0;
        } elseif ($row === 462) {
            $kg = 6.0;
        } elseif ($rawQuantity === '') {
            if (! $defaultOneSack) {
                throw new RuntimeException("Qty pakan kosong pada Cash Flow - Pengeluaran row {$row}.");
            }
            $kg = (float) $sackKg;
        } else {
            $numeric = $this->number($rawQuantity);
            $kg = stripos($rawQuantity, 'kg') !== false ? $numeric : $numeric * $sackKg;
        }
        if ($kg <= 0) {
            throw new RuntimeException("Qty pakan tidak valid pada Cash Flow - Pengeluaran row {$row}.");
        }
        $quantity = $this->decimal($kg);

        return [
            'feed' => $feed, 'quantity_kg' => $quantity,
            'price_per_kg' => $this->divideRound((string) $amount, $quantity, 3),
            'received_date' => $receivedDate,
        ];
    }

    /** @param array<string,mixed> $workbook @param array<string,mixed> $plan */
    private function mapIncome(array $workbook, array &$plan): void
    {
        $sheet = 'Cash Flow - Pemasukan & Telur K';
        foreach ($workbook['sheets'][$sheet] as $rowNumber => $row) {
            if ($rowNumber < 4 || trim($row['A'] ?? '') === '' || trim($row['B'] ?? '') === '') {
                continue;
            }
            $date = $this->excelDate($row['A'], "{$sheet} row {$rowNumber}");
            if ($date > self::CUTOFF) {
                $plan['ignored'][] = $this->ignored($sheet, $rowNumber, $date, 'Tanggal setelah cutoff inklusif.', $row);
                continue;
            }
            $transaction = trim($row['B']);
            $source = $this->source($workbook, $sheet, $rowNumber, range('A', 'M'));

            if ($transaction === 'Penjualan Telur') {
                $status = match (trim($row['C'] ?? '')) {
                    'Ya' => 'PAID', 'Tidak' => 'UNPAID',
                    default => throw new RuntimeException("Status lunas tidak valid pada {$sheet} row {$rowNumber}."),
                };
                if ($rowNumber === 190) {
                    if (trim($row['J'] ?? '') !== '') {
                        throw new RuntimeException('Koreksi eksplisit row 190 hanya berlaku ketika J190 kosong.');
                    }
                    $amount = $this->money((string) ($this->number($row['I'] ?? '') * $this->number($row['F'] ?? '')));
                    if ($amount !== 45000) {
                        throw new RuntimeException('Hasil koreksi Cash Flow - Pemasukan row 190 harus Rp45,000.');
                    }
                } else {
                    $this->assertFormulaCache($workbook, $sheet, $rowNumber, 'J');
                    $amount = $this->money($row['J'] ?? '');
                }
                $customer = $this->canonicalCustomer($row['E'] ?? '', $rowNumber);
                $metadata = [
                    'pembeli' => $customer, 'harga_per_kg' => $this->clean($row['F'] ?? ''),
                    'berat_round_kg' => $this->clean($row['I'] ?? ''), 'catatan' => $this->clean($row['M'] ?? ''),
                    'sumber' => "{$sheet} row {$rowNumber}",
                ];
                if ($rowNumber === 190) {
                    $metadata['explicit_correction'] = 'J190 kosong; Rp45,000 dihitung dari I190 2.0 × F190 Rp22,500.';
                }
                $plan['revenues'][] = [
                    'sheet' => $sheet, 'row' => $rowNumber, 'date' => $date, 'type' => 'EGG_SALE',
                    'amount' => $amount, 'status' => $status, 'customer' => $customer,
                    'detail' => [
                        'category' => $this->eggCategory($row['D'] ?? '', $rowNumber),
                        'pieces' => (int) round($this->number($row['G'] ?? ''), 0, PHP_ROUND_HALF_UP),
                        'weight_kg' => $this->decimal($row['H'] ?? ''),
                        'price_per_kg' => $this->decimal($row['F'] ?? ''),
                        'notes' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ],
                    'source' => $source,
                ];
            } elseif ($transaction === 'Hibah Telur' || $transaction === 'Koreksi Stok') {
                $plan['adjustments'][] = [
                    'sheet' => $sheet, 'row' => $rowNumber, 'kind' => 'EGG',
                    'item' => $this->eggCategory($row['D'] ?? '', $rowNumber), 'date' => $date,
                    'kg' => $this->decimal(-$this->number($row['H'] ?? '')),
                    'pieces' => (int) round(-$this->number($row['G'] ?? ''), 0, PHP_ROUND_HALF_UP),
                    'reason' => $transaction === 'Hibah Telur'
                        ? "Hibah telur tanpa pendapatan; {$sheet} row {$rowNumber}."
                        : "Angka Koreksi memakai tanda kebalikan formula workbook; {$sheet} row {$rowNumber}.",
                    'source' => $source,
                ];
            } elseif ($transaction === 'Transfer ke Rek Utama') {
                $plan['expenses'][] = [
                    'sheet' => $sheet, 'row' => $rowNumber, 'date' => $date, 'type' => 'MAIN_ACCOUNT_TRANSFER',
                    'amount' => $this->money($row['K'] ?? ''), 'status' => 'PAID', 'bucket' => 'MAIN_ACCOUNT_TRANSFER',
                    'detail' => ['description' => $this->sourceNote($sheet, $rowNumber, $this->clean($row['M'] ?? ''))],
                    'source' => $source,
                ];
            } elseif ($transaction === 'Penjualan Pakan') {
                $customer = $this->canonicalCustomer($row['E'] ?? '', $rowNumber);
                $plan['revenues'][] = [
                    'sheet' => $sheet, 'row' => $rowNumber, 'date' => $date, 'type' => 'OTHER',
                    'amount' => $this->money($row['J'] ?? ''), 'status' => 'PAID', 'customer' => $customer,
                    'detail' => ['notes' => $this->sourceNote($sheet, $rowNumber, 'Penjualan pakan Layer 15.596 kg.')],
                    'source' => $source,
                ];
                $plan['adjustments'][] = [
                    'sheet' => $sheet, 'row' => $rowNumber, 'kind' => 'FEED', 'item' => 'Layer',
                    'date' => $date, 'kg' => '-15.596', 'pieces' => null,
                    'reason' => "Pengurangan stok Layer untuk penjualan pakan; {$sheet} row {$rowNumber}.", 'source' => $source,
                ];
            } elseif ($transaction === 'Penjualan Lele') {
                $customer = $this->canonicalCustomer($row['E'] ?? '', $rowNumber);
                $plan['revenues'][] = [
                    'sheet' => $sheet, 'row' => $rowNumber, 'date' => $date, 'type' => 'FISH_SALE',
                    'amount' => $this->money($row['J'] ?? ''), 'status' => 'PAID', 'customer' => $customer,
                    'detail' => [
                        'weight_kg' => $this->decimal($row['H'] ?? ''),
                        'price_per_kg' => $this->decimal($row['F'] ?? ''),
                        'notes' => 'Penjualan hasil panen Record Lele #1 - CLOSED PANEN row 124 pada 2026-09-25; revenue source row 174.',
                    ],
                    'source' => $source,
                ];
            } else {
                throw new RuntimeException("Jenis transaksi tidak dikenal pada {$sheet} row {$rowNumber}: {$transaction}.");
            }
        }
    }

    /** @param array<string,mixed> $workbook @param array<string,mixed> $plan */
    private function mapChicken(array $workbook, array &$plan): void
    {
        $sheet = 'Record Ayam';
        foreach ($workbook['sheets'][$sheet] as $rowNumber => $row) {
            if ($rowNumber < 4 || trim($row['A'] ?? '') === '') {
                continue;
            }
            if (! is_numeric(trim($row['A']))) {
                if ($rowNumber === 64 && trim($row['A']) === 'NEW FORMULA') {
                    $plan['ignored'][] = ['sheet' => $sheet, 'row' => $rowNumber, 'date' => null, 'reason' => 'Marker reset formula, bukan daily record.', 'raw' => $row];
                    continue;
                }
                throw new RuntimeException("Tanggal Excel tidak valid pada {$sheet} row {$rowNumber}: '{$row['A']}'.");
            }
            $date = $this->excelDate($row['A'], "{$sheet} row {$rowNumber}");
            if ($date < '2026-06-04' || $date > self::CUTOFF) {
                $plan['ignored'][] = $this->ignored($sheet, $rowNumber, $date, 'Tanggal di luar periode import ayam.', $row);
                continue;
            }

            $morning = $this->decimal($row['H'] ?? '');
            $noon = $this->decimal($row['I'] ?? '');
            $total = Decimal::add($morning, $noon);
            if (Decimal::compare($total, '0') <= 0) {
                throw new RuntimeException("Konsumsi ayam harus positif pada {$sheet} row {$rowNumber}.");
            }
            $eggs = [];
            if ($date >= '2026-08-03') {
                $rejectKg = match ($rowNumber) {
                    76 => '1.504', 77 => '1.604', 134 => '0.498',
                    default => $this->decimal($row['R'] ?? ''),
                };
                $eggs = [
                    ['category' => 'PECAH', 'pieces' => $this->integer($row['O'] ?? ''), 'kg' => $this->decimal($row['P'] ?? '')],
                    ['category' => 'REJECT', 'pieces' => $this->integer($row['Q'] ?? ''), 'kg' => $rejectKg],
                    ['category' => 'UTUH', 'pieces' => $this->integer($row['U'] ?? ''), 'kg' => $this->decimal($row['V'] ?? '')],
                ];
            } else {
                $plan['ignored'][] = $this->ignored($sheet, $rowNumber, $date, 'Produksi telur pra-3 Agustus tidak dapat dipisah category weight; tercakup opening telur.', array_intersect_key($row, array_flip(['N', 'O', 'P', 'Q', 'R', 'U', 'V'])));
            }

            $plan['daily_records'][] = [
                'sheet' => $sheet, 'row' => $rowNumber, 'group' => 'AYAM-PETELUR', 'date' => $date,
                'mortality' => $this->integer($row['C'] ?? ''),
                'notes' => $this->labeledNotes(['Populasi' => $row['F'] ?? '', 'Pakan' => $row['M'] ?? '', 'Harian' => $row['AF'] ?? '']),
                'feeds' => [[
                    'feed' => $date <= '2026-07-04' ? 'Grower' : 'Layer',
                    'morning' => $morning, 'noon' => $noon, 'evening' => '0.000', 'total' => $total,
                ]],
                'eggs' => $eggs,
                'source' => $this->source($workbook, $sheet, $rowNumber, ['A', 'C', 'F', 'H', 'I', 'M', 'O', 'P', 'Q', 'R', 'U', 'V', 'AF']),
            ];
        }
    }

    /** @param array<string,mixed> $workbook @param array<string,mixed> $plan */
    private function mapOldCatfish(array $workbook, array &$plan): void
    {
        $sheet = 'Record Lele #1 - CLOSED PANEN';
        foreach ($workbook['sheets'][$sheet] as $rowNumber => $row) {
            if ($rowNumber < 4 || trim($row['A'] ?? '') === '') {
                continue;
            }
            $date = $this->excelDate($row['A'], "{$sheet} row {$rowNumber}");
            if ($date < '2026-05-28' || $date > '2026-09-25') {
                $reason = $date > self::CUTOFF ? 'Tanggal setelah cutoff inklusif.' : 'Template setelah panen/closure 25 September.';
                $plan['ignored'][] = $this->ignored($sheet, $rowNumber, $date, $reason, $row);
                continue;
            }
            foreach ([
                ['group' => 'LELE-1-C1', 'mortality' => 'C', 'morning' => 'K', 'evening' => 'L', 'priority' => 1],
                ['group' => 'LELE-1-C2', 'mortality' => 'G', 'morning' => 'N', 'evening' => 'O', 'priority' => 2],
            ] as $mapping) {
                $morning = $this->decimal($row[$mapping['morning']] ?? '');
                $evening = $this->decimal($row[$mapping['evening']] ?? '');
                $feeds = Decimal::compare(Decimal::add($morning, $evening), '0') > 0 ? [[
                    'feed' => '__LELE_FIFO__', 'morning' => $morning, 'noon' => '0.000', 'evening' => $evening,
                    'total' => Decimal::add($morning, $evening), 'priority' => $mapping['priority'],
                ]] : [];
                $plan['daily_records'][] = [
                    'sheet' => $sheet, 'row' => $rowNumber, 'group' => $mapping['group'], 'date' => $date,
                    'mortality' => $this->integer($row[$mapping['mortality']] ?? ''),
                    'notes' => $this->clean($row['X'] ?? '') ?: null, 'feeds' => $feeds, 'eggs' => [],
                    'source' => $this->source($workbook, $sheet, $rowNumber, ['A', 'C', 'G', 'K', 'L', 'N', 'O', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X']),
                ];
            }
            foreach (['S', 'T'] as $column) {
                if ($this->clean($row[$column] ?? '') !== '') {
                    $plan['samplings'][] = ['sheet' => $sheet, 'row' => $rowNumber, 'date' => $date, 'cell' => $column.$rowNumber, 'value' => $row[$column], 'reason' => 'Schema sampling tidak kompatibel dengan source 8 ekor.'];
                }
            }
        }
    }

    /** @param array<string,mixed> $workbook @param array<string,mixed> $plan */
    private function mapNewCatfish(array $workbook, array &$plan): void
    {
        $sheet = 'Record Lele #2';
        foreach ($workbook['sheets'][$sheet] as $rowNumber => $row) {
            if ($rowNumber < 4 || trim($row['A'] ?? '') === '') {
                continue;
            }
            $date = $this->excelDate($row['A'], "{$sheet} row {$rowNumber}");
            if ($date < '2026-09-17' || $date > self::CUTOFF) {
                $plan['ignored'][] = $this->ignored($sheet, $rowNumber, $date, 'Tanggal di luar periode import lele #2.', $row);
                continue;
            }

            $sourceC1 = [
                'morning' => $this->decimal($row['O'] ?? ''),
                'noon' => $this->decimal($row['P'] ?? ''),
                'evening' => $this->decimal($row['Q'] ?? ''),
            ];
            $c1Feeds = [];
            $kerambaFeeds = [];
            if ($date < '2026-09-25') {
                $c1Feeds = $this->fifoFeed($sourceC1, 3);
            } else {
                $mainPopulation = $this->number($row['E'] ?? '');
                $kerambaPopulation = $this->number($row['I'] ?? '');
                if ($mainPopulation <= 0 || $kerambaPopulation <= 0) {
                    throw new RuntimeException("Populasi pembagi C1/Keramba tidak valid pada {$sheet} row {$rowNumber}.");
                }
                $main = [];
                $keramba = [];
                foreach (['morning', 'noon', 'evening'] as $period) {
                    $main[$period] = $this->decimalRound($this->number($sourceC1[$period]) * $mainPopulation / ($mainPopulation + $kerambaPopulation));
                    $keramba[$period] = Decimal::sub($sourceC1[$period], $main[$period]);
                }
                $c1Feeds = $this->fifoFeed($main, 3);
                $kerambaFeeds = $this->fifoFeed($keramba, 4);
            }

            $c2Slots = [
                'morning' => $this->decimal($row['S'] ?? ''),
                'noon' => $this->decimal($row['T'] ?? ''),
                'evening' => $this->decimal($row['U'] ?? ''),
            ];
            $commonSource = $this->source($workbook, $sheet, $rowNumber, ['A', 'C', 'E', 'G', 'I', 'K', 'M', 'O', 'P', 'Q', 'S', 'T', 'U', 'W', 'X', 'Y', 'Z', 'AA', 'AB']);
            $plan['daily_records'][] = [
                'sheet' => $sheet, 'row' => $rowNumber, 'group' => 'LELE-2-C1', 'date' => $date,
                'mortality' => $this->integer($row['C'] ?? ''), 'notes' => $this->clean($row['Y'] ?? '') ?: null,
                'feeds' => $c1Feeds, 'eggs' => [], 'source' => $commonSource,
            ];
            if ($date >= '2026-09-25') {
                $plan['daily_records'][] = [
                    'sheet' => $sheet, 'row' => $rowNumber, 'group' => 'LELE-2-C1-KERAMBA', 'date' => $date,
                    'mortality' => $this->integer($row['G'] ?? ''), 'notes' => $this->clean($row['Y'] ?? '') ?: null,
                    'feeds' => $kerambaFeeds, 'eggs' => [], 'source' => $commonSource,
                ];
            }
            $plan['daily_records'][] = [
                'sheet' => $sheet, 'row' => $rowNumber, 'group' => 'LELE-2-C2', 'date' => $date,
                'mortality' => $this->integer($row['K'] ?? ''), 'notes' => $this->clean($row['Y'] ?? '') ?: null,
                'feeds' => $this->fifoFeed($c2Slots, 5), 'eggs' => [], 'source' => $commonSource,
            ];

            foreach (['Z', 'AA', 'AB'] as $column) {
                if ($this->clean($row[$column] ?? '') !== '') {
                    $plan['samplings'][] = ['sheet' => $sheet, 'row' => $rowNumber, 'date' => $date, 'cell' => $column.$rowNumber, 'value' => $row[$column], 'reason' => 'Source hanya satu measurement; schema mewajibkan tiga measurement × 10 ekor.'];
                }
            }
        }
    }

    /** @param array{morning:string,noon:string,evening:string} $slots
     *  @return list<array<string,mixed>>
     */
    private function fifoFeed(array $slots, int $priority): array
    {
        $total = Decimal::add(Decimal::add($slots['morning'], $slots['noon']), $slots['evening']);
        if (Decimal::compare($total, '0') <= 0) {
            return [];
        }

        return [[
            'feed' => '__LELE_FIFO__', 'morning' => $slots['morning'], 'noon' => $slots['noon'],
            'evening' => $slots['evening'], 'total' => $total, 'priority' => $priority,
        ]];
    }

    /** @param array<string,mixed> $plan */
    private function allocateCatfishFeed(array &$plan): void
    {
        $events = [];
        foreach ($plan['daily_records'] as $recordIndex => $record) {
            foreach ($record['feeds'] as $feedIndex => $feed) {
                if ($feed['feed'] !== '__LELE_FIFO__') {
                    continue;
                }
                foreach (['morning' => 1, 'noon' => 2, 'evening' => 3] as $period => $periodPriority) {
                    if (Decimal::compare($feed[$period], '0') > 0) {
                        $events[] = [
                            'record' => $recordIndex, 'feed_index' => $feedIndex, 'date' => $record['date'],
                            'priority' => $feed['priority'], 'period' => $period, 'period_priority' => $periodPriority,
                            'amount' => $feed[$period],
                        ];
                    }
                }
            }
        }
        usort($events, fn (array $a, array $b): int => [$a['date'], $a['priority'], $a['period_priority']] <=> [$b['date'], $b['priority'], $b['period_priority']]);

        foreach ($plan['daily_records'] as &$record) {
            $record['feeds'] = array_values(array_filter($record['feeds'], fn (array $feed): bool => $feed['feed'] !== '__LELE_FIFO__'));
        }
        unset($record);

        $preAlternativeUsed = '0.000';
        foreach ($events as $event) {
            if ($event['date'] >= '2026-10-11') {
                $this->addFeedAllocation($plan['daily_records'][$event['record']]['feeds'], 'Alternative', $event['period'], $event['amount']);
                continue;
            }

            $remaining = $event['amount'];
            while (Decimal::compare($remaining, '0') > 0) {
                if (Decimal::compare($preAlternativeUsed, '576.000') < 0) {
                    $feed = '781-2';
                    $boundary = '576.000';
                } elseif (Decimal::compare($preAlternativeUsed, '606.000') < 0) {
                    $feed = '781-3';
                    $boundary = '606.000';
                } else {
                    $feed = '781-2';
                    $boundary = null;
                }
                $chunk = $remaining;
                if ($boundary !== null) {
                    $capacity = Decimal::sub($boundary, $preAlternativeUsed);
                    if (Decimal::compare($chunk, $capacity) > 0) {
                        $chunk = $capacity;
                    }
                }
                $this->addFeedAllocation($plan['daily_records'][$event['record']]['feeds'], $feed, $event['period'], $chunk);
                $preAlternativeUsed = Decimal::add($preAlternativeUsed, $chunk);
                $remaining = Decimal::sub($remaining, $chunk);
            }
        }

        if ($preAlternativeUsed !== '672.900') {
            throw new RuntimeException("Total konsumsi lele sebelum Alternative tidak sesuai: {$preAlternativeUsed} kg.");
        }
    }

    /** @param list<array<string,mixed>> $feeds */
    private function addFeedAllocation(array &$feeds, string $feedName, string $period, string $amount): void
    {
        foreach ($feeds as &$feed) {
            if ($feed['feed'] === $feedName) {
                $feed[$period] = Decimal::add($feed[$period], $amount);
                $feed['total'] = Decimal::add($feed['total'], $amount);

                return;
            }
        }
        unset($feed);
        $row = ['feed' => $feedName, 'morning' => '0.000', 'noon' => '0.000', 'evening' => '0.000', 'total' => '0.000'];
        $row[$period] = $amount;
        $row['total'] = $amount;
        $feeds[] = $row;
    }

    /** @return list<array<string,mixed>> */
    private function openings(): array
    {
        return [
            ['kind' => 'FEED', 'item' => '781-2', 'date' => '2026-09-17', 'kg' => '60.000', 'pieces' => null, 'reason' => 'Opening stok siklus lele #2 dari Record Lele #2 W4.'],
            ['kind' => 'EGG', 'item' => 'UTUH', 'date' => '2026-08-02', 'kg' => '11.937', 'pieces' => 237, 'reason' => 'Opening telur legacy sebelum penjualan pertama.'],
            ['kind' => 'EGG', 'item' => 'PECAH', 'date' => '2026-08-02', 'kg' => '1.400', 'pieces' => 33, 'reason' => 'Opening telur legacy sebelum penjualan pertama.'],
            ['kind' => 'EGG', 'item' => 'REJECT', 'date' => '2026-08-02', 'kg' => '1.888', 'pieces' => 39, 'reason' => 'Opening telur legacy sebelum penjualan pertama.'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function fixedAdjustments(): array
    {
        return [
            ['kind' => 'FEED', 'item' => 'Grower', 'date' => '2026-07-05', 'kg' => '-291.200', 'pieces' => null, 'reason' => 'Sisa Grower dialihkan ke Layer.', 'sheet' => 'Record Ayam', 'row' => 35],
            ['kind' => 'FEED', 'item' => 'Layer', 'date' => '2026-07-05', 'kg' => '291.200', 'pieces' => null, 'reason' => 'Penerimaan realokasi sisa Grower.', 'sheet' => 'Record Ayam', 'row' => 35],
            ['kind' => 'FEED', 'item' => 'Layer', 'date' => '2026-08-03', 'kg' => '48.000', 'pieces' => null, 'reason' => 'Reset NEW FORMULA pada ledger ayam.', 'sheet' => 'Record Ayam', 'row' => 65],
            ['kind' => 'FEED', 'item' => 'Layer', 'date' => '2026-08-15', 'kg' => '-50.000', 'pieces' => null, 'reason' => 'Expense 400 kg, tetapi stok source bertambah 350 kg.', 'sheet' => 'Record Ayam', 'row' => 77],
            ['kind' => 'FEED', 'item' => '781-2', 'date' => '2026-09-20', 'kg' => '-60.000', 'pieces' => null, 'reason' => 'Bridge opening siklus #2 dan pembelian financial agar tidak dihitung ganda.', 'sheet' => 'Record Lele #2', 'row' => 7],
            ['kind' => 'FEED', 'item' => '781-2', 'date' => '2026-10-05', 'kg' => '18.700', 'pieces' => null, 'reason' => 'Koreksi stok berdasarkan actual di kandang.', 'sheet' => 'Record Lele #2', 'row' => 22],
            ['kind' => 'EGG', 'item' => 'UTUH', 'date' => '2026-08-21', 'kg' => '0.283', 'pieces' => 0, 'reason' => 'Buffer legacy sementara agar stok UTUH nonnegative.'],
            ['kind' => 'EGG', 'item' => 'REJECT', 'date' => '2026-08-22', 'kg' => '0.000', 'pieces' => 2, 'reason' => 'Buffer legacy sementara agar stok REJECT nonnegative.'],
            ['kind' => 'EGG', 'item' => 'PECAH', 'date' => '2026-08-24', 'kg' => '0.000', 'pieces' => 16, 'reason' => 'Buffer/rebase category PECAH legacy.'],
            ['kind' => 'EGG', 'item' => 'UTUH', 'date' => '2026-10-12', 'kg' => '-0.283', 'pieces' => 0, 'reason' => 'Pembalikan buffer UTUH pada cutoff.'],
            ['kind' => 'EGG', 'item' => 'REJECT', 'date' => '2026-10-12', 'kg' => '0.000', 'pieces' => -18, 'reason' => 'Pembalikan buffer 2 dan realokasi selisih final 16 butir.'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function fixedCorrections(): array
    {
        return [
            ['sheet' => 'Cash Flow - Pemasukan & Telur K', 'row' => 84, 'cell' => 'J84', 'from' => '27913.4', 'to' => 27913, 'reason' => 'Pembulatan half-up IDR.'],
            ['sheet' => 'Cash Flow - Pemasukan & Telur K', 'row' => 94, 'cell' => 'J94', 'from' => '57877.2', 'to' => 57877, 'reason' => 'Pembulatan half-up IDR.'],
            ['sheet' => 'Cash Flow - Pemasukan & Telur K', 'row' => 190, 'cell' => 'J190', 'from' => null, 'to' => 45000, 'reason' => 'J kosong; I190 2.0 × F190 Rp22,500.'],
            ['sheet' => 'Record Ayam', 'row' => 76, 'cell' => 'R76', 'from' => '1504', 'to' => '1.504'],
            ['sheet' => 'Record Ayam', 'row' => 77, 'cell' => 'R77', 'from' => '1604', 'to' => '1.604'],
            ['sheet' => 'Record Ayam', 'row' => 134, 'cell' => 'R134', 'from' => '498', 'to' => '0.498'],
            ['sheet' => 'Cash Flow - Pengeluaran', 'row' => 462, 'field' => 'feed_quantity', 'to' => '6.000 kg', 'reason' => 'Qty literal kg, bukan enam sak.'],
            ['sheet' => 'Cash Flow - Pengeluaran', 'row' => 488, 'field' => 'feed_quantity', 'to' => '60.000 kg', 'reason' => 'Alternative tanpa qty; mapping eksplisit.'],
        ];
    }

    /** @return array<string,int> */
    private function insertFeedTypes(User $actor, mixed $executedAt, array &$mappings): array
    {
        $ids = [];
        foreach (self::FEEDS as $feed) {
            $timestamp = $this->businessTimestamp($feed['date']);
            $id = DB::table('feed_types')->insertGetId([
                'species' => $feed['species'], 'name' => $feed['name'], 'is_active' => true,
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
            $ids[$feed['name']] = $id;
            $this->audit($actor->id, 'feed_types', $id, $executedAt, 'Master feed workbook final.', $feed);
            $mappings[] = $this->mapping('master', null, 'feed_types', $id);
        }

        return $ids;
    }

    /** @return array<string,int> */
    private function insertEmployees(User $actor, mixed $executedAt, array &$mappings): array
    {
        $ids = [];
        foreach (['Alex', 'Tangin'] as $name) {
            $timestamp = $this->businessTimestamp('2026-01-08');
            $id = DB::table('employees')->insertGetId([
                'name' => $name, 'job_title' => 'ABK', 'abk_type'=>'BOTH', 'joined_at' => '2026-01-08', 'status' => 'ACTIVE',
                'notes' => 'Diimpor dari workbook final 12 Oktober.', 'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
            $ids[$name] = $id;
            $this->audit($actor->id, 'employees', $id, $executedAt, 'Master karyawan workbook final.', ['name' => $name]);
            $mappings[] = $this->mapping('master', null, 'employees', $id);
        }

        return $ids;
    }

    /** @return array<string,int> */
    private function insertGroups(User $actor, mixed $executedAt, array &$mappings): array
    {
        $ids = [];
        foreach (self::GROUPS as $group) {
            $timestamp = $this->businessTimestamp($group['start_date']);
            $id = DB::table('livestock_groups')->insertGetId($group + ($group['species']==='LELE'?['planned_adg_g_per_day'=>'1.300']:[]) + [
                'notes' => 'Diimpor dari workbook final 12 Oktober.', 'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
            $ids[$group['code']] = $id;
            $this->audit($actor->id, 'livestock_groups', $id, $executedAt, 'Master grup ternak workbook final.', $group);
            $mappings[] = $this->mapping('master', null, 'livestock_groups', $id);
        }

        return $ids;
    }

    /** @return array<string,int> */
    private function insertCustomers(User $actor, mixed $executedAt, array &$mappings): array
    {
        $ids = [];
        foreach (self::CUSTOMERS as $key => $name) {
            $firstDate = $this->customerFirstDate($name);
            $timestamp = $this->businessTimestamp($firstDate);
            $id = DB::table('customers')->insertGetId([
                'name' => $name, 'phone' => '-', 'customer_type' => 'AGEN',
                'notes' => 'Placeholder customer dari workbook final 12 Oktober.',
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
            $ids[$name] = $id;
            $this->audit($actor->id, 'customers', $id, $executedAt, 'Placeholder customer legacy.', ['name' => $name, 'normalized_key' => $key]);
            $mappings[] = $this->mapping('master', null, 'customers', $id);
        }

        return $ids;
    }

    private function customerFirstDate(string $name): string
    {
        return match ($name) {
            'Erland' => '2026-09-20', 'Kuwat' => '2026-09-29', default => '2026-08-02',
        };
    }

    /** @param array<string,mixed> $plan @param array<string,int> $feedIds */
    private function insertOpenings(array $plan, User $actor, mixed $executedAt, array $feedIds, array &$mappings): void
    {
        foreach ($plan['openings'] as $opening) {
            $timestamp = $this->businessTimestamp($opening['date']);
            $id = DB::table('inventory_opening_balances')->insertGetId([
                'item_kind' => $opening['kind'],
                'feed_type_id' => $opening['kind'] === 'FEED' ? $feedIds[$opening['item']] : null,
                'egg_category' => $opening['kind'] === 'EGG' ? $opening['item'] : null,
                'quantity_kg' => $opening['kg'], 'quantity_pieces' => $opening['pieces'],
                'effective_date' => $opening['date'], 'created_by' => $actor->id,
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
            $this->audit($actor->id, 'inventory_opening_balances', $id, $executedAt, $opening['reason'], $opening);
            $mappings[] = $this->mapping('synthetic opening', null, 'inventory_opening_balances', $id);
        }
    }

    /** @param array<string,mixed> $plan @param array<string,int> $feedIds */
    private function insertAdjustments(array $plan, User $actor, mixed $executedAt, array $feedIds, array &$mappings): void
    {
        foreach ($plan['adjustments'] as $adjustment) {
            $timestamp = $this->businessTimestamp($adjustment['date']);
            $id = DB::table('inventory_adjustments')->insertGetId([
                'item_kind' => $adjustment['kind'],
                'feed_type_id' => $adjustment['kind'] === 'FEED' ? $feedIds[$adjustment['item']] : null,
                'egg_category' => $adjustment['kind'] === 'EGG' ? $adjustment['item'] : null,
                'delta_kg' => $adjustment['kg'], 'delta_pieces' => $adjustment['pieces'],
                'effective_date' => $adjustment['date'], 'reason' => $adjustment['reason'], 'created_by' => $actor->id,
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
            $this->audit($actor->id, 'inventory_adjustments', $id, $executedAt, $adjustment['reason'], $adjustment);
            $mappings[] = $this->mapping($adjustment['sheet'] ?? 'synthetic adjustment', $adjustment['row'] ?? null, 'inventory_adjustments', $id);
        }
    }

    /** @param array<string,mixed> $plan @param array<string,int> $groupIds */
    private function insertPopulationAdjustments(array $plan, User $actor, mixed $executedAt, array $groupIds, array &$mappings): void
    {
        foreach ($plan['population_adjustments'] as $adjustment) {
            $timestamp = $this->businessTimestamp($adjustment['date']);
            $id = DB::table('population_adjustments')->insertGetId([
                'group_id' => $groupIds[$adjustment['group']], 'delta_ekor' => $adjustment['delta'],
                'effective_date' => $adjustment['date'], 'reason' => $adjustment['reason'], 'created_by' => $actor->id,
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
            $this->audit($actor->id, 'population_adjustments', $id, $executedAt, $adjustment['reason'], $adjustment);
            $mappings[] = $this->mapping($adjustment['sheet'], $adjustment['row'], 'population_adjustments', $id);
        }
    }

    /** @param array<string,mixed> $plan @param array<string,int> $feedIds @param array<string,int> $employeeIds */
    private function insertExpenses(array $plan, User $actor, mixed $executedAt, array $feedIds, array $employeeIds, array &$mappings): void
    {
        foreach ($plan['expenses'] as $expense) {
            $timestamp = $this->businessTimestamp($expense['date']);
            $paid = $expense['status'] === 'PAID';
            if ($expense['type'] === 'MAIN_ACCOUNT_TRANSFER') {
                $id = DB::table('revenues')->insertGetId([
                    'revenue_type' => $expense['type'], 'direction' => 'OUTFLOW', 'transaction_date' => $expense['date'], 'amount_idr' => $expense['amount'],
                    'approval_status' => 'APPROVED', 'payment_status' => $expense['status'], 'payment_method' => null, 'direct_transfer_date' => null, 'rejection_reason' => null, 'customer_id' => null,
                    'created_by' => $actor->id, 'submitted_by' => $actor->id, 'approved_by' => $actor->id, 'rejected_by' => null,
                    'paid_by' => $paid ? $actor->id : null, 'proof_path' => null, 'proof_url' => null,
                    'submitted_at' => $timestamp, 'approved_at' => $timestamp, 'rejected_at' => null,
                    'paid_at' => $paid ? $timestamp : null, 'created_at' => $timestamp, 'updated_at' => $timestamp,
                ]);
                DB::table('revenue_transfer_details')->insert(['revenue_id' => $id, 'gross_amount_idr' => $expense['amount'], 'payment_cost_idr' => 0, 'notes' => $expense['detail']['description'], 'created_at' => $timestamp, 'updated_at' => $timestamp]);
                $this->audit($actor->id, 'revenues', $id, $executedAt, "Legacy {$expense['sheet']} row {$expense['row']}; {$expense['bucket']}.", $expense);
                $mappings[] = $this->mapping($expense['sheet'], $expense['row'], 'revenues', $id);
                continue;
            }
            $id = DB::table('operational_cashflows')->insertGetId([
                'cashflow_type' => $expense['type'], 'direction' => 'OUTFLOW', 'transaction_date' => $expense['date'], 'amount_idr' => $expense['amount'],
                'approval_status' => 'APPROVED', 'payment_status' => $expense['status'], 'rejection_reason' => null,
                'created_by' => $actor->id, 'submitted_by' => $actor->id, 'approved_by' => $actor->id,
                'rejected_by' => null, 'paid_by' => $paid ? $actor->id : null, 'proof_path' => null, 'proof_url' => null,
                'submitted_at' => $timestamp, 'approved_at' => $timestamp, 'rejected_at' => null,
                'paid_at' => $paid ? $timestamp : null, 'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
            if($paid) DB::table('operational_cashflow_payments')->insert(['operational_cashflow_id'=>$id,'paid_date'=>$expense['date'],'amount_idr'=>$expense['amount'],'payment_mode'=>'FULL','created_by'=>$actor->id,'created_at'=>$timestamp,'updated_at'=>$timestamp]);
            $detail = $expense['detail'];
            $base = ['operational_cashflow_id' => $id, 'created_at' => $timestamp, 'updated_at' => $timestamp];
            match ($expense['type']) {
                'FEED_AYAM', 'FEED_LELE' => DB::table('operational_cashflow_feed_details')->insert($base + [
                    'feed_type_id' => $feedIds[$detail['feed']], 'quantity_kg' => $detail['quantity_kg'],
                    'price_per_kg_idr' => $detail['price_per_kg'], 'line_amount_idr' => $expense['amount'],
                    'purchase_unit'=>'KG','purchase_quantity'=>$detail['quantity_kg'],'kg_per_sak'=>null,
                    'price_per_purchase_unit_idr'=>$detail['price_per_kg'],
                    'received_at' => $detail['received_date'] === null ? null : $this->businessTimestamp($detail['received_date']),
                    'received_by' => $detail['received_date'] === null ? null : $actor->id,
                ]),
                'SALARY' => DB::table('operational_cashflow_salary_details')->insert($base + ['employee_id' => $employeeIds[$detail['employee']], 'salary_period' => $detail['salary_period']]),
                'UTILITY' => DB::table('operational_cashflow_utility_details')->insert($base + ['description' => $detail['description']]),
                'HEALTH' => DB::table('operational_cashflow_health_details')->insert($base + ['species' => $detail['species'], 'description' => $detail['description']]),
                default => DB::table('operational_cashflow_other_details')->insert($base + ['description' => $detail['description']]),
            };
            $this->audit($actor->id, 'operational_cashflows', $id, $executedAt, "Legacy {$expense['sheet']} row {$expense['row']}; {$expense['bucket']}.", $expense);
            $mappings[] = $this->mapping($expense['sheet'], $expense['row'], 'operational_cashflows', $id);
        }
    }

    /** @param array<string,mixed> $plan @param array<string,int> $customerIds */
    private function insertRevenues(array $plan, User $actor, mixed $executedAt, array $customerIds, array &$mappings): void
    {
        foreach ($plan['revenues'] as $revenue) {
            $timestamp = $this->businessTimestamp($revenue['date']);
            $paid = $revenue['status'] === 'PAID';
            if ($revenue['type'] === 'MAIN_ACCOUNT_TOPUP') {
                $id = DB::table('operational_cashflows')->insertGetId([
                    'cashflow_type' => $revenue['type'], 'direction' => 'INFLOW', 'transaction_date' => $revenue['date'], 'amount_idr' => $revenue['amount'],
                    'approval_status' => 'NOT_REQUIRED', 'payment_status' => $revenue['status'], 'rejection_reason' => null,
                    'created_by' => $actor->id, 'submitted_by' => null, 'approved_by' => null, 'rejected_by' => null,
                    'paid_by' => $paid ? $actor->id : null, 'proof_path' => null, 'proof_url' => null,
                    'submitted_at' => null, 'approved_at' => null, 'rejected_at' => null,
                    'paid_at' => $paid ? $timestamp : null, 'created_at' => $timestamp, 'updated_at' => $timestamp,
                ]);
                DB::table('operational_cashflow_topup_details')->insert(['operational_cashflow_id' => $id, 'notes' => $revenue['detail']['notes'], 'created_at' => $timestamp, 'updated_at' => $timestamp]);
                $this->audit($actor->id, 'operational_cashflows', $id, $executedAt, "Legacy {$revenue['sheet']} row {$revenue['row']}; {$revenue['type']}.", $revenue);
                $mappings[] = $this->mapping($revenue['sheet'], $revenue['row'], 'operational_cashflows', $id);
                continue;
            }
            $id = DB::table('revenues')->insertGetId([
                'revenue_type' => $revenue['type'], 'direction' => 'INFLOW', 'transaction_date' => $revenue['date'], 'amount_idr' => $revenue['amount'],
                'approval_status' => 'NOT_REQUIRED', 'payment_status' => $revenue['status'], 'payment_method' => 'CASH', 'direct_transfer_date' => null, 'rejection_reason' => null,
                'customer_id' => $revenue['customer'] === null ? null : $customerIds[$revenue['customer']],
                'created_by' => $actor->id, 'submitted_by' => null, 'approved_by' => null, 'rejected_by' => null, 'paid_by' => $paid ? $actor->id : null,
                'proof_path' => null, 'proof_url' => null, 'paid_at' => $paid ? $timestamp : null,
                'submitted_at' => null, 'approved_at' => null, 'rejected_at' => null,
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
            if($paid) DB::table('revenue_payments')->insert(['revenue_id'=>$id,'paid_date'=>$revenue['date'],'amount_idr'=>$revenue['amount'],'payment_mode'=>'FULL','created_by'=>$actor->id,'created_at'=>$timestamp,'updated_at'=>$timestamp]);
            $base = ['revenue_id' => $id, 'created_at' => $timestamp, 'updated_at' => $timestamp];
            match ($revenue['type']) {
                'EGG_SALE' => DB::table('revenue_egg_details')->insert($base + [
                    'egg_category' => $revenue['detail']['category'], 'weight_kg' => $revenue['detail']['weight_kg'],
                    'quantity_pieces' => $revenue['detail']['pieces'], 'price_per_kg_idr' => $revenue['detail']['price_per_kg'], 'line_amount_idr' => $revenue['amount'],
                    'notes' => $revenue['detail']['notes'],
                ]),
                'FISH_SALE' => DB::table('revenue_fish_details')->insert($base + [
                    'weight_kg' => $revenue['detail']['weight_kg'], 'price_per_kg_idr' => $revenue['detail']['price_per_kg'],
                    'notes' => $revenue['detail']['notes'],
                ]),
                default => DB::table('revenue_other_details')->insert($base + ['notes' => $revenue['detail']['notes']]),
            };
            $this->audit($actor->id, 'revenues', $id, $executedAt, "Legacy {$revenue['sheet']} row {$revenue['row']}; {$revenue['type']}.", $revenue);
            $mappings[] = $this->mapping($revenue['sheet'], $revenue['row'], 'revenues', $id);
        }
    }

    /** @param array<string,mixed> $plan @param array<string,int> $feedIds @param array<string,int> $groupIds */
    private function insertDailyRecords(array $plan, User $actor, mixed $executedAt, array $feedIds, array $groupIds, array &$mappings): void
    {
        foreach ($plan['daily_records'] as $record) {
            $timestamp = $this->businessTimestamp($record['date']);
            $id = DB::table('daily_records')->insertGetId([
                'group_id' => $groupIds[$record['group']], 'record_date' => $record['date'],
                'mortality' => $record['mortality'], 'notes' => $record['notes'], 'created_by' => $actor->id,
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
            foreach ($record['feeds'] as $feed) {
                DB::table('feed_usages')->insert([
                    'daily_record_id' => $id, 'feed_type_id' => $feedIds[$feed['feed']],
                    'morning_kg' => $feed['morning'], 'noon_kg' => $feed['noon'], 'evening_kg' => $feed['evening'],
                    'quantity_kg' => $feed['total'], 'created_at' => $timestamp, 'updated_at' => $timestamp,
                ]);
            }
            foreach ($record['eggs'] as $egg) {
                DB::table('egg_harvests')->insert([
                    'daily_record_id' => $id, 'egg_category' => $egg['category'], 'weight_kg' => $egg['kg'],
                    'quantity_pieces' => $egg['pieces'], 'created_at' => $timestamp, 'updated_at' => $timestamp,
                ]);
            }
            $this->audit($actor->id, 'daily_records', $id, $executedAt, "Legacy {$record['sheet']} row {$record['row']}; grup {$record['group']}.", $record);
            $mappings[] = $this->mapping($record['sheet'], $record['row'], 'daily_records', $id);
        }
    }

    /** @param array<string,int> $feedIds @param array<string,int> $groupIds @return array<string,mixed> */
    private function databaseChecks(array $feedIds, array $groupIds): array
    {
        foreach ($groupIds as $groupId) {
            $this->balances->assertPopulationNonnegative($groupId, '2026-05-28');
        }

        return [
            'cash_balance_idr' => (int) Decimal::add($this->balances->operationalCash(self::CUTOFF),$this->balances->revenueCash(self::CUTOFF),0),
            'operational_cash_balance_idr' => (int) $this->balances->operationalCash(self::CUTOFF),
            'revenue_cash_balance_idr' => (int) $this->balances->revenueCash(self::CUTOFF),
            'feed_balances_kg' => array_map(fn (int $id): string => $this->balances->feed($id, self::CUTOFF), $feedIds),
            'egg_balances' => array_combine(['UTUH', 'PECAH', 'REJECT'], array_map(fn (string $category): array => $this->balances->egg($category, self::CUTOFF), ['UTUH', 'PECAH', 'REJECT'])),
            'populations' => array_map(fn (int $id): int => $this->balances->population($id, self::CUTOFF), $groupIds),
        ];
    }

    /** @param array<string,mixed> $checks */
    private function assertDatabaseChecks(array $checks): void
    {
        $expected = [
            'cash_balance_idr' => 296749,
            'operational_cash_balance_idr' => -578100,
            'revenue_cash_balance_idr' => 874849,
            'feed_balances_kg' => ['Grower' => '0.000', 'Layer' => '461.604', '781-2' => '11.800', '781-3' => '0.000', 'Alternative' => '46.200'],
            'egg_balances' => [
                'UTUH' => ['kg' => '37.636', 'pieces' => 620],
                'PECAH' => ['kg' => '0.045', 'pieces' => 0],
                'REJECT' => ['kg' => '1.394', 'pieces' => 0],
            ],
            'populations' => [
                'AYAM-PETELUR' => 504, 'LELE-1-C1' => 4851, 'LELE-1-C2' => 4426,
                'LELE-2-C1' => 4607, 'LELE-2-C1-KERAMBA' => 4844, 'LELE-2-C2' => 8148,
            ],
        ];
        if ($checks !== $expected) {
            throw new RuntimeException('Rekonsiliasi database setelah import tidak sesuai expected: '.json_encode($checks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function summarize(array $plan): array
    {
        $expenses = $plan['expenses'];
        $revenues = $plan['revenues'];
        $daily = $plan['daily_records'];
        $expenseCategories = [];
        foreach ($expenses as $expense) {
            $key = $expense['bucket'];
            $expenseCategories[$key]['rows'] = ($expenseCategories[$key]['rows'] ?? 0) + 1;
            $expenseCategories[$key]['total_idr'] = ($expenseCategories[$key]['total_idr'] ?? 0) + $expense['amount'];
        }
        $paidExpenses = array_filter($expenses, fn (array $expense): bool => $expense['status'] === 'PAID');
        $unpaidExpenses = array_filter($expenses, fn (array $expense): bool => $expense['status'] === 'UNPAID');
        $paidRevenues = array_filter($revenues, fn (array $revenue): bool => $revenue['status'] === 'PAID');
        $unpaidRevenues = array_filter($revenues, fn (array $revenue): bool => $revenue['status'] === 'UNPAID');

        return [
            'employees' => 2, 'customers' => count(self::CUSTOMERS), 'feed_types' => count(self::FEEDS), 'livestock_groups' => count(self::GROUPS),
            'daily_records' => count($daily),
            'feed_usages' => array_sum(array_map(fn (array $record): int => count($record['feeds']), $daily)),
            'egg_harvests' => array_sum(array_map(fn (array $record): int => count($record['eggs']), $daily)),
            'expenses' => count($expenses), 'expense_total_idr' => array_sum(array_column($expenses, 'amount')),
            'paid_expenses' => count($paidExpenses), 'paid_expense_total_idr' => array_sum(array_column($paidExpenses, 'amount')),
            'unpaid_expenses' => count($unpaidExpenses), 'unpaid_expense_total_idr' => array_sum(array_column($unpaidExpenses, 'amount')),
            'expense_categories' => $expenseCategories,
            'revenues' => count($revenues), 'revenue_total_idr' => array_sum(array_column($revenues, 'amount')),
            'paid_revenues' => count($paidRevenues), 'paid_revenue_total_idr' => array_sum(array_column($paidRevenues, 'amount')),
            'unpaid_revenues' => count($unpaidRevenues), 'unpaid_revenue_total_idr' => array_sum(array_column($unpaidRevenues, 'amount')),
            'inventory_opening_balances' => count($plan['openings']), 'inventory_adjustments' => count($plan['adjustments']),
            'population_adjustments' => count($plan['population_adjustments']),
            'cash_balance_idr' => array_sum(array_column($paidRevenues, 'amount')) - array_sum(array_column($paidExpenses, 'amount')),
            'sampling_source_cells' => count($plan['samplings']),
        ];
    }

    /** @param array<string,mixed> $summary */
    private function assertExpected(array $summary): void
    {
        foreach (self::EXPECTED as $key => $expected) {
            if (($summary[$key] ?? null) !== $expected) {
                throw new RuntimeException("Validasi hasil mapping gagal untuk {$key}: ditemukan ".json_encode($summary[$key] ?? null).", diharapkan {$expected}.");
            }
        }
        $categories = [
            'OTHER PRE_OPERATIONAL' => [243, 114514444], 'OTHER POST_OPERATIONAL' => [90, 71567809],
            'FEED_AYAM' => [20, 55960000], 'FEED_LELE' => [23, 10981500], 'SALARY' => [16, 6410000],
            'UTILITY' => [13, 1696500], 'HEALTH AYAM' => [14, 2623000], 'HEALTH LELE' => [7, 653781],
            'MAIN_ACCOUNT_TRANSFER' => [13, 40824081],
        ];
        foreach ($categories as $category => [$rows, $total]) {
            $actual = $summary['expense_categories'][$category] ?? [];
            if (($actual['rows'] ?? null) !== $rows || ($actual['total_idr'] ?? null) !== $total) {
                throw new RuntimeException("Rekonsiliasi kategori expense {$category} tidak sesuai expected: ".json_encode($actual).'.');
            }
        }
    }

    /** @param array<string,mixed> $plan */
    private function assertCustomers(array $plan): void
    {
        $actual = [];
        foreach ($plan['revenues'] as $revenue) {
            if ($revenue['customer'] !== null) {
                $actual[$revenue['customer']] = true;
            }
        }
        $actual = array_keys($actual);
        sort($actual);
        $expected = array_values(self::CUSTOMERS);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('Daftar customer workbook tidak sesuai kontrak. Ditemukan: '.implode(', ', $actual).'.');
        }
    }

    /** @param array<string,mixed> $workbook */
    private function assertFormulaCache(array $workbook, string $sheet, int $row, string $column): void
    {
        $cell = $workbook['cells'][$sheet][$row][$column] ?? null;
        if ($cell !== null && $cell['formula'] !== null && ! $cell['has_cached_value']) {
            throw new RuntimeException("Formula {$sheet}!{$column}{$row} tidak memiliki cached value.");
        }
    }

    /** @param array<string,mixed> $workbook @param list<string> $columns @return array<string,mixed> */
    private function source(array $workbook, string $sheet, int $row, array $columns): array
    {
        $cells = [];
        foreach ($columns as $column) {
            if (isset($workbook['cells'][$sheet][$row][$column])) {
                $cells[$column.$row] = $workbook['cells'][$sheet][$row][$column];
            }
        }

        return ['sheet' => $sheet, 'row' => $row, 'cells' => $cells];
    }

    /** @param array<string,string> $row @return array<string,mixed> */
    private function ignored(string $sheet, int $rowNumber, string $date, string $reason, array $row): array
    {
        return ['sheet' => $sheet, 'row' => $rowNumber, 'date' => $date, 'reason' => $reason, 'raw' => $row];
    }

    private function canonicalCustomer(string $value, int $row): string
    {
        $clean = $this->clean($value);
        $key = mb_strtolower($clean);
        if (! isset(self::CUSTOMERS[$key])) {
            throw new RuntimeException("Customer tidak dikenal pada Cash Flow - Pemasukan row {$row}: '{$clean}'.");
        }

        return self::CUSTOMERS[$key];
    }

    private function eggCategory(string $value, int $row): string
    {
        return match (mb_strtoupper(trim($value))) {
            'UTUH' => 'UTUH', 'RUSAK' => 'PECAH', 'RIJEK' => 'REJECT',
            default => throw new RuntimeException("Kategori telur tidak valid pada row {$row}: {$value}."),
        };
    }

    private function excelDate(string $value, string $context): string
    {
        if (! is_numeric(trim($value))) {
            throw new RuntimeException("Tanggal Excel tidak valid pada {$context}: '{$value}'.");
        }

        return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'Asia/Jakarta')->addDays((int) floor((float) $value))->toDateString();
    }

    private function number(string|int|float $value): float
    {
        $clean = trim(str_ireplace('kg', '', (string) $value));
        if ($clean === '') {
            return 0.0;
        }
        if (! is_numeric($clean)) {
            throw new RuntimeException("Nilai numerik workbook tidak valid: '{$value}'.");
        }

        return (float) $clean;
    }

    private function integer(string|int|float $value): int
    {
        return (int) round($this->number($value), 0, PHP_ROUND_HALF_UP);
    }

    private function money(string|int|float $value): int
    {
        return $this->integer($value);
    }

    private function decimal(string|int|float $value): string
    {
        return number_format($this->number($value), 3, '.', '');
    }

    private function decimalRound(float $value): string
    {
        return number_format(round($value, 3, PHP_ROUND_HALF_UP), 3, '.', '');
    }

    private function divideRound(string $numerator, string $denominator, int $scale): string
    {
        if (bccomp($denominator, '0', 6) === 0) {
            throw new RuntimeException('Pembagian dengan nol pada kalkulasi harga pakan.');
        }
        $working = bcdiv($numerator, $denominator, $scale + 1);
        $increment = '0.'.str_repeat('0', max(0, $scale - 1)).'5';

        return bcadd(bcadd($working, $increment, $scale + 1), '0', $scale);
    }

    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** @param array<string,string> $fields */
    private function labeledNotes(array $fields): ?string
    {
        $notes = [];
        foreach ($fields as $label => $value) {
            $clean = $this->clean($value);
            if ($clean !== '') {
                $notes[] = "{$label}: {$clean}";
            }
        }

        return $notes === [] ? null : implode(' | ', $notes);
    }

    private function sourceNote(string $sheet, int $row, string $description): string
    {
        return trim("{$sheet} row {$row}".($description !== '' ? "; {$description}" : ''));
    }

    private function businessTimestamp(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Asia/Jakarta')->startOfDay();
    }

    /** @param array<string,mixed> $after */
    private function audit(int $actorId, string $entityType, int $entityId, mixed $executedAt, string $reason, array $after): void
    {
        DB::table('audit_events')->insert([
            'actor_id' => $actorId, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'action' => 'legacy_imported', 'happened_at' => $executedAt, 'reason' => $reason,
            'before' => null, 'after' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => $executedAt, 'updated_at' => $executedAt,
        ]);
    }

    /** @return array<string,mixed> */
    private function mapping(string $sheet, ?int $row, string $target, int $id): array
    {
        return ['sheet' => $sheet, 'row' => $row, 'target' => $target, 'target_id' => $id];
    }
}
