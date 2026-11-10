<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class FeedStockProjection
{
    /**
     * @param Collection<int,object> $feedTypes
     * @param array<int|string,string> $stock
     * @return array<int,array<string,mixed>>
     */
    public function estimate(Collection $feedTypes, string $asOfDate, array $stock): array
    {
        $feedIds=$feedTypes->pluck('id')->map(fn($id)=>(int)$id);
        $dailyUsage=DB::table('feed_usages as usage')
            ->join('daily_records as record','record.id','=','usage.daily_record_id')
            ->whereIn('usage.feed_type_id',$feedIds)->where('record.record_date','<=',$asOfDate)
            ->groupBy('usage.feed_type_id','record.record_date')
            ->orderBy('record.record_date','desc')
            ->get(['usage.feed_type_id','record.record_date as usage_date',DB::raw('SUM(usage.quantity_kg) as daily_usage_kg')])
            ->groupBy('feed_type_id')->map(fn($rows)=>$rows->first());

        return $feedTypes->mapWithKeys(function($feed) use($asOfDate,$stock,$dailyUsage) {
            $balance=(string)($stock[$feed->id]??'0');
            $usage=$dailyUsage->get($feed->id);
            $usageDate=$usage?substr((string)$usage->usage_date,0,10):null;
            $daily=$usage?(string)$usage->daily_usage_kg:null;

            if(Decimal::compare($balance,'0')<=0) return [$feed->id=>[
                'status'=>'OUT_OF_STOCK','usage_date'=>$usageDate,'daily_usage_kg'=>$daily,
                'days_remaining'=>0,'estimated_out_of_stock_date'=>$asOfDate,
            ]];
            if(!$usage) return [$feed->id=>[
                'status'=>'NO_USAGE','usage_date'=>null,'daily_usage_kg'=>null,
                'days_remaining'=>null,'estimated_out_of_stock_date'=>null,
            ]];
            if(Decimal::compare($daily,'0')<=0) return [$feed->id=>[
                'status'=>'ZERO_USAGE','usage_date'=>$usageDate,'daily_usage_kg'=>$daily,
                'days_remaining'=>null,'estimated_out_of_stock_date'=>null,
            ]];

            $days=(int)bcdiv($balance,$daily,0);
            return [$feed->id=>[
                'status'=>'ESTIMATED','usage_date'=>$usageDate,'daily_usage_kg'=>$daily,
                'days_remaining'=>$days,
                'estimated_out_of_stock_date'=>Carbon::createFromFormat('Y-m-d',$asOfDate,config('app.timezone'))->addDays($days)->toDateString(),
            ]];
        })->all();
    }
}
