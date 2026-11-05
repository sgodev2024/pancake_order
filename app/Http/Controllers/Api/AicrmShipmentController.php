<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PermissionCheckMiddleware;
use App\Models\Order;
use App\Services\AicrmShipmentService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class AicrmShipmentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(PermissionCheckMiddleware::class.':warehouse-view,strict'),
            new Middleware(PermissionCheckMiddleware::class.':warehouse-pack,strict'),
        ];
    }

    public function store(Request $request, Order $order, AicrmShipmentService $service)
    {
        // Payload is always constructed server-side from the authorized order.
        $result = $service->create($request->user(), $order->id);
        $shipment = $result['shipment'];

        return response()->json([
            'success' => $shipment->status === 'succeeded', 'replayed' => $result['replayed'],
            'message' => $shipment->status === 'succeeded'
                ? match ($shipment->mode) {
                    'live' => 'Đã tạo vận đơn thật qua AICRM Live.',
                    'sandbox' => 'Đã tạo vận đơn qua AICRM Sandbox.',
                    default => 'Đã tạo vận đơn Mock trên Local.',
                }
                : $shipment->last_error,
            'data' => $shipment->summary(true),
        ], $shipment->status === 'succeeded' ? 200 : 502);
    }
}
