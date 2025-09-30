<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderProduct extends Model
{
    use HasFactory;
    protected $fillable = ['order_id', 'product_id', 'quantity', 'unit_price', 'bonus_qty', 'price_type'];

    /**
     * Relationship: An OrderProduct belongs to an Order.
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Relationship: An OrderProduct belongs to a Product.
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function order_products()
    {
        return $this->hasMany(OrderProduct::class);
    }
}
