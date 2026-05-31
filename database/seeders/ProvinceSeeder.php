<?php

namespace Database\Seeders;

use App\Models\Province;
use App\Models\Shop;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;

class ProvinceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $shop = Shop::where("pancake_shop_id", "30179419")->first();
        $res = Http::get(env("PANCAKE_API_V1") . "geo/provinces?api_key={$shop->api_key}")->json();
        if (!empty($res["data"])) {
            foreach ($res["data"] as $data_item) {
                Province::updateOrCreate(
                    [
                        "id" => $data_item["id"]
                    ], [
                        "name"    => $data_item["name"],
                        "name_en" => $data_item["name_en"],
                        "new_id"  => $data_item["new_id"]
                    ]
                );
            }
            
        }
    }
}
