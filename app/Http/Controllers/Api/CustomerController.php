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
                    'data' => $orders->items(),
                    'page_number' => $orders->currentPage(),
                    'page_size' => $orders->perPage(),
                    'total_pages' => $orders->lastPage(),
                    'total_items' => $orders->total(),
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
}
