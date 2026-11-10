<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeleSamplingMeasurement extends Model {
    protected $guarded = ['id'];
    protected function casts(): array { return ['total_weight_kg'=>'decimal:3']; }
    public function sampling() { return $this->belongsTo(LeleSampling::class, 'sampling_id'); }
}
