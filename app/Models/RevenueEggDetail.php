<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RevenueEggDetail extends Model {
    protected $guarded = ['id'];
    protected function casts(): array { return ['weight_kg'=>'decimal:3','price_per_kg_idr'=>'decimal:3','line_amount_idr'=>'decimal:0']; }
    public function revenue() { return $this->belongsTo(Revenue::class); }
}
