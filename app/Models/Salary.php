<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Salary extends Model
{
    use HasFactory;
    protected $fillable = [
        'employee_id',
        'month_year',
        'basic_salary',
        'paid_amount',
        'advance_amount',
        'due_amount',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
