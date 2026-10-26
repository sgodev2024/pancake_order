<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'products';

    protected $appends = [
        'price',
        'inventory_quantity',
        'virtual_inventory_quantity',
    ];

    protected $fillable = [
        'shop_id',
        'name',
        'pancake_product_id',
        'pancake_full_data',
    ];

    protected $casts = [
        'pancake_full_data' => 'array',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function getPriceAttribute(): int|float|null
    {
        // Explicit sale-price fields only. Import cost/wholesale/amount are not retail prices.
        return $this->firstNumericValue([
            'retail_price',
            'selling_price',
            'sale_price',
            'price',
            'product.retail_price',
            'product.selling_price',
            'product.sale_price',
            'product.price',
            'variation.retail_price',
            'variation.selling_price',
            'variation.price',
            'variation_info.retail_price',
            'variation_info.selling_price',
            'variation_info.sale_price',
            'variation_info.price',
        ]);
    }

    public function getInventoryQuantityAttribute(): int|float|null
    {
        return $this->firstNumericValue([
            'remain_quantity',
            'inventory_quantity',
            'stock_quantity',
            'stock',
            'inventory',
            'quantity',
            'remaining_quantity',
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

    public function getVirtualInventoryQuantityAttribute(): int|float
    {
        // A read-only display value. Never write this floor into the source inventory.
        return max(5, $this->getAttribute('virtual_stock') ?? $this->inventory_quantity ?? 0);
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
