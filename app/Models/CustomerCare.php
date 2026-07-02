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
        "total_edit",
        "reason",
        "is_confirm_care"
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class, "pancake_order_id", "pancake_order_id");
    }

    public function user_creator()
    {
        return $this->belongsTo(User::class, "user_creator_id", "pancake_user_id")
                    ->select("id", "name", "pancake_user_id");
    }

    public function user_care()
    {
        return $this->belongsTo(User::class, "user_care_id", "pancake_user_id")
                    ->select("id", "name", "pancake_user_id");
    }

    public function user_assigning()
    {
        return $this->belongsTo(User::class, "user_assigning_seller_id", "pancake_user_id")
                    ->select("id", "name", "pancake_user_id");
    }

    public function users()
    {
        return $this->belongsToMany(
            User::class,                     // $related
            "customer_assigneds", //$table (bảng trung gian)
            "customer_care_id",    // $foreignPivotKey 
            "pancake_user_id",                      // $relatedPivotKey (khóa trỏ về User)
            "id",                           // $parentKey 
            "pancake_user_id"          
        )->withPivot("pancake_user_id");
    }
}
