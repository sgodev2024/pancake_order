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
        Order::where("status", "!=", 3)->with("shop")->chunkById(100, function ($orders) {
            foreach ($orders as $orderItem) {
                $date_create_shop = date("Y-m-d", strtotime($orderItem->created_at));
                $date_care        = Carbon::parse($date_create_shop)->addDays($orderItem->shop->care_cycle_days ?? 3)->format("Y-m-d");
                CustomerCare::create([
                    "shop_id"               => $orderItem->shop_id,
                    "pancake_customer_id"   => $orderItem->pancake_customer_id,
                    "customer_phones"       => $orderItem->customer_phone,
                    "customer_name"         => $orderItem->customer_name,
                    "customer_addresss"     => $orderItem->customer_address,
                    "pancake_order_id"      => $orderItem->pancake_order_id,
                    "date_care"             => $date_care,
                    "user_creator_id"       => $orderItem->user_creator_id
                ]);
            }
        });
    }
}
