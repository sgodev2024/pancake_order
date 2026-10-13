<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HuongChat\HuongChatOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PancakeOrderController extends Controller
{
    public function __construct(
        protected HuongChatOrderService $huongChatService
    ) {}

    /**
     * Get orders from Huong Chat Pancake API.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', 'max:50'],
            'payment_status' => ['nullable', 'string', 'max:50'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $result = $this->huongChatService->getOrders($request->all());

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'data' => [],
                'pagination' => null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'pagination' => $result['pagination'],
        ]);
    }

    /**
     * Get detail of a specific order from Huong Chat.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $result = $this->huongChatService->getOrderDetail($id);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'data' => null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
        ]);
    }
}
