<?php

namespace Database\Seeders;

use App\Models\Order;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UpdateProvinceInOrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Order::chunkById(100, function ($orders) {
            foreach ($orders as $order_item) {
                $pancake_full_data = $order_item->pancake_full_data;
                if (!empty($pancake_full_data["shipping_address"])) {
                    $order_item->update([
                        "province_id" => (int)$pancake_full_data["shipping_address"]["province_id"]
                    ]);
                }
            }
        });
    }
}
