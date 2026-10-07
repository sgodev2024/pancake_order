<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PermissionCheckMiddleware;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerJourneyFilterService;
use App\Services\CustomerJourneyService;
use App\Services\CustomerReadAccessService;
use App\Services\ShopAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CustomerController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly ShopAccessService $shopAccessService,
        private readonly CustomerReadAccessService $customerReadAccessService,
        private readonly CustomerJourneyFilterService $customerJourneyFilterService
    ) {}

    /**
     * Khai báo middleware cho Controller
     */
    public static function middleware(): array
    {
        return [
            // Khai báo lần lượt từng middleware và chỉ định áp dụng cho method 'store'
            new Middleware(PermissionCheckMiddleware::class . ':list-customer', except: ['getOrder']),
            new Middleware(PermissionCheckMiddleware::class . ':list-customer,strict', only: ['getOrder']),
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $journeyFilters = $request->validate([
            'journey_action' => ['nullable', 'string', 'in:'.implode(',', CustomerJourneyService::ACTIONS)],
            'journey_date_from' => ['nullable', 'date_format:Y-m-d'],
            'journey_date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:journey_date_from'],
            'care_count_min' => ['nullable', 'integer', 'min:0'],
            'care_count_max' => ['nullable', 'integer', 'min:0', 'gte:care_count_min'],
            'has_order' => ['nullable', 'string', 'in:all,yes,no'],
            'care_user_id' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
        ]);
        $requestedShopId = $this->shopAccessService->authorizeRequestedShopId(
            $user,
            $request->filled('shop_id') ? $request->integer('shop_id') : null
        );
        $this->authorizeCareUserFilter($user, $requestedShopId, $journeyFilters);

        try {
            $inputs = $request->only(
                "page",
                "page_size",
                "shop_id",
                "date_from",
                "date_to",
                "search",
                "loyalty_tier_id"
            );
            // 1. Khởi tạo query từ relationship
            $query = Customer::query();
            if ($requestedShopId !== null) {
                $query->where("shop_id", $requestedShopId);
            } elseif (! $this->shopAccessService->isGlobal($user)) {
                $query->whereIn("shop_id", $this->shopAccessService->ids($user));
            }
            if (isset($inputs["loyalty_tier_id"])) {
                $query->where("loyalty_tier_id", $inputs["loyalty_tier_id"]);
            }
            $query->with([
                "shop" => function ($q) {
                    $q->select("shops.id", "shops.name");
                },
                "loyalty_tier"
            ]);
            // 2. Select các trường cụ thể cần lấy để tối ưu performance
            // (Thêm tiền tố tên bảng 'customers.id' để tránh lỗi trùng lặp cột nếu sau này có join bảng)
            $query->select([
                'id',
                'purchased_amount',
                'shop_id',
                'name', 
                'phone_numbers',
                'pancake_customer_id',
                'pancake_full_data',
                'created_at',
                'loyalty_tier_id',
                'order_count'
            ]);
            // $query->withCount("orders");

            // 3. Xử lý các điều kiện lọc (Filters)
            // Lọc theo từ khóa tìm kiếm (Tên hoặc Số điện thoại)
            $query->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($sub) use ($search) {
                    $sub->where(function ($q_sub) use ($search) {
                        $q_sub->where('name', 'like', "{$search}%")
                              ->orWhere('phone_numbers', 'like', "{$search}%");
                    });
                });
            });
            if (isset($inputs["date_from"])) {
                $query->where("created_at", ">=", $inputs["date_from"] . " 00:00:00");
            }
            if (isset($inputs["date_to"])) {
                $query->where("created_at", "<=", $inputs["date_to"] . " 23:59:59");
            }
            if (! $this->shopAccessService->isGlobal($user)) {
                $this->customerReadAccessService->applyRecordScope($query, $user, 'assigned_user_id');
            }
            $this->customerJourneyFilterService->apply($query, $journeyFilters);
            $pageNumber = $inputs["page"];
            $page_size  = $inputs["page_size"] ?? 30;
            // 4. Sắp xếp và Phân trang (Lấy 30 records mỗi trang)
            $customers = $query->latest()->paginate($page_size, ['*'], 'page', $pageNumber);

            // 5. Trả về Response
            return response()->json([
                'success' => true,
                'data'    => [
                    "customers" => $customers->items(),
                    'current_page' => $customers->currentPage(),
                    'per_page' => $customers->perPage(),
                    'total_items' => $customers->total(),
                    'total_pages' => $customers->lastPage(),
                ],
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Đã có lỗi xảy ra: ' . $th->getMessage()
            ], 500);
        }
    }

    /**
     * Customer purchase recency used only by the Customers tab inside Orders.
     * Keep this contract separate from the existing customer list so its
     * filters and response shape remain backward compatible.
     */
    public function orderInsights(Request $request)
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'shop_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:255'],
            'inactivity_group' => ['nullable', 'string', 'in:all,0_7,8_14,15_30,0_30,31_60,61_90,over_90,never'],
            'sort_by' => ['nullable', 'string', 'in:last_purchase_at,inactive_days,total_orders,total_value'],
            'sort_direction' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $user = $request->user();
        $requestedShopId = $this->shopAccessService->authorizeRequestedShopId(
            $user,
            $request->filled('shop_id') ? $request->integer('shop_id') : null
        );

        try {
            $query = Customer::query();

            if ($requestedShopId !== null) {
                $query->where('customers.shop_id', $requestedShopId);
            } elseif (! $this->shopAccessService->isGlobal($user)) {
                $query->whereIn('customers.shop_id', $this->shopAccessService->ids($user));
            }

            if (! $this->shopAccessService->isGlobal($user)) {
                $this->customerReadAccessService->applyRecordScope($query, $user, 'customers.assigned_user_id');
            }

            if ($request->filled('search')) {
                $search = trim((string) $validated['search']);
                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery
                        ->where('customers.name', 'like', "{$search}%")
                        ->orWhere('customers.phone_numbers', 'like', "{$search}%")
                        ->orWhere('customers.pancake_customer_id', 'like', "{$search}%")
                        ->orWhereRaw(
                            "CAST(JSON_EXTRACT(customers.pancake_full_data, '$.emails') AS CHAR) LIKE ?",
                            ["%{$search}%"]
                        );
                });
            }

            $overviewRow = (clone $query)
                ->selectRaw('COUNT(customers.id) AS total_customers')
                ->selectRaw('COALESCE(SUM(CASE WHEN customers.last_order_at IS NOT NULL AND TIMESTAMPDIFF(DAY, customers.last_order_at, CURRENT_DATE) BETWEEN 0 AND 7 THEN 1 ELSE 0 END), 0) AS days_0_7')
                ->selectRaw('COALESCE(SUM(CASE WHEN TIMESTAMPDIFF(DAY, customers.last_order_at, CURRENT_DATE) BETWEEN 8 AND 14 THEN 1 ELSE 0 END), 0) AS days_8_14')
                ->selectRaw('COALESCE(SUM(CASE WHEN TIMESTAMPDIFF(DAY, customers.last_order_at, CURRENT_DATE) BETWEEN 15 AND 30 THEN 1 ELSE 0 END), 0) AS days_15_30')
                ->selectRaw('COALESCE(SUM(CASE WHEN customers.last_order_at IS NOT NULL AND TIMESTAMPDIFF(DAY, customers.last_order_at, CURRENT_DATE) BETWEEN 0 AND 30 THEN 1 ELSE 0 END), 0) AS days_0_30')
                ->selectRaw('COALESCE(SUM(CASE WHEN TIMESTAMPDIFF(DAY, customers.last_order_at, CURRENT_DATE) BETWEEN 31 AND 60 THEN 1 ELSE 0 END), 0) AS days_31_60')
                ->selectRaw('COALESCE(SUM(CASE WHEN TIMESTAMPDIFF(DAY, customers.last_order_at, CURRENT_DATE) BETWEEN 61 AND 90 THEN 1 ELSE 0 END), 0) AS days_61_90')
                ->selectRaw('COALESCE(SUM(CASE WHEN TIMESTAMPDIFF(DAY, customers.last_order_at, CURRENT_DATE) > 90 THEN 1 ELSE 0 END), 0) AS over_90')
                ->selectRaw('COALESCE(SUM(CASE WHEN customers.last_order_at IS NULL THEN 1 ELSE 0 END), 0) AS never_purchased')
                ->first();

            $overview = [
                'total_customers' => (int) $overviewRow->total_customers,
                'days_0_7' => (int) $overviewRow->days_0_7,
                'days_8_14' => (int) $overviewRow->days_8_14,
                'days_15_30' => (int) $overviewRow->days_15_30,
                'days_0_30' => (int) $overviewRow->days_0_30,
                'days_31_60' => (int) $overviewRow->days_31_60,
                'days_61_90' => (int) $overviewRow->days_61_90,
                'over_90' => (int) $overviewRow->over_90,
                'never_purchased' => (int) $overviewRow->never_purchased,
            ];
            $group = $validated['inactivity_group'] ?? 'all';
            match ($group) {
                '0_7' => $query->whereRaw('TIMESTAMPDIFF(DAY, customers.last_order_at, CURRENT_DATE) BETWEEN 0 AND 7'),
                '8_14' => $query->whereRaw('TIMESTAMPDIFF(DAY, customers.last_order_at, CURRENT_DATE) BETWEEN 8 AND 14'),
                '15_30' => $query->whereRaw('TIMESTAMPDIFF(DAY, customers.last_order_at, CURRENT_DATE) BETWEEN 15 AND 30'),
                '0_30' => $query->whereRaw('TIMESTAMPDIFF(DAY, customers.last_order_at, CURRENT_DATE) BETWEEN 0 AND 30'),
                '31_60' => $query->whereRaw('TIMESTAMPDIFF(DAY, customers.last_order_at, CURRENT_DATE) BETWEEN 31 AND 60'),
                '61_90' => $query->whereRaw('TIMESTAMPDIFF(DAY, customers.last_order_at, CURRENT_DATE) BETWEEN 61 AND 90'),
                'over_90' => $query->whereRaw('TIMESTAMPDIFF(DAY, customers.last_order_at, CURRENT_DATE) > 90'),
                'never' => $query->whereNull('customers.last_order_at'),
                default => null,
            };

            $sortBy = $validated['sort_by'] ?? 'inactive_days';
            $sortDirection = $validated['sort_direction'] ?? 'desc';
            $pageSize = (int) ($validated['page_size'] ?? 30);
            $pageNumber = (int) ($validated['page'] ?? 1);
            $totalItems = match ($group) {
                '0_7' => $overview['days_0_7'],
                '8_14' => $overview['days_8_14'],
                '15_30' => $overview['days_15_30'],
                '0_30' => $overview['days_0_30'],
                '31_60' => $overview['days_31_60'],
                '61_90' => $overview['days_61_90'],
                'over_90' => $overview['over_90'],
                'never' => $overview['never_purchased'],
                default => $overview['total_customers'],
            };

            $query
                ->leftJoin('shops', 'shops.id', '=', 'customers.shop_id')
                ->leftJoin('loyalty_tiers', 'loyalty_tiers.id', '=', 'customers.loyalty_tier_id')
                ->select([
                    'customers.id',
                    'customers.shop_id',
                    'customers.pancake_customer_id',
                    'customers.name',
                    'customers.phone_numbers',
                    'customers.purchased_amount',
                    'customers.order_count',
                    'customers.loyalty_tier_id',
                    'customers.created_at',
                    'shops.name AS shop_name',
                    'loyalty_tiers.name AS loyalty_tier_name',
                    'loyalty_tiers.discount_percent AS loyalty_discount_percent',
                ])
                ->selectRaw('COALESCE(customers.order_count, 0) AS total_orders')
                ->selectRaw('customers.last_order_at AS last_purchase_at')
                ->selectRaw('CASE WHEN customers.last_order_at IS NULL THEN NULL ELSE GREATEST(0, TIMESTAMPDIFF(DAY, customers.last_order_at, CURRENT_DATE)) END AS inactive_days')
                ->selectRaw('COALESCE(customers.purchased_amount, 0) AS total_value');

            if ($sortBy === 'inactive_days') {
                $query->orderByRaw('customers.last_order_at IS NULL ASC')
                    ->orderBy('customers.last_order_at', $sortDirection === 'desc' ? 'asc' : 'desc');
            } elseif ($sortBy === 'last_purchase_at') {
                $query->orderByRaw('customers.last_order_at IS NULL ASC')
                    ->orderBy('customers.last_order_at', $sortDirection);
            } elseif ($sortBy === 'total_orders') {
                $query->orderBy('customers.order_count', $sortDirection);
            } else {
                $query->orderBy('customers.purchased_amount', $sortDirection);
            }

            $rows = $query
                ->orderByDesc('customers.id')
                ->forPage($pageNumber, $pageSize)
                ->get();

            // Keep the window/sort query narrow. The original customer JSON is
            // large, so load it only for the current page after pagination.
            $profiles = Customer::query()
                ->whereIn('id', $rows->pluck('id')->map(fn ($id) => (int) $id))
                ->get(['id', 'pancake_full_data'])
                ->keyBy('id');

            $customers = $rows->map(function ($row) use ($profiles) {
                $payload = $profiles->get((int) $row->id)?->pancake_full_data;
                $customer = $row->toArray();
                foreach (array_keys($customer) as $key) {
                    if (str_starts_with($key, 'overview_') || $key === 'filtered_total') {
                        unset($customer[$key]);
                    }
                }
                $customer['pancake_full_data'] = is_array($payload) ? $payload : [];
                $customer['shop'] = $row->shop_id === null ? null : [
                    'id' => (int) $row->shop_id,
                    'name' => $row->shop_name,
                ];
                $customer['loyalty_tier'] = $row->loyalty_tier_id === null ? null : [
                    'id' => (int) $row->loyalty_tier_id,
                    'name' => $row->loyalty_tier_name,
                    'discount_percent' => $row->loyalty_discount_percent,
                ];
                unset(
                    $customer['shop_name'],
                    $customer['loyalty_tier_name'],
                    $customer['loyalty_discount_percent']
                );

                return $customer;
            })->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'customers' => $customers,
                    'overview' => $overview,
                    'current_page' => $pageNumber,
                    'per_page' => $pageSize,
                    'total_items' => $totalItems,
                    'total_pages' => max(1, (int) ceil($totalItems / $pageSize)),
                ],
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Đã có lỗi xảy ra: '.$th->getMessage(),
            ], 500);
        }
    }

    /** @param array<string, mixed> $filters */
    private function authorizeCareUserFilter(User $actor, ?int $requestedShopId, array $filters): void
    {
        if (empty($filters['care_user_id'])) {
            return;
        }

        $careUser = User::query()->findOrFail((int) $filters['care_user_id']);
        $allowed = $requestedShopId !== null
            ? $careUser->shops()->whereKey($requestedShopId)->exists()
            : ($this->shopAccessService->isGlobal($actor)
                || $this->shopAccessService->canAccessUser($actor, $careUser));

        if (! $allowed) {
            throw new AuthorizationException('You do not have access to the requested care employee.');
        }
    }

    public function getOrder(Request $request, $pancake_customer_id)
    {
        try {
            $actor = $request->user();
            $pageNumber = $request->integer('page_number', 1);
            $pageSize = 10;
            $requestedShopId = $this->shopAccessService->authorizeRequestedShopId(
                $actor,
                $request->filled('shop_id') ? $request->integer('shop_id') : null
            );

            $ordersQuery = Order::query()
                ->where('pancake_customer_id', $pancake_customer_id)
                ->with([
                    'shop:id,name',
                    'user_creator',
                    'user_care',
                    'user_assigning',
                ])
                ->select([
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
                    'user_assigning_seller_id',
                ]);

            if ($requestedShopId !== null) {
                $ordersQuery->where('shop_id', $requestedShopId);
            }
            if (! $this->shopAccessService->isGlobal($actor)) {
                $ordersQuery->whereIn('shop_id', $this->shopAccessService->ids($actor));
                $this->applyOrderRecordScope($ordersQuery, $actor);
            }

            $orders = $ordersQuery->latest('created_at')
                ->paginate($pageSize, ['*'], 'page_number', $pageNumber);

            // Keep the legacy wrapper returned by the Pancake proxy while using
            // a locally constrained query as the authorization boundary.
            return response()->json([
                'success' => true,
                'data' => [
                    // Preserve the current local-order payload and add the fields
                    // read by the deployed V1 customer-order modal. The old modal
                    // renders product rows from `items`, the creator from
                    // `creator.name`, and the purchase date from `inserted_at`.
                    'data' => collect($orders->items())
                        ->map(fn (Order $order) => $this->legacyOrderDetailData($order))
                        ->values()
                        ->all(),
                    'page_number' => $orders->currentPage(),
                    'page_size' => $orders->perPage(),
                    'total_pages' => $orders->lastPage(),
                    'total_items' => $orders->total(),
                    'total_entries' => $orders->total(),
                ],
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Đã có lỗi xảy ra: ' . $th->getMessage()
            ], 500);
        }
    }

    private function applyOrderRecordScope($query, User $actor): void
    {
        if ($actor->isAdmin() || $actor->isManagerSale() || $actor->isManagerCskh()) {
            return;
        }

        $query->where(function ($scope) use ($actor) {
            $scope->where('user_creator_id', $actor->pancake_user_id)
                ->orWhere('user_care_id', $actor->pancake_user_id);
        });
    }

    /**
     * Keep the response fields expected by the legacy V1 customer order modal
     * without removing fields consumed by newer clients.
     *
     * @return array<string, mixed>
     */
    private function legacyOrderDetailData(Order $order): array
    {
        $payload = is_array($order->pancake_full_data) ? $order->pancake_full_data : [];
        $payloadCreator = $payload['creator'] ?? null;
        $localCreator = $order->user_creator;

        if (is_array($payloadCreator)) {
            $creator = $payloadCreator;

            if (empty($creator['name']) && $localCreator !== null) {
                $creator['name'] = $localCreator->name;
            }
        } elseif ($localCreator !== null) {
            $creator = [
                'id' => $localCreator->pancake_user_id,
                'name' => $localCreator->name,
            ];
        } else {
            $creator = null;
        }

        return array_merge($order->toArray(), [
            // V1 renders every Pancake item, including multi-product orders.
            'items' => is_array($payload['items'] ?? null) ? $payload['items'] : [],
            // Preserve Pancake's original purchase timestamp when available.
            'inserted_at' => $payload['inserted_at'] ?? null,
            // Preserve the source creator and fill a missing name from the
            // already eager-loaded local mapping only.
            'creator' => $creator,
        ]);
    }
}
