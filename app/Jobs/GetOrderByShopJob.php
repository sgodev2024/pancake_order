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
                $insertData[] = [
                    "shop_id"                  => $this->shop_id,
                    "pancake_order_id"         => $data_item["id"],
                    "total_quantity"           => $total_quantity,
                    "cod"                      => $data_item["cod"] ?? 0,
                    "cash"                     => $data_item["cash"] ?? 0,
                    "note"                     => $data_item["note"] ?? NULL,
                    "user_creator_id"          => !empty($data_item["creator"]) ? $data_item["creator"]["id"] : NULL,
                    "user_care_id"             => !empty($data_item["assigning_care"]["id"]) ? $data_item["assigning_care"]["id"] : NULL,
                    "user_assigning_seller_id" => !empty($data_item["assigning_seller"]["id"]) ? $data_item["assigning_seller"]["id"] : NULL,
                    "pancake_customer_id"      => !empty($data_item["customer"]["id"]) ? $data_item["customer"]["id"] : NULL,
                    "status"                   => $data_item["status"],
                    "pancake_full_data"        => json_encode($data_item),
                    "created_at"               => Carbon::parse($data_item["inserted_at"])->format("Y-m-d H:i:s"),
                    "updated_at"               => Carbon::parse($data_item["inserted_at"])->format("Y-m-d H:i:s")
                ];
            }
            Order::insert($insertData);

            return;
        } catch (\Throwable $th) {
            Log::info($th->getMessage());
        }
    }
}
