<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = "customers";

    protected $fillable = [
        "shop_id",
        "loyalty_tier_id",
        "pancake_customer_id",
        "fb_id",
        "name",
        "phone_numbers",
        "pancake_full_data",
        "assigned_user_id",
        "purchased_amount"
    ];

    protected $casts = [
        'pancake_full_data'  => 'array'
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, "pancake_customer_id", "pancake_customer_id");
    }

    public function loyalty_tier()
    {
        return $this->belongsTo(LoyaltyTier::class)->select("id", "name");
    }
}
