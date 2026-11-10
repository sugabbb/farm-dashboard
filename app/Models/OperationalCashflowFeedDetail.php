<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalCashflowFeedDetail extends Model {
    protected $guarded = ['id'];

    protected function casts(): array {
        return [
            'quantity_kg'=>'decimal:3','price_per_kg_idr'=>'decimal:3','line_amount_idr'=>'decimal:0',
            'purchase_quantity'=>'decimal:3','kg_per_sak'=>'decimal:3','price_per_purchase_unit_idr'=>'decimal:3',
            'received_at'=>'datetime',
        ];
    }

    public function operationalCashflow() { return $this->belongsTo(OperationalCashflow::class); }
    public function feedType() { return $this->belongsTo(FeedType::class); }
    public function receiver() { return $this->belongsTo(User::class, 'received_by'); }
}
