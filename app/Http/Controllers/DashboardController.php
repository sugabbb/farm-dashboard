<?php

namespace App\Http\Controllers;

use App\Models\{LeleSampling, LivestockGroup, OperationalCashflow, Revenue};
use App\Services\{Balances, FinanceMetrics, FinancePayments, LeleSamplingService, MonthlyReport};
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request, Balances $balances, FinancePayments $payments, LeleSamplingService $samplingService)
    {
        $today = today();
        $validated = $request->validate([
            'summary_from' => ['nullable', 'required_with:summary_to', 'date_format:Y-m-d', 'before_or_equal:today'],
            'summary_to' => ['nullable', 'required_with:summary_from', 'date_format:Y-m-d', 'after_or_equal:summary_from', 'before_or_equal:today'],
            'harvest_from' => ['nullable', 'required_with:harvest_to', 'date_format:Y-m-d', 'before_or_equal:today'],
            'harvest_to' => ['nullable', 'required_with:harvest_from', 'date_format:Y-m-d', 'after_or_equal:harvest_from', 'before_or_equal:today'],
            'finance_from' => ['nullable', 'required_with:finance_to', 'date_format:Y-m-d', 'before_or_equal:today'],
            'finance_to' => ['nullable', 'required_with:finance_from', 'date_format:Y-m-d', 'after_or_equal:finance_from', 'before_or_equal:today'],
        ]);

        $summaryRange = $this->range($validated, 'summary', $today->copy()->startOfMonth(), $today);
        $harvestRange = $this->range($validated, 'harvest', $today->copy()->startOfMonth(), $today);
        $financeRange = $this->range($validated, 'finance', $today->copy()->startOfMonth()->subMonths(11), $today);
        $todayString = $today->toDateString();
        $operatingRevenue = Revenue::query()->whereIn('revenue_type', FinanceMetrics::OPERATING_REVENUE_TYPES);
        $approvedExpenses = OperationalCashflow::query()->where('direction', 'OUTFLOW')->where('approval_status', 'APPROVED');
        $eggStock = collect(['UTUH', 'PECAH', 'REJECT'])->mapWithKeys(function ($category) use ($balances, $todayString) {
            $stock = $balances->egg($category, $todayString);
            return [$category => ['kg' => (string) $stock['kg'], 'pieces' => $stock['pieces']]];
        });
        $breakdown = (clone $approvedExpenses)->whereBetween('transaction_date', [$summaryRange['from'], $summaryRange['to']])
            ->select('cashflow_type')->selectRaw('SUM(amount_idr) total')->groupBy('cashflow_type')->orderByDesc('total')->get();
        $recent = collect(OperationalCashflow::latest()->limit(5)->get()->map(fn ($row) => [
            'kind' => 'Operational Cashflow', 'direction' => $row->direction, 'date' => $row->transaction_date, 'type' => $row->cashflow_type, 'amount' => $row->amount_idr,
        ]))->merge(Revenue::latest()->limit(5)->get()->map(fn ($row) => [
            'kind' => 'Revenues', 'direction' => $row->direction, 'date' => $row->transaction_date, 'type' => $row->revenue_type, 'amount' => $row->amount_idr,
        ]))->sortByDesc('date')->take(8);

        $approvedOutstanding=(clone $approvedExpenses)->with('payments')->get()->reduce(fn($sum,$row)=>\App\Services\Decimal::add($sum,$payments->operationalRemaining($row),0),'0');
        $revenueOutstanding=(clone $operatingRevenue)->with('payments')->get()->reduce(fn($sum,$row)=>\App\Services\Decimal::add($sum,$payments->revenueRemaining($row),0),'0');
        return Inertia::render('Dashboard', [
            'today' => $todayString,
            'summaryRange' => $summaryRange,
            'harvestRange' => $harvestRange,
            'financeRange' => $financeRange,
            'current' => [
                'operational_cash' => (string) ($operationalCash=$balances->operationalCash($todayString)),
                'estimated_operational_cash' => \App\Services\Decimal::sub($operationalCash,$approvedOutstanding,0),
                'revenue_cash' => (string) $balances->revenueCash($todayString),
                'approved_unpaid_expenses' => $approvedOutstanding,
                'waiting_expenses' => (string) OperationalCashflow::where('direction', 'OUTFLOW')->where('approval_status', 'SUBMITTED')->sum('amount_idr'),
                'unpaid_revenue' => $revenueOutstanding,
            ],
            'performance' => [
                'all_revenue' => (string) (clone $operatingRevenue)->sum('amount_idr'),
                'all_expenses' => (string) (clone $approvedExpenses)->sum('amount_idr'),
                'period_revenue' => (string) (clone $operatingRevenue)->whereBetween('transaction_date', [$summaryRange['from'], $summaryRange['to']])->sum('amount_idr'),
                'period_expenses' => (string) (clone $approvedExpenses)->whereBetween('transaction_date', [$summaryRange['from'], $summaryRange['to']])->sum('amount_idr'),
            ],
            'latestHarvest' => $this->latestHarvest($balances, $todayString),
            'eggStock' => $eggStock,
            'biomass' => $this->biomass($balances, $samplingService, $todayString),
            'financeSeries' => $this->financeSeries($financeRange['from'], $financeRange['to']),
            'harvestSeries' => $this->harvestSeries($harvestRange['from'], $harvestRange['to']),
            'expenseSeries' => $this->expenseSeries($todayString),
            'expenseTypes' => MonthlyReport::EXPENSE_LABELS,
            'breakdown' => $breakdown->map(fn ($row) => ['cashflow_type' => $row->cashflow_type, 'total' => (string) $row->total]),
            'recent' => $recent->values()->map(fn ($row) => ['kind' => $row['kind'], 'direction' => $row['direction'], 'date' => (string) $row['date'], 'type' => $row['type'], 'amount' => (string) $row['amount']]),
        ]);
    }

    private function range(array $validated, string $prefix, Carbon $defaultFrom, Carbon $defaultTo): array
    {
        return ['from' => $validated[$prefix.'_from'] ?? $defaultFrom->toDateString(), 'to' => $validated[$prefix.'_to'] ?? $defaultTo->toDateString()];
    }

    private function biomass(Balances $balances, LeleSamplingService $samplingService, string $today): array
    {
        return LivestockGroup::query()->where('species', 'LELE')->where('status', 'ACTIVE')->where('start_date', '<=', $today)->orderBy('name')->get()
            ->map(function (LivestockGroup $group) use ($balances, $samplingService, $today) {
                $latest = LeleSampling::with(['group', 'measurements', 'creator'])->where('group_id', $group->id)->where('sampling_date', '<=', $today)->latest('sampling_date')->latest('id')->first();
                $base = ['group_id' => $group->id, 'group_name' => $group->name, 'group_code' => $group->code, 'location' => $group->location, 'population' => $balances->population($group, $today), 'sampling_date' => null, 'average_weight_g' => null, 'estimated_biomass_kg' => null];
                if (! $latest) return $base;
                $metrics = $samplingService->metrics($latest, $today);
                return array_merge($base, ['sampling_date' => $latest->sampling_date->toDateString(), 'average_weight_g' => $metrics['average'], 'estimated_biomass_kg' => $metrics['currentBiomass']]);
            })->values()->all();
    }

    private function latestHarvest(Balances $balances, string $today): array
    {
        $date = DB::table('egg_harvests as h')->join('daily_records as r', 'r.id', '=', 'h.daily_record_id')->where('r.record_date', '<=', $today)
            ->where(fn ($query) => $query->where('h.quantity_pieces', '>', 0)->orWhere('h.weight_kg', '>', 0))->max('r.record_date');
        if (! $date) return ['date' => null, 'population' => 0, 'utuh_pieces' => 0, 'total_pieces' => 0, 'utuh_percentage' => null, 'total_percentage' => null];
        $rows = DB::table('daily_records as r')->join('livestock_groups as g', 'g.id', '=', 'r.group_id')->leftJoin('egg_harvests as h', 'h.daily_record_id', '=', 'r.id')
            ->where('g.species', 'AYAM')->where('r.record_date', $date)->select('g.id as group_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN h.egg_category='UTUH' THEN h.quantity_pieces ELSE 0 END),0) utuh_pieces")
            ->selectRaw('COALESCE(SUM(h.quantity_pieces),0) total_pieces')->groupBy('g.id')->get();
        $groups = LivestockGroup::whereIn('id', $rows->pluck('group_id'))->get()->keyBy('id');
        $population = $rows->sum(fn ($row) => $balances->population($groups[$row->group_id], $date));
        $utuh = (int) $rows->sum('utuh_pieces'); $total = (int) $rows->sum('total_pieces');
        return ['date' => $date, 'population' => $population, 'utuh_pieces' => $utuh, 'total_pieces' => $total, 'utuh_percentage' => $population > 0 ? round($utuh / $population * 100, 2) : null, 'total_percentage' => $population > 0 ? round($total / $population * 100, 2) : null];
    }

    private function financeSeries(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfMonth(); $end = Carbon::parse($to)->startOfMonth();
        $revenue = Revenue::whereIn('revenue_type', FinanceMetrics::OPERATING_REVENUE_TYPES)->whereBetween('transaction_date', [$from, $to])
            ->selectRaw("to_char(transaction_date, 'YYYY-MM') bucket, SUM(amount_idr) total")->groupBy('bucket')->pluck('total', 'bucket');
        $expenses = OperationalCashflow::where('direction', 'OUTFLOW')->where('approval_status', 'APPROVED')->whereBetween('transaction_date', [$from, $to])
            ->selectRaw("to_char(transaction_date, 'YYYY-MM') bucket, SUM(amount_idr) total")->groupBy('bucket')->pluck('total', 'bucket');
        $series = [];
        for ($month = $start->copy(); $month->lte($end); $month->addMonth()) {
            $key = $month->format('Y-m');
            $series[] = ['key' => $key, 'label' => $month->translatedFormat('M Y'), 'revenue' => (string) ($revenue[$key] ?? 0), 'expenses' => (string) ($expenses[$key] ?? 0)];
        }
        return $series;
    }

    private function harvestSeries(string $from, string $to): array
    {
        $raw = DB::table('egg_harvests as h')->join('daily_records as r', 'r.id', '=', 'h.daily_record_id')->whereBetween('r.record_date', [$from, $to])->selectRaw('r.record_date bucket')
            ->selectRaw("SUM(CASE WHEN h.egg_category='UTUH' THEN h.quantity_pieces ELSE 0 END) utuh")
            ->selectRaw("SUM(CASE WHEN h.egg_category='PECAH' THEN h.quantity_pieces ELSE 0 END) pecah")
            ->selectRaw("SUM(CASE WHEN h.egg_category='REJECT' THEN h.quantity_pieces ELSE 0 END) reject")->groupBy('r.record_date')->get()->keyBy('bucket');
        $series = []; $end = Carbon::parse($to);
        for ($day = Carbon::parse($from); $day->lte($end); $day->addDay()) {
            $key = $day->toDateString(); $row = $raw[$key] ?? null;
            $utuh = (int) ($row?->utuh ?? 0); $pecah = (int) ($row?->pecah ?? 0); $reject = (int) ($row?->reject ?? 0);
            $series[] = ['key' => $key, 'label' => $day->translatedFormat('d M'), 'utuh' => $utuh, 'pecah' => $pecah, 'reject' => $reject, 'total' => $utuh + $pecah + $reject];
        }
        return $series;
    }

    private function expenseSeries(string $asOf): array
    {
        $end = Carbon::parse($asOf); $start = $end->copy()->startOfMonth()->subMonths(11);
        $raw = OperationalCashflow::where('direction', 'OUTFLOW')->where('approval_status', 'APPROVED')->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->select('cashflow_type')->selectRaw("to_char(transaction_date, 'YYYY-MM') bucket, SUM(amount_idr) total")->groupBy('cashflow_type', 'bucket')->get()->groupBy('bucket');
        return collect(range(0, 11))->map(function ($offset) use ($start, $raw) {
            $month = $start->copy()->addMonths($offset); $key = $month->format('Y-m'); $bucket = $raw->get($key); $values = ['key' => $key, 'label' => $month->translatedFormat('M Y')];
            foreach (array_keys(MonthlyReport::EXPENSE_LABELS) as $type) $values[$type] = (string) ($bucket?->firstWhere('cashflow_type', $type)?->total ?? 0);
            return $values;
        })->all();
    }
}
