<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Shop;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GetOrderByShopJob implements ShouldQueue
{
    use Queueable;

    protected $datas;

    protected $shop_id;

    // 🔁 Số lần retry
    public $tries = 3;

    // ⏱️ Thời gian delay giữa các lần retry (giây)
    public $backoff = [30, 60, 120]; // Retry sau 1, 3, 5 phút

    /**
     * Create a new job instance.
     */
    public function __construct(
        $datas,
        $shop_id
    )
    {
        $this->datas = $datas;
        $this->shop_id = $shop_id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $insertData = [];
            foreach ($this->datas as $data_item) {
                $total_quantity = 0;
                if (!empty($data_item["items"])) {
                    $total_quantity = collect($data_item["items"])->sum("quantity");
                }
                $time = Carbon::parse($data_item["inserted_at"])->format("Y-m-d H:i:s");
                $insertData[] = [
                    "shop_id"                  => $this->shop_id,
                    "order_number_vtp"         => $data_item["order_number_vtp"],
                    "pancake_order_id"         => $data_item["id"],
                    "total_quantity"           => $total_quantity,
                    "cod"                      => $data_item["cod"] ?? 0,
                    "cash"                     => $data_item["cash"] ?? 0,
                    "note"                     => $data_item["note"] ?? NULL,
                    "user_creator_id"          => $data_item["creator"]["id"] ?? NULL,
                    "user_care_id"             => $data_item["assigning_care"]["id"] ?? NULL,
                    "user_assigning_seller_id" => $data_item["assigning_seller"]["id"] ?? NULL,
                    "pancake_customer_id"      => $data_item["customer"]["id"] ?? NULL,
                    "status"                   => $data_item["status"],
                    "pancake_full_data"        => json_encode($data_item),
                    "received_at_shop"         => $data_item["received_at_shop"],
                    "customer_name"            => $data_item["customer"]["name"] ?? NULL,
                    "customer_phone"           => !empty($data_item["customer"]["phone_numbers"]) ? implode(",", $data_item["customer"]["phone_numbers"]) : NULL,
                    "customer_address"         => $data_item["shipping_address"]["full_address"] ?? NULL,
                    "created_at"               => $time,
                    "updated_at"               => $time
                ];
            }
            Order::insert($insertData);

            return;
        } catch (\Throwable $th) {
            Log::info($th->getMessage());
        }
    }
}
