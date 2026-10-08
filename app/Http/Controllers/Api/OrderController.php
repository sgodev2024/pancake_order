<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use App\Models\Province;
use App\Services\ShopAccessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
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
            // Snapshot before the date window so the trend can compare the
            // previous period and the same period last year in one pass.
            $trendBase = clone $query;
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
                });
            }
            // 2. Select các trường cụ thể cần lấy để tối ưu performance
            // (Thêm tiền tố tên bảng 'customers.id' để tránh lỗi trùng lặp cột nếu sau này có join bảng)
            if ($isSummaryView) {
                $query->select([
                    'id',
                    'pancake_order_id',
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
            $revenueExpression = "COALESCE(SUM(COALESCE(orders.cod, 0) + COALESCE({$prepaidColumn}, 0)), 0)";
            $prepaidAmountExpression = Schema::hasColumn('orders', 'prepaid_amount')
                ? 'COALESCE(SUM(COALESCE(orders.prepaid_amount, 0)), 0)'
                : '0';
            // Counts and COD money only touch indexed columns. The prepaid JSON
            // sum reads every order document and was leaving the overview stuck.
            $overviewExpression = <<<'SQL'
COALESCE(SUM(COALESCE(orders.cod, 0)), 0) AS total_cod,
COALESCE(SUM(CASE WHEN orders.status IN (2, 8, 9) THEN 1 ELSE 0 END), 0) AS shipping_count,
COALESCE(SUM(CASE WHEN orders.status IN (2, 8, 9) THEN COALESCE(orders.cod, 0) ELSE 0 END), 0) AS shipping_amount,
COALESCE(SUM(CASE WHEN orders.status IN (3, 16) THEN 1 ELSE 0 END), 0) AS success_count,
COALESCE(SUM(CASE WHEN orders.status IN (3, 16) THEN COALESCE(orders.cod, 0) ELSE 0 END), 0) AS success_amount,
COALESCE(SUM(CASE WHEN orders.status IN (6, 7) THEN 1 ELSE 0 END), 0) AS cancel_count,
COALESCE(SUM(CASE WHEN orders.status IN (6, 7) THEN COALESCE(orders.cod, 0) ELSE 0 END), 0) AS cancel_amount,
COALESCE(SUM(CASE WHEN orders.status IN (4, 5, 15) THEN 1 ELSE 0 END), 0) AS return_count,
COALESCE(SUM(CASE WHEN orders.status IN (4, 5, 15) THEN COALESCE(orders.cod, 0) ELSE 0 END), 0) AS return_amount,
COALESCE(SUM(CASE WHEN orders.status NOT IN (2, 3, 4, 5, 6, 7, 8, 9, 15, 16) THEN 1 ELSE 0 END), 0) AS pending_count,
COALESCE(SUM(CASE WHEN orders.status NOT IN (2, 3, 4, 5, 6, 7, 8, 9, 15, 16) THEN COALESCE(orders.cod, 0) ELSE 0 END), 0) AS pending_amount
SQL;
            $statusBreakdown = null;
            $successOrderCount = null;
            $successAmount = null;
            $totalPrepaidAmount = null;
            $overdueCustomerCount = null;
            $trendPayload = null;
            $comparePayload = null;

            if ($isSummaryView) {
                $pageNumber = max((int) ($inputs['page'] ?? 1), 1);
                $pageSize = max((int) ($inputs['page_size'] ?? 30), 1);
                $summaryTotals = null;

                if ($includeTotals || $totalsOnly) {
                    // The overview request must stay on real columns. Parsing
                    // prepaid out of pancake_full_data does not finish on the
                    // full order table, so that sum stays on the smaller path.
                    $revenueSelect = $totalsOnly
                        ? 'COALESCE(SUM(COALESCE(orders.cod, 0)), 0) AS total_revenue'
                        : "{$revenueExpression} AS total_revenue";
                    $summaryTotals = (clone $query)->toBase()
                        ->cloneWithout(['columns', 'orders'])
                        ->cloneWithoutBindings(['select', 'order'])
                        ->selectRaw("COUNT(*) AS total_items, {$revenueSelect}, {$overviewExpression}, {$prepaidAmountExpression} AS total_prepaid_amount")
                        ->first();
                }

                if ($totalsOnly && $summaryTotals !== null) {
                    $statusBreakdown = $this->statusBreakdown($summaryTotals);
                    $successOrderCount = (int) $summaryTotals->success_count;
                    $successAmount = $summaryTotals->success_amount;
                    $totalPrepaidAmount = $summaryTotals->total_prepaid_amount;
                    $overdueCustomerCount = $this->overdueCustomerCount($requestedShopId, $user);
                    $trend = $this->deliveryTrend($trendBase, $inputs);
                    $trendPayload = $trend['series'];
                    $comparePayload = $trend['compare'];
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
                $totalRevenue = (clone $query)->sum(DB::raw("COALESCE(orders.cod, 0) + COALESCE({$prepaidColumn}, 0)"));
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
                    'success_order_count' => $successOrderCount,
                    'success_amount' => $successAmount,
                    'total_prepaid_amount' => $totalPrepaidAmount,
                    'overdue_customer_count' => $overdueCustomerCount,
                    'status_breakdown' => $statusBreakdown,
                    'trend' => $trendPayload,
                    'compare' => $comparePayload,
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

    public function show($id)
    {
        $order = Order::find($id);
        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đơn hàng.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $order,
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

    private function statusBreakdown(object $totals): array
    {
        return [
            ['key' => 'shipping', 'label' => 'Đang giao', 'count' => (int) $totals->shipping_count, 'amount' => $totals->shipping_amount],
            ['key' => 'success', 'label' => 'Giao thành công', 'count' => (int) $totals->success_count, 'amount' => $totals->success_amount],
            ['key' => 'cancel', 'label' => 'Hủy', 'count' => (int) $totals->cancel_count, 'amount' => $totals->cancel_amount],
            ['key' => 'return', 'label' => 'Hoàn', 'count' => (int) $totals->return_count, 'amount' => $totals->return_amount],
            ['key' => 'pending', 'label' => 'Chưa giao / đang xử lý', 'count' => (int) $totals->pending_count, 'amount' => $totals->pending_amount],
        ];
    }

    private function overdueCustomerCount(?int $shopId, $user): ?int
    {
        try {
            if (! Schema::hasTable('customer_cares')) {
                return null;
            }

            $query = CustomerCare::query()
                ->where('status', 0)
                ->whereDate('date_care', '<', now()->toDateString());

            if ($shopId !== null) {
                $query->where('shop_id', $shopId);
            } elseif (! $this->shopAccessService->isGlobal($user)) {
                $query->whereIn('shop_id', $this->shopAccessService->ids($user));
            }

            return (int) $query->count();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Successful-delivery value split into 30 equal slices of the selected
     * window, plus the previous window and the same window last year.
     */
    private function deliveryTrend($baseQuery, array $inputs): array
    {
        $hasRange = isset($inputs['date_from'], $inputs['date_to'])
            && $inputs['date_from'] !== ''
            && $inputs['date_to'] !== '';

        $trendQuery = clone $baseQuery;
        $currentStart = null;
        $currentEnd = null;
        $previousStart = null;
        $previousEnd = null;
        $yearStart = null;
        $yearEnd = null;

        if ($hasRange) {
            $currentStart = Carbon::parse($inputs['date_from'])->startOfDay();
            $currentEnd = Carbon::parse($inputs['date_to'])->startOfDay();
            if ($currentStart->greaterThan($currentEnd)) {
                [$currentStart, $currentEnd] = [$currentEnd->copy(), $currentStart->copy()];
            }
            $dayCount = (int) $currentStart->diffInDays($currentEnd) + 1;
            $previousEnd = $currentStart->copy()->subDay();
            $previousStart = $previousEnd->copy()->subDays($dayCount - 1);
            $yearStart = $currentStart->copy()->subYear();
            $yearEnd = $currentEnd->copy()->subYear();
            $fetchStart = $previousStart->lessThan($yearStart) ? $previousStart->copy() : $yearStart->copy();
            $trendQuery
                ->where('orders.created_at', '>=', $fetchStart->toDateTimeString())
                ->where('orders.created_at', '<=', $currentEnd->copy()->endOfDay()->toDateTimeString());
        }

        $rows = $trendQuery
            ->toBase()
            ->cloneWithout(['columns', 'orders'])
            ->cloneWithoutBindings(['select', 'order'])
            ->selectRaw("DATE(orders.created_at) AS day, COALESCE(SUM(CASE WHEN orders.status IN (3, 16) THEN COALESCE(orders.cod, 0) ELSE 0 END), 0) AS success_amount, COALESCE(SUM(COALESCE(orders.cod, 0)), 0) AS total_cod")
            ->groupByRaw('DATE(orders.created_at)')
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row->day] = $row;
        }

        if (! $hasRange) {
            $days = array_keys($byDay);
            sort($days);
            $currentStart = $days === [] ? now()->startOfDay() : Carbon::parse($days[0])->startOfDay();
            $currentEnd = $days === [] ? $currentStart->copy() : Carbon::parse($days[array_key_last($days)])->startOfDay();
        }

        $current = $this->bucketWindow($byDay, $currentStart, $currentEnd);
        $previous = $hasRange ? $this->bucketWindow($byDay, $previousStart, $previousEnd) : null;
        $year = $hasRange ? $this->bucketWindow($byDay, $yearStart, $yearEnd) : null;

        return [
            'series' => [
                'labels' => $current['labels'],
                'current' => $current['success'],
                'previous' => $previous['success'] ?? [],
                'previous_year' => $year['success'] ?? [],
            ],
            'compare' => $hasRange ? [
                'created_vs_previous' => $this->percentChange($current['total'], $previous['total']),
                'created_vs_year' => $this->percentChange($current['total'], $year['total']),
                'success_vs_previous' => $this->percentChange($current['success_total'], $previous['success_total']),
                'success_vs_year' => $this->percentChange($current['success_total'], $year['success_total']),
            ] : null,
        ];
    }

    private function bucketWindow(array $byDay, Carbon $start, Carbon $end): array
    {
        $startDay = $start->copy()->startOfDay();
        $endDay = $end->copy()->startOfDay();
        if ($startDay->greaterThan($endDay)) {
            [$startDay, $endDay] = [$endDay->copy(), $startDay->copy()];
        }

        $dayCount = max(1, (int) $startDay->diffInDays($endDay) + 1);
        $bucketCount = 30;
        $labels = [];
        $success = array_fill(0, $bucketCount, 0.0);
        $total = 0.0;
        $successTotal = 0.0;

        for ($index = 0; $index < $bucketCount; $index++) {
            $fromOffset = intdiv($index * $dayCount, $bucketCount);
            $toOffset = intdiv(($index + 1) * $dayCount, $bucketCount) - 1;
            if ($toOffset < $fromOffset) {
                $toOffset = $fromOffset;
            }

            $from = $startDay->copy()->addDays($fromOffset);
            $to = $startDay->copy()->addDays($toOffset);
            $labels[] = $from->format('d/m');
            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                $row = $byDay[$cursor->toDateString()] ?? null;
                if ($row !== null) {
                    $success[$index] += (float) $row->success_amount;
                    $total += (float) $row->total_cod;
                    $successTotal += (float) $row->success_amount;
                }
                $cursor->addDay();
            }
        }

        return [
            'labels' => $labels,
            'success' => $success,
            'total' => $total,
            'success_total' => $successTotal,
        ];
    }

    private function percentChange(float|int|string|null $current, float|int|string|null $previous): ?float
    {
        $baseline = (float) $previous;
        if ($baseline == 0.0) {
            return null;
        }

        return round((((float) $current) - $baseline) / $baseline * 100, 1);
    }

    /**
     * Get aggregated revenue per shop per product for chart visualization.
     */
    public function salesChartData(Request $request)
    {
        $actor = $request->user();
        $requestedShopId = $this->shopAccessService->authorizeRequestedShopId(
            $actor,
            $request->filled('shop_id') ? $request->integer('shop_id') : null
        );
        $shopIds = $requestedShopId !== null
            ? [$requestedShopId]
            : (! $this->shopAccessService->isGlobal($actor) ? $this->shopAccessService->ids($actor)->all() : null);

        if ($shopIds === []) {
            return response()->json([
                'success' => true,
                'data' => [
                    'from' => null,
                    'to' => null,
                    'products' => [],
                    'top_pairings' => [],
                    'total_revenue' => 0,
                    'total_quantity' => 0,
                ],
            ]);
        }

        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable'],
            'order_page_id' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
        ]);

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $start = Carbon::parse($request->query('date_from'))->startOfDay();
            $end = Carbon::parse($request->query('date_to'))->endOfDay();
            if ($end->lt($start)) {
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }
        } else {
            $latestOrderAt = Order::query()->max('created_at');
            if ($latestOrderAt === null) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'from' => null,
                        'to' => null,
                        'products' => [],
                        'top_pairings' => [],
                        'total_revenue' => 0,
                        'total_quantity' => 0,
                    ],
                ]);
            }
            $end = Carbon::parse($latestOrderAt)->endOfDay();
            $start = $end->copy()->subDays(30)->startOfDay();
        }

        $whereClauses = [
            'orders.deleted_at IS NULL',
            'orders.pancake_full_data IS NOT NULL',
            'orders.created_at BETWEEN ? AND ?',
        ];
        $bindings = [$start->toDateTimeString(), $end->toDateTimeString()];

        if ($shopIds !== null) {
            $placeholders = implode(',', array_fill(0, count($shopIds), '?'));
            $whereClauses[] = "orders.shop_id IN ({$placeholders})";
            $bindings = array_merge($bindings, $shopIds);
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            if (is_array($status)) {
                $placeholders = implode(',', array_fill(0, count($status), '?'));
                $whereClauses[] = "orders.status IN ({$placeholders})";
                $bindings = array_merge($bindings, $status);
            } else {
                $whereClauses[] = 'orders.status = ?';
                $bindings[] = (int) $status;
            }
        } else {
            $whereClauses[] = 'orders.status NOT IN (6, 7)';
        }

        if ($request->filled('order_page_id')) {
            $whereClauses[] = 'orders.pancake_order_page_id = ?';
            $bindings[] = $request->input('order_page_id');
        }

        if ($request->filled('search')) {
            $searchTerm = $request->input('search') . '%';
            $whereClauses[] = '(orders.order_number_vtp LIKE ? OR orders.customer_phone LIKE ? OR orders.customer_name LIKE ? OR orders.pancake_order_id = ?)';
            $bindings[] = $searchTerm;
            $bindings[] = $searchTerm;
            $bindings[] = $searchTerm;
            $bindings[] = $request->input('search');
        }

        $whereSql = implode(' AND ', $whereClauses);

        $sql = "SELECT 
            orders.shop_id, 
            shops.name as shop_name, 
            COALESCE(NULLIF(TRIM(item.product_name), ''), 'Chưa rõ sản phẩm') as product_name, 
            SUM(COALESCE(item.quantity, 0)) as quantity, 
            SUM(COALESCE(item.quantity, 0) * COALESCE(item.retail_price, 0)) as revenue, 
            COUNT(DISTINCT orders.id) as orders_count 
        FROM orders 
        LEFT JOIN shops ON shops.id = orders.shop_id 
        JOIN JSON_TABLE(orders.pancake_full_data, '$.items[*]' COLUMNS (
            product_name VARCHAR(255) PATH '$.variation_info.name', 
            quantity DECIMAL(12,2) PATH '$.quantity', 
            retail_price DECIMAL(14,2) PATH '$.variation_info.retail_price'
        )) AS item 
        WHERE {$whereSql}
        GROUP BY orders.shop_id, shops.name, product_name 
        HAVING revenue > 0 
        ORDER BY revenue DESC";

        $rows = \App\Services\OrderItemSql::select($sql, $bindings);

        $byProduct = [];
        $topPairings = [];
        $totalSystemRevenue = 0;
        $totalSystemQuantity = 0;

        foreach ($rows as $row) {
            $revenue = (float) $row->revenue;
            $quantity = (float) $row->quantity;
            $ordersCount = (int) $row->orders_count;
            $shopName = $row->shop_name ?? ('Cửa hàng #' . $row->shop_id);
            $pName = $row->product_name;

            $totalSystemRevenue += $revenue;
            $totalSystemQuantity += $quantity;

            if (!isset($byProduct[$pName])) {
                $byProduct[$pName] = [
                    'product_name' => $pName,
                    'total_revenue' => 0,
                    'total_quantity' => 0,
                    'total_orders' => 0,
                    'shops' => [],
                ];
            }

            $byProduct[$pName]['total_revenue'] += $revenue;
            $byProduct[$pName]['total_quantity'] += $quantity;
            $byProduct[$pName]['total_orders'] += $ordersCount;
            $byProduct[$pName]['shops'][] = [
                'shop_id' => $row->shop_id,
                'shop_name' => $shopName,
                'revenue' => round($revenue),
                'quantity' => floor($quantity) === $quantity ? (int) $quantity : round($quantity, 2),
                'orders_count' => $ordersCount,
            ];

            if (count($topPairings) < 15) {
                $topPairings[] = [
                    'shop_id' => $row->shop_id,
                    'shop_name' => $shopName,
                    'product_name' => $pName,
                    'revenue' => round($revenue),
                    'quantity' => floor($quantity) === $quantity ? (int) $quantity : round($quantity, 2),
                    'orders_count' => $ordersCount,
                ];
            }
        }

        $products = [];
        foreach ($byProduct as $product) {
            usort($product['shops'], fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
            $productRevenue = $product['total_revenue'];
            foreach ($product['shops'] as &$shop) {
                $shop['share_percent'] = $productRevenue > 0 ? round(($shop['revenue'] / $productRevenue) * 100, 1) : 0;
            }
            unset($shop);

            $topShop = $product['shops'][0] ?? null;

            $products[] = [
                'product_name' => $product['product_name'],
                'total_revenue' => round($productRevenue),
                'total_quantity' => floor($product['total_quantity']) === $product['total_quantity'] ? (int) $product['total_quantity'] : round($product['total_quantity'], 2),
                'total_orders' => $product['total_orders'],
                'top_shop' => $topShop,
                'shops' => $product['shops'],
            ];
        }

        usort($products, fn ($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);

        return response()->json([
            'success' => true,
            'data' => [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'total_revenue' => round($totalSystemRevenue),
                'total_quantity' => floor($totalSystemQuantity) === $totalSystemQuantity ? (int) $totalSystemQuantity : round($totalSystemQuantity, 2),
                'total_products' => count($products),
                'top_pairings' => $topPairings,
                'products' => $products,
            ],
        ]);
    }
}
