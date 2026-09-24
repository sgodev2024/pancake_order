<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = "products";
    protected $appends = [
        'price',
        'inventory_quantity',
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

    public function getInventoryQuantityAttribute(): int|float|null
    {
        return $this->firstNumericValue([
            'inventory_quantity',
            'stock_quantity',
            'stock',
            'inventory',
            'quantity',
            'remaining_quantity',
            'remain_quantity',
            'available_quantity',
            'available_stock',
            'product.inventory_quantity',
            'product.stock_quantity',
            'product.stock',
            'product.inventory',
            'product.quantity',
            'product.remaining_quantity',
            'product.remain_quantity',
            'product.available_quantity',
            'variation_info.inventory_quantity',
            'variation_info.stock_quantity',
            'variation_info.stock',
            'variation_info.inventory',
            'variation_info.quantity',
            'variation_info.remaining_quantity',
            'variation_info.remain_quantity',
            'variation_info.available_quantity',
        ]);
    }

    /** @param list<string> $paths */
    private function firstNumericValue(array $paths): int|float|null
    {
        $data = is_array($this->pancake_full_data) ? $this->pancake_full_data : [];

        foreach ($paths as $path) {
            $value = data_get($data, $path);

            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                return $value + 0;
            }
        }

        return null;
    }
}
