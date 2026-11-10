<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model {
    protected $guarded = ['id'];
    public function revenues() { return $this->hasMany(Revenue::class); }
}
