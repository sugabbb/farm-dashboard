<?php
namespace App\Http\Controllers;

use App\Models\{LeleSampling,LivestockGroup};
use App\Services\{Balances,LeleSamplingService};
use Illuminate\Http\Request;
use Inertia\Inertia;

class LeleBiomassController extends Controller {
    public function index(Request $request,LeleSamplingService $service,Balances $balances) {
        $q=LeleSampling::with(['group','measurements','creator']);
        if($request->filled('group_id')) $q->where('group_id',$request->group_id);
        if($request->filled('from')) $q->where('sampling_date','>=',$request->from);
        if($request->filled('to')) $q->where('sampling_date','<=',$request->to);
        [$sort,$direction,$column]=$this->sorting($request,['sampling_date'=>'sampling_date','group_id'=>'group_id'],'sampling_date','desc');
        $samplings=$q->orderBy($column,$direction)->orderByDesc('id')->paginate(30)->withQueryString()->through(fn($sampling)=>$this->data($sampling,$service));
        $groups=LivestockGroup::with('growthProfile.points')->where('species','LELE')->where('status','ACTIVE')->orderBy('location')->orderBy('name')->get();
        $summaries=$groups->map(function($group) use($service,$balances) {
            $latest=LeleSampling::with(['group','measurements','creator'])->where('group_id',$group->id)->where('sampling_date','<=',today())->latest('sampling_date')->latest('id')->first();
            $population=$balances->population($group);
            $base=['group_id'=>$group->id,'group_name'=>$group->name,'group_code'=>$group->code,'location'=>$group->location,'population'=>$population,
                'initial_population'=>$group->initial_population,'current_target_kg'=>round($population/7,3),'cycle_target_kg'=>round(((int)$group->initial_population*.85)/7,3),'growth'=>$this->growth($group)];
            if(!$latest) return $base+['latest'=>null];
            $metrics=$service->metrics($latest);
            return $base+['latest'=>[
                'sampling_date'=>$latest->sampling_date->toDateString(),'average_weight_g'=>$metrics['average'],'estimated_biomass_kg'=>$metrics['currentBiomass'],
            ]];
        });
        return Inertia::render('LeleBiomass',[
            'samplings'=>$samplings,'groups'=>$groups->map(fn($g)=>['id'=>$g->id,'name'=>$g->name,'code'=>$g->code,'location'=>$g->location]),
            'summaries'=>$summaries,'filters'=>$request->only(['group_id','from','to']),'sort'=>$sort,'direction'=>$direction,
        ]);
    }

    public function store(Request $request,LeleSamplingService $service) {
        $service->save($this->validated($request),$request->user());
        return back()->with('success','Sampling Biomasa Lele disimpan.');
    }

    public function update(Request $request,LeleSampling $sampling,LeleSamplingService $service) {
        $service->save($this->validated($request),$request->user(),$sampling);
        return back()->with('success','Sampling Biomasa Lele diperbarui.');
    }

    private function validated(Request $request): array {
        return $request->validate([
            'group_id'=>'required|integer|exists:livestock_groups,id',
            'sampling_date'=>'required|date_format:Y-m-d|before_or_equal:today',
            'notes'=>'nullable|string|max:3000','measurements'=>'required|array|size:3',
            'measurements.*.sequence'=>'required|integer|between:1,3|distinct',
            'measurements.*.total_weight_kg'=>'required|numeric|gt:0',
        ]);
    }

    private function data(LeleSampling $sampling,LeleSamplingService $service): array {
        $metrics=$service->metrics($sampling);
        return [
            'id'=>$sampling->id,'group_id'=>$sampling->group_id,'group_name'=>$sampling->group->name,'group_code'=>$sampling->group->code,'location'=>$sampling->group->location,
            'sampling_date'=>$sampling->sampling_date->toDateString(),'notes'=>$sampling->notes,'creator_name'=>$sampling->creator?->name,'created_at'=>$sampling->created_at->toIso8601String(),
            'measurements'=>$sampling->measurements->map(fn($line)=>['sequence'=>$line->sequence,'fish_count'=>$line->fish_count,'total_weight_kg'=>(string)$line->total_weight_kg])->values(),
            'total_weight_kg'=>$metrics['total'],'average_weight_g'=>$metrics['average'],'population_at_sampling'=>$metrics['populationAtSampling'],'estimated_biomass_kg'=>$metrics['samplingBiomass'],
        ];
    }

    public function growth(LivestockGroup $group): ?array {
        $profile=$group->growthProfile; if(!$profile) return null;
        return ['status'=>$profile->calculation_status,'target_weight_g'=>(string)$profile->target_weight_g,
            'planned_adg_g_per_day'=>$profile->planned_adg_g_per_day===null?null:(string)$profile->planned_adg_g_per_day,
            'calculated_adg_g_per_day'=>$profile->calculated_adg_g_per_day===null?null:(string)$profile->calculated_adg_g_per_day,
            'planned_harvest_date'=>$profile->planned_harvest_date?->toDateString(),'chart_end_date'=>$profile->chart_end_date->toDateString(),
            'rebuilt_at'=>$profile->rebuilt_at->toIso8601String(),'points'=>$profile->points->map(fn($point)=>[
                'date'=>$point->point_date->toDateString(),'planned'=>$point->planned_weight_g===null?null:(float)$point->planned_weight_g,
                'calculated'=>$point->calculated_weight_g===null?null:(float)$point->calculated_weight_g,'actual'=>$point->actual_weight_g===null?null:(float)$point->actual_weight_g,
                'kinds'=>$point->point_kinds,
            ])->values()];
    }
}
