<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DailyRecord extends Model {
    protected $guarded = ['id'];
    protected function casts(): array { return ['record_date'=>'date']; }
    public function group() { return $this->belongsTo(LivestockGroup::class, 'group_id'); }
    public function feedUsages() { return $this->hasMany(FeedUsage::class); }
    public function eggHarvests() { return $this->hasMany(EggHarvest::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
