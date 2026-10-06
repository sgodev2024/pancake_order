<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GetOrderFromWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function recivePancake()
    {
        try {
            $data = file_get_contents('php://input');
            $res = json_decode($data, true);
            Log::channel("pancake-webhook")->info($res);
            GetOrderFromWebhookJob::dispatch($res)->onQueue("get-order-webhook");
            \App\Services\WebhookMonitor::record("received", $res["shop_id"] ?? null);

            return;
        } catch (\Throwable $th) {
            \App\Services\WebhookMonitor::record("error", null, "Không thể nhận / đưa webhook vào hàng đợi");
            Log::channel("pancake-webhook-error")->info($th->getMessage());

            return;
        }
    }
}
