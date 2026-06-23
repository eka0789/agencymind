<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'unit_price_usd' => 'decimal:4',
        'total_usd' => 'decimal:4',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
