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

   
}
