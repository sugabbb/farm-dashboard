<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LeleGrowthProfile extends Model {
    protected $guarded=['id'];
    protected function casts(): array { return ['planned_harvest_date'=>'date','chart_end_date'=>'date','source_sampling_ids'=>'array','rebuilt_at'=>'datetime']; }
    public function group() { return $this->belongsTo(LivestockGroup::class,'group_id'); }
    public function points() { return $this->hasMany(LeleGrowthPoint::class,'profile_id')->orderBy('point_date'); }
}
