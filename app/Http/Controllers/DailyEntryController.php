<?php
namespace App\Http\Controllers;

use App\Models\{AbkSubmission,DailyRecord,FeedType,LivestockGroup};
use App\Services\FarmOperations;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DailyEntryController extends Controller {
    public function index(Request $request,string $species) {
        $records=DailyRecord::with(['group','feedUsages.feedType','eggHarvests'])->whereHas('group',fn($q)=>$q->where('species',$species));
        if($request->filled('from')) $records->where('record_date','>=',$request->from); if($request->filled('to')) $records->where('record_date','<=',$request->to);
        [$sort,$direction,$column]=$this->sorting($request,['record_date'=>'record_date','mortality'=>'mortality'],'record_date','desc');
        $page=$records->orderBy($column,$direction)->orderByDesc('id')->paginate(30)->withQueryString()->through(fn($record)=>[
            'id'=>$record->id,'record_date'=>$record->record_date->toDateString(),'group'=>['id'=>$record->group->id,'name'=>$record->group->name,'code'=>$record->group->code,'location'=>$record->group->location],
            'mortality'=>$record->mortality,'notes'=>$record->notes,
            'feeds'=>$record->feedUsages->map(fn($line)=>['id'=>$line->id,'feed_type_id'=>$line->feed_type_id,'feed_name'=>$line->feedType->name,'morning_kg'=>(string)$line->morning_kg,'noon_kg'=>(string)$line->noon_kg,'evening_kg'=>(string)$line->evening_kg,'quantity_kg'=>(string)$line->quantity_kg])->values(),
            'eggs'=>$record->eggHarvests->map(fn($line)=>['egg_category'=>$line->egg_category,'weight_kg'=>(string)$line->weight_kg,'quantity_pieces'=>$line->quantity_pieces])->keyBy('egg_category'),
        ]);
        return Inertia::render('DailyEntry',[
            'species'=>$species,
            'groups'=>LivestockGroup::where('species',$species)->where('status','ACTIVE')->when($species==='LELE',fn($q)=>$q->orderBy('location'))->orderBy('name')->get(['id','name','code','location']),
            'feeds'=>FeedType::where('species',$species)->where('is_active',true)->orderBy('name')->get(['id','name','species']),
            'allFeeds'=>FeedType::where('species',$species)->orderBy('name')->get(['id','name','species','is_active']),
            'records'=>$page,'filters'=>$request->only(['from','to']),'sort'=>$sort,'direction'=>$direction,
            'abkSubmissions'=>$this->submissions($request,$species),
        ]);
    }
    public function store(Request $request,string $species,FarmOperations $service) {
        $rules=['rows'=>'required|array|min:1|max:50']+$this->rowRules($species,'rows.*.',true);
        $v=$request->validate($rules); $service->saveDaily($species,$v['rows'],$request->user()); return back()->with('success','Semua input harian berhasil disimpan.');
    }
    public function update(Request $request,DailyRecord $record,string $species,FarmOperations $service) {
        $v=$request->validate($this->rowRules($species));
        $service->updateDaily($species,$record,$v,$request->user());
        return back()->with('success','Input harian berhasil diperbarui.');
    }
    private function rowRules(string $species,string $prefix='',bool $identity=false): array {
        $rules=[];
        if($identity) {
            $rules[$prefix.'record_date']='required|date_format:Y-m-d';
            $rules[$prefix.'group_id']='required|integer|exists:livestock_groups,id';
        }
        $rules[$prefix.'mortality']='required|integer|min:0';
        $rules[$prefix.'notes']='nullable|string|max:3000';
        $rules[$prefix.'feeds']=$identity?'required|array|min:1|max:30':'nullable|array|max:30';
        $rules[$prefix.'feeds.*.feed_type_id']='required|integer|exists:feed_types,id';
        $rules[$prefix.'feeds.*.morning_kg']='required|numeric|min:0';
        $rules[$prefix.'feeds.*.noon_kg']='required|numeric|min:0';
        $rules[$prefix.'feeds.*.evening_kg']=$species==='LELE'?'required|numeric|min:0':'prohibited';
        if($species==='AYAM') foreach(['UTUH','PECAH','REJECT'] as $category) {
            $rules[$prefix."eggs.$category.weight_kg"]='required|numeric|min:0';
            $rules[$prefix."eggs.$category.quantity_pieces"]='required|integer|min:0';
        }
        return $rules;
    }
    private function submissions(Request $request,string $species) { $q=AbkSubmission::with(['employee:id,name','reviewer:id,name'])->where('kind','DAILY_'.$species);if($request->filled('abk_status'))$q->where('status',$request->validate(['abk_status'=>'in:DRAFT,APPROVED,REJECTED'])['abk_status']);else $q->where('status','DRAFT');return $q->latest()->paginate(15,['*'],'abk_page')->withQueryString(); }
}
