<?php

namespace App\Services;

use App\Models\{OperationalCashflow, Revenue};
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class FinanceChartReport
{
    private const DEFINITIONS = [
        ['key'=>'paid_paid','title'=>'Revenue Paid vs Operational Paid','revenue_basis'=>'PAID_AT','operational_basis'=>'PAID_AT'],
        ['key'=>'transaction_paid','title'=>'Revenue Date vs Operational Paid','revenue_basis'=>'TRANSACTION_DATE','operational_basis'=>'PAID_AT'],
        ['key'=>'paid_transaction','title'=>'Revenue Paid vs Operational Transaction Date','revenue_basis'=>'PAID_AT','operational_basis'=>'TRANSACTION_DATE'],
        ['key'=>'transaction_transaction','title'=>'Revenue Date vs Operational Transaction Date','revenue_basis'=>'TRANSACTION_DATE','operational_basis'=>'TRANSACTION_DATE'],
    ];

    public function build(int $months, string $granularity,?array $revenueTypes=null,?array $operationalTypes=null): array
    {
        $today=today(config('app.timezone'))->startOfDay();
        $from=$today->copy()->startOfMonth()->subMonths($months-1);
        $to=$today->copy();
        [$allRevenues,$allOperational]=$this->rows($from,$to);
        $availableRevenue=$allRevenues->pluck('revenue_type')->unique()->sort()->values()->all();
        $availableOperational=$allOperational->pluck('cashflow_type')->unique()->sort()->values()->all();
        $revenueTypes=$revenueTypes===null?$availableRevenue:array_values(array_intersect($revenueTypes,$availableRevenue));
        $operationalTypes=$operationalTypes===null?$availableOperational:array_values(array_intersect($operationalTypes,$availableOperational));
        $revenues=$allRevenues->whereIn('revenue_type',$revenueTypes)->values();
        $operational=$allOperational->whereIn('cashflow_type',$operationalTypes)->values();

        return [
            'filters'=>['months'=>$months,'granularity'=>$granularity,'revenue_types'=>$revenueTypes,'operational_types'=>$operationalTypes],
            'available_types'=>[
                'revenues'=>collect($availableRevenue)->map(fn($type)=>['value'=>$type,'label'=>MonthlyReport::REVENUE_LABELS[$type]])->values()->all(),
                'operational'=>collect($availableOperational)->map(fn($type)=>['value'=>$type,'label'=>MonthlyReport::EXPENSE_LABELS[$type]])->values()->all(),
            ],
            'range'=>['from'=>$from->toDateString(),'to'=>$to->toDateString()],
            'series'=>[
                'weekly'=>$this->charts($this->weeklyBuckets($from,$to),$revenues,$operational),
                'monthly'=>$this->charts($this->monthlyBuckets($from,$to),$revenues,$operational),
            ],
        ];
    }

    private function rows(Carbon $from,Carbon $to): array
    {
        $utcFrom=$from->copy()->startOfDay()->utc(); $utcTo=$to->copy()->endOfDay()->utc();
        $dates=function($query) use($from,$to,$utcFrom,$utcTo) {
            $query->whereBetween('transaction_date',[$from->toDateString(),$to->toDateString()])
                ->orWhereBetween('paid_at',[$utcFrom,$utcTo])
                ->orWhereHas('payments',fn($payments)=>$payments->whereNull('voided_at')->whereBetween('paid_date',[$from->toDateString(),$to->toDateString()]));
        };
        $revenues=Revenue::query()->whereIn('revenue_type',FinanceMetrics::OPERATING_REVENUE_TYPES)
            ->whereIn('approval_status',['NOT_REQUIRED','APPROVED'])->where($dates)
            ->with('payments')->get(['id','revenue_type','payment_method','transaction_date','amount_idr','payment_status','paid_at']);
        $operational=OperationalCashflow::query()->where('direction','OUTFLOW')->where('approval_status','APPROVED')
            ->where($dates)->with('payments')->get(['id','cashflow_type','transaction_date','amount_idr','payment_status','paid_at']);
        return [$revenues,$operational];
    }

    private function charts(array $buckets,Collection $revenues,Collection $operational): array
    {
        return collect(self::DEFINITIONS)->map(function(array $definition) use($buckets,$revenues,$operational) {
            return $definition+['points'=>collect($buckets)->map(function(array $bucket) use($definition,$revenues,$operational) {
                return [
                    'date'=>$bucket['date'],
                    'revenue'=>(float)$this->sum($revenues,$definition['revenue_basis'],$bucket['revenue_from'],$bucket['revenue_to'],'revenue'),
                    'operational'=>(float)$this->sum($operational,$definition['operational_basis'],$bucket['operational_from'],$bucket['operational_to'],'operational'),
                    'revenue_from'=>$bucket['revenue_from']->toDateString(),'revenue_to'=>$bucket['revenue_to']->toDateString(),
                    'operational_from'=>$bucket['operational_from']->toDateString(),'operational_to'=>$bucket['operational_to']->toDateString(),
                    'partial'=>$bucket['partial'],
                ];
            })->all()];
        })->all();
    }

    private function sum(Collection $rows,string $basis,Carbon $from,Carbon $to,string $ledger): string
    {
        if($from->gt($to)) return '0';
        return $rows->reduce(function(string $sum,$row) use($basis,$from,$to,$ledger) {
            if($basis==='PAID_AT') {
                $manual=$ledger==='operational'||$row->payment_method==='CASH';
                if($manual) {
                    $payments=$row->payments->whereNull('voided_at')->sortBy(fn($payment)=>$payment->paid_date->toDateString().str_pad((string)$payment->id,20,'0',STR_PAD_LEFT))->values();
                    foreach($payments as $index=>$payment) {
                        $date=$payment->paid_date->copy()->startOfDay();
                        $amount=(string)$payment->amount_idr;
                        if($row->payment_status==='PAID' && $index===$payments->count()-1) $amount=Decimal::sub((string)$row->amount_idr,(string)$payments->take($index)->sum('amount_idr'),0);
                        if($date->betweenIncluded($from,$to)) $sum=Decimal::add($sum,$amount,0);
                    }
                    return $sum;
                }
                if($row->payment_status!=='PAID' || !$row->paid_at) return $sum;
                $date=$row->paid_at->copy()->timezone(config('app.timezone'))->startOfDay();
            } else $date=$row->transaction_date->copy()->startOfDay();
            return $date->betweenIncluded($from,$to)?Decimal::add($sum,(string)$row->amount_idr,0):$sum;
        },'0');
    }

    private function weeklyBuckets(Carbon $from,Carbon $to): array
    {
        $buckets=[]; $monday=$from->copy()->startOfWeek(Carbon::MONDAY);
        while($monday->lte($to)) {
            $revenueFullFrom=$monday->copy(); $revenueFullTo=$monday->copy()->addDays(6);
            $operationalFullFrom=$monday->copy()->subDay(); $operationalFullTo=$monday->copy()->addDays(5);
            $revenueFrom=$this->maxDate($revenueFullFrom,$from); $revenueTo=$this->minDate($revenueFullTo,$to);
            $operationalFrom=$this->maxDate($operationalFullFrom,$from); $operationalTo=$this->minDate($operationalFullTo,$to);
            $current=$revenueFullFrom->lte($to)&&$revenueFullTo->gte($to);
            $buckets[]=[
                'date'=>($current?$to:$revenueFullTo)->toDateString(),
                'revenue_from'=>$revenueFrom,'revenue_to'=>$revenueTo,
                'operational_from'=>$operationalFrom,'operational_to'=>$operationalTo,
                'partial'=>$current||!$revenueFrom->equalTo($revenueFullFrom)||!$revenueTo->equalTo($revenueFullTo)||!$operationalFrom->equalTo($operationalFullFrom)||!$operationalTo->equalTo($operationalFullTo),
            ];
            $monday->addWeek();
        }
        return $buckets;
    }

    private function monthlyBuckets(Carbon $from,Carbon $to): array
    {
        $buckets=[]; $month=$from->copy()->startOfMonth();
        while($month->lte($to)) {
            $fullTo=$month->copy()->endOfMonth(); $periodTo=$this->minDate($fullTo,$to); $current=$month->isSameMonth($to);
            $buckets[]=[
                'date'=>($current?$to:$fullTo)->toDateString(),
                'revenue_from'=>$month->copy(),'revenue_to'=>$periodTo->copy(),
                'operational_from'=>$month->copy(),'operational_to'=>$periodTo->copy(),
                'partial'=>$current||!$periodTo->equalTo($fullTo),
            ];
            $month->addMonth()->startOfMonth();
        }
        return $buckets;
    }

    private function maxDate(Carbon $left,Carbon $right): Carbon { return $left->gte($right)?$left->copy():$right->copy(); }
    private function minDate(Carbon $left,Carbon $right): Carbon { return $left->lte($right)?$left->copy():$right->copy(); }
}
