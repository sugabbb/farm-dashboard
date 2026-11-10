<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EggHarvest extends Model {
    protected $guarded = ['id'];
    protected function casts(): array { return ['weight_kg'=>'decimal:3']; }
    public function dailyRecord() { return $this->belongsTo(DailyRecord::class); }
}
