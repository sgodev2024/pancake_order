<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = "products";

    protected $fillable = [
        "name",
        "pancake_product_id",
        "pancake_full_data"
    ];
}
