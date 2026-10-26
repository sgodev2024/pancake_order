<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PermissionCheckMiddleware;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Services\ShopAccessService;
use App\Services\WarehouseService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ShopAccessService $access, private readonly WarehouseService $warehouse) {}

    public static function middleware(): array
    {
        return [
            new Middleware(PermissionCheckMiddleware::class.':warehouse-view,strict'),
            new Middleware(PermissionCheckMiddleware::class.':warehouse-pack,strict', only: ['complete']),
        ];
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'shop_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in([Order::WAREHOUSE_WAITING, Order::WAREHOUSE_PACKED])],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'], 'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $shopId = $this->access->authorizeRequestedShopId($request->user(), $data['shop_id'] ?? null);
        $configuration = $this->warehouse->configuration();
        if (! $configuration['enabled']) {
            return response()->json(['success' => true, 'data' => ['orders' => [], 'total_items' => 0,
                'current_page' => $data['page'] ?? 1, 'per_page' => $data['page_size'] ?? 20, 'module' => $configuration]]);
        }
        $at = $this->warehouse->activation()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        $status = $data['status'] ?? Order::WAREHOUSE_WAITING;
        $query = Order::where('warehouse_status', $status)->where('warehouse_queued_at', '>=', $at)
            ->where(function ($birth) use ($at) {
                $birth->where(fn ($local) => $local->where('origin', 'local_pos')->where('created_at', '>=', $at))
                    ->orWhere(fn ($pancake) => $pancake->where('origin', 'pancake')->where('source_created_at', '>=', $at));
            })->with(['shop:id,name', 'packedBy', 'user_creator', 'packingLog:id,subject_id,actor_user_id,actor_name']);
        if ($status === Order::WAREHOUSE_WAITING) {
            $query->whereIn('status', WarehouseService::PACKABLE_STATUSES);
        }
        if ($shopId !== null) {
            $query->where('shop_id', $shopId);
        } elseif (! $this->access->isGlobal($request->user())) {
            $query->whereIn('shop_id', $this->access->ids($request->user()));
        }
        if (! empty($data['search'])) {
            $term = $data['search'];
            $query->where(function ($search) use ($term) {
                $search->where('pancake_order_id', 'like', '%'.$term.'%')
                    ->orWhere('customer_name', 'like', '%'.$term.'%')->orWhere('customer_phone', 'like', '%'.$term.'%');
                if (preg_match('/^POS-(\d+)$/i', $term, $match)) {
                    $search->orWhere(fn ($local) => $local->where('origin', 'local_pos')->where('id', $match[1]));
                }
            });
        }
        $rows = $query->orderByDesc($status === Order::WAREHOUSE_PACKED ? 'packed_at' : 'warehouse_queued_at')
            ->orderByDesc('id')->paginate($data['page_size'] ?? 20, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(['success' => true, 'data' => [
            'orders' => collect($rows->items())->map(fn ($order) => $this->orderData($order)),
            'current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
            'total_items' => $rows->total(), 'module' => $configuration,
        ]]);
    }

    public function show(Request $request, Order $order)
    {
        $this->warehouse->authorizeShop($request->user(), $order);
        abort_unless($this->warehouse->enabled() && $this->warehouse->inActiveQueue($order)
            && in_array($order->warehouse_status, [Order::WAREHOUSE_WAITING, Order::WAREHOUSE_PACKED], true), 404, 'Đơn không nằm trong module Kho.');
        abort_if($order->warehouse_status === Order::WAREHOUSE_WAITING && ! $this->warehouse->packableStatus($order->status), 409, 'Đơn không còn đủ điều kiện đóng hàng.');

        return response()->json(['success' => true, 'data' => $this->orderData($order, true)]);
    }

    public function complete(Request $request, Order $order)
    {
        $result = $this->warehouse->complete($request->user(), $order->id);

        return response()->json(['success' => true,
            'message' => $result['replayed'] ? 'Đơn đã được đóng hàng trước đó.' : 'Hoàn tất đóng hàng thành công.',
            'replayed' => $result['replayed'], 'data' => $this->orderData($result['order'], true)]);
    }

    private function orderData(Order $order, bool $history = false): array
    {
        $order->loadMissing('shop:id,name', 'packedBy', 'user_creator', 'packingLog:id,subject_id,actor_user_id,actor_name');
        $data = $order->only(['id', 'shop_id', 'origin', 'warehouse_status', 'warehouse_queued_at', 'source_created_at',
            'first_synced_at', 'packed_by_user_id', 'packed_at', 'created_at', 'customer_name', 'customer_phone', 'customer_address', 'note', 'total_quantity', 'status']);
        $data['reference'] = $order->origin === 'local_pos' ? 'POS-'.$order->id : $order->pancake_order_id;
        $data['shop'] = $order->shop?->only(['id', 'name']);
        $data['packed_by'] = $order->packedBy?->only(['id', 'name']) ?? ($order->packingLog ? [
            'id' => $order->packed_by_user_id, 'name' => $order->packingLog->actor_name,
        ] : null);
        $data['creator'] = $order->user_creator?->only(['id', 'name']) ?? [
            'id' => $order->user_creator_id, 'name' => $order->source_creator_name,
        ];
        $data['items'] = collect(data_get($order->pancake_full_data, 'items', []))->filter(fn ($item) => is_array($item))
            ->map(fn ($item) => [
                'name' => data_get($item, 'variation_info.name') ?? $item['name'] ?? data_get($item, 'product.name') ?? 'Sản phẩm',
                'image' => data_get($item, 'variation_info.images.0'), 'quantity' => $item['quantity'] ?? 0,
            ])->values();
        if ($history) {
            $data['history'] = ActivityLog::where('shop_id', $order->shop_id)->where('subject_type', 'order')
                ->where('subject_id', (string) $order->id)->whereIn('action', ['warehouse.packed', 'warehouse.order_removed'])
                ->orderBy('id')->get(['id', 'action', 'actor_user_id', 'actor_name', 'created_at', 'old_values', 'new_values']);
        }

        return $data;
    }
}
