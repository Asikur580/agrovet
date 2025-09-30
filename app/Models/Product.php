<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'cat_id',
        'brand_id',
        'pack_size',
        'expire_date',
        'buy_price',
        'sell_price',
        'flat_price',
        'quantity',
        'image',
    ];

    /**
     * Get the category that the product belongs to.
     */
    public function category()
    {
        return $this->belongsTo(Category::class, 'cat_id');
    }

    /**
     * Get the brand that the product belongs to.
     */
    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /**
     * Relationship: A Product has many OrderProducts.
     */
    public function orderProducts()
    {
        return $this->hasMany(OrderProduct::class, 'product_id');
    }
}
