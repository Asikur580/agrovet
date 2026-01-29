<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;
    protected $fillable = [
        'employee_id',
        'customer_name',
        'customer_id',
        'proprietor_name',
        'phone',
        'address',
        'image',       
        'old_due',
    ];

    /**
     * Relationship: A Customer has many Orders.
     */
    public function orders()
    {
        return $this->hasMany(Order::class, 'cust_id');
    }
    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'cust_id'); // The foreign key is 'cust_id' in the invoices table
    }
    
    // Define the relationship with Employee
     public function employee()
     {
         return $this->belongsTo(Employee::class);
     }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'cust_id');
    }

}
