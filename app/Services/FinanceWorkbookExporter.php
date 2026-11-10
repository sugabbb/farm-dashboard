<?php

namespace App\Services;

use App\Models\{OperationalCashflow, Revenue};
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Fill};
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class FinanceWorkbookExporter
{
    private const CURRENCY_FORMAT = '[$Rp-409] #,##0';
    private const DECIMAL_FORMAT = '[$-409]#,##0.000';
    private const INTEGER_FORMAT = '[$-409]#,##0';

    public function __construct(private FinanceQuery $queries, private MonthlyReport $reports,private FinancePayments $payments) {}

    public function report(string $month)
    {
        $report = $this->reports->build($month);
        $operational = $this->reports->operationalDetails($month);
        $revenues = $this->reports->revenueDetails($month);
        $book = new Spreadsheet();
        $this->reportSummarySheet($book->getActiveSheet(), $report, $operational->count(), $revenues->count());
        $this->transactionSheet($book->createSheet(), 'Operational Details', $operational, false);
        $this->transactionSheet($book->createSheet(), 'Revenue Details', $revenues, true);
        return $this->download($book, "report-{$month}.xlsx");
    }

    public function operational(array $filters)
    {
        $query = OperationalCashflow::with(['feedDetails.feedType', 'creator', 'payer','payments']);
        $this->queries->operational($query, $filters);
        $rows = $query->orderBy('transaction_date')->orderBy('id')->get();
        $book = new Spreadsheet();
        $this->summarySheet($book->getActiveSheet(), 'Operational Cashflow', [$filters['from'], $filters['to']], $filters, $this->totals($rows));
        $this->transactionSheet($book->createSheet(), 'Transactions', $rows, false);
        $this->operationalDetailSheet($book->createSheet(), $rows);
        return $this->download($book, "operational-cashflow-{$filters['from']}-{$filters['to']}.xlsx");
    }

    public function revenues(array $filters)
    {
        $query = Revenue::with(['eggDetails', 'fishDetail', 'customer', 'creator', 'payer','payments']);
        $this->queries->revenues($query, $filters);
        $rows = $query->orderBy('transaction_date')->orderBy('id')->get();
        $book = new Spreadsheet();
        $this->summarySheet($book->getActiveSheet(), 'Revenues', [$filters['from'], $filters['to']], $filters, $this->totals($rows));
        $this->transactionSheet($book->createSheet(), 'Transactions', $rows, true);
        $this->revenueDetailSheet($book->createSheet(), $rows);
        return $this->download($book, "revenues-{$filters['from']}-{$filters['to']}.xlsx");
    }

    private function summarySheet(Worksheet $sheet, string $title, array $period, array $filters, array $totals): void
    {
        $sheet->setTitle('Summary');
        $rows = [['Laporan', $title], ['Periode', $period[0].' s.d. '.$period[1]]];
        foreach ($filters as $key => $value) if ($value !== null && $value !== '') $rows[] = ['Filter '.str_replace('_', ' ', $key), (string)$value];
        foreach ($totals as $key => $value) $rows[] = [$key, $value];
        $sheet->fromArray($rows);
        $sheet->getStyle('A1:A'.count($rows))->getFont()->setBold(true);
        foreach ($rows as $index => $row) if (in_array($row[0], ['Total', 'Paid', 'Unpaid'])) {
            $cell = 'B'.($index + 1); $sheet->setCellValueExplicit($cell, (float)$row[1], DataType::TYPE_NUMERIC); $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
        }
        $sheet->getColumnDimension('A')->setWidth(28); $sheet->getColumnDimension('B')->setWidth(42); $sheet->freezePane('A2');
    }

    private function reportSummarySheet(Worksheet $sheet, array $report, int $operationalCount, int $revenueCount): void
    {
        $sheet->setTitle('Summary');
        $sheet->fromArray([
            ['Laporan', 'Report Bulanan'],
            ['Periode', $report['from'].' s.d. '.$report['to']],
            ['Jumlah Transaksi Operational', $operationalCount],
            ['Jumlah Transaksi Revenue', $revenueCount],
        ], null, 'A1');
        $sheet->getStyle('A1:A4')->getFont()->setBold(true);
        $sheet->getStyle('B3:B4')->getNumberFormat()->setFormatCode(self::INTEGER_FORMAT);
        $rowNumber = 6;
        foreach ($report['sections'] as $section) {
            $sheet->mergeCells("A{$rowNumber}:D{$rowNumber}");
            $sheet->setCellValue("A{$rowNumber}", $section['label']);
            $sheet->getStyle("A{$rowNumber}:D{$rowNumber}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle("A{$rowNumber}:D{$rowNumber}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2563EB');
            $headerRow = ++$rowNumber;
            $sheet->fromArray(['Kategori', 'Total', 'Paid', 'Unpaid'], null, "A{$headerRow}");
            $sheet->getStyle("A{$headerRow}:D{$headerRow}")->getFont()->setBold(true);
            $sheet->getStyle("A{$headerRow}:D{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDCE8FF');
            foreach ($section['rows'] as $row) {
                $rowNumber++;
                $sheet->setCellValue("A{$rowNumber}", $row['label']);
                foreach (['B' => 'total', 'C' => 'paid', 'D' => 'unpaid'] as $column => $key) {
                    $sheet->setCellValueExplicit($column.$rowNumber, (float) $row[$key], DataType::TYPE_NUMERIC);
                    $sheet->getStyle($column.$rowNumber)->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
                }
            }
            $rowNumber += 2;
        }
        $sheet->freezePane('A7');
        $sheet->getColumnDimension('A')->setWidth(38);
        foreach (['B', 'C', 'D'] as $column) $sheet->getColumnDimension($column)->setWidth(22);
        $sheet->getStyle('A1:D'.max(1, $rowNumber - 1))->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
    }

    private function transactionSheet(Worksheet $sheet, string $title, Collection $rows, bool $revenue): void
    {
        $sheet->setTitle($title);
        $headers = $revenue
            ? ['ID', 'Tanggal Transaksi', 'Tanggal Paid', 'Jenis', 'Arah', 'Tipe Pembayaran', 'Tanggal Direct Transfer', 'Approval', 'Payment', 'Jumlah Neto', 'Spesies/Pelanggan', 'Catatan', 'URL Bukti', 'Dibuat Oleh', 'Dibayar Oleh']
            : ['ID', 'Tanggal Transaksi', 'Tanggal Paid', 'Jenis', 'Arah', 'Approval', 'Payment', 'Jumlah', 'Spesies/Pelanggan', 'Catatan', 'URL Bukti', 'Dibuat Oleh', 'Dibayar Oleh'];
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $i => $row) {
            $detail = $revenue ? $this->revenueDetail($row) : $this->operationalDetail($row);
            $values = $revenue
                ? [$row->id, null, null, $this->typeLabel('revenue',$row->revenue_type), $row->direction, $row->payment_method, null, $row->approval_status, $row->payment_status, (float)$row->amount_idr, $detail['party'], $detail['notes'], $row->proof_url, $row->creator?->name, $row->payer?->name]
                : [$row->id, null, null, $this->typeLabel('expense',$row->cashflow_type), $row->direction, $row->approval_status, $row->payment_status, (float)$row->amount_idr, $detail['party'], $detail['notes'], $row->proof_url, $row->creator?->name, $row->payer?->name];
            $line = $i + 2; $sheet->fromArray($values, null, 'A'.$line);
            $this->dateCell($sheet, 'B'.$line, $row->transaction_date); $this->dateCell($sheet, 'C'.$line, $row->paid_at);
            if($revenue) { $this->dateCell($sheet,'G'.$line,$row->direct_transfer_date); $sheet->getStyle('J'.$line)->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT); }
            else $sheet->getStyle('H'.$line)->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT);
        }
        $widths=$revenue?[10,18,18,28,12,20,20,16,14,20,24,42,35,22,22]:[10,18,18,28,12,16,14,20,24,42,35,22,22];
        $this->finishTable($sheet, count($headers), $rows->count() + 1, $widths);
    }

    private function operationalDetailSheet(Worksheet $sheet, Collection $rows): void
    {
        $sheet->setTitle('Details'); $headers = ['Detail ID', 'Operational ID', 'Jenis', 'Spesies/Item', 'Unit Pembelian', 'Kuantitas Pembelian', 'Kg per Sak', 'Kg Kanonis', 'Harga per Unit', 'Harga per Kg', 'Jumlah Baris', 'Catatan']; $sheet->fromArray($headers, null, 'A1');
        $line = 2;
        foreach ($rows as $row) {
            if (in_array($row->cashflow_type, ['FEED_AYAM', 'FEED_LELE'])) foreach ($row->feedDetails as $detail) {
                $sheet->fromArray([$detail->id, $row->id, $this->typeLabel('expense', $row->cashflow_type), $detail->feedType?->name, $detail->purchase_unit, (float)$detail->purchase_quantity, $detail->kg_per_sak===null?null:(float)$detail->kg_per_sak, (float)$detail->quantity_kg, (float)$detail->price_per_purchase_unit_idr, (float)$detail->price_per_kg_idr, (float)$detail->line_amount_idr, null], null, 'A'.$line++);
            } else {
                $detail = $this->operationalDetail($row);
                $sheet->fromArray([$detail['id'], $row->id, $this->typeLabel('expense', $row->cashflow_type), $detail['party'], null, null, null, null, null, null, (float)$row->amount_idr, $detail['notes']], null, 'A'.$line++);
            }
        }
        if ($line > 2) { $sheet->getStyle('F2:H'.($line - 1))->getNumberFormat()->setFormatCode(self::DECIMAL_FORMAT); $sheet->getStyle('I2:K'.($line - 1))->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT); }
        $this->finishTable($sheet, count($headers), max(1, $line - 1), [12,16,26,25,16,18,15,16,20,20,20,42]);
    }

    private function revenueDetailSheet(Worksheet $sheet, Collection $rows): void
    {
        $sheet->setTitle('Details'); $headers = ['Detail ID', 'Revenue ID', 'Jenis', 'Kategori/Item', 'Kg', 'Butir', 'Harga Satuan', 'Jumlah Baris', 'Pelanggan', 'Catatan', 'Transfer Bruto', 'Payment Cost', 'Transfer Neto']; $sheet->fromArray($headers, null, 'A1');
        $line = 2;
        foreach ($rows as $row) {
            if ($row->revenue_type === 'EGG_SALE') foreach ($row->eggDetails as $detail) {
                $sheet->fromArray([$detail->id, $row->id, 'Penjualan Telur', $detail->egg_category === 'REJECT' ? 'RIJEK' : $detail->egg_category, (float)$detail->weight_kg, $detail->quantity_pieces, (float)$detail->price_per_kg_idr, (float)$detail->line_amount_idr, $row->customer?->name, $detail->notes, null, null, null], null, 'A'.$line++);
            } elseif ($row->revenue_type === 'FISH_SALE' && $row->fishDetail) {
                $detail = $row->fishDetail; $sheet->fromArray([$detail->id, $row->id, 'Penjualan Lele', 'LELE', (float)$detail->weight_kg, null, (float)$detail->price_per_kg_idr, (float)$row->amount_idr, $row->customer?->name, $detail->notes, null, null, null], null, 'A'.$line++);
            } else {
                $detail = $this->revenueDetail($row); $transfer=$row->revenue_type==='MAIN_ACCOUNT_TRANSFER'?DB::table('revenue_transfer_details')->where('revenue_id',$row->id)->first():null;
                $sheet->fromArray([$detail['id'], $row->id, $this->typeLabel('revenue', $row->revenue_type), null, null, null, null, (float)$row->amount_idr, $row->customer?->name, $detail['notes'], $transfer?(float)$transfer->gross_amount_idr:null, $transfer?(float)$transfer->payment_cost_idr:null, $transfer?(float)$row->amount_idr:null], null, 'A'.$line++);
            }
        }
        if ($line > 2) { $sheet->getStyle('E2:E'.($line - 1))->getNumberFormat()->setFormatCode(self::DECIMAL_FORMAT); $sheet->getStyle('F2:F'.($line - 1))->getNumberFormat()->setFormatCode(self::INTEGER_FORMAT); $sheet->getStyle('G2:H'.($line - 1))->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT); $sheet->getStyle('K2:M'.($line - 1))->getNumberFormat()->setFormatCode(self::CURRENCY_FORMAT); }
        $this->finishTable($sheet, count($headers), max(1, $line - 1), [12,14,25,20,14,14,20,20,24,45,20,20,20]);
    }

    private function operationalDetail(OperationalCashflow $row): array
    {
        if (in_array($row->cashflow_type, ['FEED_AYAM', 'FEED_LELE'])) return ['id' => null, 'party' => $row->cashflow_type === 'FEED_AYAM' ? 'AYAM' : 'LELE', 'notes' => $row->feedDetails->pluck('feedType.name')->filter()->join(', ')];
        $table = match ($row->cashflow_type) {
            'SALARY' => 'operational_cashflow_salary_details', 'UTILITY' => 'operational_cashflow_utility_details', 'HEALTH' => 'operational_cashflow_health_details', 'OTHER' => 'operational_cashflow_other_details',
            'LELE_SEED_PURCHASE', 'PULLET_PURCHASE' => 'operational_cashflow_livestock_purchase_details', 'BANK_ADMIN_FEE' => 'operational_cashflow_bank_admin_details', default => 'operational_cashflow_topup_details',
        };
        if($row->cashflow_type==='SALARY') {
            $detail=DB::table("$table as detail")->leftJoin('employees as employee','employee.id','=','detail.employee_id')->where('detail.operational_cashflow_id',$row->id)->select('detail.*','employee.name as employee_name')->first();
        } else $detail = DB::table($table)->where('operational_cashflow_id', $row->id)->first();
        return ['id' => $detail?->id, 'party' => isset($detail->species) ? $detail->species : ($detail?->employee_name ?? (isset($detail->employee_id) ? 'Employee #'.$detail->employee_id : null)), 'notes' => $detail->notes ?? $detail->description ?? $detail->salary_period ?? null];
    }

    private function revenueDetail(Revenue $row): array
    {
        if ($row->revenue_type === 'EGG_SALE') return ['id' => null, 'party' => $row->customer?->name, 'notes' => $row->eggDetails->map(fn ($d) => ($d->egg_category === 'REJECT' ? 'RIJEK' : $d->egg_category).': '.$d->quantity_pieces.' butir')->join('; ')];
        if ($row->revenue_type === 'FISH_SALE') return ['id' => $row->fishDetail?->id, 'party' => $row->customer?->name, 'notes' => $row->fishDetail?->notes];
        $table = $row->revenue_type === 'MAIN_ACCOUNT_TRANSFER' ? 'revenue_transfer_details' : 'revenue_other_details'; $detail = DB::table($table)->where('revenue_id', $row->id)->first();
        return ['id' => $detail?->id, 'party' => $row->customer?->name, 'notes' => $detail?->notes];
    }

    private function totals(Collection $rows): array
    {
        $revenue=$rows->first() instanceof Revenue;
        $paid=$rows->reduce(fn($sum,$row)=>Decimal::add($sum,$revenue?$this->payments->revenuePaidAmount($row):$this->payments->operationalPaidAmount($row),0),'0');
        $unpaid=$rows->reduce(fn($sum,$row)=>Decimal::add($sum,$revenue?$this->payments->revenueRemaining($row):$this->payments->operationalRemaining($row),0),'0');
        return ['Jumlah Baris' => $rows->count(), 'Total' => (float)$rows->sum('amount_idr'), 'Paid' => (float)$paid, 'Unpaid' => (float)$unpaid];
    }

    private function typeLabel(string $ledger, string $type): string
    {
        if ($ledger === 'expense') return MonthlyReport::EXPENSE_LABELS[$type] ?? ($type === 'MAIN_ACCOUNT_TOPUP' ? 'Top Up dari Rekening Utama' : $type);
        return MonthlyReport::REVENUE_LABELS[$type] ?? ($type === 'MAIN_ACCOUNT_TRANSFER' ? 'Transfer ke Rekening Utama' : $type);
    }

    private function dateCell(Worksheet $sheet, string $cell, $value): void
    {
        if (!$value) return; $sheet->setCellValueExplicit($cell, Date::PHPToExcel($value), DataType::TYPE_NUMERIC); $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('[$-id-ID]ddd, d mmm yyyy');
    }

    private function finishTable(Worksheet $sheet, int $columns, int $lastRow, array $widths): void
    {
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columns);
        $sheet->freezePane('A2'); $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2563EB');
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        foreach ($widths as $index => $width) $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
    }

    private function download(Spreadsheet $book, string $filename)
    {
        $book->setActiveSheetIndex(0);
        return response()->streamDownload(function () use ($book) { (new Xlsx($book))->save('php://output'); $book->disconnectWorksheets(); }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
