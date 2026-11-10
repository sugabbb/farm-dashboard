<?php
namespace App\Http\Controllers;

use App\Models\{Employee,FeedType,OperationalCashflow,OperationalCashflowFeedDetail,OperationalCashflowPayment};
use App\Services\{Balances,FinanceAuditTimeline,FinanceMetrics,FinancePayments,FinanceQuery,FinanceService,ProofStorage};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OperationalCashflowController extends Controller {
    public function index(Request $request,FinanceMetrics $metrics,Balances $balances,FinanceAuditTimeline $auditTimeline,FinanceQuery $financeQuery) {
        $q=OperationalCashflow::with(['feedDetails.feedType','feedDetails.receiver','creator','submitter','approver','rejector','payer','payments.creator','payments.voider']);
        $financeQuery->operational($q,$request->only(['cashflow_type','approval_status','payment_status','from','to']));
        [$sort,$direction,$column]=$this->sorting($request,['transaction_date'=>'transaction_date','cashflow_type'=>'cashflow_type','amount_idr'=>'amount_idr','approval_status'=>'approval_status','payment_status'=>'payment_status'],'transaction_date','desc');
        $edit=$request->filled('edit')?OperationalCashflow::with(['feedDetails.feedType'])->findOrFail($request->edit):null;
        $cashflows=$q->orderBy($column,$direction)->orderByDesc('id')->paginate(30)->withQueryString();
        $auditRows=collect($cashflows->items()); if($edit && !$auditRows->contains('id',$edit->id)) $auditRows->push($edit);
        $audits=$auditTimeline->operational($auditRows);
        $salaryCashflowIds=collect($cashflows->items())->where('cashflow_type','SALARY')->pluck('id');
        if($edit?->cashflow_type==='SALARY') $salaryCashflowIds->push($edit->id);
        $historicalEmployeeIds=$salaryCashflowIds->isEmpty()?collect():DB::table('operational_cashflow_salary_details')->whereIn('operational_cashflow_id',$salaryCashflowIds->unique())->pluck('employee_id');
        $employeeQuery=Employee::query()->where(fn($query)=>$query->where('status','ACTIVE')->when($historicalEmployeeIds->isNotEmpty(),fn($q)=>$q->orWhereIn('id',$historicalEmployeeIds)));
        return Inertia::render('OperationalCashflow',[
            'cashflows'=>$cashflows->through(fn($row)=>$this->data($row,$audits[$row->id]??[])),
            'edit'=>$edit?$this->data($edit,$audits[$edit->id]??[]):null,
            'feeds'=>FeedType::where('is_active',true)->orderBy('species')->orderBy('name')->get(['id','name','species']),
            'employees'=>$employeeQuery->orderBy('name')->get(['id','name','job_title']),
            'summary'=>($summary=$metrics->expenseSummary())+['operational_cash'=>(string)($cash=$balances->operationalCash()),'estimated_operational_cash'=>\App\Services\Decimal::sub($cash,$summary['approved_unpaid'],0)],
            'filters'=>$request->only(['cashflow_type','approval_status','payment_status','from','to']),'sort'=>$sort,'direction'=>$direction,
        ]);
    }

    public function store(Request $request,FinanceService $service,ProofStorage $proofs) {
        $data=$this->validated($request);
        $proofs->replace($request->file('proof'),'operational-cashflow',null,function($proof) use($request,$service,$data) {
            if($proof) $data=array_merge($data,['proof_path'=>$proof['path'],'proof_url'=>$proof['url']]);
            DB::transaction(function() use($request,$service,$data) {
                $row=$service->saveOperationalCashflow($data,$request->user());
                if($row->direction==='OUTFLOW' && $request->input('save_action')==='submit') $service->submitOperational($row,$request->user());
            });
        });
        $message=$data['cashflow_type']==='MAIN_ACCOUNT_TOPUP'?'Top Up disimpan dan otomatis ditandai Paid.':'Operational Cashflow disimpan.';
        return redirect()->route('operational-cashflow.index')->with('success',$message);
    }

    public function update(Request $request,OperationalCashflow $cashflow,FinanceService $service,ProofStorage $proofs) {
        $data=$this->validated($request,$cashflow);
        $proofs->replace($request->file('proof'),'operational-cashflow',$cashflow->proof_path,function($proof) use($request,$service,$data,$cashflow) {
            if($proof) $data=array_merge($data,['proof_path'=>$proof['path'],'proof_url'=>$proof['url']]);
            return $service->saveOperationalCashflow($data,$request->user(),$cashflow);
        });
        return redirect()->route('operational-cashflow.index')->with('success','Operational Cashflow diperbarui.');
    }

    public function correct(Request $request,OperationalCashflow $cashflow,FinanceService $service,ProofStorage $proofs) {
        $data=$this->validated($request,$cashflow); $v=$request->validate(['correction_reason'=>'required|string|max:3000']);
        $proofs->replace($request->file('proof'),'operational-cashflow',$cashflow->proof_path,function($proof) use($request,$service,$data,$cashflow,$v) {
            if($proof) $data=array_merge($data,['proof_path'=>$proof['path'],'proof_url'=>$proof['url']]);
            return $service->saveOperationalCashflow($data,$request->user(),$cashflow,true,$v['correction_reason']);
        });
        return redirect()->route('operational-cashflow.index')->with('success','Koreksi Operational Cashflow dicatat dalam audit.');
    }

    public function submit(Request $request,OperationalCashflow $cashflow,FinanceService $service) { $service->submitOperational($cashflow,$request->user()); return back()->with('success','Pengeluaran diajukan untuk persetujuan.'); }
    public function decision(Request $request,OperationalCashflow $cashflow,FinanceService $service) { $v=$request->validate(['decision'=>'required|in:APPROVED,REJECTED','reason'=>'nullable|required_if:decision,REJECTED|string|max:3000']); $service->decideOperational($cashflow,$request->user(),$v['decision'],$v['reason']??null); return back()->with('success','Keputusan pengeluaran disimpan.'); }
    public function pay(Request $request,OperationalCashflow $cashflow,FinanceService $service,ProofStorage $proofs) {
        abort_unless($request->user()->isSuperadmin(),403);
        $request->mergeIfMissing(['payment_mode'=>'FULL']);
        $v=$request->validate(['payment_mode'=>'required|in:PARTIAL,FULL','paid_date'=>'required|date_format:Y-m-d|before_or_equal:today','amount_idr'=>'nullable|required_if:payment_mode,PARTIAL|prohibited_if:payment_mode,FULL|numeric|min:0','proof'=>['nullable','file','extensions:jpg,jpeg,png','mimes:jpg,jpeg,png','mimetypes:image/jpeg,image/png','max:5120']]);
        $proofs->replace($request->file('proof'),'operational-payments',null,fn($proof)=>$service->payOperational($cashflow,$request->user(),$v['paid_date'],$v['payment_mode'],$v['amount_idr']??null,$proof));
        return back()->with('success',$v['payment_mode']==='FULL'?'Transaksi ditandai Paid.':'Pembayaran sebagian dicatat.');
    }
    public function voidPayment(Request $request,OperationalCashflow $cashflow,OperationalCashflowPayment $payment,FinanceService $service) { $v=$request->validate(['reason'=>'required|string|max:3000']); $service->voidOperationalPayment($cashflow,$payment,$request->user(),$v['reason']); return back()->with('success','Pembayaran dibatalkan dan dicatat dalam audit.'); }
    public function receive(Request $request,OperationalCashflowFeedDetail $line,FinanceService $service) { $service->receive($line,$request->user(),$request->validate(['received_date'=>'required|date_format:Y-m-d'])['received_date']); return back()->with('success','Pakan ditandai Received.'); }

    private function validated(Request $request,?OperationalCashflow $existing=null): array {
        $base=$request->validate(['cashflow_type'=>'required|in:FEED_AYAM,FEED_LELE,SALARY,UTILITY,HEALTH,OTHER,LELE_SEED_PURCHASE,PULLET_PURCHASE,BANK_ADMIN_FEE,MAIN_ACCOUNT_TOPUP','direction'=>'nullable|in:INFLOW,OUTFLOW','transaction_date'=>'required|date_format:Y-m-d','amount_idr'=>'nullable|numeric|min:0','details'=>'required|array','proof'=>['nullable','file','extensions:jpg,jpeg,png','mimes:jpg,jpeg,png','mimetypes:image/jpeg,image/png','max:5120']]);
        $type=$base['cashflow_type']; $derived=$type==='MAIN_ACCOUNT_TOPUP'?'INFLOW':'OUTFLOW';
        if(isset($base['direction']) && $base['direction']!==$derived) throw \Illuminate\Validation\ValidationException::withMessages(['direction'=>'Arah transaksi tidak sesuai jenis.']);
        if($type==='MAIN_ACCOUNT_TOPUP') $request->validate(['approval_status'=>'prohibited','payment_status'=>'prohibited','paid_date'=>'prohibited','paid_at'=>'prohibited','paid_by'=>'prohibited']);
        if(in_array($type,['FEED_AYAM','FEED_LELE'])) {
            $normalized=collect($request->input('details',[]))->map(function($line) {
                $line=(array)$line;
                $line['purchase_unit']=strtoupper((string)($line['purchase_unit']??'KG'));
                $line['purchase_quantity']=$line['purchase_quantity']??$line['quantity_kg']??null;
                $line['price_per_purchase_unit_idr']=$line['price_per_purchase_unit_idr']??$line['price_per_kg_idr']??null;
                $line['kg_per_sak']=$line['purchase_unit']==='SAK'?($line['kg_per_sak']??null):null;
                return $line;
            })->all();
            $request->merge(['details'=>$normalized]);
            $request->validate([
                'details'=>'required|array|min:1|max:30','details.*.id'=>'nullable|integer',
                'details.*.feed_type_id'=>'required|integer|exists:feed_types,id',
                'details.*.purchase_unit'=>'required|in:KG,SAK','details.*.purchase_quantity'=>'required|numeric|min:0',
                'details.*.kg_per_sak'=>'nullable|numeric','details.*.price_per_purchase_unit_idr'=>'required|numeric|min:0',
                'details.*.quantity_kg'=>'nullable|numeric|min:0','details.*.price_per_kg_idr'=>'nullable|numeric|min:0',
                'details.*.line_amount_idr'=>'prohibited',
            ]);
            foreach($normalized as $index=>$line) if($line['purchase_unit']==='SAK') {
                if((float)$line['purchase_quantity']<=0 || floor((float)$line['purchase_quantity'])!==(float)$line['purchase_quantity']) throw \Illuminate\Validation\ValidationException::withMessages(["details.$index.purchase_quantity"=>'Jumlah sak harus berupa bilangan bulat lebih dari 0.']);
                if(!is_numeric($line['kg_per_sak']??null) || (float)$line['kg_per_sak']<=0) throw \Illuminate\Validation\ValidationException::withMessages(["details.$index.kg_per_sak"=>'Kg per sak wajib lebih dari 0.']);
            }
        }
        elseif($type==='SALARY') { $v=$request->validate(['details.employee_id'=>'required|exists:employees,id','details.salary_period'=>'required|string|max:40']); $historical=$existing && DB::table('operational_cashflow_salary_details')->where('operational_cashflow_id',$existing->id)->where('employee_id',$v['details']['employee_id'])->exists(); if(!$historical && !Employee::whereKey($v['details']['employee_id'])->where('status','ACTIVE')->exists()) throw \Illuminate\Validation\ValidationException::withMessages(['details.employee_id'=>'ABK harus aktif untuk pengeluaran gaji baru.']); }
        elseif($type==='HEALTH') $request->validate(['details.species'=>'required|in:AYAM,LELE','details.description'=>'required|string|max:3000']);
        elseif(in_array($type,['UTILITY','OTHER'])) $request->validate(['details.description'=>'required|string|max:3000']);
        elseif(in_array($type,['LELE_SEED_PURCHASE','PULLET_PURCHASE'])) {
            $detail=$request->validate(['details.species'=>'nullable|in:AYAM,LELE','details.notes'=>'nullable|string|max:3000'])['details'];
            $derived=$type==='LELE_SEED_PURCHASE'?'LELE':'AYAM';
            if(isset($detail['species']) && $detail['species']!==$derived) throw \Illuminate\Validation\ValidationException::withMessages(['details.species'=>'Spesies tidak sesuai jenis pengeluaran.']);
        }
        elseif($type==='BANK_ADMIN_FEE') $request->validate(['details.species'=>'prohibited','details.notes'=>'nullable|string|max:3000']);
        else $request->validate(['details.notes'=>'nullable|string|max:3000']);
        if(!in_array($type,['FEED_AYAM','FEED_LELE']) && (!array_key_exists('amount_idr',$base) || $base['amount_idr']===null || $base['amount_idr']==='')) throw \Illuminate\Validation\ValidationException::withMessages(['amount_idr'=>'Jumlah wajib diisi.']);
        return $request->only(['cashflow_type','transaction_date','amount_idr','details']);
    }

    private function details(OperationalCashflow $row): array {
        if(in_array($row->cashflow_type,['FEED_AYAM','FEED_LELE'])) return $row->feedDetails->toArray();
        $table=match($row->cashflow_type){'SALARY'=>'operational_cashflow_salary_details','UTILITY'=>'operational_cashflow_utility_details','HEALTH'=>'operational_cashflow_health_details','OTHER'=>'operational_cashflow_other_details','LELE_SEED_PURCHASE','PULLET_PURCHASE'=>'operational_cashflow_livestock_purchase_details','BANK_ADMIN_FEE'=>'operational_cashflow_bank_admin_details',default=>'operational_cashflow_topup_details'};
        if($row->cashflow_type==='SALARY') return (array)DB::table("$table as detail")->leftJoin('employees as employee','employee.id','=','detail.employee_id')->where('detail.operational_cashflow_id',$row->id)->select('detail.*','employee.name as employee_name')->first();
        return (array)DB::table($table)->where('operational_cashflow_id',$row->id)->first();
    }

    private function data(OperationalCashflow $row,array $audits=[]): array {
        $lines=$row->feedDetails->map(fn($line)=>[
            'id'=>$line->id,'feed_type_id'=>$line->feed_type_id,'feed_name'=>$line->feedType?->name,
            'purchase_unit'=>$line->purchase_unit,'purchase_quantity'=>(string)$line->purchase_quantity,
            'kg_per_sak'=>$line->kg_per_sak===null?null:(string)$line->kg_per_sak,
            'price_per_purchase_unit_idr'=>(string)$line->price_per_purchase_unit_idr,
            'quantity_kg'=>(string)$line->quantity_kg,'price_per_kg_idr'=>(string)$line->price_per_kg_idr,
            'line_amount_idr'=>(string)$line->line_amount_idr,'received_at'=>$line->received_at?->toIso8601String(),'receiver_name'=>$line->receiver?->name,
        ])->values();
        $received=$lines->contains(fn($line)=>$line['received_at']!==null);
        $paymentData=app(FinancePayments::class)->operationalData($row);
        $hasPayments=collect($paymentData['payments'])->contains(fn($payment)=>$payment['voided_at']===null);
        $isPaid=$row->payment_status==='PAID';
        return ['id'=>$row->id,'cashflow_type'=>$row->cashflow_type,'direction'=>$row->direction,'transaction_date'=>$row->transaction_date->toDateString(),'amount_idr'=>(string)$row->amount_idr,'approval_status'=>$row->approval_status,'payment_status'=>$row->payment_status,'rejection_reason'=>$row->rejection_reason,'proof_url'=>$row->proof_url,'created_at'=>$row->created_at->toIso8601String(),'creator_name'=>$row->creator?->name,'submitted_at'=>$row->submitted_at?->toIso8601String(),'submitter_name'=>$row->submitter?->name,'approved_at'=>$row->approved_at?->toIso8601String(),'approver_name'=>$row->approver?->name,'rejected_at'=>$row->rejected_at?->toIso8601String(),'rejector_name'=>$row->rejector?->name,'paid_at'=>$row->paid_at?->toIso8601String(),'payer_name'=>$row->payer?->name,'feed_details'=>$lines,'details'=>in_array($row->cashflow_type,['FEED_AYAM','FEED_LELE'])?$lines:$this->details($row),'audit_logs'=>$audits,'locked'=>$hasPayments||$received||$isPaid,'requires_correction'=>$hasPayments||$received||$isPaid]+$paymentData;
    }
}
