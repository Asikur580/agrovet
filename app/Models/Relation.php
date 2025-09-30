<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Relation extends Model
{
    use HasFactory;
    protected $fillable = [
        'employee_id',
        'relation_id',
        'created_by',
    ];

    // Relation to Employee (employee_id)
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
    

    // Relation to Employee (relation_id)
    public function relatedEmployee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function relationEmployee()
    {
        return $this->belongsTo(Employee::class, 'relation_id');
    }

    // Relation to User (created_by)
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
