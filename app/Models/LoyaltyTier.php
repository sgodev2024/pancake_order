<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTier extends Model
{
    protected $table = "loyalty_tiers";
    
    protected $fillable = [
        'name',
        'discount_percent',
        'min_order_value',
        'max_order_value',
        'is_active',
        'description',
    ];

    protected $casts = [
        'discount_percent' => 'decimal:2',
        'min_order_value'  => 'decimal:2',
        'max_order_value'  => 'decimal:2',
        'is_active'        => 'boolean',
    ];
}
