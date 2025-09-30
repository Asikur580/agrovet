<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;
    protected $fillable = [
        'proprietor_name',
        'company_name',
        'phone',
        'email',
        'whatsapp',
        'country',
        'address',
        'image',
        'balance',
        'due',
    ];

    /**
     * Relationship: A Supplier has many StockInOuts.
     */

    public function stockInOuts()
    {
        return $this->hasMany(StockInOut::class, 'supplier_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'supplier_id');
    }

    // Define the relationship between Supplier and Invoice (if you haven't already)
    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'supplier_id');
    }
}
