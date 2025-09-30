<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CostCategory extends Model
{
    use HasFactory;
    protected $fillable = ['name'];

    public function costs()
    {
        return $this->hasMany(Cost::class, 'cost_cat_id');
    }
}
