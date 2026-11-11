<?php

namespace App\Http\Controllers;

use App\Services\AicrmOrderClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class AicrmOrderController extends Controller
{
    public function __construct(
        private readonly AicrmOrderClient $client
    ) {}

    /**
     * POST /api/aicrm/orders
     * Tạo vận đơn trên AICRM DEV.
     */
    public function store(Request $request): JsonResponse
    {
        // 1. Validate input từ client gửi lên.
        $validator = Validator::make($request->all(), [
            'productName'      => ['required', 'string', 'max:255'],
            'quantity'         => ['required', 'integer', 'min:1'],
            'totalPrice'       => ['required', 'numeric', 'min:0'],
            'moneyCollection'  => ['required', 'integer', 'min:0'],
            'receiveFullName'  => ['required', 'string', 'max:255'],
            'receiverPhone'    => ['required', 'string', 'max:20'],
            'receiverAddress'  => ['required', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $order = $validator->validated();

        // 2. Gọi service.
        try {
            $result = $this->client->createOrder($order);

            return response()->json([
                'success' => true,
                'message' => 'Tạo vận đơn thành công.',
                'data'    => $result,
            ], 201);
        } catch (RuntimeException $e) {
            // Lỗi nghiệp vụ / cấu hình / response không hợp lệ.
            Log::warning('AICRM createOrder failed', [
                'order'   => $order,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 502);
        } catch (Throwable $e) {
            // Lỗi không lường trước.
            Log::error('AICRM createOrder unexpected error', [
                'order' => $order,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Lỗi hệ thống khi tạo vận đơn.',
            ], 500);
        }
    }
}