<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\LoyaltyTier;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RefactorCustomerLoyaltyTier extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Customer::chunkById(100, function ($customers) {
            foreach ($customers as $customer_item) {
                $customer_item->update([
                    "purchased_amount" => $customer_item->pancake_full_data["purchased_amount"],
                    "loyalty_tier_id"  => get_loyalty_tier($customer_item->pancake_full_data["purchased_amount"])
                ]);
            }
        });
    }
}
