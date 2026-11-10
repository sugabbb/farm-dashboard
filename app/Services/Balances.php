<?php
namespace App\Services;

use App\Models\LivestockGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Balances {
    public function feed(int $feedTypeId, ?string $date = null): string {
        $date ??= today()->toDateString();
        $opening=(string)DB::table('inventory_opening_balances')->where('item_kind','FEED')->where('feed_type_id',$feedTypeId)->where('effective_date','<=',$date)->sum('quantity_kg');
        $received=(string)DB::table('operational_cashflow_feed_details as d')->join('operational_cashflows as c','c.id','=','d.operational_cashflow_id')->where('d.feed_type_id',$feedTypeId)->where('c.approval_status','APPROVED')->whereNotNull('d.received_at')->whereDate('d.received_at','<=',$date)->sum('d.quantity_kg');
        $used=(string)DB::table('feed_usages as u')->join('daily_records as r','r.id','=','u.daily_record_id')->where('u.feed_type_id',$feedTypeId)->where('r.record_date','<=',$date)->sum('u.quantity_kg');
        $adjusted=(string)DB::table('inventory_adjustments')->where('item_kind','FEED')->where('feed_type_id',$feedTypeId)->where('effective_date','<=',$date)->sum('delta_kg');
        return Decimal::add(Decimal::sub(Decimal::add($opening,$received),$used),$adjusted);
    }

    public function egg(string $category, ?string $date = null): array {
        $date ??= today()->toDateString();
        $opening=DB::table('inventory_opening_balances')->where('item_kind','EGG')->where('egg_category',$category)->where('effective_date','<=',$date);
        $harvest=DB::table('egg_harvests as h')->join('daily_records as r','r.id','=','h.daily_record_id')->where('h.egg_category',$category)->where('r.record_date','<=',$date);
        $sold=DB::table('revenue_egg_details as d')->join('revenues as r','r.id','=','d.revenue_id')->where('d.egg_category',$category)->where('r.transaction_date','<=',$date);
        $adjust=DB::table('inventory_adjustments')->where('item_kind','EGG')->where('egg_category',$category)->where('effective_date','<=',$date);
        $kg=Decimal::add(Decimal::sub(Decimal::add((string)(clone $opening)->sum('quantity_kg'),(string)(clone $harvest)->sum('weight_kg')),(string)(clone $sold)->sum('weight_kg')),(string)(clone $adjust)->sum('delta_kg'));
        $pieces=(int)(clone $opening)->sum('quantity_pieces')+(int)(clone $harvest)->sum('quantity_pieces')-(int)(clone $sold)->sum('quantity_pieces')+(int)(clone $adjust)->sum('delta_pieces');
        return ['kg'=>$kg,'pieces'=>$pieces];
    }

    public function population(LivestockGroup|int $group, ?string $date = null): int {
        $group=$group instanceof LivestockGroup?$group:LivestockGroup::findOrFail($group);
        $date ??= today()->toDateString();
        if($date<$group->start_date->toDateString()) return 0;
        $mortality=(int)DB::table('daily_records')->where('group_id',$group->id)->where('record_date','<=',$date)->sum('mortality');
        $adjustments=(int)DB::table('population_adjustments')->where('group_id',$group->id)->where('effective_date','<=',$date)->sum('delta_ekor');
        return $group->initial_population-$mortality+$adjustments;
    }

    public function operationalCash(?string $date = null): string {
        $date ??= today()->toDateString();
        $opening=(string)DB::table('financial_opening_balances')->where('account','OPERATIONAL')->where('effective_date','<=',$date)->sum('amount_idr');
        $income=(string)DB::table('operational_cashflows')->where('direction','INFLOW')->where('payment_status','PAID')->whereDate('paid_at','<=',$date)->sum('amount_idr');
        $out=(string)DB::table('operational_cashflow_payments')->whereNull('voided_at')->where('paid_date','<=',$date)->sum('amount_idr');
        return Decimal::sub(Decimal::add($opening,$income,0),$out,0);
    }

    public function revenueCash(?string $date = null): string {
        $date ??= today()->toDateString();
        $opening=(string)DB::table('financial_opening_balances')->where('account','REVENUE')->where('effective_date','<=',$date)->sum('amount_idr');
        $income=(string)DB::table('revenue_payments')->whereNull('voided_at')->where('paid_date','<=',$date)->sum('amount_idr');
        $out=(string)DB::table('revenues as revenue')->join('revenue_transfer_details as detail','detail.revenue_id','=','revenue.id')->where('revenue.direction','OUTFLOW')->where('revenue.payment_status','PAID')->whereDate('revenue.paid_at','<=',$date)->sum('detail.gross_amount_idr');
        return Decimal::sub(Decimal::add($opening,$income,0),$out,0);
    }

    public function accountCash(string $account, ?string $date = null): string {
        return $account==='OPERATIONAL'?$this->operationalCash($date):$this->revenueCash($date);
    }

    /** @return array{projected_balance:string,earliest_negative_date:string}|null */
    public function projectedAfterImpact(string $account,string $effectiveDate,string $delta): ?array {
        return $this->projectedAfterImpacts($account,[$effectiveDate=>$delta]);
    }

    /**
     * Project a ledger after one or more dated adjustments. Each delta is applied
     * from its effective date onward against the currently persisted ledger.
     *
     * @param array<string,string> $impacts
     * @return array{projected_balance:string,earliest_negative_date:string}|null
     */
    public function projectedAfterImpacts(string $account,array $impacts): ?array {
        $table=$account==='OPERATIONAL'?'operational_cashflows':'revenues';
        $from=collect(array_keys($impacts))->min();
        $dates=collect(array_keys($impacts));
        if(today()->toDateString()>=$from) $dates->push(today()->toDateString());
        $dates=$dates
            ->merge(DB::table('financial_opening_balances')->where('account',$account)->where('effective_date','>=',$from)->pluck('effective_date'))
            ->merge(DB::table($table)->where('payment_status','PAID')->whereDate('paid_at','>=',$from)->pluck('paid_at')->map(fn($date)=>substr((string)$date,0,10)));
        $paymentTable=$account==='OPERATIONAL'?'operational_cashflow_payments':'revenue_payments';
        $dates=$dates->merge(DB::table($paymentTable)->whereNull('voided_at')->where('paid_date','>=',$from)->pluck('paid_date'));
        foreach($dates->unique()->sort() as $date) {
            $delta='0';
            foreach($impacts as $effectiveDate=>$impact) if($effectiveDate<=(string)$date) $delta=Decimal::add($delta,$impact,0);
            $projected=Decimal::add($this->accountCash($account,(string)$date),$delta,0);
            if(Decimal::compare($projected,0)<0) return ['projected_balance'=>$projected,'earliest_negative_date'=>(string)$date];
        }
        return null;
    }

    public function assertPopulationNonnegative(int $groupId, string $from): void {
        $dates=collect([$from,today()->toDateString()])
            ->merge(DB::table('daily_records')->where('group_id',$groupId)->where('record_date','>=',$from)->pluck('record_date'))
            ->merge(DB::table('population_adjustments')->where('group_id',$groupId)->where('effective_date','>=',$from)->pluck('effective_date'));
        foreach($dates->unique()->sort() as $date) if($this->population($groupId,(string)$date)<0) throw ValidationException::withMessages(['population'=>"Populasi menjadi negatif pada {$date}."]);
    }

    public function lock(): void { DB::select('select pg_advisory_xact_lock(20261009)'); }
}
