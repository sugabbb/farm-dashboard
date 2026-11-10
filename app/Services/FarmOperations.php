<?php
namespace App\Services;

use App\Models\{DailyRecord,EggHarvest,FeedType,FeedUsage,InventoryAdjustment,LivestockGroup,PopulationAdjustment,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FarmOperations {
    public function __construct(private Balances $balances) {}

    public function saveDaily(string $species, array $rows, User $actor): void {
        $seen=[];
        foreach($rows as $index=>$row) {
            if(!is_array($row['feeds']??null) || count($row['feeds'])<1 || collect($row['feeds'])->contains(fn($feed)=>(int)($feed['feed_type_id']??0)<1)) {
                throw ValidationException::withMessages(["rows.$index.feeds"=>'Minimal satu jenis pakan wajib diisi.']);
            }
            $key=($row['group_id']??'').':'.($row['record_date']??'');
            if(isset($seen[$key])) throw ValidationException::withMessages(["rows.$index.record_date"=>'Grup dan tanggal yang sama hanya boleh muncul satu kali.']);
            $seen[$key]=true;
        }

        DB::transaction(function() use($species,$rows,$actor) {
            $this->balances->lock(); $groupChecks=[];
            foreach($rows as $index=>$input) {
                $group=LivestockGroup::lockForUpdate()->findOrFail($input['group_id']);
                if($group->species!==$species || $group->status!=='ACTIVE') throw ValidationException::withMessages(["rows.$index.group_id"=>'Grup harus aktif dan sesuai jenis ternak.']);
                if($input['record_date']<$group->start_date->toDateString()) throw ValidationException::withMessages(["rows.$index.record_date"=>'Tanggal catatan tidak boleh sebelum tanggal mulai grup.']);
                if(DailyRecord::where('group_id',$group->id)->where('record_date',$input['record_date'])->exists()) throw ValidationException::withMessages(["rows.$index.record_date"=>'Input harian untuk grup dan tanggal ini sudah ada. Gunakan aksi Edit.']);

                $record=DailyRecord::create(['group_id'=>$group->id,'record_date'=>$input['record_date'],'mortality'=>$input['mortality']??0,'notes'=>$input['notes']??null,'created_by'=>$actor->id]);
                $this->replaceDailyChildren($record,$species,$input,"rows.$index");
                $groupChecks[$group->id]=min($groupChecks[$group->id]??$input['record_date'],$input['record_date']);
                Audit::record($record,'daily_created');
            }
            $this->assertPopulationBalances($groupChecks);
        },3);
    }

    public function updateDaily(string $species, DailyRecord $record, array $input, User $actor): void {
        DB::transaction(function() use($species,$record,$input) {
            $this->balances->lock();
            $record=DailyRecord::with(['group','feedUsages','eggHarvests'])->lockForUpdate()->findOrFail($record->id);
            if($record->group->species!==$species) abort(404);
            $before=$record->toArray();
            $date=$record->record_date->toDateString();
            $record->update(['mortality'=>$input['mortality']??0,'notes'=>$input['notes']??null]);
            $this->replaceDailyChildren($record,$species,$input,'');
            $this->assertPopulationBalances([$record->group_id=>$date]);
            Audit::record($record,'daily_updated',$before);
        },3);
    }

    private function replaceDailyChildren(DailyRecord $record,string $species,array $input,string $errorKey): void {
        $field=fn(string $name)=>$errorKey===''?$name:"$errorKey.$name";
        $record->feedUsages()->delete(); $seenFeeds=[];
        foreach(array_values($input['feeds']??[]) as $index=>$feed) {
            $feedId=(int)($feed['feed_type_id']??0);
            if(!$feedId) continue;
            if(isset($seenFeeds[$feedId])) throw ValidationException::withMessages([$field("feeds.$index.feed_type_id")=>'Jenis pakan tidak boleh duplikat dalam satu catatan.']);
            $seenFeeds[$feedId]=true;
            $type=FeedType::findOrFail($feedId);
            if($type->species!==$species) throw ValidationException::withMessages([$field("feeds.$index.feed_type_id")=>'Jenis pakan tidak sesuai dengan grup.']);
            $morning=Decimal::add($feed['morning_kg']??0,0,3); $noon=Decimal::add($feed['noon_kg']??0,0,3); $evening=Decimal::add($feed['evening_kg']??0,0,3);
            if($species==='AYAM' && Decimal::compare($evening,0)!==0) throw ValidationException::withMessages([$field("feeds.$index.evening_kg")=>'Pakan Ayam hanya memiliki periode Pagi dan Siang.']);
            $total=Decimal::add(Decimal::add($morning,$noon,3),$evening,3);
            if(Decimal::compare($total,0)<0) throw ValidationException::withMessages([$field("feeds.$index.morning_kg")=>'Total pakan tidak boleh negatif.']);
            FeedUsage::create(['daily_record_id'=>$record->id,'feed_type_id'=>$type->id,'morning_kg'=>$morning,'noon_kg'=>$noon,'evening_kg'=>$evening,'quantity_kg'=>$total]);
        }

        $record->eggHarvests()->delete();
        if($species==='AYAM') foreach(['UTUH','PECAH','REJECT'] as $category) {
            $egg=$input['eggs'][$category]??[];
            EggHarvest::create(['daily_record_id'=>$record->id,'egg_category'=>$category,'weight_kg'=>$egg['weight_kg']??0,'quantity_pieces'=>$egg['quantity_pieces']??0]);
        }
    }

    private function assertPopulationBalances(array $groupChecks): void {
        foreach($groupChecks as $id=>$from) $this->balances->assertPopulationNonnegative((int)$id,$from);
    }

    public function adjustInventory(array $data, User $actor): InventoryAdjustment {
        return DB::transaction(function() use($data,$actor) {
            $this->balances->lock(); $data['created_by']=$actor->id;
            if($data['item_kind']==='FEED') { $data['egg_category']=null; $data['delta_pieces']=null; }
            else { $data['feed_type_id']=null; }
            $adjustment=InventoryAdjustment::create($data);
            Audit::record($adjustment,'inventory_adjusted',null,$data['reason']); return $adjustment;
        },3);
    }

    public function updateFeedAdjustment(InventoryAdjustment $adjustment,array $data,User $actor): InventoryAdjustment {
        return DB::transaction(function() use($adjustment,$data,$actor) {
            $this->balances->lock();
            $adjustment=InventoryAdjustment::lockForUpdate()->findOrFail($adjustment->id);
            abort_unless($adjustment->item_kind==='FEED',404);
            $currentFeed=FeedType::findOrFail($adjustment->feed_type_id);
            $replacement=FeedType::findOrFail($data['feed_type_id']);
            if($currentFeed->species!==$replacement->species) throw ValidationException::withMessages(['feed_type_id'=>'Jenis pakan pengganti harus untuk spesies yang sama.']);
            $before=$adjustment->toArray();
            $adjustment->update([
                'feed_type_id'=>$replacement->id,'delta_kg'=>$data['delta_kg'],
                'effective_date'=>$data['effective_date'],'reason'=>$data['reason'],
            ]);
            Audit::record($adjustment,'inventory_adjustment_updated',$before,$data['reason'],null,$actor->id);
            return $adjustment->fresh();
        },3);
    }

    public function adjustPopulation(array $data, User $actor): PopulationAdjustment {
        return DB::transaction(function() use($data,$actor) {
            $this->balances->lock(); $data['created_by']=$actor->id; $adjustment=PopulationAdjustment::create($data);
            $this->balances->assertPopulationNonnegative($data['group_id'],$data['effective_date']);
            Audit::record($adjustment,'population_adjusted',null,$data['reason']); return $adjustment;
        },3);
    }
}
