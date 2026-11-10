<?php

namespace Tests\Unit;

use App\Console\Commands\ImportLegacyFarmWorkbook;
use App\Services\FinanceAuditTimeline;
use App\Services\FinanceWorkbookExporter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class NumericFormattingTest extends TestCase
{
    public function test_finance_audit_values_use_comma_grouping_and_period_decimals(): void
    {
        $format = new ReflectionMethod(FinanceAuditTimeline::class, 'format');
        $timeline = new FinanceAuditTimeline();

        $this->assertSame('Rp 1,250,000', $format->invoke($timeline, 'amount_idr', '1250000', [], [], []));
        $this->assertSame('1,234.5 kg', $format->invoke($timeline, 'quantity_kg', '1234.500', [], [], []));
        $this->assertSame('1,250 butir', $format->invoke($timeline, 'quantity_pieces', 1250, [], [], []));
    }

    public function test_import_summary_and_excel_formats_use_the_new_convention(): void
    {
        $rupiah = new ReflectionMethod(ImportLegacyFarmWorkbook::class, 'rupiah');
        $this->assertSame('Rp1,250,000', $rupiah->invoke(new ImportLegacyFarmWorkbook(), 1250000));

        $exporter = new ReflectionClass(FinanceWorkbookExporter::class);
        $this->assertSame('[$Rp-409] #,##0', $exporter->getConstant('CURRENCY_FORMAT'));
        $this->assertSame('[$-409]#,##0.000', $exporter->getConstant('DECIMAL_FORMAT'));
        $this->assertSame('[$-409]#,##0', $exporter->getConstant('INTEGER_FORMAT'));
    }
}
