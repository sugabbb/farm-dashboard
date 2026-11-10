<?php
namespace App\Services;

use App\Models\{LeleGrowthPoint,LeleGrowthProfile,LivestockGroup};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class LeleGrowthService {
    public const TARGET_WEIGHT_G=142.857143;

    public function rebuild(LivestockGroup|int $source,?string $asOf=null): LeleGrowthProfile {
        return DB::transaction(function() use($source,$asOf) {
            $group=$source instanceof LivestockGroup?$source:LivestockGroup::findOrFail($source);
            $group=LivestockGroup::with(['leleSamplings.measurements'])->lockForUpdate()->findOrFail($group->id);
            abort_unless($group->species==='LELE',422);
            $today=Carbon::parse($asOf??today()->toDateString(),config('app.timezone'))->startOfDay();
            $start=$group->start_date->copy()->startOfDay();
            $samplings=$group->leleSamplings->sortBy(fn($row)=>$row->sampling_date->toDateString().str_pad((string)$row->id,12,'0',STR_PAD_LEFT))->values();
            $actuals=$samplings->mapWithKeys(fn($sampling)=>[$sampling->sampling_date->toDateString()=>$this->average($sampling->measurements->pluck('total_weight_kg')->all())]);
            $plannedAdg=$group->planned_adg_g_per_day!==null?(float)$group->planned_adg_g_per_day:1.3;
            $initial=$group->initial_average_weight_g!==null?(float)$group->initial_average_weight_g:null;
            $plannedHarvest=null;
            if($initial!==null) {
                $days=$initial>=self::TARGET_WEIGHT_G?0:(int)ceil((self::TARGET_WEIGHT_G-$initial)/$plannedAdg);
                $plannedHarvest=$start->copy()->addDays($days);
            }
            $end=$group->closed_at?->copy()->startOfDay()??$today->copy();
            if(!$group->closed_at && $plannedHarvest && $plannedHarvest->gt($end)) $end=$plannedHarvest->copy();
            if($end->lt($start)) $end=$start->copy();

            $calculatedAdg=$plannedAdg; $calculatedAnchorDate=null; $calculatedAnchorWeight=null;
            if($samplings->count()>=2) {
                $previous=$samplings[$samplings->count()-2]; $latest=$samplings->last();
                $calculatedAnchorDate=$previous->sampling_date->copy()->startOfDay();
                $calculatedAnchorWeight=(float)$actuals[$previous->sampling_date->toDateString()];
                $elapsed=max(1,$calculatedAnchorDate->diffInDays($latest->sampling_date));
                $calculatedAdg=((float)$actuals[$latest->sampling_date->toDateString()]-$calculatedAnchorWeight)/$elapsed;
            }

            $ids=$samplings->pluck('id')->map(fn($id)=>(int)$id)->all();
            $status=$initial===null?'MISSING_INITIAL_WEIGHT':($calculatedAdg<=0?'NON_POSITIVE_CALCULATED_ADG':'READY');
            $hash=hash('sha256',json_encode([$group->id,$initial,$plannedAdg,$group->start_date->toDateString(),$group->closed_at?->toDateString(),$ids,$actuals->all(),$today->toDateString()]));
            $profile=LeleGrowthProfile::updateOrCreate(['group_id'=>$group->id],[
                'target_weight_g'=>self::TARGET_WEIGHT_G,'planned_adg_g_per_day'=>$plannedAdg,
                'calculated_adg_g_per_day'=>round($calculatedAdg,6),'planned_harvest_date'=>$plannedHarvest?->toDateString(),
                'chart_end_date'=>$end->toDateString(),'calculation_status'=>$status,'source_sampling_ids'=>$ids,
                'source_hash'=>$hash,'rebuilt_at'=>now(),
            ]);

            $dates=collect();
            for($cursor=$start->copy();$cursor->lte($end);$cursor->addDays(7)) $dates->push($cursor->toDateString());
            $dates->push($end->toDateString());
            if(!$group->closed_at && $today->lte($end) && $today->gte($start)) $dates->push($today->toDateString());
            if($plannedHarvest && $plannedHarvest->lte($end) && $plannedHarvest->gte($start)) $dates->push($plannedHarvest->toDateString());
            foreach($actuals->keys() as $date) if($date>=$start->toDateString() && $date<=$end->toDateString()) $dates->push($date);
            $dates=$dates->unique()->sort()->values();

            $profile->points()->delete();
            foreach($dates as $date) {
                $point=Carbon::parse($date,config('app.timezone'))->startOfDay(); $kinds=[];
                if($start->diffInDays($point)%7===0) $kinds[]='WEEKLY';
                if($actuals->has($date)) $kinds[]='SAMPLING';
                if(!$group->closed_at && $date===$today->toDateString()) $kinds[]='TODAY';
                if($plannedHarvest && $date===$plannedHarvest->toDateString()) $kinds[]='PLANNED_HARVEST';
                if($group->closed_at && $date===$group->closed_at->toDateString()) $kinds[]='HARVEST';
                $planned=$initial===null?null:min(self::TARGET_WEIGHT_G,max(0,$initial+$plannedAdg*$start->diffInDays($point)));
                $calculated=null;
                if($calculatedAnchorDate && $point->gte($calculatedAnchorDate)) {
                    $raw=$calculatedAnchorWeight+$calculatedAdg*$calculatedAnchorDate->diffInDays($point);
                    $calculated=$calculatedAdg>0?min(self::TARGET_WEIGHT_G,max(0,$raw)):max(0,$raw);
                } elseif(!$calculatedAnchorDate && $planned!==null) $calculated=$planned;
                LeleGrowthPoint::create(['profile_id'=>$profile->id,'point_date'=>$date,
                    'planned_weight_g'=>$planned===null?null:round($planned,3),
                    'calculated_weight_g'=>$calculated===null?null:round($calculated,3),
                    'actual_weight_g'=>$actuals->has($date)?round((float)$actuals[$date],3):null,
                    'point_kinds'=>$kinds]);
            }
            return $profile->fresh('points');
        },3);
    }

    public function rebuildAll(?string $asOf=null,bool $activeOnly=false): array {
        $result=['rebuilt'=>0,'missing_initial_weight'=>[]];
        LivestockGroup::where('species','LELE')->when($activeOnly,fn($query)=>$query->where('status','ACTIVE'))->orderBy('id')->each(function($group) use(&$result,$asOf) {
            $profile=$this->rebuild($group,$asOf); $result['rebuilt']++;
            if($profile->calculation_status==='MISSING_INITIAL_WEIGHT') $result['missing_initial_weight'][]=$group->code;
        });
        return $result;
    }

    private function average(array $weights): float { return array_sum(array_map('floatval',$weights))*1000/30; }
}
