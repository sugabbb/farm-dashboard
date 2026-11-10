<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeleSampling extends Model {
    protected $guarded = ['id'];
    protected function casts(): array { return ['sampling_date'=>'date']; }
    public function group() { return $this->belongsTo(LivestockGroup::class, 'group_id'); }
    public function measurements() { return $this->hasMany(LeleSamplingMeasurement::class, 'sampling_id')->orderBy('sequence'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
