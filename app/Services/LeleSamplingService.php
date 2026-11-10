<?php
namespace App\Services;

use App\Models\{LeleSampling,LeleSamplingMeasurement,LivestockGroup,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LeleSamplingService {
    public function __construct(private Balances $balances,private LeleGrowthService $growth) {}

    public function save(array $data,User $actor,?LeleSampling $sampling=null): LeleSampling {
        return DB::transaction(function() use($data,$actor,$sampling) {
            $this->balances->lock(); $creating=$sampling===null;
            if($sampling) $sampling=LeleSampling::with('measurements')->lockForUpdate()->findOrFail($sampling->id);
            $group=LivestockGroup::lockForUpdate()->findOrFail($data['group_id']);
            if($group->species!=='LELE') $this->fail('Sampling hanya dapat dicatat untuk grup Lele.','group_id');
            if(($creating || $sampling->group_id!==$group->id) && $group->status!=='ACTIVE') $this->fail('Grup Lele harus aktif.','group_id');
            if($data['sampling_date']<$group->start_date->toDateString()) $this->fail('Tanggal sampling tidak boleh sebelum tanggal mulai grup.','sampling_date');
            if($group->closed_at && $data['sampling_date']>$group->closed_at->toDateString()) $this->fail('Tanggal sampling tidak boleh setelah grup ditutup.','sampling_date');
            if($this->balances->population($group,$data['sampling_date'])<30) $this->fail('Populasi pada tanggal sampling harus minimal 30 ekor.','group_id');
            $sequences=collect($data['measurements'])->pluck('sequence')->map(fn($value)=>(int)$value)->sort()->values()->all();
            if($sequences!==[1,2,3]) $this->fail('Sampling harus berisi Sampel 1, 2, dan 3.','measurements');

            $oldGroupId=$sampling?->group_id; $before=$sampling?->load('measurements')->toArray();
            $sampling ??=new LeleSampling(['created_by'=>$actor->id]);
            $sampling->fill(['group_id'=>$group->id,'sampling_date'=>$data['sampling_date'],'notes'=>$data['notes']??null])->save();
            $sampling->measurements()->delete();
            foreach($data['measurements'] as $line) LeleSamplingMeasurement::create([
                'sampling_id'=>$sampling->id,'sequence'=>$line['sequence'],'fish_count'=>10,'total_weight_kg'=>$line['total_weight_kg'],
            ]);
            Audit::record($sampling,$before?'lele_sampling_updated':'lele_sampling_created',$before,null,$sampling->fresh('measurements')->toArray());
            $this->growth->rebuild($group);
            if($oldGroupId && $oldGroupId!==$group->id) $this->growth->rebuild($oldGroupId);
            return $sampling->fresh(['group','measurements','creator']);
        },3);
    }

    public function metrics(LeleSampling $sampling,?string $currentDate=null): array {
        $sampling->loadMissing(['group','measurements','creator']);
        $total=$sampling->measurements->reduce(fn($sum,$line)=>Decimal::add($sum,$line->total_weight_kg,3),'0');
        $average=Decimal::div(Decimal::mul($total,1000,3),30,3);
        $populationAtSampling=$this->balances->population($sampling->group,$sampling->sampling_date->toDateString());
        $samplingBiomass=Decimal::div(Decimal::mul($average,$populationAtSampling,3),1000,3);
        $currentPopulation=$this->balances->population($sampling->group,$currentDate??today()->toDateString());
        $currentBiomass=Decimal::div(Decimal::mul($average,$currentPopulation,3),1000,3);
        return compact('total','average','populationAtSampling','samplingBiomass','currentPopulation','currentBiomass');
    }

    private function fail(string $message,string $key): never { throw ValidationException::withMessages([$key=>$message]); }
}
