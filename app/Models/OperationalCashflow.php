<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalCashflow extends Model {
    protected $guarded = ['id'];

    protected function casts(): array {
        return [
            'transaction_date'=>'date', 'amount_idr'=>'decimal:0', 'submitted_at'=>'datetime',
            'approved_at'=>'datetime', 'rejected_at'=>'datetime', 'paid_at'=>'datetime',
        ];
    }

    public function feedDetails() { return $this->hasMany(OperationalCashflowFeedDetail::class); }
    public function payments() { return $this->hasMany(OperationalCashflowPayment::class)->orderBy('paid_date')->orderBy('id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function submitter() { return $this->belongsTo(User::class, 'submitted_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function rejector() { return $this->belongsTo(User::class, 'rejected_by'); }
    public function payer() { return $this->belongsTo(User::class, 'paid_by'); }
}
