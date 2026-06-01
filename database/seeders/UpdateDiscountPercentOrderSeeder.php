<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Order;

class UpdateDiscountPercentOrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Order::chunkById(100, function ($orders) {
            foreach ($orders as $order_item) {
                $order_item->update([
                    "discount_percent" => get_discount_by_customer($order_item->pancake_customer_id)
                ]);
            }
        });
    }
}
