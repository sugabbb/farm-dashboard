<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LeleGrowthPoint extends Model {
    protected $guarded=['id'];
    protected function casts(): array { return ['point_date'=>'date','point_kinds'=>'array']; }
    public function profile() { return $this->belongsTo(LeleGrowthProfile::class,'profile_id'); }
}
