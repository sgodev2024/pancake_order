<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\OrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GetOrderFromWebhookJob implements ShouldQueue
{
    use Queueable;

    protected $data;

    /**
     * Create a new job instance.
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $pancake_shop_id = $this->data["shop_id"];
            $shop = Shop::where("pancake_shop_id", $pancake_shop_id)->first();
            if ($shop) {
                $orderService = new OrderService();
                $orderService->getOrderItem($this->data, $shop->id);
            }

            return;
        } catch (\Throwable $th) {
            Log::channel("pancake-webhook")->info($th->getMessage());
        }
    }
}
