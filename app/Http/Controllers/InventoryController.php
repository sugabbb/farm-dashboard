<?php
namespace App\Http\Controllers;

use App\Models\{FeedType,InventoryAdjustment};
use App\Services\{Balances,FarmOperations,FeedStockLedger,FeedStockProjection};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InventoryController extends Controller {
    public function feed(Request $request,string $species,Balances $balances,FeedStockLedger $ledger,FeedStockProjection $projection) {
        $validated=$request->validate(['date'=>'nullable|date_format:Y-m-d','feed_type_id'=>'nullable|exists:feed_types,id']);
        $date=$validated['date']??today()->toDateString();
        $types=FeedType::where('species',$species)->orderBy('name')->get();
        [$sort,$direction]=$this->sorting($request,['movement_date'=>'movement_date','recorded_at'=>'recorded_at','feed_name'=>'feed_name','movement_type'=>'movement_type','quantity_in'=>'quantity_in','quantity_out'=>'quantity_out','quantity_delta'=>'quantity_delta','balance_after'=>'balance_after'],'movement_date','desc');
        $stock=$types->mapWithKeys(fn($f)=>[$f->id=>(string)$balances->feed($f->id,$date)]);
        return Inertia::render('Feed',[
            'species'=>$species,'date'=>$date,'types'=>$types->map(fn($f)=>['id'=>$f->id,'name'=>$f->name,'species'=>$f->species,'is_active'=>$f->is_active])->values(),
            'stock'=>$stock,'estimates'=>$projection->estimate($types,$date,$stock->all()),
            'movements'=>$ledger->paginate($species,$date,isset($validated['feed_type_id'])?(int)$validated['feed_type_id']:null,$sort,$direction),
            'filters'=>$request->only(['feed_type_id']),'sort'=>$sort,'direction'=>$direction,
        ]);
    }
    public function eggs(Request $request,Balances $balances) {
        $validated=$request->validate(['date'=>'nullable|date_format:Y-m-d','egg_category'=>'nullable|in:UTUH,PECAH,REJECT']); $date=$validated['date']??today()->toDateString();
        $harvest=DB::table('daily_records as r')->join('livestock_groups as g','g.id','=','r.group_id')->leftJoin('egg_harvests as h','h.daily_record_id','=','r.id')
            ->where('g.species','AYAM')->select('r.id','r.record_date','g.id as group_id','g.name as group_name')
            ->selectRaw("COALESCE(SUM(CASE WHEN h.egg_category='UTUH' THEN h.weight_kg ELSE 0 END),0) utuh_kg")
            ->selectRaw("COALESCE(SUM(CASE WHEN h.egg_category='UTUH' THEN h.quantity_pieces ELSE 0 END),0) utuh_pieces")
            ->selectRaw("COALESCE(SUM(CASE WHEN h.egg_category='PECAH' THEN h.weight_kg ELSE 0 END),0) pecah_kg")
            ->selectRaw("COALESCE(SUM(CASE WHEN h.egg_category='PECAH' THEN h.quantity_pieces ELSE 0 END),0) pecah_pieces")
            ->selectRaw("COALESCE(SUM(CASE WHEN h.egg_category='REJECT' THEN h.weight_kg ELSE 0 END),0) reject_kg")
            ->selectRaw("COALESCE(SUM(CASE WHEN h.egg_category='REJECT' THEN h.quantity_pieces ELSE 0 END),0) reject_pieces")
            ->groupBy('r.id','r.record_date','g.id','g.name');
        [$sort,$direction,$column]=$this->sorting($request,['record_date'=>'r.record_date','group_name'=>'g.name'],'record_date','desc');
        $page=$harvest->orderBy($column,$direction)->orderByDesc('r.id')->paginate(40)->withQueryString();
        $groups=\App\Models\LivestockGroup::whereIn('id',collect($page->items())->pluck('group_id'))->get()->keyBy('id');
        return Inertia::render('Eggs',[
            'date'=>$date,'stock'=>collect(['UTUH','PECAH','REJECT'])->mapWithKeys(function($c) use($balances,$date) { $value=$balances->egg($c,$date); return [$c=>['kg'=>(string)$value['kg'],'pieces'=>$value['pieces']]]; }),
            'harvest'=>$page->through(function($row) use($balances,$groups) {
                $population=$balances->population($groups[$row->group_id],$row->record_date); $total=(int)$row->utuh_pieces+(int)$row->pecah_pieces+(int)$row->reject_pieces;
                $percentage=fn($pieces)=>$population>0?round(((int)$pieces/$population)*100,2):null;
                return ['id'=>$row->id,'record_date'=>$row->record_date,'group_name'=>$row->group_name,'population'=>$population,
                    'utuh'=>['kg'=>(string)$row->utuh_kg,'pieces'=>(int)$row->utuh_pieces,'percentage'=>$percentage($row->utuh_pieces)],
                    'pecah'=>['kg'=>(string)$row->pecah_kg,'pieces'=>(int)$row->pecah_pieces,'percentage'=>$percentage($row->pecah_pieces)],
                    'reject'=>['kg'=>(string)$row->reject_kg,'pieces'=>(int)$row->reject_pieces,'percentage'=>$percentage($row->reject_pieces)],
                    'total_percentage'=>$percentage($total)];
            }),
            'adjustments'=>InventoryAdjustment::with('creator')->where('item_kind','EGG')->latest('effective_date')->limit(15)->get()->map(fn($row)=>['id'=>$row->id,'effective_date'=>$row->effective_date->toDateString(),'egg_category'=>$row->egg_category,'delta_kg'=>(string)$row->delta_kg,'delta_pieces'=>$row->delta_pieces,'reason'=>$row->reason,'creator_name'=>$row->creator?->name,'created_at'=>$row->created_at->toIso8601String()]),
            'filters'=>$request->only(['egg_category']),'sort'=>$sort,'direction'=>$direction,
        ]);
    }
    public function adjust(Request $request,FarmOperations $service) {
        abort_unless($request->user()->isSuperadmin(),403);
        $v=$request->validate(['item_kind'=>'required|in:FEED,EGG','feed_type_id'=>'nullable|required_if:item_kind,FEED|exists:feed_types,id','egg_category'=>'nullable|required_if:item_kind,EGG|in:UTUH,PECAH,REJECT','delta_kg'=>'required|numeric|not_in:0','delta_pieces'=>'nullable|required_if:item_kind,EGG|integer','effective_date'=>'required|date_format:Y-m-d','reason'=>'required|string|max:3000']);
        $service->adjustInventory($v,$request->user()); return back()->with('success','Penyesuaian stok dicatat.');
    }
    public function updateAdjustment(Request $request,InventoryAdjustment $adjustment,FarmOperations $service) {
        abort_unless($request->user()->isSuperadmin(),403);
        abort_unless($adjustment->item_kind==='FEED',404);
        $v=$request->validate(['feed_type_id'=>'required|integer|exists:feed_types,id','delta_kg'=>'required|numeric|not_in:0','effective_date'=>'required|date_format:Y-m-d','reason'=>'required|string|max:3000']);
        $service->updateFeedAdjustment($adjustment,$v,$request->user());
        return back()->with('success','Penyesuaian stok diperbarui.');
    }
}
