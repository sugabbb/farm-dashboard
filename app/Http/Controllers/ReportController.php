<?php

namespace App\Http\Controllers;

use App\Services\{FinanceChartReport,MonthlyReport};
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function index(Request $request, FinanceChartReport $reports)
    {
        $data=$request->validate(['months'=>'nullable|integer|in:1,2,3','granularity'=>'nullable|in:WEEKLY,MONTHLY','revenue_types'=>'nullable|string','operational_types'=>'nullable|string']);
        $revenue=$this->types($request,'revenue_types',array_keys(MonthlyReport::REVENUE_LABELS));
        $operational=$this->types($request,'operational_types',array_keys(MonthlyReport::EXPENSE_LABELS));
        return Inertia::render('Report',$reports->build((int)($data['months']??1),$data['granularity']??'WEEKLY',$revenue,$operational));
    }

    public function exportIndex(Request $request, MonthlyReport $reports)
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $data['month'] ?? today()->format('Y-m');
        $selected = \Carbon\Carbon::createFromFormat('Y-m-d', $month.'-01', config('app.timezone'))->startOfMonth();

        if ($selected->isAfter(today()->startOfMonth())) {
            throw \Illuminate\Validation\ValidationException::withMessages(['month' => 'Bulan laporan tidak boleh di masa depan.']);
        }

        return Inertia::render('ExportReport',$reports->build($month));
    }

    private function types(Request $request,string $key,array $allowed): ?array
    {
        if(!$request->has($key)) return null;
        $types=array_values(array_filter(explode(',',(string)$request->input($key)),fn($value)=>$value!==''));
        $invalid=array_diff($types,$allowed);
        if($invalid) throw ValidationException::withMessages([$key=>'Jenis filter tidak valid: '.implode(', ',$invalid)]);
        return array_values(array_unique($types));
    }
}
