<?php

namespace App\Models;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;
    protected $fillable = [
        'employee_id',
        'designation_id',
        'name',
        'phone',
        'territory',
        'district',
        'national_id',
        'blood_group',
        'image',
        'credit_limit',
        'basic_salary',
        'created_by',
        'status'
    ];

    public function designation()
    {
        return $this->belongsTo(Designation::class, 'designation_id');
    }
   

    public function relations()
    {
        return $this->hasMany(Relation::class, 'employee_id');
    }

    public function relatedTo()
    {
        return $this->hasMany(Relation::class, 'relation_id');
    }

    public function user()
    {
        return $this->hasOne(User::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'employee_id');
    }

    public function salaries()
    {
        return $this->hasMany(Salary::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * Get subordinate employees (employees where relation_id = this employee's id)
     * Manager -> Officers, RSM -> Managers
     */
    public function subordinates()
    {
        return $this->hasManyThrough(
            Employee::class,
            Relation::class,
            'relation_id',  // Foreign key on relations table (superior)
            'id',           // Foreign key on employees table
            'id',           // Local key on employees table
            'employee_id'   // Local key on relations table (subordinate)
        );
    }

    /**
     * Recalculate credit_limit and cascade up the hierarchy
     * 
     * Officer: credit_limit = SUM(customers' credit_limit)
     * Manager: credit_limit = SUM(officers' credit_limit)
     * RSM:     credit_limit = SUM(managers' credit_limit)
     */
    public function recalculateCreditLimit()
    {
        // Credit limit is now manually managed during Employee Add/Edit.
        // This function is kept empty to avoid breaking existing calls from other controllers.
    }
}
