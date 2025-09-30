<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockInOut extends Model
{
    use HasFactory;
    protected $fillable = [
        'product_id',
        'quantity',
        'in_out',
        'purpose',
        'supplier_id',
        'buy_price',
        'who_take',
        'in_out_date',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
