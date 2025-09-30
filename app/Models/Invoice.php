<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;
    protected $fillable = [
        'invoiceId',
        'cust_id',
        'employee_id',
        'total_item',
        'total_price',
        'discount',
        'less',
        'grand_total',
        'paid',
        'due',
        'sale_date',
        'sale_type',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'cust_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function products()
    {
        return $this->hasMany(InvoiceProduct::class, 'invoice_id');
    }
}
