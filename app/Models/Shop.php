<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shop extends Model
{
    use SoftDeletes;

    protected $table = "shops";

    protected $fillable = [
        "avatar_url",
        "pancake_shop_id",
        "name",
        "api_key",
        "pancake_full_data",
        "care_cycle_days"
    ];

    protected $casts = [
        'pancake_full_data'  => 'array'
    ];


    public function customers()
    {
        return $this->hasMany(Customer::class, "shop_id", "id");
    }

    public function orders()
    {
        return $this->hasMany(Order::class, "shop_id", "id");
    }

    public function users()
    {
        return $this->belongsToMany(User::class, ShopUser::class)->withPivot('is_manager');
    }
}
