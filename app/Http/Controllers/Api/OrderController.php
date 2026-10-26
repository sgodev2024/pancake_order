<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PermissionCheckMiddleware;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use App\Models\Province;
use App\Services\OrderService;
use App\Services\ShopAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(PermissionCheckMiddleware::class.':list-product,strict', only: ['store']),
            new Middleware(PermissionCheckMiddleware::class.':list-customer,strict', only: ['store']),
        ];
    }

    private ShopAccessService $shopAccessService;

    public function __construct(?ShopAccessService $shopAccessService = null)
    {
        $this->shopAccessService = $shopAccessService ?? app(ShopAccessService::class);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $isSummaryView = $request->query('view') === 'summary';
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            // A list view never needs an unbounded response.  Apart from
            // increasing response time, a large value causes Eloquent to
            // hydrate far more rows than the screen can render.
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'order_page_id' => ['nullable', 'string', 'max:255'],
            'cod' => ['nullable', 'numeric', 'min:0'],
            // The list can be returned before its expensive aggregate is
            // calculated. This keeps the table responsive on large local
            // databases while retaining the existing response by default.
            'include_totals' => ['nullable', 'boolean'],
            'totals_only' => ['nullable', 'boolean'],
        ]);
        $includeTotals = $request->boolean('include_totals', true);
        $totalsOnly = $request->boolean('totals_only');
        $requestedShopId = $this->shopAccessService->authorizeRequestedShopId(
            $user,
            $request->filled('shop_id') ? $request->integer('shop_id') : null
        );

        try {
            $inputs = $request->only(
                'search',
                'shop_id',
                'status',
                'received_at_shop',
                'page',
                'page_size',
                'date_from',
                'date_to',
                'user_id',
                'order_source_id',
                'order_page_id',
                'cod'
            );
            // 1. Khởi tạo query từ relationship
            $query = Order::query();
            if ($isSummaryView) {
                $query->with([
                    'shop' => function ($query) {
                        $query->select('id', 'name');
                    },
                    'user_creator',
                ]);
            } else {
                $query->with([
                    'shop' => function ($query) {
                        $query->select('id', 'name');
                    },
                    'user_creator',
                    'user_care',
                    'user_assigning',
                ]);
            }
            if ($requestedShopId !== null) {
                $query->where('shop_id', $requestedShopId);
            } elseif (! $this->shopAccessService->isGlobal($user)) {
                $query->whereIn('shop_id', $this->shopAccessService->ids($user));
            }
            if (! $this->shopAccessService->isGlobal($user)) {
                if (! $user->isManagerSale() && ! $user->isManagerCskh()) {
                    $query->where(function ($q) use ($user) {
                        $q->where('user_creator_id', $user->pancake_user_id)
                            ->orWhere('user_care_id', $user->pancake_user_id);
                    });
                }
            }
            if (isset($inputs['user_id'])) {
                $query->where(function ($q) use ($inputs) {
                    $q->where('user_creator_id', $inputs['user_id'])
                        ->orWhere('user_care_id', $inputs['user_id']);
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
            if (isset($inputs['date_from'])) {
                $query->where('created_at', '>=', $inputs['date_from'].' 00:00:00');
            }
            if (isset($inputs['date_to'])) {
                $query->where('created_at', '<=', $inputs['date_to'].' 23:59:59');
            }
            // Lọc theo received_at_shop (thường là boolean 0/1)
            if (isset($inputs['received_at_shop']) && $inputs['received_at_shop'] !== '') {
                $query->where('orders.received_at_shop', $inputs['received_at_shop']);
            }
            if (isset($inputs['order_source_id']) && $inputs['order_source_id'] !== '') {
                $query->where('orders.pancake_order_source_id', $inputs['order_source_id']);
            }
            if (isset($inputs['cod']) && $inputs['cod'] !== '') {
                $query->where('orders.cod', $inputs['cod']);
            }
            if ($request->filled('order_page_id')) {
                $query->where('orders.pancake_order_page_id', $inputs['order_page_id']);
            }
            // (Bonus) Lọc theo search (ví dụ tìm theo số điện thoại hoặc mã đơn VTP)
            if (! empty($inputs['search'])) {
                $searchTerm = $inputs['search'].'%';
                $query->where(function ($q) use ($searchTerm, $inputs) {
                    $q->where('orders.order_number_vtp', 'like', $searchTerm)
                        ->orWhere('orders.customer_phone', 'like', $searchTerm)
                        ->orWhere('orders.customer_name', 'like', $searchTerm)
                        ->orWhere('orders.pancake_order_id', $inputs['search']);
                    if (preg_match('/^POS-(\d+)$/i', $inputs['search'], $match)) {
                        $q->orWhere(function ($local) use ($match) {
                            $local->where('orders.origin', 'local_pos')->where('orders.id', $match[1]);
                        });
                    }
                });
            }
            // 2. Select các trường cụ thể cần lấy để tối ưu performance
            // (Thêm tiền tố tên bảng 'customers.id' để tránh lỗi trùng lặp cột nếu sau này có join bảng)
            if ($isSummaryView) {
                $query->select([
                    'id',
                    'pancake_order_id',
                    'pancake_full_data->origin as order_origin',
                    'pancake_full_data->pos_totals->total_amount as pos_total_amount',
                    'created_at',
                    'status',
                    'status_vtp',
                    'order_number_vtp',
                    'total_quantity',
                    'cod',
                    'cash',
                    'pancake_full_data->prepaid as prepaid_amount',
                    'pancake_full_data->shipping_fee as shipping_fee',
                    'customer_name',
                    'customer_phone',
                    'customer_address',
                    'note',
                    'pancake_order_source_id as order_source_id',
                    'pancake_order_source_name as order_source_name',
                    'shop_id',
                    'pancake_order_page_id as order_page_id',
                    'pancake_order_page_name as order_page_name',
                    'user_creator_id',
                ])->selectSub(
                    Province::query()
                        ->select('name')
                        ->whereColumn('provinces.id', 'orders.province_id')
                        ->limit(1),
                    'province_name'
                );
            } else {
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
                    'pancake_order_source_id as order_source_id',
                    'pancake_order_source_name as order_source_name',
                    'user_creator_id',
                    'user_care_id',
                    'user_assigning_seller_id',
                ]);
            }

            $prepaidColumn = $query->getQuery()->getGrammar()->wrap('orders.pancake_full_data->prepaid');
            $posTotalColumn = $query->getQuery()->getGrammar()->wrap('orders.pancake_full_data->pos_totals->total_amount');
            $orderValueExpression = "COALESCE({$posTotalColumn}, COALESCE(orders.cod, 0) + COALESCE({$prepaidColumn}, 0))";
            $revenueExpression = "COALESCE(SUM({$orderValueExpression}), 0)";

            if ($isSummaryView) {
                $pageNumber = max((int) ($inputs['page'] ?? 1), 1);
                $pageSize = max((int) ($inputs['page_size'] ?? 30), 1);
                $summaryTotals = null;

                if ($includeTotals || $totalsOnly) {
                    // Do this only when the caller needs it: SUM over a JSON
                    // field must inspect every matching order and was holding
                    // up the first render of the list.
                    $summaryTotals = (clone $query)->toBase()
                        ->cloneWithout(['columns', 'orders'])
                        ->cloneWithoutBindings(['select', 'order'])
                        ->selectRaw("COUNT(*) AS total_items, {$revenueExpression} AS total_revenue")
                        ->first();
                }

                if ($totalsOnly) {
                    $orderItems = [];
                } else {
                    // Read one extra record instead of running COUNT(*) while
                    // the table is loading. The client uses it only until the
                    // totals request has completed.
                    $orderItems = $query->latest('created_at')
                        ->forPage($pageNumber, $includeTotals ? $pageSize : $pageSize + 1)
                        ->get();
                }

                $hasMore = ! $includeTotals && ! $totalsOnly && $orderItems->count() > $pageSize;
                if ($hasMore) {
                    $orderItems = $orderItems->take($pageSize)->values();
                }
                $totalItems = $summaryTotals === null ? null : (int) $summaryTotals->total_items;
                $totalPages = $summaryTotals === null
                    ? null
                    : max(1, (int) ceil($totalItems / $pageSize));
                $totalRevenue = $summaryTotals?->total_revenue;

                foreach ($orderItems as $order) {
                    $userCreator = $order->getRelation('user_creator');
                    if ($userCreator !== null) {
                        $userCreator->setVisible(['id', 'name']);
                    }
                }
            } else {
                $pageNumber = $inputs['page'] ?? 1;
                $pageSize = $inputs['page_size'] ?? 30;
                $totalRevenue = (clone $query)->sum(DB::raw($orderValueExpression));
                $orders = $query->latest('created_at')->paginate($pageSize, ['*'], 'page', $pageNumber);
                $orderItems = $orders->items();
                $pageNumber = $orders->currentPage();
                $pageSize = $orders->perPage();
                $totalItems = $orders->total();
                $totalPages = $orders->lastPage();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'orders' => $orderItems,
                    'current_page' => $pageNumber,
                    'per_page' => $pageSize,
                    'total_items' => $totalItems,
                    'total_pages' => $totalPages,
                    'total_revenue' => $totalRevenue,
                    'has_more' => $isSummaryView && ! $includeTotals && ! $totalsOnly
                        ? $hasMore
                        : null,
                ],
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Đã có lỗi xảy ra: '.$th->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request, OrderService $service)
    {
        $user = $request->user();
        // Custom sales roles must opt in explicitly; read permissions alone never allow POS.
        if (! $user->isAdmin() && ! $user->isStaffSale() && ! $user->isManagerSale()
            && ! can_access('pos-create')) {
            throw new AuthorizationException('Bạn không có quyền tạo đơn POS.');
        }
        $money = ['numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'];
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'shop_id' => ['required', 'integer', Rule::exists('shops', 'id')->whereNull('deleted_at')],
            'customer_id' => ['nullable', 'integer', 'prohibits:customer'],
            'customer' => ['required_without:customer_id', 'array:name,phone', 'prohibits:customer_id'],
            'customer.name' => ['required_with:customer', 'string', 'max:255'],
            'customer.phone' => ['required_with:customer', 'string', 'regex:/^(0\d{9,10}|\+84\d{9,10})$/'],
            'shipping_address' => ['required', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['required', 'array:product_id,quantity,unit_price'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            // This is only a numeric safety bound, unrelated to displayed/physical stock.
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_price' => ['sometimes', 'required', ...$money],
            'discount_amount' => ['sometimes', 'required', ...$money],
            'shipping_fee' => ['sometimes', 'required', ...$money],
            'surcharge' => ['sometimes', 'required', ...$money],
            'paid_amount' => ['sometimes', 'required', ...$money],
            'payment_method' => ['sometimes', 'required', Rule::in(['cash', 'bank_transfer'])],
            'cod_mode' => ['sometimes', 'required', Rule::in(['remaining', 'none'])],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
        $order = $service->createLocalOrder($user, $data);

        return response()->json([
            'success' => true, 'message' => 'Tạo đơn hàng thành công.',
            'replayed' => ! $order->wasRecentlyCreated,
            'data' => $this->orderDetailData($order),
        ], $order->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, Order $order)
    {
        $actor = $request->user();
        if (! $this->shopAccessService->canAccessShop($actor, (int) $order->shop_id)) {
            throw new AuthorizationException('Bạn không có quyền xem đơn hàng thuộc shop này.');
        }
        if (! $actor->isAdmin() && ! $actor->isManagerSale() && ! $actor->isManagerCskh()
            && (string) $order->user_creator_id !== (string) $actor->pancake_user_id
            && (string) $order->user_care_id !== (string) $actor->pancake_user_id) {
            throw new AuthorizationException('Bạn không có quyền xem đơn hàng này.');
        }

        return response()->json(['success' => true, 'data' => $this->orderDetailData($order)]);
    }

    private function orderDetailData(Order $order): array
    {
        $payload = $order->pancake_full_data ?? [];

        return array_merge($order->load('shop:id,name', 'user_creator')->toArray(), [
            'reference' => $order->origin === 'local_pos' ? 'POS-'.$order->id : $order->pancake_order_id,
            'totals' => $payload['pos_totals'] ?? null,
            'items' => $payload['items'] ?? [], 'payments' => $payload['payments'] ?? [],
        ]);
    }

    /**
     * Lấy đơn status=3 không có CustomerCareAssignment đang hoạt động.
     * CustomerCare cũ được giữ lại làm lịch sử và không ảnh hưởng availability.
     */
    public function chance(Request $request)
    {
        $request->validate([
            'order_page_id' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $requestedShopId = $user === null
            ? null
            : $this->shopAccessService->authorizeRequestedShopId(
                $user,
                $request->filled('shop_id') ? $request->integer('shop_id') : null
            );

        try {
            $inputs = $request->only(
                'shop_id',
                'page',
                'date_from',
                'date_to',
                'order_page_id',
                'phone'
            );
            $queries = Order::query();
            $queries->where('status', 3)
                ->whereDoesntHave('customerCareAssignments', function ($query) {
                    $query->where('status', CustomerCareAssignment::STATUS_ACTIVE);
                });
            $queries->with([
                'shop' => function ($query) {
                    $query->select('id', 'name');
                },
            ]);
            if (isset($inputs['date_from'])) {
                $queries->where('created_at', '>=', $inputs['date_from'].' 00:00:00');
            }
            if (isset($inputs['date_to'])) {
                $queries->where('created_at', '<=', $inputs['date_to'].' 23:59:59');
            }
            if ($request->filled('order_page_id')) {
                $queries->where('orders.pancake_order_page_id', $inputs['order_page_id']);
            }
            $phone = trim($inputs['phone'] ?? '');
            if ($phone !== '') {
                $queries->where('orders.customer_phone', 'like', '%'.$phone.'%');
            }
            $shopIds = $user === null ? null : $this->accessibleShopIds($user);
            if ($requestedShopId !== null) {
                $queries->where('shop_id', $requestedShopId);
            } elseif ($shopIds !== null) {
                $queries->whereIn('shop_id', $shopIds);
            }
            $queries->select([
                'id',
                'shop_id',
                'created_at',
                'customer_name',
                'customer_phone',
                'customer_address',
                'pancake_order_id',
                'pancake_order_page_id as order_page_id',
                'pancake_order_page_name as order_page_name',
                'status',
            ])
                ->latest('created_at');
            $orders = $queries->paginate(30, ['*'], 'page', $inputs['page'] ?? 1);

            return response()->json([
                'success' => true,
                'data' => [
                    'orders' => $orders->items(),
                    'current_page' => $orders->currentPage(),
                    'per_page' => $orders->perPage(),
                    'total_items' => $orders->total(),
                    'total_pages' => $orders->lastPage(),
                ],
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Đã có lỗi xảy ra: '.$th->getMessage(),
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
            if ($order->customer_id !== null) {
                $historyQuery->where('customer_id', $order->customer_id)->where('shop_id', $order->shop_id);
            } elseif ($customerId === null || trim((string) $customerId) === '') {
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
        return $this->shopAccessService->isGlobal($user)
            ? null
            : $this->shopAccessService->ids($user);
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
