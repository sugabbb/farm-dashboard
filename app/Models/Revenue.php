<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Revenue extends Model {
    protected $guarded = ['id'];
    protected function casts(): array { return ['transaction_date'=>'date','direct_transfer_date'=>'date','amount_idr'=>'decimal:0','submitted_at'=>'datetime','approved_at'=>'datetime','rejected_at'=>'datetime','paid_at'=>'datetime']; }
    public function eggDetails() { return $this->hasMany(RevenueEggDetail::class); }
    public function fishDetail() { return $this->hasOne(RevenueFishDetail::class); }
    public function payments() { return $this->hasMany(RevenuePayment::class)->orderBy('paid_date')->orderBy('id'); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function submitter() { return $this->belongsTo(User::class, 'submitted_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function rejector() { return $this->belongsTo(User::class, 'rejected_by'); }
    public function payer() { return $this->belongsTo(User::class, 'paid_by'); }
}
