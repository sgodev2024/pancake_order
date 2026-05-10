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
            GetOrderFromWebhookJob::dispatch($res);

            return;
        } catch (\Throwable $th) {
            Log::channel("pancake-webhook-error")->info($th->getMessage());

            return;
        }
    }
}
