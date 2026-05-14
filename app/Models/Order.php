<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $table = "orders";

    protected $fillable = [
        "shop_id",
        "pancake_order_id",
        "order_number_vtp",
        "total_quantity",
        "cod",
        "cash",
        "note",
        "user_creator_id", // Thông tin người tạo đơn
        "user_care_id", // nhân vien chăm sóc
        "user_assigning_seller_id", // nhân viên được phân công
        "status",
        "pancake_customer_id",
        "status_vtp",
        "pancake_full_data",
        "received_at_shop",
        "customer_name",
        "customer_phone",
        "customer_address"
    ];

    protected $casts = [
        'pancake_full_data'  => 'array'
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
