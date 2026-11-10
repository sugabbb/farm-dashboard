<?php
namespace App\Http\Controllers;

use App\Models\LivestockGroup;
use App\Models\AppSetting;
use App\Services\{Audit,Balances,FarmOperations,LeleGrowthService};
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class GroupController extends Controller {
    public function show(Request $request,LivestockGroup $group,Balances $balances,LeleBiomassController $biomass) {
        abort_unless($group->species==='LELE',404); $group->load(['growthProfile.points','leleSamplings.measurements']);
        return Inertia::render('LeleGroupDetail',['group'=>[
            'id'=>$group->id,'name'=>$group->name,'code'=>$group->code,'location'=>$group->location,'status'=>$group->status,
            'start_date'=>$group->start_date->toDateString(),'closed_at'=>$group->closed_at?->toDateString(),'closure_reason'=>$group->closure_reason,
            'initial_population'=>$group->initial_population,'current_population'=>$balances->population($group,$group->closed_at?->toDateString()),
            'initial_average_weight_g'=>$group->initial_average_weight_g===null?null:(string)$group->initial_average_weight_g,
            'planned_adg_g_per_day'=>$group->planned_adg_g_per_day===null?null:(string)$group->planned_adg_g_per_day,
            'growth'=>$biomass->growth($group),
        ]]);
    }
    public function index(Request $request, string $species, Balances $balances) {
        $q=LivestockGroup::where('species',$species)->with(['dailyRecords.feedUsages','dailyRecords.eggHarvests']);
        if($request->filled('q')) $q->where(fn($q)=>$q->where('name','ilike','%'.$request->q.'%')->orWhere('code','ilike','%'.$request->q.'%'));
        if(in_array($request->status,['ACTIVE','CLOSED'])) $q->where('status',$request->status);
        [$sort,$direction,$column]=$this->sorting($request,['name'=>'name','code'=>'code','start_date'=>'start_date','status'=>'status'],'name');
        $groups=$q->orderBy($column,$direction)->orderBy('id')->paginate(25)->withQueryString()->through(function($g) use($balances) {
            return [
                'id'=>$g->id,'species'=>$g->species,'code'=>$g->code,'name'=>$g->name,'location'=>$g->location,
                'start_date'=>$g->start_date->toDateString(),'age_days'=>$g->start_date->diffInDays(today()),
                'initial_population'=>$g->initial_population,'current_population'=>$balances->population($g),'initial_average_weight_g'=>$g->initial_average_weight_g === null?null:(string)$g->initial_average_weight_g,'planned_adg_g_per_day'=>$g->planned_adg_g_per_day === null?null:(string)$g->planned_adg_g_per_day,
                'mortality'=>$g->dailyRecords->sum('mortality'),
                'feed_kg'=>(string)$g->dailyRecords->sum(fn($r)=>(float)$r->feedUsages->sum('quantity_kg')),
                'egg_kg'=>(string)$g->dailyRecords->sum(fn($r)=>(float)$r->eggHarvests->sum('weight_kg')),
                'egg_pieces'=>$g->dailyRecords->sum(fn($r)=>(int)$r->eggHarvests->sum('quantity_pieces')),
                'status'=>$g->status,'closed_at'=>$g->closed_at?->toDateString(),'closure_reason'=>$g->closure_reason,'notes'=>$g->notes,
            ];
        });
        $existingCodes=LivestockGroup::pluck('code');
        return Inertia::render('Groups',['species'=>$species,'groups'=>$groups,'existingCodes'=>$existingCodes,'filters'=>$request->only(['q','status']),'sort'=>$sort,'direction'=>$direction]);
    }
    public function store(Request $request,string $species) {
        $v=$request->validate(['code'=>'nullable|string|max:255','name'=>'required|string|max:255','start_date'=>'required|date_format:Y-m-d','initial_population'=>'required|integer|min:0','location'=>'required|string|max:255','notes'=>'nullable|string|max:3000','initial_average_weight_g'=>$species==='LELE'?'required|numeric|min:0':'nullable','planned_adg_g_per_day'=>$species==='LELE'?'nullable|numeric|gt:0':'nullable']);
        if($species!=='LELE') unset($v['initial_average_weight_g'],$v['planned_adg_g_per_day']);
        if($species==='LELE' && !filled($v['planned_adg_g_per_day']??null)) $v['planned_adg_g_per_day']=AppSetting::find('default_lele_adg_g_per_day')?->value??'1.300';
        $base=$this->normalizeCode($species==='LELE'?$v['location'].'-'.$v['name']:$v['name'].'-'.$v['location']);
        $submitted=$this->normalizeCode($v['code']??'');
        if($submitted!=='' && $submitted!==$base) {
            validator(['code'=>$submitted],['code'=>Rule::unique('livestock_groups','code')])->validate();
            $v['code']=$submitted;
        } else $v['code']=$this->availableCode($base);
        return $this->save($species,new LivestockGroup,$v);
    }
    public function update(Request $request,LivestockGroup $group,string $species) {
        abort_unless($group->species===$species,404);
        $v=$request->validate(['code'=>'prohibited','name'=>'required|string|max:255','start_date'=>'required|date_format:Y-m-d','initial_population'=>'required|integer|min:0','location'=>'required|string|max:255','notes'=>'nullable|string|max:3000','initial_average_weight_g'=>$species==='LELE'?'required|numeric|min:0':'nullable','planned_adg_g_per_day'=>$species==='LELE'?'required|numeric|gt:0':'nullable']);
        if($species!=='LELE') unset($v['initial_average_weight_g'],$v['planned_adg_g_per_day']);
        return $this->save($species,$group,$v);
    }
    private function save(string $species,LivestockGroup $group,array $v) {
        if($group->exists && ($group->initial_population!=(int)$v['initial_population'] || $group->start_date->toDateString()!==$v['start_date'])) return back()->withErrors(['group'=>'Tanggal mulai dan populasi awal tidak dapat diubah. Gunakan penyesuaian populasi.'])->withInput();
        $before=$group->exists?$group->toArray():null; $group->fill($v+['species'=>$species,'status'=>$group->status??'ACTIVE'])->save(); Audit::record($group,$before?'group_updated':'group_created',$before);
        if($species==='LELE') app(LeleGrowthService::class)->rebuild($group);
        return back()->with('success','Grup berhasil disimpan.');
    }
    private function normalizeCode(string $value): string { return Str::limit(Str::upper(Str::slug($value,'-')),60,''); }
    private function availableCode(string $base): string {
        $base=$base!==''?$base:'GROUP'; $candidate=$base; $suffix=2;
        while(LivestockGroup::where('code',$candidate)->exists()) {
            $tail='-'.$suffix++; $candidate=substr($base,0,60-strlen($tail)).$tail;
        }
        return $candidate;
    }
    public function close(Request $request,LivestockGroup $group,string $species) {
        abort_unless($group->species===$species,404);
        $rules=['closed_at'=>'required|date_format:Y-m-d|after_or_equal:'.$group->start_date->toDateString(),'reason'=>$species==='LELE'?'required|string|max:3000':'nullable|string|max:3000'];
        $v=$request->validate($rules); if($species==='LELE' && $group->leleSamplings()->where('sampling_date','>',$v['closed_at'])->exists()) return back()->withErrors(['closed_at'=>'Tanggal panen tidak boleh sebelum sampling terakhir.']); $before=$group->toArray();
        $group->update(['status'=>'CLOSED','closed_at'=>$v['closed_at'],'closure_reason'=>$v['reason']??null]);
        if($species==='LELE') app(LeleGrowthService::class)->rebuild($group);
        Audit::record($group,'group_closed',$before,$v['reason']??null); return back()->with('success','Grup ditutup; riwayat tetap tersimpan.');
    }
    public function adjust(Request $request,LivestockGroup $group,string $species,FarmOperations $service) {
        abort_unless($request->user()->isSuperadmin() && $group->species===$species,403);
        $v=$request->validate(['delta_ekor'=>'required|integer|not_in:0','effective_date'=>'required|date_format:Y-m-d|after_or_equal:'.$group->start_date->toDateString(),'reason'=>'required|string|max:3000']); $v['group_id']=$group->id;
        $service->adjustPopulation($v,$request->user()); return back()->with('success','Penyesuaian populasi dicatat.');
    }
}
