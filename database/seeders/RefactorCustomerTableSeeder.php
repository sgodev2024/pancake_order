<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RefactorCustomerTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $customers = Customer::all();
        foreach ($customers as $customer_item) {
            if (!empty($customer_item->pancake_full_data)) {
                $data = $customer_item->pancake_full_data;
                if (!empty($data["assigned_user_id"])) {
                    $customer_item->update([
                        "assigned_user_id" => $data["assigned_user_id"]
                    ]);
                }
            }
        }
    }
}
