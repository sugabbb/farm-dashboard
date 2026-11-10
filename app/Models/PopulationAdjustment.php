<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PopulationAdjustment extends Model {
    protected $guarded = ['id'];
    protected function casts(): array { return ['effective_date'=>'date']; }
    public function group() { return $this->belongsTo(LivestockGroup::class, 'group_id'); }
}
