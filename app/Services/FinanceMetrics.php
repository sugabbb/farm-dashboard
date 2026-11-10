<?php
namespace App\Services;

use App\Models\{OperationalCashflow,Revenue,RevenueEggDetail};
use Carbon\Carbon;

final class FinanceMetrics {
    public const OPERATING_REVENUE_TYPES=['EGG_SALE','FISH_SALE','OTHER'];

    private function revenue() { return Revenue::query()->whereIn('revenue_type',self::OPERATING_REVENUE_TYPES); }
    private function expense() { return OperationalCashflow::query()->where('direction','OUTFLOW'); }
    public function __construct(private FinancePayments $payments) {}

    public function revenueSummary(): array {
        [$start,$end,$previousStart,$previousEnd]=$this->monthRanges();
        $current=(string)$this->revenue()->whereBetween('transaction_date',[$start,$end])->sum('amount_idr');
        $previous=(string)$this->revenue()->whereBetween('transaction_date',[$previousStart,$previousEnd])->sum('amount_idr');
        $utuh=RevenueEggDetail::query()->join('revenues','revenues.id','=','revenue_egg_details.revenue_id')
            ->where('revenue_egg_details.egg_category','UTUH')->where('revenue_egg_details.weight_kg','>',0)
            ->whereBetween('revenues.transaction_date',[$start,$end])
            ->selectRaw('COALESCE(SUM(revenue_egg_details.line_amount_idr),0) total_idr, COALESCE(SUM(revenue_egg_details.weight_kg),0) total_kg')->first();
        $eggAverage=Decimal::compare((string)$utuh->total_kg,0)===0?'0':Decimal::money(Decimal::div((string)$utuh->total_idr,(string)$utuh->total_kg,6));
        return [
            'unpaid'=>$this->sum($this->revenue()->with('payments')->get(),fn($row)=>$this->payments->revenueRemaining($row)),
            'current_month'=>$current,'previous_month'=>$previous,'total'=>(string)$this->revenue()->sum('amount_idr'),
            'egg_avg_price_current_month'=>$eggAverage,
            'change_percent'=>$this->change($current,$previous),'current_from'=>$start,'current_to'=>$end,
            'previous_from'=>$previousStart,'previous_to'=>$previousEnd,
        ];
    }

    public function expenseSummary(): array {
        [$start,$end,$previousStart,$previousEnd]=$this->monthRanges();
        $approved=fn()=> $this->expense()->where('approval_status','APPROVED');
        $current=(string)$approved()->whereBetween('transaction_date',[$start,$end])->sum('amount_idr');
        $previous=(string)$approved()->whereBetween('transaction_date',[$previousStart,$previousEnd])->sum('amount_idr');
        return [
            'waiting_approval'=>(string)$this->expense()->where('approval_status','SUBMITTED')->sum('amount_idr'),
            'approved_unpaid'=>$this->sum($approved()->with('payments')->get(),fn($row)=>$this->payments->operationalRemaining($row)),
            'current_month'=>$current,'previous_month'=>$previous,'total'=>(string)$approved()->sum('amount_idr'),
            'change_percent'=>$this->change($current,$previous),'current_from'=>$start,'current_to'=>$end,
            'previous_from'=>$previousStart,'previous_to'=>$previousEnd,
        ];
    }

    public function dashboard(string $from,string $to): array {
        $approved=fn()=> $this->expense()->where('approval_status','APPROVED');
        return [
            'unpaid_revenue'=>$this->sum($this->revenue()->with('payments')->whereBetween('transaction_date',[$from,$to])->get(),fn($row)=>$this->payments->revenueRemaining($row)),
            'paid_revenue'=>$this->paidRevenueBetween($from,$to),
            'approved_expenses'=>(string)$approved()->whereBetween('transaction_date',[$from,$to])->sum('amount_idr'),
            'approved_unpaid_expenses'=>$this->sum($approved()->with('payments')->whereBetween('transaction_date',[$from,$to])->get(),fn($row)=>$this->payments->operationalRemaining($row)),
            'paid_expenses'=>(string)\Illuminate\Support\Facades\DB::table('operational_cashflow_payments as payment')->join('operational_cashflows as cashflow','cashflow.id','=','payment.operational_cashflow_id')->where('cashflow.approval_status','APPROVED')->whereNull('payment.voided_at')->whereBetween('payment.paid_date',[$from,$to])->sum('payment.amount_idr'),
            'pending_transfers'=>(string)Revenue::query()->join('revenue_transfer_details as detail','detail.revenue_id','=','revenues.id')->where('revenues.revenue_type','MAIN_ACCOUNT_TRANSFER')->whereIn('revenues.approval_status',['DRAFT','SUBMITTED'])->sum('detail.gross_amount_idr'),
        ];
    }

    private function monthRanges(): array {
        $today=today(); $start=$today->copy()->startOfMonth(); $previous=$start->copy()->subMonth();
        return [$start->toDateString(),$today->toDateString(),$previous->toDateString(),$previous->copy()->endOfMonth()->toDateString()];
    }

    private function change(string $current,string $previous): ?string {
        if(Decimal::compare($previous,0)===0) return null;
        return Decimal::mul(Decimal::div(Decimal::sub($current,$previous,3),$previous,6),100,2);
    }
    private function sum($rows,callable $value): string { return $rows->reduce(fn($sum,$row)=>Decimal::add($sum,$value($row),0),'0'); }
    private function paidRevenueBetween(string $from,string $to): string {
        $cash=(string)\Illuminate\Support\Facades\DB::table('revenue_payments as payment')->join('revenues as revenue','revenue.id','=','payment.revenue_id')->whereIn('revenue.revenue_type',self::OPERATING_REVENUE_TYPES)->whereNull('payment.voided_at')->whereBetween('payment.paid_date',[$from,$to])->sum('payment.amount_idr');
        $direct=(string)$this->revenue()->where('payment_method','DIRECT_TRANSFER')->where('payment_status','PAID')->whereBetween('paid_at',[$from.' 00:00:00',$to.' 23:59:59'])->sum('amount_idr');
        return Decimal::add($cash,$direct,0);
    }
}
