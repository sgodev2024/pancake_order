<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        try {
            $inputs = $request->only(
                "search",
                "shop_id",
                "status",
                "received_at_shop",
                "page",
                "page_size",
                "date_from",
                "date_to",
                "user_id"
            );
            $user = auth()->user();
            // 1. Khởi tạo query từ relationship
            $query = Order::query();
            $query->with([
                "shop" => function ($query) {
                    $query->select("id", "name");
                },
                "user_creator",
                "user_care",
                "user_assigning"
            ]);
            if (isset($inputs["shop_id"])) {
                $query->where("shop_id", $inputs["shop_id"]);
            }
            if (!$user->isAdmin()) {
                $query->whereIn("shop_id", $user->shops()->select("shops.id"));
                if (!$user->isManagerSale() && !$user->isManagerCskh()) {
                    $query->where(function ($q) use ($user) {
                        $q->where("user_creator_id", $user->pancake_user_id)
                            ->orWhere("user_care_id", $user->pancake_user_id);
                    });
                }
            }
            if (isset($inputs["user_id"])) {
                $query->where(function ($q) use ($inputs) {
                        $q->where("user_creator_id", $inputs["user_id"])
                            ->orWhere("user_care_id", $inputs["user_id"]);
                    });
            }
            // 2. XỬ LÝ LỌC (FILTERING)
            // Lọc theo status
            if (isset($inputs['status']) && $inputs['status'] !== '') {
                // Hỗ trợ cả trường hợp FE gửi lên một mảng status hoặc 1 status duy nhất
                if (is_array($inputs['status'])) {
                    $query->whereIn('orders.status', $inputs['status']);
                } else {
                    $query->where('orders.status', $inputs['status']);
                }
            }
            if (isset($inputs["date_from"])) {
                $query->where("created_at", ">=", $inputs["date_from"] . " 00:00:00");
            }
            if (isset($inputs["date_to"])) {
                $query->where("created_at", "<=", $inputs["date_to"] . " 23:59:59");
            }
            // Lọc theo received_at_shop (thường là boolean 0/1)
            if (isset($inputs['received_at_shop']) && $inputs['received_at_shop'] !== '') {
                $query->where('orders.received_at_shop', $inputs['received_at_shop']);
            }
            // (Bonus) Lọc theo search (ví dụ tìm theo số điện thoại hoặc mã đơn VTP)
            if (!empty($inputs['search'])) {
                $searchTerm = $inputs['search'] . '%';
                $query->where(function ($q) use ($searchTerm, $inputs) {
                    $q->where('orders.order_number_vtp', 'like', $searchTerm)
                      ->orWhere('orders.customer_phone', 'like', $searchTerm)
                      ->orWhere('orders.customer_name', 'like', $searchTerm)
                      ->orWhere('orders.pancake_order_id', $inputs['search']);
                });
            }
            // 2. Select các trường cụ thể cần lấy để tối ưu performance
            // (Thêm tiền tố tên bảng 'customers.id' để tránh lỗi trùng lặp cột nếu sau này có join bảng)
            $query->select([
                'id', 
                'shop_id',
                'order_number_vtp', 
                'total_quantity',
                'cod',
                'cash',
                'note',
                'created_at',
                'status',
                'status_vtp',
                'pancake_full_data',
                'received_at_shop',
                'customer_name',
                'customer_phone',
                'customer_address',
                'pancake_order_id',
                'user_creator_id',
                'user_care_id',
                'user_assigning_seller_id'
            ]);
            
            $pageNumber = $inputs["page"];
            $page_size = $inputs["page_size"] ?? 30;
            // 4. Sắp xếp và Phân trang (Lấy 30 records mỗi trang)
            $total_revenue = (clone $query)->sum('cod');
            $orders = $query->latest('created_at')->paginate($page_size, ['*'], 'page', $pageNumber);

            return response()->json([
                "success" => true,
                "data" => [
                    'orders' => $orders->items(),
                    'current_page' => $orders->currentPage(),
                    'per_page'     => $orders->perPage(),
                    'total_items'  => $orders->total(),
                    'total_pages'  => $orders->lastPage(),
                    'total_revenue' => $total_revenue
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Đã có lỗi xảy ra: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Lấy đơn status=3 không có CustomerCareAssignment đang hoạt động.
     * CustomerCare cũ được giữ lại làm lịch sử và không ảnh hưởng availability.
     */
    public function chance(Request $request)
    {
        try {
            $inputs = $request->only(
                "shop_id",
                "page",
                "date_from",
                "date_to"
            );
            $user = auth()->user();
            $queries = Order::query();
            $queries->where("status", 3)
                   ->whereDoesntHave("customerCareAssignments", function ($query) {
                       $query->where('status', CustomerCareAssignment::STATUS_ACTIVE);
                   });
            $queries->with([
                "shop" => function ($query) {
                    $query->select("id", "name");
                }
            ]);
            if (isset($inputs["date_from"])) {
                $queries->where("created_at", ">=", $inputs["date_from"] . " 00:00:00");
            }
            if (isset($inputs["date_to"])) {
                $queries->where("created_at", "<=", $inputs["date_to"] . " 23:59:59");
            }
            $shopIds = $this->accessibleShopIds($user);
            if ($shopIds !== null) {

                if (isset($inputs['shop_id']) && ! $shopIds->contains($inputs['shop_id'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Bạn không có quyền truy cập cửa hàng này.',
                    ]);
                }

                $queries->whereIn('shop_id', $shopIds);
            }
            if (isset($inputs['shop_id'])) {
                $queries->where('shop_id', $inputs['shop_id']);
            }
            $queries->select([
                'id', 
                'shop_id',
                'created_at',
                'customer_name',
                'customer_phone',
                'customer_address',
                'pancake_order_id',
                'status'
            ])
            ->latest('created_at');
            $orders = $queries->paginate(30, ['*'], 'page', $inputs["page"] ?? 1);

            return response()->json([
                "success" => true,
                "data" => [
                    'orders'       => $orders->items(),
                    'current_page' => $orders->currentPage(),
                    'per_page'     => $orders->perPage(),
                    'total_items'  => $orders->total(),
                    'total_pages'  => $orders->lastPage()
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Đã có lỗi xảy ra: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Return the historical local orders related to the exact route-bound order.
     *
     * The route parameter is the local orders.id. The related-order lookup uses
     * pancake_customer_id only after that anchor has been authorized.
     */
    public function history(Request $request, Order $order)
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $user = auth()->user();
            $shopIds = $this->accessibleShopIds($user);

            if ($shopIds !== null && ! $shopIds->contains($order->shop_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn không có quyền xem lịch sử đơn hàng này.',
                ], 403);
            }

            $historyQuery = Order::query()
                ->select([
                    'id',
                    'created_at',
                    'cod',
                    'user_creator_id',
                    'pancake_full_data',
                ])
                ->with([
                    'user_creator',
                ])
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            $customerId = $order->pancake_customer_id;
            if ($customerId === null || trim((string) $customerId) === '') {
                // Never use whereNull here: a blank customer identity is not a safe grouping key.
                $historyQuery->whereKey($order->getKey());
            } else {
                $historyQuery->where('pancake_customer_id', $customerId);
            }

            if ($shopIds !== null) {
                $historyQuery->whereIn('shop_id', $shopIds);
            }

            $page = (int) ($validated['page'] ?? 1);
            $perPage = (int) ($validated['per_page'] ?? 30);
            $orders = $historyQuery->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'data' => collect($orders->items())
                    ->map(fn (Order $historyOrder) => $this->historyOrderData($historyOrder))
                    ->values()
                    ->all(),
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'last_page' => $orders->lastPage(),
                ],
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể tải lịch sử đơn hàng.',
            ], 500);
        }
    }

    private function accessibleShopIds($user)
    {
        return $user->isAdmin()
            ? null
            : $user->shops()->pluck('shops.id');
    }

    private function historyOrderData(Order $order): array
    {
        $items = is_array($order->pancake_full_data)
            ? ($order->pancake_full_data['items'] ?? [])
            : [];

        $products = collect(is_array($items) ? $items : [])
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item): array {
                $variation = is_array($item['variation_info'] ?? null)
                    ? $item['variation_info']
                    : [];
                $images = is_array($variation['images'] ?? null)
                    ? $variation['images']
                    : [];
                $image = $images[0] ?? null;

                return [
                    'name' => $variation['name'] ?? null,
                    'image' => is_string($image) && trim($image) !== '' ? $image : null,
                    'quantity' => $item['quantity'] ?? 0,
                ];
            })
            ->values()
            ->all();

        return [
            'id' => $order->id,
            'created_at' => $order->created_at,
            'amount' => $order->cod,
            'creator' => $order->user_creator === null
                ? null
                : [
                    'id' => $order->user_creator->id,
                    'name' => $order->user_creator->name,
                ],
            'products' => $products,
        ];
    }
}
