<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const MARKER='2026_10_13_000004_backfill_automatic_finance_payments';

    public function up(): void
    {
        DB::transaction(function () {
            DB::select('select pg_advisory_xact_lock(20261009)');
            $topups=DB::table('operational_cashflows')->where('cashflow_type','MAIN_ACCOUNT_TOPUP')->where('payment_status','UNPAID')->orderBy('id')->lockForUpdate()->get();
            $transfers=DB::table('revenues')->where('revenue_type','MAIN_ACCOUNT_TRANSFER')->where('approval_status','APPROVED')->where('payment_status','UNPAID')->orderBy('id')->lockForUpdate()->get();
            foreach($transfers as $transfer) if(!$transfer->approved_at || !$transfer->approved_by) throw new RuntimeException("Approved transfer {$transfer->id} is missing approval history; automatic payment backfill aborted.");

            $expectedTopups=['count'=>$topups->count(),'amount'=>(int)$topups->sum('amount_idr')];
            $expectedTransfers=['count'=>$transfers->count(),'amount'=>(int)$transfers->sum('amount_idr')];
            $now=now();
            foreach($topups as $topup) {
                $before=(array)$topup; $paidAt=$topup->transaction_date.' 00:00:00+07';
                DB::table('operational_cashflows')->where('id',$topup->id)->update(['payment_status'=>'PAID','paid_at'=>$paidAt,'paid_by'=>$topup->created_by]);
                $after=(array)DB::table('operational_cashflows')->where('id',$topup->id)->first();
                $this->audit('operational_cashflows',(int)$topup->id,$before,$after,$now);
            }
            foreach($transfers as $transfer) {
                $before=(array)$transfer;
                DB::table('revenues')->where('id',$transfer->id)->update(['payment_status'=>'PAID','paid_at'=>$transfer->approved_at,'paid_by'=>$transfer->approved_by]);
                $after=(array)DB::table('revenues')->where('id',$transfer->id)->first();
                $this->audit('revenues',(int)$transfer->id,$before,$after,$now);
            }

            $actualTopups=['count'=>DB::table('operational_cashflows')->whereIn('id',$topups->pluck('id'))->where('payment_status','PAID')->count(),'amount'=>(int)DB::table('operational_cashflows')->whereIn('id',$topups->pluck('id'))->where('payment_status','PAID')->sum('amount_idr')];
            $actualTransfers=['count'=>DB::table('revenues')->whereIn('id',$transfers->pluck('id'))->where('payment_status','PAID')->count(),'amount'=>(int)DB::table('revenues')->whereIn('id',$transfers->pluck('id'))->where('payment_status','PAID')->sum('amount_idr')];
            if($expectedTopups!==$actualTopups || $expectedTransfers!==$actualTransfers) throw new RuntimeException('Automatic payment backfill reconciliation failed; all changes were rolled back.');

            if($transfers->isNotEmpty()) $this->auditHistoricalRevenueDeficit($transfers,$now);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::select('select pg_advisory_xact_lock(20261009)');
            $events=DB::table('audit_events')->where('action','automatic_payment_backfilled')->where('reason',self::MARKER)->orderByDesc('id')->get();
            foreach($events as $event) {
                $before=json_decode($event->before,true);
                if(!$before || !in_array($event->entity_type,['operational_cashflows','revenues'],true)) continue;
                DB::table($event->entity_type)->where('id',$event->entity_id)->update([
                    'payment_status'=>$before['payment_status'], 'paid_at'=>$before['paid_at'], 'paid_by'=>$before['paid_by'],
                ]);
            }
            DB::table('audit_events')->where('reason',self::MARKER)->whereIn('action',['automatic_payment_backfilled','negative_balance_override'])->delete();
        });
    }

    private function audit(string $entityType,int $entityId,array $before,array $after,mixed $now): void
    {
        DB::table('audit_events')->insert([
            'actor_id'=>null,'entity_type'=>$entityType,'entity_id'=>$entityId,'action'=>'automatic_payment_backfilled',
            'happened_at'=>$now,'reason'=>self::MARKER,'before'=>json_encode($before),'after'=>json_encode($after),
            'created_at'=>$now,'updated_at'=>$now,
        ]);
    }

    private function auditHistoricalRevenueDeficit(mixed $transfers,mixed $now): void
    {
        $daily=[];
        foreach(DB::table('financial_opening_balances')->where('account','REVENUE')->get() as $opening) $daily[(string)$opening->effective_date]=($daily[(string)$opening->effective_date]??0)+(int)$opening->amount_idr;
        foreach(DB::table('revenues')->where('payment_status','PAID')->orderBy('paid_at')->get() as $movement) {
            $date=substr((string)$movement->paid_at,0,10); $sign=$movement->direction==='INFLOW'?1:-1;
            $daily[$date]=($daily[$date]??0)+$sign*(int)$movement->amount_idr;
        }
        ksort($daily); $balance=0; $earliest=null; $earliestBalance=null; $minimum=0;
        foreach($daily as $date=>$delta) {
            $balance+=$delta;
            if($balance<0 && $earliest===null) { $earliest=$date; $earliestBalance=$balance; }
            $minimum=min($minimum,$balance);
        }
        if($earliest===null) return;
        $responsible=$transfers->first(fn($transfer)=>substr((string)$transfer->approved_at,0,10)===$earliest)
            ??$transfers->first(fn($transfer)=>substr((string)$transfer->approved_at,0,10)<=$earliest)
            ??$transfers->first();
        $after=['account'=>'REVENUE','effective_date'=>$earliest,'earliest_negative_date'=>$earliest,'projected_balance'=>(string)$earliestBalance,'minimum_projected_balance'=>(string)$minimum,'affected_transfer_ids'=>$transfers->pluck('id')->map(fn($id)=>(int)$id)->values()->all(),'migration'=>self::MARKER];
        DB::table('audit_events')->insert([
            'actor_id'=>null,'entity_type'=>'revenues','entity_id'=>$responsible->id,'action'=>'negative_balance_override',
            'happened_at'=>$now,'reason'=>self::MARKER,'before'=>null,'after'=>json_encode($after),'created_at'=>$now,'updated_at'=>$now,
        ]);
    }
};
