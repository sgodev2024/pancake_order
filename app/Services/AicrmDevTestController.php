<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AicrmOrderClient;
use Illuminate\Http\JsonResponse;

class AicrmDevTestController extends Controller
{
    public function providers(
        AicrmOrderClient $client
    ): JsonResponse {
        abort_unless(
            app()->environment('local'),
            403,
            'Chỉ dùng trong môi trường Local.'
        );

        try {
            $providerId = $client->getViettelPostProviderId();

            return response()->json([
                'success' => true,
                'message' => 'Kết nối AICRM DEV thành công.',
                'shippingProviderId' => $providerId,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Không lấy được Viettel Post provider.',
            ], 502);
        }
    }
}
