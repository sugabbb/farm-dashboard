<?php
namespace App\Http\Controllers;

use App\Models\{AppSetting,FeedType,User};
use App\Services\{Audit,Balances};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SettingsController extends Controller {
    private function authorizeSuper(Request $request): void { abort_unless($request->user()->isSuperadmin(),403); }
    public function index(Request $request,Balances $balances) {
        $this->authorizeSuper($request);
        $cash=DB::table('financial_opening_balances')->get()->keyBy('account');
        return Inertia::render('Settings',[
            'feeds'=>FeedType::orderBy('species')->orderBy('name')->get()->map(fn($feed)=>['id'=>$feed->id,'species'=>$feed->species,'name'=>$feed->name,'is_active'=>$feed->is_active]),
            'cash'=>collect(['OPERATIONAL','REVENUE'])->mapWithKeys(function($account) use($cash) { $row=$cash->get($account); return [$account=>$row?['amount_idr'=>(string)$row->amount_idr,'effective_date'=>(string)$row->effective_date]:null]; }),
            'openings'=>DB::table('inventory_opening_balances')->get()->map(fn($row)=>['id'=>$row->id,'item_kind'=>$row->item_kind,'feed_type_id'=>$row->feed_type_id,'egg_category'=>$row->egg_category,'quantity_kg'=>(string)$row->quantity_kg,'quantity_pieces'=>$row->quantity_pieces,'effective_date'=>(string)$row->effective_date]),
            'users'=>User::orderBy('name')->get()->map(fn($user)=>['id'=>$user->id,'name'=>$user->name,'email'=>$user->email,'role'=>$user->role,'is_active'=>$user->is_active,'must_change_password'=>$user->must_change_password]),
            'defaultLeleAdg'=>AppSetting::find('default_lele_adg_g_per_day')?->value??'1.300',
        ]);
    }
    public function feedType(Request $request) { $this->authorizeSuper($request); $v=$request->validate(['species'=>'required|in:AYAM,LELE','name'=>['required','string','max:255',Rule::unique('feed_types')->where('species',$request->species)]]); $feed=FeedType::create($v+['is_active'=>true]); Audit::record($feed,'feed_type_created'); return back()->with('success','Jenis pakan ditambahkan.'); }
    public function feedTypeUpdate(Request $request,FeedType $feedType) { $this->authorizeSuper($request); $v=$request->validate(['name'=>['required','string','max:255',Rule::unique('feed_types')->where('species',$feedType->species)->ignore($feedType->id)],'is_active'=>'required|boolean']); $before=$feedType->toArray(); $feedType->update($v); Audit::record($feedType,'feed_type_updated',$before); return back()->with('success','Jenis pakan diperbarui.'); }
    public function leleAdg(Request $request) { $this->authorizeSuper($request); $v=$request->validate(['value'=>'required|numeric|gt:0|max:100']); AppSetting::updateOrCreate(['key'=>'default_lele_adg_g_per_day'],['value'=>(string)$v['value'],'updated_by'=>$request->user()->id]); return back()->with('success','Default ADG Lele diperbarui untuk grup baru.'); }
    public function cashOpening(Request $request) {
        $this->authorizeSuper($request); $v=$request->validate(['account'=>'required|in:OPERATIONAL,REVENUE','amount_idr'=>'required|numeric|min:0','effective_date'=>'required|date_format:Y-m-d']);
        return DB::transaction(function() use($request,$v) {
            if(DB::table('financial_opening_balances')->where('account',$v['account'])->exists()) return back()->withErrors(['opening'=>'Saldo awal akun ini hanya dapat dicatat sekali.']);
            $table=$v['account']==='OPERATIONAL'?'operational_cashflows':'revenues'; $paymentTable=$v['account']==='OPERATIONAL'?'operational_cashflow_payments':'revenue_payments';
            $firstPaid=collect([DB::table($table)->whereNotNull('paid_at')->min('paid_at'),DB::table($paymentTable)->whereNull('voided_at')->min('paid_date')])->filter()->min();
            if($firstPaid && $v['effective_date']>substr((string)$firstPaid,0,10)) return back()->withErrors(['effective_date'=>'Saldo awal harus berlaku sebelum transaksi Paid pertama pada akun ini.']);
            DB::table('financial_opening_balances')->insert($v+['created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]); return back()->with('success','Saldo awal '.$v['account'].' dicatat.');
        });
    }
    public function inventoryOpening(Request $request,Balances $balances) {
        $this->authorizeSuper($request); $v=$request->validate(['item_kind'=>'required|in:FEED,EGG','feed_type_id'=>'nullable|required_if:item_kind,FEED|exists:feed_types,id','egg_category'=>'nullable|required_if:item_kind,EGG|in:UTUH,PECAH,REJECT','quantity_kg'=>'required|numeric','quantity_pieces'=>'nullable|required_if:item_kind,EGG|integer','effective_date'=>'required|date_format:Y-m-d']);
        return DB::transaction(function() use($request,$v,$balances) { $balances->lock(); if($v['item_kind']==='FEED') { $v['egg_category']=null; $v['quantity_pieces']=null; } else $v['feed_type_id']=null; $exists=DB::table('inventory_opening_balances')->when($v['item_kind']==='FEED',fn($q)=>$q->where('feed_type_id',$v['feed_type_id']),fn($q)=>$q->where('egg_category',$v['egg_category']))->exists(); if($exists) return back()->withErrors(['opening'=>'Saldo awal untuk item ini sudah ada.']); DB::table('inventory_opening_balances')->insert($v+['created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]); return back()->with('success','Saldo awal inventori dicatat.'); });
    }
}
