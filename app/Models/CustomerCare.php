<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerCare extends Model
{
    protected $table = "customer_cares";

    protected $fillable = [
        "shop_id",
        "pancake_customer_id",
        "customer_phones",
        "customer_name",
        "customer_addresss",
        "pancake_order_id",
        "date_care",
        "note",
        "user_creator_id",
        "user_care_id",
        "user_assigning_seller_id",
        "status",
        "time_care",
        "is_accept",
        "user_accept_id",
        "total_edit"
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    protected $casts = [
        'customer_phones' => 'array'
    ];
}
