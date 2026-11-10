<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class AbkSubmission extends Model {
    use HasUuids;
    protected $guarded=[]; public $incrementing=false; protected $keyType='string';
    protected function casts(): array { return ['payload'=>'array','base_updated_at'=>'datetime','reviewed_at'=>'datetime']; }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function reviewer() { return $this->belongsTo(User::class,'reviewed_by'); }
}
