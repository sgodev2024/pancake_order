<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = "products";
    protected $appends = [
        'price',
    ];
    protected $fillable = [
        "shop_id",
        "name",
        "pancake_product_id",
        "pancake_full_data"
    ];
    protected $casts = [
        'pancake_full_data'  => 'array'
    ];
    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
    public function getPriceAttribute(): int|float|null
    {
        $data = is_array($this->pancake_full_data) ? $this->pancake_full_data : [];

        foreach ([
            'price',
            'retail_price',
            'selling_price',
            'product.price',
            'product.retail_price',
            'variation.price',
            'variation.retail_price',
        ] as $path) {
            $value = data_get($data, $path);
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                return $value + 0;
            }
        }
        return null;
    }
}
