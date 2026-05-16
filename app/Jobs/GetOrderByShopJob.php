<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Shop;
use App\Services\OrderService;
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
            $orderService = new OrderService();
            foreach ($this->datas as $data_item) {
                // if (!empty($data_item["partner"]["order_number_vtp"])) {
                    $insertData[] = $orderService->getOrderItem($data_item, $this->shop_id);
                // }
            }
            Order::insert($insertData);

            return;
        } catch (\Throwable $th) {
            Log::info($th->getMessage());
        }
    }
}
