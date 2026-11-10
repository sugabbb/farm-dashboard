<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalCashflowPayment extends Model {
    protected $guarded=['id'];
    protected function casts(): array { return ['paid_date'=>'date','amount_idr'=>'decimal:0','voided_at'=>'datetime']; }
    public function operationalCashflow() { return $this->belongsTo(OperationalCashflow::class); }
    public function creator() { return $this->belongsTo(User::class,'created_by'); }
    public function voider() { return $this->belongsTo(User::class,'voided_by'); }
}
