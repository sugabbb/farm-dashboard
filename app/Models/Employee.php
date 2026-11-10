<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Employee extends Model {
    protected $guarded = ['id'];
    protected $hidden = ['api_pin_hash'];
    protected function casts(): array { return ['joined_at'=>'date','api_locked_until'=>'datetime','credentials_updated_at'=>'datetime']; }
    public function submissions() { return $this->hasMany(AbkSubmission::class); }
    public function canAccessSpecies(string $species): bool { return $this->abk_type==='BOTH' || $this->abk_type===$species; }
    public function hasApiAccess(): bool { return $this->status==='ACTIVE' && $this->abk_type!=='NO_SYSTEM_ACCESS' && filled($this->api_username) && filled($this->api_pin_hash); }
}
