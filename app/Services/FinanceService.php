<?php
namespace App\Services;

use App\Models\{Customer,FeedType,OperationalCashflow,OperationalCashflowFeedDetail,OperationalCashflowPayment,Revenue,RevenueEggDetail,RevenueFishDetail,RevenuePayment,User};
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinanceService {
    private const CASHFLOW_DETAIL_TABLES=['operational_cashflow_salary_details','operational_cashflow_utility_details','operational_cashflow_health_details','operational_cashflow_other_details','operational_cashflow_livestock_purchase_details','operational_cashflow_bank_admin_details','operational_cashflow_topup_details'];
    public const CASHFLOW_OUTFLOW_TYPES=['FEED_AYAM','FEED_LELE','SALARY','UTILITY','HEALTH','OTHER','LELE_SEED_PURCHASE','PULLET_PURCHASE','BANK_ADMIN_FEE'];
    public const REVENUE_INFLOW_TYPES=['EGG_SALE','FISH_SALE','OTHER'];
    public function __construct(private Balances $balances,private FinancePayments $payments) {}
    private function fail(string $message,string $key='transaction'): never { throw ValidationException::withMessages([$key=>$message]); }

    public function saveOperationalCashflow(array $data,User $actor,?OperationalCashflow $cashflow=null,bool $correction=false,?string $reason=null): OperationalCashflow {
        return DB::transaction(function() use($data,$actor,$cashflow,$correction,$reason) {
            $this->balances->lock();
            if($correction && (!$actor->isSuperadmin() || !filled($reason))) $this->fail('Koreksi memerlukan Superadmin dan alasan.');
            if($cashflow) {
                $cashflow=OperationalCashflow::lockForUpdate()->findOrFail($cashflow->id);
                if(!$correction && ($cashflow->payment_status==='PAID' || $cashflow->payments()->whereNull('voided_at')->exists())) $this->fail('Transaksi yang sudah memiliki pembayaran hanya dapat dikoreksi Superadmin.');
                if(!$correction && $cashflow->feedDetails()->whereNotNull('received_at')->exists()) $this->fail('Rincian pakan yang sudah diterima hanya dapat dikoreksi Superadmin.');
                if(!$correction && !in_array($cashflow->approval_status,['DRAFT','REJECTED','APPROVED','NOT_REQUIRED'])) $this->fail('Transaksi sedang menunggu persetujuan dan tidak dapat diedit.');
            }
            $before=$cashflow?->load('feedDetails')->toArray(); $oldFeed=$cashflow?->feedDetails()->get()->keyBy('id')??collect(); $creating=$cashflow===null;
            $oldAmount=$cashflow?(string)$cashflow->amount_idr:null; $oldApproval=$cashflow?->approval_status; $oldPayment=$cashflow?->payment_status;
            $type=$data['cashflow_type']; $direction=$type==='MAIN_ACCOUNT_TOPUP'?'INFLOW':'OUTFLOW'; $details=$data['details']??[];
            if($cashflow && $cashflow->direction!==$direction) $this->fail('Arah arus kas tidak dapat diubah. Buat transaksi baru.');
            if(in_array($type,['FEED_AYAM','FEED_LELE'])) {
                if(count($details)<1) $this->fail('Minimal satu rincian pakan diperlukan.');
                $species=$type==='FEED_AYAM'?'AYAM':'LELE'; $amount='0';
                foreach($details as $index=>&$line) {
                    if(!empty($line['id']) && !$oldFeed->has((int)$line['id'])) $this->fail('Rincian pakan tidak termasuk dalam transaksi ini.',"details.$index.id");
                    $feed=FeedType::findOrFail($line['feed_type_id']);
                    if($feed->species!==$species) $this->fail('Jenis pakan tidak sesuai kategori pengeluaran.');
                    $line=$this->normalizeFeedPurchase($line);
                    $amount=Decimal::add($amount,$line['line_amount_idr'],0);
                }
                unset($line);
            } else $amount=Decimal::money($data['amount_idr']);
            if($cashflow && $cashflow->direction==='OUTFLOW' && Decimal::compare($amount,$this->payments->operationalTotal($cashflow))<0) $this->fail('Jumlah transaksi tidak boleh lebih kecil dari pembayaran yang sudah tercatat.','amount_idr');
            $cashflow ??= new OperationalCashflow(['created_by'=>$actor->id,'payment_status'=>'UNPAID']);
            $status=$direction==='INFLOW'?'NOT_REQUIRED':($cashflow->exists?$cashflow->approval_status:'DRAFT');
            $approvedIncrease=$cashflow->exists && $direction==='OUTFLOW' && $oldApproval==='APPROVED' && Decimal::compare($amount,$oldAmount)>0;
            $approvedPreserved=$cashflow->exists && $direction==='OUTFLOW' && $oldApproval==='APPROVED' && !$approvedIncrease;
            if($approvedIncrease) $status='DRAFT';
            $cashflow->fill(['cashflow_type'=>$type,'direction'=>$direction,'transaction_date'=>$data['transaction_date'],'amount_idr'=>$amount,'approval_status'=>$status,'rejection_reason'=>null]);
            if($approvedIncrease) {
                $cashflow->fill([
                    'submitted_at'=>null,'submitted_by'=>null,'approved_at'=>null,'approved_by'=>null,
                    'rejected_at'=>null,'rejected_by'=>null,
                ]);
                if($cashflow->payments()->whereNull('voided_at')->exists()) $cashflow->fill(['payment_status'=>'PARTIALLY_PAID','paid_at'=>null,'paid_by'=>null]);
            }
            if($correction && $cashflow->payment_status==='PAID' && $type==='MAIN_ACCOUNT_TOPUP') $cashflow->fill(['paid_at'=>$this->dateTime($this->notAfterToday($data['transaction_date']))]);
            if(isset($data['proof_path'],$data['proof_url'])) $cashflow->fill(['proof_path'=>$data['proof_path'],'proof_url'=>$data['proof_url']]);
            if(in_array($status,['DRAFT','NOT_REQUIRED']) && $cashflow->exists && !$correction) $cashflow->fill(['approved_at'=>null,'approved_by'=>null,'rejected_at'=>null,'rejected_by'=>null]);
            $cashflow->save();
            $retainedFeedIds=in_array($type,['FEED_AYAM','FEED_LELE'])?collect($details)->pluck('id')->filter()->map(fn($id)=>(int)$id):collect();
            if($correction) foreach($oldFeed->except($retainedFeedIds->all()) as $deletedLine) Audit::record($deletedLine,'cashflow_feed_detail_deleted',$deletedLine->toArray(),$reason,[],$actor->id);
            $cashflow->feedDetails()->delete();
            foreach(self::CASHFLOW_DETAIL_TABLES as $table) DB::table($table)->where('operational_cashflow_id',$cashflow->id)->delete();
            $base=['operational_cashflow_id'=>$cashflow->id,'created_at'=>now(),'updated_at'=>now()];
            if(in_array($type,['FEED_AYAM','FEED_LELE'])) foreach($details as $line) {
                $old=!empty($line['id'])?$oldFeed->get((int)$line['id']):null;
                OperationalCashflowFeedDetail::create($base+[
                    'feed_type_id'=>$line['feed_type_id'],'purchase_unit'=>$line['purchase_unit'],
                    'purchase_quantity'=>$line['purchase_quantity'],'kg_per_sak'=>$line['kg_per_sak'],
                    'price_per_purchase_unit_idr'=>$line['price_per_purchase_unit_idr'],
                    'quantity_kg'=>$line['quantity_kg'],'price_per_kg_idr'=>$line['price_per_kg_idr'],
                    'line_amount_idr'=>$line['line_amount_idr'],'received_at'=>$old?->received_at,'received_by'=>$old?->received_by,
                ]);
            } elseif($type==='SALARY') DB::table('operational_cashflow_salary_details')->insert($base+['employee_id'=>$details['employee_id'],'salary_period'=>$details['salary_period']]);
            elseif($type==='UTILITY') DB::table('operational_cashflow_utility_details')->insert($base+['description'=>$details['description']]);
            elseif($type==='HEALTH') DB::table('operational_cashflow_health_details')->insert($base+['species'=>$details['species'],'description'=>$details['description']]);
            elseif($type==='OTHER') DB::table('operational_cashflow_other_details')->insert($base+['description'=>$details['description']]);
            elseif(in_array($type,['LELE_SEED_PURCHASE','PULLET_PURCHASE'])) DB::table('operational_cashflow_livestock_purchase_details')->insert($base+['species'=>$type==='LELE_SEED_PURCHASE'?'LELE':'AYAM','notes'=>$details['notes']??null]);
            elseif($type==='BANK_ADMIN_FEE') DB::table('operational_cashflow_bank_admin_details')->insert($base+['notes'=>$details['notes']??null]);
            else DB::table('operational_cashflow_topup_details')->insert($base+['notes'=>$details['notes']??null]);
            if($creating && $type==='MAIN_ACCOUNT_TOPUP') {
                Audit::record($cashflow,'cashflow_created',null,null,null,$actor->id);
                $beforePayment=$cashflow->toArray();
                $cashflow->update(['payment_status'=>'PAID','paid_at'=>$this->dateTime($this->notAfterToday($data['transaction_date'])),'paid_by'=>$actor->id]);
                Audit::record($cashflow,'cashflow_paid',$beforePayment,'Pembayaran otomatis saat Top Up dibuat.',null,$actor->id);
            } else {
                $action=$correction?'cashflow_corrected':($before?'cashflow_updated':'cashflow_created');
                if($approvedPreserved) $action='cashflow_approved_edit_preserved';
                elseif($approvedIncrease && $oldPayment==='PAID') $action='cashflow_paid_increase_reset';
                elseif($approvedIncrease) $action='cashflow_approval_increase_reset';
                Audit::record($cashflow,$action,$before,$reason,null,$actor->id);
            }
            if($direction==='OUTFLOW' && $cashflow->payments()->whereNull('voided_at')->exists()) $this->payments->syncOperational($cashflow);
            return $cashflow->fresh(['feedDetails','payments']);
        },3);
    }

    public function submitOperational(OperationalCashflow $cashflow,User $actor): OperationalCashflow {
        return DB::transaction(function() use($cashflow,$actor) {
            $cashflow=OperationalCashflow::lockForUpdate()->findOrFail($cashflow->id);
            if($cashflow->direction!=='OUTFLOW' || !in_array($cashflow->approval_status,['DRAFT','REJECTED'])) $this->fail('Status Operational Cashflow tidak dapat diajukan.');
            $before=$cashflow->toArray(); $cashflow->update(['approval_status'=>'SUBMITTED','submitted_by'=>$actor->id,'submitted_at'=>now(),'rejection_reason'=>null]); Audit::record($cashflow,'cashflow_submitted',$before,null,null,$actor->id); return $cashflow;
        });
    }

    public function decideOperational(OperationalCashflow $cashflow,User $actor,string $decision,?string $reason=null): OperationalCashflow {
        abort_unless($actor->isSuperadmin(),403);
        return DB::transaction(function() use($cashflow,$actor,$decision,$reason) {
            $cashflow=OperationalCashflow::lockForUpdate()->findOrFail($cashflow->id);
            if($cashflow->direction!=='OUTFLOW' || $cashflow->approval_status!=='SUBMITTED') $this->fail('Hanya pengeluaran yang diajukan dapat diputuskan.');
            $before=$cashflow->toArray();
            if($decision==='APPROVED') $cashflow->update(['approval_status'=>'APPROVED','approved_by'=>$actor->id,'approved_at'=>now(),'rejected_by'=>null,'rejected_at'=>null,'rejection_reason'=>null]);
            else $cashflow->update(['approval_status'=>'REJECTED','rejected_by'=>$actor->id,'rejected_at'=>now(),'approved_by'=>null,'approved_at'=>null,'rejection_reason'=>$reason]);
            Audit::record($cashflow,strtolower($decision),$before,$reason,null,$actor->id); return $cashflow;
        });
    }

    public function payOperational(OperationalCashflow $cashflow,User $actor,string $date,string $mode='FULL',mixed $amount=null,?array $proof=null): OperationalCashflow {
        return DB::transaction(function() use($cashflow,$actor,$date,$mode,$amount,$proof) {
            $this->balances->lock(); $cashflow=OperationalCashflow::lockForUpdate()->findOrFail($cashflow->id);
            $this->assertPaymentDate($date);
            if($cashflow->direction!=='OUTFLOW') $this->fail('Top Up otomatis Paid saat dibuat dan tidak dapat dibayar manual.');
            if($cashflow->approval_status!=='APPROVED') $this->fail('Pengeluaran harus disetujui sebelum dibayar.');
            if($cashflow->payment_status==='PAID') return $cashflow;
            $before=$cashflow->toArray(); $remaining=$this->payments->operationalRemaining($cashflow); $paymentAmount=$this->paymentAmount($mode,$amount,$remaining);
            OperationalCashflowPayment::create(['operational_cashflow_id'=>$cashflow->id,'paid_date'=>$date,'amount_idr'=>$paymentAmount,'payment_mode'=>$mode,'proof_path'=>$proof['path']??null,'proof_url'=>$proof['url']??null,'created_by'=>$actor->id]);
            $cashflow=$this->payments->syncOperational($cashflow);
            Audit::record($cashflow,$cashflow->payment_status==='PAID'?'cashflow_paid':'cashflow_partially_paid',$before,null,$cashflow->toArray()+['payment_amount_idr'=>$paymentAmount,'payment_date'=>$date,'payment_proof_url'=>$proof['url']??null],$actor->id);
            return $cashflow;
        });
    }

    public function voidOperationalPayment(OperationalCashflow $cashflow,OperationalCashflowPayment $payment,User $actor,string $reason): OperationalCashflow {
        abort_unless($actor->isSuperadmin(),403);
        return DB::transaction(function() use($cashflow,$payment,$actor,$reason) {
            $this->balances->lock(); $cashflow=OperationalCashflow::lockForUpdate()->findOrFail($cashflow->id); $payment=OperationalCashflowPayment::lockForUpdate()->findOrFail($payment->id);
            if($payment->operational_cashflow_id!==$cashflow->id) $this->fail('Pembayaran tidak termasuk transaksi ini.');
            if($payment->voided_at) return $cashflow;
            $before=$cashflow->toArray(); $payment->update(['voided_at'=>now(),'voided_by'=>$actor->id,'void_reason'=>$reason]); $cashflow=$this->payments->syncOperational($cashflow);
            Audit::record($cashflow,'cashflow_payment_voided',$before,$reason,$cashflow->toArray()+['payment_amount_idr'=>(string)$payment->amount_idr,'payment_date'=>$payment->paid_date->toDateString()],$actor->id); return $cashflow;
        });
    }

    public function receive(OperationalCashflowFeedDetail $line,User $actor,string $date): OperationalCashflowFeedDetail {
        return DB::transaction(function() use($line,$actor,$date) {
            $this->balances->lock(); $line=OperationalCashflowFeedDetail::lockForUpdate()->findOrFail($line->id); $line->load('operationalCashflow');
            if($line->operationalCashflow->approval_status!=='APPROVED') $this->fail('Pakan hanya dapat diterima setelah pengeluaran disetujui.');
            if($line->received_at) return $line;
            $before=$line->toArray(); $line->update(['received_at'=>$this->dateTime($date),'received_by'=>$actor->id]); Audit::record($line,'feed_received',$before,null,null,$actor->id); return $line;
        });
    }

    public function saveRevenue(array $data,User $actor,?Revenue $revenue=null,bool $correction=false,?string $reason=null,bool $acknowledgeNegative=false,bool $allowAbkPaidCorrection=false): Revenue {
        return DB::transaction(function() use($data,$actor,$revenue,$correction,$reason,$acknowledgeNegative,$allowAbkPaidCorrection) {
            $this->balances->lock();
            if($correction && ((!$actor->isSuperadmin() && !$allowAbkPaidCorrection) || !filled($reason))) $this->fail('Koreksi memerlukan otorisasi dan alasan.');
            if($revenue) {
                $revenue=Revenue::lockForUpdate()->findOrFail($revenue->id);
                if(!$correction && ($revenue->payment_status==='PAID' || $revenue->payments()->whereNull('voided_at')->exists())) $this->fail('Transaksi yang sudah memiliki pembayaran hanya dapat dikoreksi Superadmin.');
                if(!$correction && $revenue->approval_status==='SUBMITTED') $this->fail('Transaksi sedang menunggu persetujuan dan tidak dapat diedit.');
            }
            $before=$revenue?->load(['eggDetails','fishDetail'])->toArray(); $oldEgg=$revenue?->eggDetails()->get()->keyBy('id')??collect();
            $oldTransfer=$revenue?DB::table('revenue_transfer_details')->where('revenue_id',$revenue->id)->first():null;
            if($before && $oldTransfer) $before+=['gross_amount_idr'=>(string)$oldTransfer->gross_amount_idr,'payment_cost_idr'=>(string)$oldTransfer->payment_cost_idr];
            $type=$data['revenue_type']; $direction=$type==='MAIN_ACCOUNT_TRANSFER'?'OUTFLOW':'INFLOW'; $details=$data['details']??[];
            $paymentMethod=$direction==='INFLOW'?($data['payment_method']??'CASH'):null;
            if($revenue && $revenue->direction!==$direction) $this->fail('Arah arus kas tidak dapat diubah. Buat transaksi baru.');
            $customerId=null;
            if(in_array($type,['EGG_SALE','FISH_SALE'])) {
                if(!empty($data['new_customer'])) { $customer=Customer::create($data['new_customer']); Audit::record($customer,'customer_created'); $customerId=$customer->id; }
                else $customerId=!empty($data['customer_id'])?(int)$data['customer_id']:null;
            }
            if($type==='EGG_SALE') {
                foreach($details as $index=>$line) if(!empty($line['id']) && !$oldEgg->has((int)$line['id'])) $this->fail('Rincian telur tidak termasuk dalam transaksi ini.',"details.$index.id");
                $amount=collect($details)->reduce(fn($sum,$line)=>Decimal::add($sum,$this->lineAmount($line['weight_kg'],$line['price_per_kg_idr']),0),'0');
            }
            elseif($type==='FISH_SALE') $amount=$this->lineAmount($details['weight_kg'],$details['price_per_kg_idr']);
            elseif($type==='MAIN_ACCOUNT_TRANSFER') {
                // Keep service-level callers and historical jobs compatible while the
                // HTTP contract requires the new gross/cost detail shape.
                $gross=Decimal::money($details['gross_amount_idr']??$data['amount_idr']??0); $cost=Decimal::money($details['payment_cost_idr']??0);
                $amount=Decimal::sub($gross,$cost,0);
            } else $amount=Decimal::money($data['amount_idr']);
            if($revenue && $revenue->direction==='INFLOW' && $revenue->payment_method==='CASH') {
                $paidTotal=$this->payments->revenueTotal($revenue);
                if(Decimal::compare($amount,$paidTotal)<0) $this->fail('Jumlah transaksi tidak boleh lebih kecil dari pembayaran yang sudah tercatat.','amount_idr');
                if(Decimal::compare($paidTotal,0)>0 && $paymentMethod!=='CASH') $this->fail('Tipe pembayaran tidak dapat diubah setelah pembayaran tercatat.','payment_method');
            }
            if($paymentMethod==='DIRECT_TRANSFER' && isset($data['direct_transfer_date'])) $this->assertPaymentDate($data['direct_transfer_date'],'direct_transfer_date');
            $correctedPaidDate=$paymentMethod==='DIRECT_TRANSFER'?($data['direct_transfer_date']??null):($data['paid_date']??$revenue?->paid_at?->toDateString());
            if($correction && $revenue?->payment_status==='PAID') $this->guardRevenueCorrection($revenue,$oldTransfer,$direction,$paymentMethod,$amount,$gross??null,$correctedPaidDate,$acknowledgeNegative,$actor);
            $revenue ??= new Revenue(['created_by'=>$actor->id,'payment_status'=>'UNPAID']);
            if($direction==='OUTFLOW') {
                $status=$revenue->exists?$revenue->approval_status:'DRAFT';
                if(!$correction && $revenue->exists && $status==='APPROVED') $status='DRAFT';
            } elseif($paymentMethod==='DIRECT_TRANSFER') $status=$correction && $revenue->payment_status==='PAID'?'APPROVED':'SUBMITTED';
            else $status='NOT_REQUIRED';
            $revenue->fill(['revenue_type'=>$type,'direction'=>$direction,'transaction_date'=>$data['transaction_date'],'amount_idr'=>$amount,'customer_id'=>$customerId,'payment_method'=>$paymentMethod,'direct_transfer_date'=>$paymentMethod==='DIRECT_TRANSFER'?($data['direct_transfer_date']??null):null,'approval_status'=>$status,'rejection_reason'=>null]);
            if(isset($data['proof_path'],$data['proof_url'])) $revenue->fill(['proof_path'=>$data['proof_path'],'proof_url'=>$data['proof_url']]);
            if($status==='SUBMITTED') $revenue->fill(['submitted_at'=>now(),'submitted_by'=>$actor->id,'approved_at'=>null,'approved_by'=>null,'rejected_at'=>null,'rejected_by'=>null]);
            elseif(in_array($status,['DRAFT','NOT_REQUIRED']) && (!$correction || $status==='NOT_REQUIRED')) $revenue->fill(['submitted_at'=>null,'submitted_by'=>null,'approved_at'=>null,'approved_by'=>null,'rejected_at'=>null,'rejected_by'=>null]);
            if($correction && $revenue->payment_status==='PAID') {
                if($paymentMethod==='DIRECT_TRANSFER') $revenue->fill(['approval_status'=>'APPROVED','approved_at'=>now(),'approved_by'=>$actor->id,'paid_at'=>$this->dateTime($data['direct_transfer_date']),'paid_by'=>$actor->id]);
                elseif($direction==='INFLOW' && $paymentMethod!=='CASH') $revenue->fill(['approval_status'=>'NOT_REQUIRED','submitted_at'=>null,'submitted_by'=>null,'approved_at'=>null,'approved_by'=>null,'rejected_at'=>null,'rejected_by'=>null,'paid_at'=>$this->dateTime($correctedPaidDate)]);
            }
            $revenue->save();
            $retainedEggIds=$type==='EGG_SALE'?collect($details)->pluck('id')->filter()->map(fn($id)=>(int)$id):collect();
            if($correction) foreach($oldEgg->except($retainedEggIds->all()) as $deletedLine) Audit::record($deletedLine,'revenue_egg_detail_deleted',$deletedLine->toArray(),$reason,[],$actor->id);
            $revenue->eggDetails()->delete(); $revenue->fishDetail()->delete();
            foreach(['revenue_other_details','revenue_transfer_details'] as $table) DB::table($table)->where('revenue_id',$revenue->id)->delete();
            $base=['revenue_id'=>$revenue->id,'created_at'=>now(),'updated_at'=>now()];
            if($type==='EGG_SALE') foreach($details as $line) RevenueEggDetail::create($base+['egg_category'=>$line['egg_category'],'weight_kg'=>$line['weight_kg'],'quantity_pieces'=>$line['quantity_pieces'],'price_per_kg_idr'=>$line['price_per_kg_idr'],'line_amount_idr'=>$this->lineAmount($line['weight_kg'],$line['price_per_kg_idr']),'notes'=>$line['notes']??null]);
            elseif($type==='FISH_SALE') RevenueFishDetail::create($base+['weight_kg'=>$details['weight_kg'],'price_per_kg_idr'=>$details['price_per_kg_idr'],'notes'=>$details['notes']??null]);
            elseif($type==='MAIN_ACCOUNT_TRANSFER') DB::table('revenue_transfer_details')->insert($base+['gross_amount_idr'=>$gross,'payment_cost_idr'=>$cost,'notes'=>$details['notes']??null]);
            else DB::table('revenue_other_details')->insert($base+['notes'=>$details['notes']??null]);
            $after=null;
            if($type==='MAIN_ACCOUNT_TRANSFER') $after=$revenue->fresh()->toArray()+['gross_amount_idr'=>$gross,'payment_cost_idr'=>$cost];
            Audit::record($revenue,$correction?'revenue_corrected':($before?'revenue_updated':'revenue_created'),$before,$reason,$after,$actor->id);
            if($direction==='INFLOW' && $paymentMethod==='CASH' && $revenue->payments()->whereNull('voided_at')->exists()) $this->payments->syncRevenue($revenue);
            return $revenue->fresh(['eggDetails','fishDetail','payments']);
        },3);
    }

    public function submitRevenueTransfer(Revenue $revenue,User $actor): Revenue {
        return DB::transaction(function() use($revenue,$actor) {
            $revenue=Revenue::lockForUpdate()->findOrFail($revenue->id);
            if($revenue->direction!=='OUTFLOW' || !in_array($revenue->approval_status,['DRAFT','REJECTED'])) $this->fail('Status transfer tidak dapat diajukan.');
            $before=$revenue->toArray(); $revenue->update(['approval_status'=>'SUBMITTED','submitted_by'=>$actor->id,'submitted_at'=>now(),'rejection_reason'=>null]); Audit::record($revenue,'transfer_submitted',$before,null,null,$actor->id); return $revenue;
        });
    }

    public function decideRevenueTransfer(Revenue $revenue,User $actor,string $decision,?string $reason=null,bool $acknowledgeNegative=false): Revenue {
        abort_unless($actor->isSuperadmin(),403);
        return DB::transaction(function() use($revenue,$actor,$decision,$reason,$acknowledgeNegative) {
            $this->balances->lock(); $revenue=Revenue::lockForUpdate()->findOrFail($revenue->id);
            if($decision==='APPROVED' && $revenue->approval_status==='APPROVED' && $revenue->payment_status==='PAID') return $revenue;
            $direct=$revenue->direction==='INFLOW' && $revenue->payment_method==='DIRECT_TRANSFER';
            if((!$direct && $revenue->direction!=='OUTFLOW') || $revenue->approval_status!=='SUBMITTED') $this->fail('Hanya transfer yang diajukan dapat diputuskan.');
            $before=$revenue->toArray();
            if($decision==='APPROVED') {
                $approvedAt=now(); $date=$approvedAt->toDateString();
                if($direct) {
                    $this->assertPaymentDate($revenue->direct_transfer_date->toDateString(),'direct_transfer_date');
                    $paidAt=$this->dateTime($revenue->direct_transfer_date->toDateString());
                    $revenue->update(['approval_status'=>'APPROVED','approved_by'=>$actor->id,'approved_at'=>$approvedAt,'rejected_by'=>null,'rejected_at'=>null,'rejection_reason'=>null,'payment_status'=>'PAID','paid_at'=>$paidAt,'paid_by'=>$actor->id]);
                    Audit::record($revenue,'direct_transfer_approved',$before,null,null,$actor->id);
                    Audit::record($revenue,'revenue_paid',$before,'Pembayaran dikonfirmasi saat Direct Transfer disetujui.',null,$actor->id);
                    return $revenue;
                }
                if(!$direct) {
                    $gross=(string)DB::table('revenue_transfer_details')->where('revenue_id',$revenue->id)->value('gross_amount_idr');
                    $this->guardNegative('REVENUE',$date,$gross,$acknowledgeNegative,$revenue,$actor);
                }
                $revenue->update(['approval_status'=>'APPROVED','approved_by'=>$actor->id,'approved_at'=>$approvedAt,'rejected_by'=>null,'rejected_at'=>null,'rejection_reason'=>null]);
                Audit::record($revenue,'transfer_approved',$before,null,null,$actor->id);
                $beforePayment=$revenue->fresh()->toArray();
                $revenue->update(['payment_status'=>'PAID','paid_at'=>$approvedAt,'paid_by'=>$actor->id]);
                Audit::record($revenue,'revenue_paid',$beforePayment,'Pembayaran otomatis saat transfer disetujui.',null,$actor->id);
            } else {
                $revenue->update(['approval_status'=>'REJECTED','rejected_by'=>$actor->id,'rejected_at'=>now(),'approved_by'=>null,'approved_at'=>null,'rejection_reason'=>$reason]);
                Audit::record($revenue,$direct?'direct_transfer_rejected':'transfer_rejected',$before,$reason,null,$actor->id);
            }
            return $revenue;
        });
    }

    public function payRevenue(Revenue $revenue,User $actor,string $date,bool $acknowledgeNegative=false,string $mode='FULL',mixed $amount=null,?array $proof=null): Revenue {
        return DB::transaction(function() use($revenue,$actor,$date,$acknowledgeNegative,$mode,$amount,$proof) {
            $this->balances->lock(); $revenue=Revenue::lockForUpdate()->findOrFail($revenue->id);
            $this->assertPaymentDate($date);
            if($revenue->direction!=='INFLOW' || $revenue->payment_method!=='CASH') $this->fail('Hanya revenue Cash yang dapat ditandai Paid secara manual.');
            if($revenue->payment_status==='PAID') return $revenue;
            $before=$revenue->toArray(); $remaining=$this->payments->revenueRemaining($revenue); $paymentAmount=$this->paymentAmount($mode,$amount,$remaining);
            RevenuePayment::create(['revenue_id'=>$revenue->id,'paid_date'=>$date,'amount_idr'=>$paymentAmount,'payment_mode'=>$mode,'proof_path'=>$proof['path']??null,'proof_url'=>$proof['url']??null,'created_by'=>$actor->id]);
            $revenue=$this->payments->syncRevenue($revenue);
            Audit::record($revenue,$revenue->payment_status==='PAID'?'revenue_paid':'revenue_partially_paid',$before,null,$revenue->toArray()+['payment_amount_idr'=>$paymentAmount,'payment_date'=>$date,'payment_proof_url'=>$proof['url']??null],$actor->id); return $revenue;
        });
    }

    public function voidRevenuePayment(Revenue $revenue,RevenuePayment $payment,User $actor,string $reason): Revenue {
        abort_unless($actor->isSuperadmin(),403);
        return DB::transaction(function() use($revenue,$payment,$actor,$reason) {
            $this->balances->lock(); $revenue=Revenue::lockForUpdate()->findOrFail($revenue->id); $payment=RevenuePayment::lockForUpdate()->findOrFail($payment->id);
            if($payment->revenue_id!==$revenue->id) $this->fail('Pembayaran tidak termasuk transaksi ini.');
            if($payment->voided_at) return $revenue;
            $before=$revenue->toArray(); $payment->update(['voided_at'=>now(),'voided_by'=>$actor->id,'void_reason'=>$reason]); $revenue=$this->payments->syncRevenue($revenue);
            Audit::record($revenue,'revenue_payment_voided',$before,$reason,$revenue->toArray()+['payment_amount_idr'=>(string)$payment->amount_idr,'payment_date'=>$payment->paid_date->toDateString()],$actor->id); return $revenue;
        });
    }

    private function guardNegative(string $account,string $date,string $amount,bool $acknowledge,Model $entity,User $actor): void {
        if(Decimal::compare($amount,0)===0) return;
        $projection=$this->balances->projectedAfterImpact($account,$date,Decimal::sub(0,$amount,0));
        if(!$projection) return;
        if(!$acknowledge) $this->fail("Saldo {$account} diproyeksikan menjadi Rp ".number_format((float)$projection['projected_balance'],0,'.',',')."; tanggal negatif pertama {$projection['earliest_negative_date']}. Konfirmasi untuk tetap memproses.",'balance_warning');
        Audit::record($entity,'negative_balance_override',null,"{$account} {$projection['earliest_negative_date']}: {$projection['projected_balance']}",['account'=>$account,'effective_date'=>$date,'earliest_negative_date'=>$projection['earliest_negative_date'],'projected_balance'=>$projection['projected_balance'],'acknowledged_by'=>$actor->id],$actor->id);
    }

    private function guardRevenueCorrection(Revenue $revenue,?object $oldTransfer,string $newDirection,?string $newMethod,string $newAmount,?string $newGross,?string $directDate,bool $acknowledge,User $actor): void {
        $oldImpact=$revenue->direction==='OUTFLOW'?Decimal::sub(0,(string)($oldTransfer?->gross_amount_idr??$revenue->amount_idr),0):($revenue->payment_method==='CASH'?(string)$revenue->amount_idr:'0');
        $newImpact=$newDirection==='OUTFLOW'?Decimal::sub(0,(string)$newGross,0):($newMethod==='CASH'?$newAmount:'0');
        $oldDate=substr((string)$revenue->paid_at,0,10); $newDate=$directDate??$oldDate;
        if(Decimal::compare($oldImpact,$newImpact)===0 && (Decimal::compare($oldImpact,0)===0 || $oldDate===$newDate)) return;
        $impacts=[$oldDate=>Decimal::sub(0,$oldImpact,0)];
        $impacts[$newDate]=isset($impacts[$newDate])?Decimal::add($impacts[$newDate],$newImpact,0):$newImpact;
        $projection=$this->balances->projectedAfterImpacts('REVENUE',$impacts);
        if(!$projection) return;
        if(!$acknowledge) $this->fail("Koreksi membuat saldo REVENUE menjadi Rp ".number_format((float)$projection['projected_balance'],0,'.',',')."; tanggal negatif pertama {$projection['earliest_negative_date']}. Konfirmasi untuk tetap memproses.",'balance_warning');
        Audit::record($revenue,'negative_balance_override',null,"REVENUE correction {$projection['earliest_negative_date']}: {$projection['projected_balance']}",['account'=>'REVENUE','effective_date'=>$newDate,'earliest_negative_date'=>$projection['earliest_negative_date'],'projected_balance'=>$projection['projected_balance'],'acknowledged_by'=>$actor->id],$actor->id);
    }

    private function dateTime(string $date): Carbon { return Carbon::parse($date,config('app.timezone'))->startOfDay(); }
    private function assertPaymentDate(string $date,string $key='paid_date'): void { if($date>today(config('app.timezone'))->toDateString()) $this->fail('Tanggal pembayaran tidak boleh setelah hari ini.',$key); }
    private function notAfterToday(string $date): string { return min($date,today(config('app.timezone'))->toDateString()); }
    private function paymentAmount(string $mode,mixed $amount,string $remaining): string {
        if(!in_array($mode,['PARTIAL','FULL'],true)) $this->fail('Mode pembayaran tidak valid.','payment_mode');
        if($mode==='FULL') return $remaining;
        $value=Decimal::money($amount??0);
        if(Decimal::compare($value,0)<=0) $this->fail('Jumlah pembayaran sebagian harus lebih dari 0.','amount_idr');
        if(Decimal::compare($value,$remaining)>=0) $this->fail('Jumlah pembayaran sebagian harus lebih kecil dari sisa tagihan; pilih Full untuk melunasi.','amount_idr');
        return $value;
    }
    private function lineAmount(mixed $quantity,mixed $price): string { return Decimal::money(Decimal::mul($quantity,$price,3)); }

    private function normalizeFeedPurchase(array $line): array {
        $unit=strtoupper((string)($line['purchase_unit']??'KG'));
        $purchaseQuantity=(string)($line['purchase_quantity']??$line['quantity_kg']??0);
        $purchasePrice=(string)($line['price_per_purchase_unit_idr']??$line['price_per_kg_idr']??0);
        if(!in_array($unit,['KG','SAK'],true)) $this->fail('Unit pembelian pakan harus KG atau SAK.','details.purchase_unit');
        if(Decimal::compare($purchasePrice,0)<0) $this->fail('Harga per unit tidak boleh negatif.','details.price_per_purchase_unit_idr');
        $kgPerSak=$unit==='SAK'?(string)($line['kg_per_sak']??0):null;
        if($unit==='SAK') {
            if(Decimal::compare($purchaseQuantity,0)<=0 || bccomp($purchaseQuantity,bcadd($purchaseQuantity,'0',0),3)!==0) $this->fail('Jumlah sak harus berupa bilangan bulat lebih dari 0.','details.purchase_quantity');
            if(Decimal::compare($kgPerSak,0)<=0) $this->fail('Kg per sak wajib lebih dari 0.','details.kg_per_sak');
            $quantityKg=Decimal::mul($purchaseQuantity,$kgPerSak,3);
            $pricePerKg=Decimal::div($purchasePrice,$kgPerSak,3);
        } else {
            if(Decimal::compare($purchaseQuantity,0)<0) $this->fail('Jumlah kg tidak boleh negatif.','details.purchase_quantity');
            $unit='KG'; $quantityKg=Decimal::add($purchaseQuantity,0,3); $pricePerKg=Decimal::add($purchasePrice,0,3);
        }
        return array_merge($line,[
            'purchase_unit'=>$unit,'purchase_quantity'=>$purchaseQuantity,'kg_per_sak'=>$kgPerSak,
            'price_per_purchase_unit_idr'=>$purchasePrice,'quantity_kg'=>$quantityKg,'price_per_kg_idr'=>$pricePerKg,
            'line_amount_idr'=>$this->lineAmount($purchaseQuantity,$purchasePrice),
        ]);
    }
}
