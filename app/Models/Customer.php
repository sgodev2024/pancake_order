<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = "customers";

    protected $fillable = [
        "order_count",
        "shop_id",
        "loyalty_tier_id",
        "pancake_customer_id",
        "fb_id",
        "name",
        "phone_numbers",
        "pancake_full_data",
        "last_order_at",
        "assigned_user_id",
        "purchased_amount"
    ];

    protected $casts = [
        'pancake_full_data' => 'array',
        'last_order_at' => 'datetime',
    ];

    public function shippingAddress(): string
    {
        $profile = $this->pancake_full_data ?? [];
        $addresses = [$this->crm_shipping_address, data_get($profile, 'address')];
        foreach (['shop_customer_addresses', 'addresses'] as $key) {
            foreach ((array) data_get($profile, $key, []) as $entry) {
                $addresses[] = data_get($entry, 'full_address');
            }
        }
        foreach ($addresses as $address) {
            if (is_string($address) && trim($address) !== '') {
                return trim($address);
            }
        }

        return '';
    }

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
        return $this->belongsTo(LoyaltyTier::class)->select("id", "name", "discount_percent");
    }
}
