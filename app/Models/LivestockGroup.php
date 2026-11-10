<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LivestockGroup extends Model {
    protected $guarded = ['id'];
    protected function casts(): array { return ['start_date'=>'date','closed_at'=>'date','initial_average_weight_g'=>'decimal:3','planned_adg_g_per_day'=>'decimal:3']; }
    public function dailyRecords() { return $this->hasMany(DailyRecord::class, 'group_id'); }
    public function populationAdjustments() { return $this->hasMany(PopulationAdjustment::class, 'group_id'); }
    public function leleSamplings() { return $this->hasMany(LeleSampling::class, 'group_id'); }
    public function growthProfile() { return $this->hasOne(LeleGrowthProfile::class,'group_id'); }
    protected static function booted(): void { static::creating(function($group){if($group->species==='LELE'&&!filled($group->planned_adg_g_per_day))$group->planned_adg_g_per_day=AppSetting::find('default_lele_adg_g_per_day')?->value??'1.300';}); }
}
