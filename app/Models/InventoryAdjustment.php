<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InventoryAdjustment extends Model {
    protected $guarded = ['id'];
    protected function casts(): array { return ['effective_date'=>'date','delta_kg'=>'decimal:3']; }
    public function feedType() { return $this->belongsTo(FeedType::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
