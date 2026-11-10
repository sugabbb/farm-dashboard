<?php
namespace App\Services;

use App\Models\{OperationalCashflow,Revenue};
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

final class FinancePayments {
    public function operationalTotal(OperationalCashflow $row): string { return (string)$row->payments()->whereNull('voided_at')->sum('amount_idr'); }
    public function revenueTotal(Revenue $row): string { return (string)$row->payments()->whereNull('voided_at')->sum('amount_idr'); }

    public function operationalPaidAmount(OperationalCashflow $row): string {
        if($row->direction==='INFLOW') return $row->payment_status==='PAID'?(string)$row->amount_idr:'0';
        return $row->payment_status==='PAID'?(string)$row->amount_idr:$this->operationalTotal($row);
    }
    public function revenuePaidAmount(Revenue $row): string {
        if($row->direction!=='INFLOW' || $row->payment_method!=='CASH') return $row->payment_status==='PAID'?(string)$row->amount_idr:'0';
        return $row->payment_status==='PAID'?(string)$row->amount_idr:$this->revenueTotal($row);
    }
    public function operationalRemaining(OperationalCashflow $row): string { return Decimal::sub((string)$row->amount_idr,$this->operationalPaidAmount($row),0); }
    public function revenueRemaining(Revenue $row): string { return Decimal::sub((string)$row->amount_idr,$this->revenuePaidAmount($row),0); }

    public function syncOperational(OperationalCashflow $row): OperationalCashflow {
        if($row->direction!=='OUTFLOW') return $row;
        return $this->sync($row,$this->operationalTotal($row));
    }
    public function syncRevenue(Revenue $row): Revenue {
        if($row->direction!=='INFLOW' || $row->payment_method!=='CASH') return $row;
        return $this->sync($row,$this->revenueTotal($row));
    }

    private function sync(OperationalCashflow|Revenue $row,string $total): OperationalCashflow|Revenue {
        if(Decimal::compare($total,(string)$row->amount_idr)>0) throw ValidationException::withMessages(['amount_idr'=>'Total pembayaran tidak boleh melebihi jumlah transaksi.']);
        $latest=$row->payments()->reorder()->whereNull('voided_at')->orderByDesc('paid_date')->orderByDesc('id')->first();
        if(!$latest) $values=['payment_status'=>'UNPAID','paid_at'=>null,'paid_by'=>null];
        elseif(Decimal::compare($total,(string)$row->amount_idr)===0) $values=['payment_status'=>'PAID','paid_at'=>Carbon::parse($latest->paid_date,config('app.timezone'))->startOfDay(),'paid_by'=>$latest->created_by];
        else $values=['payment_status'=>'PARTIALLY_PAID','paid_at'=>null,'paid_by'=>null];
        $row->update($values);
        return $row->fresh();
    }

    public function operationalData(OperationalCashflow $row): array { return $this->data($row,$this->operationalPaidAmount($row),$this->operationalRemaining($row)); }
    public function revenueData(Revenue $row): array { return $this->data($row,$this->revenuePaidAmount($row),$this->revenueRemaining($row)); }
    private function data(OperationalCashflow|Revenue $row,string $paid,string $remaining): array {
        $row->loadMissing('payments.creator','payments.voider');
        return ['paid_amount_idr'=>$paid,'remaining_amount_idr'=>$remaining,'payments'=>$row->payments->map(fn($payment)=>[
            'id'=>$payment->id,'payment_mode'=>$payment->payment_mode,'paid_date'=>$payment->paid_date->toDateString(),
            'amount_idr'=>(string)$payment->amount_idr,'proof_url'=>$payment->proof_url,'creator_name'=>$payment->creator?->name,
            'created_at'=>$payment->created_at->toIso8601String(),'voided_at'=>$payment->voided_at?->toIso8601String(),
            'voider_name'=>$payment->voider?->name,'void_reason'=>$payment->void_reason,
        ])->values()->all()];
    }
}
