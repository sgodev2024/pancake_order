<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopUser extends Model
{
    protected $table = "shop_users";

    protected $fillable = [
        "shop_id",
        "user_id",
        "is_manager"
    ];
}
