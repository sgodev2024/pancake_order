<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = "customers";

    protected $fillable = [
        "shop_id",
        "pancake_customer_id",
        "fb_id",
        "name",
        "phone_numbers",
        "pancake_full_data"
    ];

    protected $casts = [
        'pancake_full_data'  => 'array',
        'phone_numbers'      => 'array'
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
