<?php

namespace Tests\Feature;

use App\Models\{OperationalCashflow, User};
use App\Services\{FinanceChartReport,FinanceService};
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class FinanceReportingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'Asia/Jakarta'));
        $this->seed(DatabaseSeeder::class);
        User::query()->update(['must_change_password' => false]);
        $this->admin = User::where('role', 'ADMIN')->firstOrFail();
        $this->superadmin = User::where('role', 'SUPERADMIN')->firstOrFail();
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_new_operational_types_derive_species_and_follow_workflow(): void
    {
        foreach ([['LELE_SEED_PURCHASE', 'LELE'], ['PULLET_PURCHASE', 'AYAM']] as [$type, $species]) {
            $this->post('/operational-cashflow', ['cashflow_type' => $type, 'transaction_date' => '2026-10-10', 'amount_idr' => 100000, 'details' => ['species' => $species, 'notes' => 'Pembelian'], 'save_action' => 'submit'])->assertSessionHasNoErrors();
            $row = OperationalCashflow::latest('id')->firstOrFail();
            $this->assertSame('SUBMITTED', $row->approval_status);
            $this->assertDatabaseHas('operational_cashflow_livestock_purchase_details', ['operational_cashflow_id' => $row->id, 'species' => $species]);
        }
        $this->post('/operational-cashflow', ['cashflow_type' => 'BANK_ADMIN_FEE', 'transaction_date' => '2026-10-10', 'amount_idr' => 5000, 'details' => ['notes' => 'Administrasi'], 'save_action' => 'draft'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('operational_cashflow_bank_admin_details', ['notes' => 'Administrasi']);
        $this->post('/operational-cashflow', ['cashflow_type' => 'PULLET_PURCHASE', 'transaction_date' => '2026-10-10', 'amount_idr' => 100000, 'details' => ['species' => 'LELE']])->assertSessionHasErrors('details.species');
    }

    public function test_report_reconciles_transaction_and_paid_dates_and_exclusions(): void
    {
        $finance = app(FinanceService::class);
        $finance->saveOperationalCashflow(['cashflow_type' => 'MAIN_ACCOUNT_TOPUP', 'transaction_date' => '2026-10-02', 'amount_idr' => 500000, 'details' => ['notes' => null]], $this->admin);
        $revenue = $finance->saveRevenue(['revenue_type' => 'OTHER', 'transaction_date' => '2026-10-03', 'amount_idr' => 250000, 'details' => ['notes' => null]], $this->admin);
        $finance->payRevenue($revenue, $this->admin, '2026-10-12');
        $expense = $finance->saveOperationalCashflow(['cashflow_type' => 'BANK_ADMIN_FEE', 'transaction_date' => '2026-10-04', 'amount_idr' => 5000, 'details' => ['notes' => null]], $this->admin);
        $finance->submitOperational($expense, $this->admin); $finance->decideOperational($expense, $this->superadmin, 'APPROVED');

        $this->get('/export-report?month=2026-10')->assertInertia(fn (Assert $page) => $page->component('ExportReport')
            ->where('from', '2026-10-01')->where('to', '2026-10-14')
            ->where('sections.0.rows.0.total', '500000')->where('sections.1.rows.0.total', '250000')
            ->where('sections.2.rows.0.total', '5000')->where('sections.2.rows.0.unpaid', '5000'));
        $this->get('/export-report?month=2026-11')->assertSessionHasErrors('month');
    }

    public function test_chart_report_builds_four_weekly_and_monthly_date_basis_variants(): void
    {
        $finance=app(FinanceService::class);
        $revenue=$finance->saveRevenue(['revenue_type'=>'OTHER','transaction_date'=>'2026-10-05','amount_idr'=>250000,'details'=>['notes'=>'Operating']],$this->admin);
        $finance->payRevenue($revenue,$this->admin,'2026-10-12');
        $expense=$finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-04','amount_idr'=>50000,'details'=>['description'=>'Operating']],$this->admin);
        $finance->submitOperational($expense,$this->admin); $finance->decideOperational($expense,$this->superadmin,'APPROVED'); $finance->payOperational($expense,$this->superadmin,'2026-10-10');
        $finance->saveOperationalCashflow(['cashflow_type'=>'OTHER','transaction_date'=>'2026-10-05','amount_idr'=>999999,'details'=>['description'=>'Draft excluded']],$this->admin);
        $finance->saveOperationalCashflow(['cashflow_type'=>'MAIN_ACCOUNT_TOPUP','transaction_date'=>'2026-10-05','amount_idr'=>888888,'details'=>['notes'=>'Top up excluded']],$this->admin);

        $report=app(FinanceChartReport::class)->build(1,'WEEKLY');
        $this->assertSame('2026-10-01',$report['range']['from']); $this->assertSame('2026-10-14',$report['range']['to']);
        $this->assertCount(4,$report['series']['weekly']); $this->assertCount(4,$report['series']['monthly']);
        $weekly=collect($report['series']['weekly'])->keyBy('key');
        $second=collect($weekly['transaction_paid']['points'])->firstWhere('date','2026-10-11');
        $this->assertSame(250000.0,$second['revenue']); $this->assertSame(50000.0,$second['operational']);
        $this->assertSame(0.0,collect($weekly['paid_paid']['points'])->firstWhere('date','2026-10-11')['revenue']);
        $this->assertSame(250000.0,collect($weekly['paid_paid']['points'])->firstWhere('date','2026-10-14')['revenue']);
        $this->assertTrue(collect($weekly['paid_paid']['points'])->firstWhere('date','2026-10-14')['partial']);
        foreach($report['series']['monthly'] as $chart) {
            $this->assertSame('2026-10-14',$chart['points'][0]['date']);
            $this->assertTrue($chart['points'][0]['partial']);
        }

        $this->get('/reports?months=3&granularity=MONTHLY')->assertInertia(fn(Assert $page)=>$page->component('Report')->where('filters.months',3)->where('filters.granularity','MONTHLY')->has('series.weekly',4)->has('series.monthly',4));
        $this->get('/reports?months=4')->assertSessionHasErrors('months');
    }

    public function test_excel_exports_have_expected_sheets_and_require_ranges(): void
    {
        $this->get('/operational-cashflow/export')->assertSessionHasErrors(['from', 'to']);
        app(FinanceService::class)->saveOperationalCashflow(['cashflow_type' => 'BANK_ADMIN_FEE', 'transaction_date' => '2026-10-04', 'amount_idr' => 5000, 'details' => ['notes' => 'Bank']], $this->admin);
        app(FinanceService::class)->saveOperationalCashflow(['cashflow_type' => 'OTHER', 'transaction_date' => '2026-10-05', 'amount_idr' => 7000, 'details' => ['description' => 'Peralatan']], $this->admin);
        $response = $this->get('/operational-cashflow/export?from=2026-10-01&to=2026-10-14&cashflow_type=BANK_ADMIN_FEE')->assertOk();
        $this->assertStringContainsString('operational-cashflow-2026-10-01-2026-10-14.xlsx', $response->headers->get('content-disposition'));
        $path = tempnam(sys_get_temp_dir(), 'finance-export-');
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        $this->assertSame(['Summary', 'Transactions', 'Details'], $book->getSheetNames());
        $this->assertSame('Biaya Admin Bank', $book->getSheetByName('Transactions')->getCell('D2')->getValue());
        $this->assertSame('Lain-lain', $book->getSheetByName('Transactions')->getCell('D3')->getValue());
        $this->assertSame('n', $book->getSheetByName('Transactions')->getCell('H2')->getDataType());
        $this->assertSame('[$Rp-409] #,##0', $book->getSheetByName('Transactions')->getStyle('H2')->getNumberFormat()->getFormatCode());
        unlink($path);

        app(FinanceService::class)->saveRevenue(['revenue_type' => 'OTHER', 'transaction_date' => '2026-10-06', 'amount_idr' => 8000, 'details' => ['notes' => 'Satu']], $this->admin);
        app(FinanceService::class)->saveRevenue(['revenue_type' => 'OTHER', 'transaction_date' => '2026-10-07', 'amount_idr' => 9000, 'details' => ['notes' => 'Dua']], $this->admin);
        $revenueResponse = $this->get('/revenues/export?from=2026-10-01&to=2026-10-14&revenue_type=FISH_SALE')->assertOk();
        $revenuePath = tempnam(sys_get_temp_dir(), 'revenue-export-');
        file_put_contents($revenuePath, $revenueResponse->streamedContent());
        $revenueBook = IOFactory::load($revenuePath);
        $this->assertSame(3, $revenueBook->getSheetByName('Transactions')->getHighestDataRow());
        unlink($revenuePath);

        $reportResponse = $this->get('/reports/export?month=2026-10')->assertOk();
        $this->get('/export-report/download?month=2026-10')->assertOk();
        $reportPath = tempnam(sys_get_temp_dir(), 'monthly-report-');
        file_put_contents($reportPath, $reportResponse->streamedContent());
        $reportBook = IOFactory::load($reportPath);
        $summary = $reportBook->getSheetByName('Summary');
        $this->assertSame('Pergerakan Kas', $summary->getCell('A6')->getValue());
        $this->assertSame(['Kategori', 'Total', 'Paid', 'Unpaid'], [$summary->getCell('A7')->getValue(), $summary->getCell('B7')->getValue(), $summary->getCell('C7')->getValue(), $summary->getCell('D7')->getValue()]);
        $this->assertSame('n', $summary->getCell('B8')->getDataType());
        $this->assertSame('[$Rp-409] #,##0', $summary->getStyle('B8')->getNumberFormat()->getFormatCode());
        unlink($reportPath);
    }
}
