<?php

namespace Tests\Unit;

use App\Models\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductTest extends TestCase
{
    public static function salePrices(): array
    {
        return [
            'real Pancake variation' => [['retail_price' => 180000, 'average_imported_price' => 45000, 'last_imported_price' => 50000, 'wholesale_price' => 100000], 180000],
            'retail beats ambiguous price' => [['retail_price' => 180000, 'price' => 50000], 180000],
            'zero sale price is valid' => [['retail_price' => 0, 'price' => 50000], 0],
            'string sale price' => [['retail_price' => '180000.25'], 180000.25],
            'legacy price' => [['price' => 123000], 123000],
            'selling price' => [['selling_price' => 123000], 123000],
            'nested selling price' => [['variation_info' => ['selling_price' => 123000]], 123000],
            'product retail price' => [['product' => ['retail_price' => 123000]], 123000],
            'cost only must stay unknown' => [['average_imported_price' => 45000, 'last_imported_price' => 50000, 'cost' => 40000, 'wholesale_price' => 100000, 'total_purchase_price' => 200000, 'amount' => 1], null],
            'nested cost must stay unknown' => [['product' => ['cost' => 123000]], null],
            'missing price' => [[], null],
        ];
    }

    #[DataProvider('salePrices')]
    public function test_only_explicit_sale_price_fields_are_used(array $data, int|float|null $expected): void
    {
        $product = new Product(['pancake_full_data' => $data]);
        $this->assertSame($expected, $product->price);
    }

    public static function quantities(): array
    {
        return [[0, 5], [3, 5], [5, 5], [20, 20], [-10, 5], [null, 5]];
    }

    #[DataProvider('quantities')]
    public function test_virtual_stock_does_not_mutate_source_inventory(?int $quantity, int $display): void
    {
        $product = new Product(['pancake_full_data' => ['remain_quantity' => $quantity]]);
        $before = $product->getAttributes();
        $this->assertSame($display, $product->virtual_inventory_quantity);
        $this->assertSame($quantity, $product->inventory_quantity);
        $this->assertSame($before, $product->getAttributes());
    }
}
