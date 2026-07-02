<?php

namespace Database\Seeders;

use App\Models\CustomerCare;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Order;
use Carbon\Carbon;

class ConvertCustomerCareFromOrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Order::where("status", 3)->chunkById(100, function ($orders) {
            foreach ($orders as $orderItem) {
                CustomerCare::create([
                    "shop_id"               => $orderItem->shop_id,
                    "pancake_customer_id"   => $orderItem->pancake_customer_id,
                    "customer_phones"       => $orderItem->customer_phone,
                    "customer_name"         => $orderItem->customer_name,
                    "customer_address"     => $orderItem->customer_address,
                    "pancake_order_id"      => $orderItem->pancake_order_id,
                    "date_care"             => now()->addDays($orderItem->shop->care_cycle_days ?? 3)->toDateString(),
                    "user_creator_id"       => $orderItem->user_creator_id
                ]);
            }
        });
    }
}
