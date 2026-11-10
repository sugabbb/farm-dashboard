<?php
namespace App\Http\Controllers;

use App\Models\{AbkSubmission,Customer,Revenue,RevenuePayment};
use App\Services\{Balances,FinanceAuditTimeline,FinanceMetrics,FinancePayments,FinanceQuery,FinanceService,ProofStorage};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RevenueController extends Controller {
    public function index(Request $request,FinanceMetrics $metrics,Balances $balances,FinanceAuditTimeline $auditTimeline,FinanceQuery $financeQuery) {
        $abkStatus=$request->filled('abk_status')?$request->validate(['abk_status'=>'in:DRAFT,APPROVED,REJECTED'])['abk_status']:null;
        $relations=['eggDetails','fishDetail','customer','creator','payer','submitter','approver','rejector','payments.creator','payments.voider'];
        $q=Revenue::with($relations); $financeQuery->revenues($q,$request->only(['revenue_type','payment_method','payment_status','approval_status','summary_filter','customer_id','from','to']));
        [$sort,$direction,$column]=$this->sorting($request,['transaction_date'=>'transaction_date','revenue_type'=>'revenue_type','amount_idr'=>'amount_idr','approval_status'=>'approval_status','payment_status'=>'payment_status'],'transaction_date','desc');
        $edit=$request->filled('edit')?Revenue::with($relations)->findOrFail($request->edit):null;
        $revenues=$q->orderBy($column,$direction)->orderByDesc('id')->paginate(30)->withQueryString();
        $auditRows=collect($revenues->items()); if($edit && !$auditRows->contains('id',$edit->id)) $auditRows->push($edit);
        $audits=$auditTimeline->revenues($auditRows);
        return Inertia::render('Revenues',[
            'revenues'=>$revenues->through(fn($row)=>$this->data($row,$audits[$row->id]??[])),
            'edit'=>$edit?$this->data($edit,$audits[$edit->id]??[]):null,
            'customers'=>Customer::orderBy('name')->orderBy('id')->get(['id','name','phone','customer_type']),
            'summary'=>$metrics->revenueSummary()+[
                'revenue_cash'=>(string)$balances->revenueCash(),
                'pending_transfers'=>(string)DB::table('revenues as revenue')->join('revenue_transfer_details as detail','detail.revenue_id','=','revenue.id')->whereIn('revenue.approval_status',['DRAFT','SUBMITTED'])->sum('detail.gross_amount_idr'),
                'pending_direct_transfer_approvals'=>(string)Revenue::where('payment_method','DIRECT_TRANSFER')->where('approval_status','SUBMITTED')->sum('amount_idr'),
            ],
            'filters'=>$request->only(['revenue_type','payment_method','payment_status','approval_status','summary_filter','customer_id','from','to']),'sort'=>$sort,'direction'=>$direction,
            'abkSubmissions'=>AbkSubmission::with(['employee:id,name','reviewer:id,name'])->where('kind','EGG_SALE')->when($abkStatus,fn($q)=>$q->where('status',$abkStatus),fn($q)=>$q->where('status','DRAFT'))->latest()->paginate(15,['*'],'abk_page')->withQueryString(),
        ]);
    }

    public function store(Request $request,FinanceService $service,ProofStorage $proofs) {
        $data=$this->validated($request); $paidDate=($data['payment_method']??null)==='CASH' && $request->input('payment_status')==='PAID'?$request->validate(['payment_status'=>'required|in:UNPAID,PAID','paid_date'=>'required|date_format:Y-m-d|before_or_equal:today'])['paid_date']:null;
        $proofs->replace($request->file('proof'),'revenues',null,function($proof) use($request,$service,$data,$paidDate) {
            if($proof) $data=array_merge($data,['proof_path'=>$proof['path'],'proof_url'=>$proof['url']]);
            DB::transaction(function() use($request,$service,$data,$paidDate) {
                $revenue=$service->saveRevenue($data,$request->user());
                if($revenue->direction==='OUTFLOW' && $request->input('save_action')==='submit') $service->submitRevenueTransfer($revenue,$request->user());
                if($revenue->direction==='INFLOW' && $paidDate) $service->payRevenue($revenue,$request->user(),$paidDate);
            });
        });
        return redirect()->route('revenues.index')->with('success','Revenues disimpan.');
    }

    public function update(Request $request,Revenue $revenue,FinanceService $service,ProofStorage $proofs) {
        $data=$this->validated($request);
        $proofs->replace($request->file('proof'),'revenues',$revenue->proof_path,function($proof) use($request,$service,$data,$revenue) { if($proof) $data=array_merge($data,['proof_path'=>$proof['path'],'proof_url'=>$proof['url']]); return $service->saveRevenue($data,$request->user(),$revenue); });
        return redirect()->route('revenues.index')->with('success','Revenues diperbarui.');
    }

    public function correct(Request $request,Revenue $revenue,FinanceService $service,ProofStorage $proofs) {
        $data=$this->validated($request); $v=$request->validate(['correction_reason'=>'required|string|max:3000','acknowledge_negative_balance'=>'nullable|boolean']);
        $proofs->replace($request->file('proof'),'revenues',$revenue->proof_path,function($proof) use($request,$service,$data,$revenue,$v) { if($proof) $data=array_merge($data,['proof_path'=>$proof['path'],'proof_url'=>$proof['url']]); return $service->saveRevenue($data,$request->user(),$revenue,true,$v['correction_reason'],(bool)($v['acknowledge_negative_balance']??false)); });
        return redirect()->route('revenues.index')->with('success','Koreksi Revenues dicatat dalam audit.');
    }

    public function submit(Request $request,Revenue $revenue,FinanceService $service) { $service->submitRevenueTransfer($revenue,$request->user()); return back()->with('success','Transfer diajukan untuk persetujuan.'); }
    public function decision(Request $request,Revenue $revenue,FinanceService $service) { $v=$request->validate(['decision'=>'required|in:APPROVED,REJECTED','reason'=>'nullable|required_if:decision,REJECTED|string|max:3000','acknowledge_negative_balance'=>'nullable|boolean']); $direct=$revenue->payment_method==='DIRECT_TRANSFER'; $service->decideRevenueTransfer($revenue,$request->user(),$v['decision'],$v['reason']??null,(bool)($v['acknowledge_negative_balance']??false)); $message=$v['decision']==='APPROVED'?($direct?'Direct Transfer disetujui dan ditandai Paid.':'Transfer disetujui dan otomatis ditandai Paid.'):'Transfer ditolak.'; return back()->with('success',$message); }
    public function pay(Request $request,Revenue $revenue,FinanceService $service,ProofStorage $proofs) {
        $request->mergeIfMissing(['payment_mode'=>'FULL']);
        $v=$request->validate(['payment_mode'=>'required|in:PARTIAL,FULL','paid_date'=>'required|date_format:Y-m-d|before_or_equal:today','amount_idr'=>'nullable|required_if:payment_mode,PARTIAL|prohibited_if:payment_mode,FULL|numeric|min:0','proof'=>['nullable','file','extensions:jpg,jpeg,png','mimes:jpg,jpeg,png','mimetypes:image/jpeg,image/png','max:5120']]);
        $proofs->replace($request->file('proof'),'revenue-payments',null,fn($proof)=>$service->payRevenue($revenue,$request->user(),$v['paid_date'],false,$v['payment_mode'],$v['amount_idr']??null,$proof));
        return back()->with('success',$v['payment_mode']==='FULL'?'Transaksi ditandai Paid.':'Pembayaran sebagian dicatat.');
    }
    public function voidPayment(Request $request,Revenue $revenue,RevenuePayment $payment,FinanceService $service) { $v=$request->validate(['reason'=>'required|string|max:3000']); $service->voidRevenuePayment($revenue,$payment,$request->user(),$v['reason']); return back()->with('success','Pembayaran dibatalkan dan dicatat dalam audit.'); }

    private function validated(Request $request): array {
        $base=$request->validate(['revenue_type'=>'required|in:EGG_SALE,FISH_SALE,OTHER,MAIN_ACCOUNT_TRANSFER','direction'=>'nullable|in:INFLOW,OUTFLOW','transaction_date'=>'required|date_format:Y-m-d','amount_idr'=>'nullable|numeric|min:0','payment_method'=>'nullable|in:CASH,DIRECT_TRANSFER','direct_transfer_date'=>'nullable|date_format:Y-m-d|before_or_equal:today','details'=>'required|array','proof'=>['nullable','file','extensions:jpg,jpeg,png','mimes:jpg,jpeg,png','mimetypes:image/jpeg,image/png','max:5120']]);
        $type=$base['revenue_type']; $derived=$type==='MAIN_ACCOUNT_TRANSFER'?'OUTFLOW':'INFLOW';
        if(isset($base['direction']) && $base['direction']!==$derived) throw \Illuminate\Validation\ValidationException::withMessages(['direction'=>'Arah transaksi tidak sesuai jenis.']);
        if($type==='MAIN_ACCOUNT_TRANSFER') $request->validate(['amount_idr'=>'prohibited','payment_method'=>'prohibited','direct_transfer_date'=>'prohibited','approval_status'=>'prohibited','payment_status'=>'prohibited','paid_date'=>'prohibited','paid_at'=>'prohibited','paid_by'=>'prohibited','details.gross_amount_idr'=>'required|integer|min:0','details.payment_cost_idr'=>'nullable|integer|min:0|lte:details.gross_amount_idr','details.notes'=>'nullable|string|max:3000']);
        else {
            $request->validate(['approval_status'=>'prohibited','payment_method'=>'required|in:CASH,DIRECT_TRANSFER','direct_transfer_date'=>'required_if:payment_method,DIRECT_TRANSFER|prohibited_if:payment_method,CASH|date_format:Y-m-d']);
            if($request->input('payment_method')==='DIRECT_TRANSFER') $request->validate(['payment_status'=>'prohibited','paid_date'=>'prohibited','paid_at'=>'prohibited','paid_by'=>'prohibited']);
            else $request->validate(['payment_status'=>'nullable|in:UNPAID,PAID','paid_date'=>'nullable|required_if:payment_status,PAID|date_format:Y-m-d|before_or_equal:today']);
        }
        if(in_array($type,['EGG_SALE','FISH_SALE'])) {
            $request->validate(['customer_id'=>'nullable|integer|exists:customers,id','new_customer'=>'nullable|array','new_customer.name'=>'required_with:new_customer|string|max:255','new_customer.phone'=>'required_with:new_customer|string|max:50','new_customer.customer_type'=>'required_with:new_customer|in:WARUNG,AGEN','new_customer.notes'=>'nullable|string|max:3000']);
            $hasExisting=filled($request->input('customer_id')); $hasNew=is_array($request->input('new_customer')); if($hasExisting===$hasNew) throw \Illuminate\Validation\ValidationException::withMessages(['customer_id'=>'Pilih satu pelanggan atau tambahkan satu pelanggan baru.']);
        } else $request->validate(['customer_id'=>'prohibited','new_customer'=>'prohibited']);
        if($type==='EGG_SALE') $request->validate(['details'=>'required|array|min:1|max:3','details.*.id'=>'nullable|integer','details.*.egg_category'=>'required|in:UTUH,PECAH,REJECT|distinct','details.*.weight_kg'=>'required|numeric|min:0','details.*.quantity_pieces'=>'required|integer|min:0','details.*.price_per_kg_idr'=>'required|numeric|min:0','details.*.line_amount_idr'=>'prohibited','details.*.notes'=>'nullable|string|max:1000']);
        elseif($type==='FISH_SALE') $request->validate(['details.weight_kg'=>'required|numeric|min:0','details.price_per_kg_idr'=>'required|numeric|min:0','details.notes'=>'nullable|string|max:3000']);
        elseif($type==='OTHER') $request->validate(['amount_idr'=>'required|numeric|min:0','details.notes'=>'nullable|string|max:3000']);
        return $request->only(['revenue_type','transaction_date','amount_idr','payment_method','direct_transfer_date','payment_status','paid_date','details','customer_id','new_customer']);
    }

    private function details(Revenue $row): array {
        if($row->revenue_type==='EGG_SALE') return $row->eggDetails->toArray(); if($row->revenue_type==='FISH_SALE') return $row->fishDetail?->toArray()??[];
        $table=$row->revenue_type==='MAIN_ACCOUNT_TRANSFER'?'revenue_transfer_details':'revenue_other_details'; return (array)DB::table($table)->where('revenue_id',$row->id)->first();
    }

    private function data(Revenue $row,array $audits=[]): array {
        $details=$this->details($row); $transfer=$row->revenue_type==='MAIN_ACCOUNT_TRANSFER'?$details:null;
        $paymentData=app(FinancePayments::class)->revenueData($row);
        $hasPayments=collect($paymentData['payments'])->contains(fn($payment)=>$payment['voided_at']===null);
        $isPaid=$row->payment_status==='PAID';
        return ['id'=>$row->id,'revenue_type'=>$row->revenue_type,'direction'=>$row->direction,'transaction_date'=>$row->transaction_date->toDateString(),'amount_idr'=>(string)$row->amount_idr,'payment_method'=>$row->payment_method,'direct_transfer_date'=>$row->direct_transfer_date?->toDateString(),'customer_id'=>$row->customer_id,'customer'=>$row->customer?['id'=>$row->customer->id,'name'=>$row->customer->name,'phone'=>$row->customer->phone,'customer_type'=>$row->customer->customer_type]:null,'approval_status'=>$row->approval_status,'payment_status'=>$row->payment_status,'rejection_reason'=>$row->rejection_reason,'proof_url'=>$row->proof_url,'created_at'=>$row->created_at->toIso8601String(),'creator_name'=>$row->creator?->name,'submitted_at'=>$row->submitted_at?->toIso8601String(),'submitter_name'=>$row->submitter?->name,'approved_at'=>$row->approved_at?->toIso8601String(),'approver_name'=>$row->approver?->name,'rejected_at'=>$row->rejected_at?->toIso8601String(),'rejector_name'=>$row->rejector?->name,'paid_at'=>$row->paid_at?->toIso8601String(),'payer_name'=>$row->payer?->name,'egg_details'=>$row->eggDetails->map(fn($line)=>['id'=>$line->id,'egg_category'=>$line->egg_category,'weight_kg'=>(string)$line->weight_kg,'quantity_pieces'=>$line->quantity_pieces,'price_per_kg_idr'=>(string)$line->price_per_kg_idr,'line_amount_idr'=>(string)$line->line_amount_idr,'notes'=>$line->notes])->values(),'fish_detail'=>$row->fishDetail?['weight_kg'=>(string)$row->fishDetail->weight_kg,'price_per_kg_idr'=>(string)$row->fishDetail->price_per_kg_idr,'notes'=>$row->fishDetail?->notes]:null,'transfer_detail'=>$transfer?['gross_amount_idr'=>(string)$transfer['gross_amount_idr'],'payment_cost_idr'=>(string)$transfer['payment_cost_idr'],'net_amount_idr'=>(string)$row->amount_idr,'notes'=>$transfer['notes']]:null,'details'=>$details,'audit_logs'=>$audits,'locked'=>$hasPayments||$row->approval_status==='SUBMITTED'||$isPaid,'requires_correction'=>$hasPayments||$isPaid]+$paymentData;
    }
}
