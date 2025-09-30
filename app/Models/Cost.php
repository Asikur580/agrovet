<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cost extends Model
{
    use HasFactory;
    protected $fillable = [
        'cost_cat_id',
        'employee_cost_cat_id',
        'employee_id',
        'amount',
        'cost_date',
    ];

    /**
     * Get the cost category associated with the cost.
     */
    public function category()
    {
        return $this->belongsTo(CostCategory::class, 'costCategory_id');
    }

    /**
     * Get the employee associated with the cost.
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function costCategory()
    {
        return $this->belongsTo(CostCategory::class, 'cost_cat_id');
    }
}
