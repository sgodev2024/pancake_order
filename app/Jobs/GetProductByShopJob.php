<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GetProductByShopJob implements ShouldQueue
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
                if (!empty($data_item["id"])) {
                    $product = Product::where("shop_id", $this->shop_id)
                                      ->where("pancake_product_id", $data_item["id"])
                                      ->first();
                    if (!$product) {
                        $insertData[] = [
                            "shop_id"            => $this->shop_id,
                            "pancake_product_id" => $data_item["id"],
                            "name"               => $data_item["product"]["name"],
                            "pancake_full_data"  => json_encode($data_item)
                        ];
                    }
                }
            }
            Product::insert($insertData);

            return;
        } catch (\Throwable $th) {
            Log::info($th->getMessage());
        }
    }
}
