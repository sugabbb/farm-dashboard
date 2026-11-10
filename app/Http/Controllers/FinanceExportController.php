<?php

namespace App\Http\Controllers;

use App\Services\FinanceWorkbookExporter;
use Carbon\Carbon;
use Illuminate\Http\Request;

class FinanceExportController extends Controller
{
    public function report(Request $request, FinanceWorkbookExporter $exporter)
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $data['month'] ?? today()->format('Y-m');
        if (Carbon::createFromFormat('Y-m-d', $month.'-01', config('app.timezone'))->startOfMonth()->isAfter(today()->startOfMonth())) {
            throw \Illuminate\Validation\ValidationException::withMessages(['month' => 'Bulan laporan tidak boleh di masa depan.']);
        }
        return $exporter->report($month);
    }

    public function operational(Request $request, FinanceWorkbookExporter $exporter)
    {
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        return $exporter->operational($filters);
    }

    public function revenues(Request $request, FinanceWorkbookExporter $exporter)
    {
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'payment_method' => ['nullable', 'in:CASH,DIRECT_TRANSFER'],
        ]);
        return $exporter->revenues($filters);
    }
}
