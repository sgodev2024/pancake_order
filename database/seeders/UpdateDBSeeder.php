<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerAssigned;
use App\Models\CustomerCare;
use App\Models\Order;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UpdateDBSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Customer::chunkById(100, function ($customers) {
            foreach ($customers as $item) {
                $full_data = $item->pancake_full_data;
                $customer_id = $full_data["customer_id"];
                CustomerCare::where("pancake_customer_id", $item->pancake_customer_id)->update([
                    "pancake_customer_id" => $customer_id
                ]);
                Order::where("pancake_customer_id", $item->pancake_customer_id)->update([
                    "pancake_customer_id" => $customer_id
                ]);
                CustomerAssigned::where("pancake_customer_id", $item->pancake_customer_id)->update([
                    "pancake_customer_id" => $customer_id
                ]);
                $item->update([
                    "pancake_customer_id" => $customer_id,
                    "order_count"         => $full_data["order_count"]
                ]);
            }
        });
    }
}
