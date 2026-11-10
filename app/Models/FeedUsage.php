<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FeedUsage extends Model {
    protected $guarded = ['id'];
    protected function casts(): array { return ['morning_kg'=>'decimal:3','noon_kg'=>'decimal:3','evening_kg'=>'decimal:3','quantity_kg'=>'decimal:3']; }
    public function dailyRecord() { return $this->belongsTo(DailyRecord::class); }
    public function feedType() { return $this->belongsTo(FeedType::class); }
}
