<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;
    protected $fillable = ['cust_id', 'employee_id','discount','order_date','order_type','status', 'offer'];


    /**
     * Relationship: An Order belongs to a Customer.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'cust_id');
    }

    /**
     * Relationship with the Employee model.
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }


    /**
     * Relationship: An Order has many OrderProducts.
     */
    public function orderProducts()
    {
        return $this->hasMany(OrderProduct::class, 'order_id');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'order_products', 'order_id', 'product_id')
            ->withPivot('quantity');
    }
}
