<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PermissionCheckMiddleware;
use App\Http\Middleware\AdminOnlyMiddleware;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use App\Models\User;
use App\Services\ShopAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class UserController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ShopAccessService $shopAccessService)
    {
    }

    /**
     * Khai báo middleware cho Controller
     */
    public static function middleware(): array
    {
        return [
            // Khai báo lần lượt từng middleware và chỉ định áp dụng cho method 'store'
            new Middleware(AdminOnlyMiddleware::class . ':report-query', only: ['index']),
            new Middleware(AdminOnlyMiddleware::class, only: ['store', 'update', 'destroy']),
            new Middleware(PermissionCheckMiddleware::class . ':list-staff', only: ['index']),
            new Middleware(PermissionCheckMiddleware::class . ':list-staff,strict', only: ['show']),
        ];
    }

    /**
     * Lấy danh sách tất cả users (Index)
     * GET /api/users
     */
    public function index(Request $request)
    {
        $actor = $request->user();
        $requestedShopId = $this->shopAccessService->authorizeRequestedShopId(
            $actor,
            $request->filled('shop_id') ? $request->integer('shop_id') : null
        );
        $effectiveShopIds = $requestedShopId !== null
            ? collect([$requestedShopId])
            : (! $this->shopAccessService->isGlobal($actor)
                ? $this->shopAccessService->ids($actor)
                : null);

        try {
            $inputs = $request->only("role_id", "date", "date_from", "date_to", "page", "search", "is_all", "page_name", "shop_id");
            $inputs['shop_id'] = $requestedShopId;
            // Sử dụng paginate để phân trang thay vì get() tất cả nếu dữ liệu lớn
            $queries = User::with([
                            "shops" => function ($q) use ($inputs) {
                                if (isset($inputs["shop_id"])) {
                                    $q->where("shops.id", $inputs["shop_id"]);
                                }
                                $q->select("shops.id", "shops.name");
                                $q->with(['managers:id,name']);
                                if (($inputs["page_name"] ?? null) == "report_page") {
                                    $q->with([
                                        "users" => function ($query) {
                                            $query->select("users.id", "users.role_id", "users.name")
                                                  ->where("users.role_id", 2);
                                        }
                                    ]);
                                }
                            },
                            "role" => function ($query) {
                                $query->select("name", "id");
                            }
                        ])
                        ->select("id", "name", "phone_number", "email", "role_id", "pancake_user_id");
            if (($inputs["page_name"] ?? null) == "report_page") {
                $queries->withCount(['orders' => function ($q) use ($inputs, $effectiveShopIds) {
                            if ($effectiveShopIds !== null) {
                                $q->whereIn('orders.shop_id', $effectiveShopIds);
                            }
                            if (isset($inputs["date_from"]) || isset($inputs["date_to"]) || isset($inputs["date"])) {
                                $q->whereBetween("orders.created_at", [
                                    ($inputs["date_from"] ?? $inputs["date"] ?? '1970-01-01') . " 00:00:00",
                                    ($inputs["date_to"] ?? $inputs["date"] ?? now()->toDateString()) . " 23:59:59"
                                ]);
                            }
                        }])
                        ->withSum(['orders' => function ($q) use ($inputs, $effectiveShopIds) {
                            if ($effectiveShopIds !== null) {
                                $q->whereIn('orders.shop_id', $effectiveShopIds);
                            }
                            if (isset($inputs["date_from"]) || isset($inputs["date_to"]) || isset($inputs["date"])) {
                                $q->whereBetween("orders.created_at", [
                                    ($inputs["date_from"] ?? $inputs["date"] ?? '1970-01-01') . " 00:00:00",
                                    ($inputs["date_to"] ?? $inputs["date"] ?? now()->toDateString()) . " 23:59:59"
                                ]);
                            }
                        }], 'cod');
            }    
            $queries->where(function ($q) use ($inputs, $effectiveShopIds) {
                if (isset($inputs["role_id"])) {
                    $q->where("role_id", $inputs["role_id"]);
                }
                if (isset($inputs["search"])) {
                    $searchTerm = $inputs["search"] . "%";
                    $q->where(function ($query) use ($searchTerm) {
                        $query->where("email", "like", $searchTerm)
                        ->orWhere("name", "like", $searchTerm)
                        ->orWhere("phone_number", "like", $searchTerm);
                    });
                }
                if ($effectiveShopIds !== null) {
                    $q->whereHas("shops", function ($query) use ($effectiveShopIds) {
                        $query->whereIn("shops.id", $effectiveShopIds);
                    });
                }
            })
            ->latest();
            $userIds = (clone $queries)->pluck('users.pancake_user_id');
            $total_cod = Order::whereIn('user_creator_id', $userIds)
                                ->when($effectiveShopIds !== null, function ($q) use ($effectiveShopIds) {
                                    $q->whereIn('shop_id', $effectiveShopIds);
                                })
                                ->when(isset($inputs["date_from"]) || isset($inputs["date_to"]) || isset($inputs["date"]), function ($q) use ($inputs) {
                                    $q->whereBetween("created_at", [
                                        ($inputs["date_from"] ?? $inputs["date"] ?? '1970-01-01') . " 00:00:00",
                                        ($inputs["date_to"] ?? $inputs["date"] ?? now()->toDateString()) . " 23:59:59"
                                    ]);
                                })
                                ->sum('cod');
            if (!empty($inputs["is_all"])) {
                $users = $queries->get();
                $total_user = count($users);
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'total_cod' => $total_cod,
                        'items' => $users,
                        'current_page' => 1,
                        'per_page'     => $total_user,
                        'total_items'  => $total_user,
                        'total_pages'  => 1,
                    ]
                ], 200);
            }
            // Ngược lại phân trang
            $users = $queries->paginate(
                30,
                ['*'],
                'page',
                $inputs["page"] ?? 1
            );
            return response()->json([
                'success' => true,
                'data' => [
                    'total_cod' => $total_cod,
                    'items' => $users->items(),
                    'current_page' => $users->currentPage(),
                    'per_page'     => $users->perPage(),
                    'total_items'  => $users->total(),
                    'total_pages'  => $users->lastPage(),
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => true,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getOrder(Request $request, $pancake_user_id)
    {
        $actor = $request->user();
        $target = User::query()
            ->where('pancake_user_id', $pancake_user_id)
            ->firstOrFail();

        if (! $this->shopAccessService->canAccessUser($actor, $target)) {
            throw new AuthorizationException('You do not have access to this user.');
        }

        try {
            $inputs = $request->only("page");
            $ordersQuery = Order::query()
                           ->where("user_creator_id", $target->pancake_user_id);

            if (! $this->shopAccessService->isGlobal($actor)) {
                $ordersQuery->whereIn(
                    'shop_id',
                    $this->shopAccessService->sharedIds($actor, $target)
                );
                $this->applyOrderRecordScope($ordersQuery, $actor);
            }

            $orders = $ordersQuery->select(
                                'id', 
                                'shop_id',
                                'order_number_vtp', 
                                'total_quantity',
                                'cod',
                                'cash',
                                'note',
                                'status',
                                'status_vtp',
                                'customer_name',
                                'customer_phone',
                                'customer_address',
                                'pancake_order_id',
                                'created_at',
                                'pancake_full_data',
                           )
                           ->paginate(50, ['*'], 'page', $inputs["page"] ?? 1);

            return response()->json([
                "success" => true,
                "data" => [
                    'items' => $orders->items(),
                    'current_page' => $orders->currentPage(),
                    'per_page'     => $orders->perPage(),
                    'total_items'  => $orders->total(),
                    'total_pages'  => $orders->lastPage(),
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => true,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Tạo mới một user (Create / Store)
     * POST /api/users
     */
    public function store(Request $request)
    {
        // 1. Validate dữ liệu đầu vào
        $validator = Validator::make($request->all(), [
            'name'         => 'required|string|max:255',
            'email'        => 'required|string|email|max:255|unique:users,email',
            'password'     => 'required|string|min:6',
            'phone_number' => ['required', 'string', 'regex:/^(0\d{9,10}|\+84\d{9,10})$/'],
            'role_id'      => 'required|exists:roles,id',
            'shop_ids'     => 'nullable|array',
            'shop_ids.*'   => 'exists:shops,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ',
                'errors'  => $validator->errors()
            ], 422);
        }
        $validatedData = $validator->validated();

        // 2. Mã hóa mật khẩu
        $validatedData['password'] = Hash::make($validatedData['password']);

        // 3. Tạo user mới
        $shopIds = $validatedData['shop_ids'] ?? [];
        unset($validatedData['shop_ids']);
        $user = User::create($validatedData);
        // Hàm sync() sẽ tự động:
        // 1. Thêm những ID mới
        // 2. Xóa những ID cũ không có trong mảng gửi lên
        // 3. Giữ lại những ID đang có
        if (!empty($shopIds)) {
            $user->shops()->sync($shopIds);
        }
        // 4. Trả về response (HTTP 201 Created)
        return response()->json([
            'success' => true,
            'message' => 'Tạo user thành công',
            'data' => $user->load(['shops:id,name', 'role:id,name'])
        ], 201);
    }

    /**
     * Lấy thông tin 1 user cụ thể (Show - bổ sung cho đủ bộ RESTful)
     * GET /api/users/{user}
     */
    public function show(Request $request, User $user)
    {
        if (! $this->shopAccessService->canAccessUser($request->user(), $user)) {
            throw new AuthorizationException('You do not have access to this user.');
        }

        return response()->json([
            'success' => true,
            'data' => $user->load(['shops:id,name', 'role:id,name'])
        ], 200);
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

    public function updateProfile(Request $request)
    {
        try {
            $user = $request->user();

            $blockedFields = [
                'role',
                'role_id',
                'shop_ids',
                'permissions',
                'permission_ids',
                'is_admin',
                'is_manager',
                'pancake_user_id',
                'fb_id',
                'is_first_login',
                'status',
                'api_key',
                'access_token',
                'wallet',
                'balance',
            ];

            if ($request->hasAny($blockedFields)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authorization fields cannot be changed through the profile endpoint.',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name'         => 'sometimes|string|max:255',
                'email'        => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
                'password'     => 'sometimes|string|min:6',
                'phone_number' => ['sometimes', 'string', 'regex:/^(0\d{9,10}|\+84\d{9,10})$/'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dữ liệu không hợp lệ',
                    'errors'  => $validator->errors()
                ], 422);
            }

            $validatedData = $validator->validated();
            if (isset($validatedData['password'])) {
                $validatedData['password'] = Hash::make($validatedData['password']);
            }

            $user->update($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật user thành công'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => $th->getMessage()
            ]);
        }
    }

    public function validateData($request, $user_id)
    {
        // 1. Validate dữ liệu đầu vào (cho phép null nếu chỉ update 1 vài field)
        $validator = Validator::make($request->all(), [
            'name'         => 'sometimes|string|max:255',
            'email'        => 'sometimes|string|email|max:255|unique:users,email,' . $user_id,
            'password'     => 'sometimes|string|min:6',
            'phone_number' => 'sometimes|string|min:10',
            'role_id'      => 'required|exists:roles,id',
            'shop_ids'     => 'nullable|array',
            'shop_ids.*'   => 'exists:shops,id',
        ]);

        return $validator;
    }

    /**
     * Cập nhật thông tin user (Update)
     * PUT/PATCH /api/users/{user}
     */
    public function update(Request $request, User $user)
    {
        // 1. Validate dữ liệu đầu vào (cho phép null nếu chỉ update 1 vài field)
        $validator = $this->validateData($request, $user->id);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ',
                'errors'  => $validator->errors()
            ], 422);
        }
        
        return $this->updateUser($validator, $user);
    }

    public function updateUser($validator, $user)
    {
        $validatedData = $validator->validated();
        // 2. Nếu có update mật khẩu thì cần mã hóa lại
        if (isset($validatedData['password'])) {
            $validatedData['password'] = Hash::make($validatedData['password']);
        }
        $shopIds = $validatedData['shop_ids'] ?? [];
        unset($validatedData['shop_ids']);
        $user->shops()->sync($shopIds);
        // 3. Cập nhật dữ liệu
        $user->update($validatedData);

        // 4. Trả về response
        return response()->json([
            'success' => true,
            'message' => 'Cập nhật user thành công'
        ], 200);
    }

    /**
     * Xóa một user (Delete / Destroy)
     * DELETE /api/users/{user}
     */
    public function destroy(User $user)
    {
        if ($user->email == "admin@gmail.com") {
            return response()->json([
                "success" => false,
                "message" => "Tài khoản này không được phép xóa"
            ]);
        }
        // Xóa user
        $user->delete();

        // Trả về response
        return response()->json([
            'success' => true,
            'message' => 'Đã xóa user thành công'
        ], 200); // Có thể dùng 204 No Content nếu không muốn trả về body
    }

    public function getAllUser(Request $request)
    {
        $user = $request->user();
        $requestedShopId = $this->shopAccessService->authorizeRequestedShopId(
            $user,
            $request->filled('shop_id') ? $request->integer('shop_id') : null
        );

        try {
            $inputs = $request->only("assignment_eligible");
            $queries = User::query();

            if (filter_var($inputs['assignment_eligible'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $queries->whereHas('role', function ($query) {
                    $query->whereIn('slug', ['manager-cskh', 'staff-cskh']);
                });
            }
            
            if ($requestedShopId !== null) {
                $queries->whereHas("shops", function ($query) use ($requestedShopId) {
                    $query->where("shops.id", $requestedShopId);
                });
            } elseif (! $this->shopAccessService->isGlobal($user)) {
                $shopIds = $this->shopAccessService->ids($user);
                $queries->whereHas("shops", function ($query) use ($shopIds) {
                    $query->whereIn("shops.id", $shopIds);
                });
            }
            return response()->json([
                "success" => true,
                "data"    => $queries->selectRaw("id, name, COALESCE(pancake_user_id, id) as pancake_user_id")
                                    ->latest()
                                    ->get()
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => $th->getMessage()
            ]);
        }
    }

    public function getMornitoring(Request $request)
    {
        $user = $request->user();
        $requestedShopId = $this->shopAccessService->authorizeRequestedShopId(
            $user,
            $request->filled('shop_id') ? $request->integer('shop_id') : null
        );
        $isGlobal = $this->shopAccessService->isGlobal($user);
        $shopIds = $requestedShopId !== null
            ? collect([$requestedShopId])
            : $this->shopAccessService->ids($user);
        $mustScopeByShop = $requestedShopId !== null || ! $isGlobal;

        try {
            $request->validate([
                'staff_id' => ['nullable', 'integer', 'exists:users,id'],
            ]);

            $today = now(config('app.timezone'))->toDateString();
            $assignmentStats = CustomerCareAssignment::query()
                ->join('customer_cares as monitored_cares', 'monitored_cares.id', '=', 'customer_care_assignments.customer_care_id')
                ->select('customer_care_assignments.assignee_user_id')
                ->selectRaw('SUM(CASE WHEN monitored_cares.date_care = ? THEN 1 ELSE 0 END) as today_total', [$today])
                ->selectRaw('SUM(CASE WHEN monitored_cares.date_care = ? AND monitored_cares.status = 1 THEN 1 ELSE 0 END) as today_done', [$today])
                ->selectRaw('SUM(CASE WHEN monitored_cares.date_care > ? THEN 1 ELSE 0 END) as upcoming_total', [$today])
                ->selectRaw('SUM(CASE WHEN monitored_cares.date_care > ? AND monitored_cares.status = 1 THEN 1 ELSE 0 END) as upcoming_done', [$today])
                ->selectRaw('SUM(CASE WHEN monitored_cares.date_care < ? THEN 1 ELSE 0 END) as overdue_total', [$today])
                ->selectRaw('SUM(CASE WHEN monitored_cares.date_care < ? AND monitored_cares.status = 1 THEN 1 ELSE 0 END) as overdue_done', [$today])
                ->whereIn('customer_care_assignments.source_type', [CustomerCareAssignment::SOURCE_ORDER, CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY])
                ->when($mustScopeByShop, fn ($query) => $query->whereIn('customer_care_assignments.shop_id', $shopIds))
                ->groupBy('customer_care_assignments.assignee_user_id');

            $staffs = User::query()
                ->select('users.id', 'users.name')
                ->selectRaw('COALESCE(stats.today_done, 0) as today_done')
                ->selectRaw('COALESCE(stats.today_total, 0) as today_total')
                ->selectRaw('COALESCE(stats.upcoming_done, 0) as upcoming_done')
                ->selectRaw('COALESCE(stats.upcoming_total, 0) as upcoming_total')
                ->selectRaw('COALESCE(stats.overdue_done, 0) as overdue_done')
                ->selectRaw('COALESCE(stats.overdue_total, 0) as overdue_total')
                ->leftJoinSub($assignmentStats, 'stats', 'stats.assignee_user_id', '=', 'users.id')
                ->whereHas('role', fn ($role) => $role->whereIn('slug', ['manager-cskh', 'staff-cskh']))
                ->when($mustScopeByShop, fn ($query) => $query->whereHas('shops', fn ($shop) => $shop->whereIn('shops.id', $shopIds)))
                ->when($request->filled('staff_id'), fn ($query) => $query->whereKey($request->integer('staff_id')))
                ->orderBy('users.name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'items' => $staffs,
                    'total_appointments' => $staffs->sum(fn ($staff) => (int) $staff->today_total + (int) $staff->upcoming_total + (int) $staff->overdue_total),
                ],
            ]);

            // Subquery 1: Thống kê orders
            $orderStats = DB::table('orders')
                ->select([
                    'user_creator_id',
                    DB::raw('COUNT(id) as total_orders'),
                    DB::raw('COALESCE(SUM(cod), 0) as total_revenue'),
                ])
                ->when($mustScopeByShop, fn($q) => $q->whereIn('shop_id', $shopIds))
                ->groupBy('user_creator_id');

            // Subquery 2: Thống kê customer_cares
            $careStats = CustomerCare::query()
                ->actionable()
                ->select([
                    'user_creator_id',
                    DB::raw('
                        ROUND(
                            100.0 * SUM(CASE WHEN date_care = CURRENT_DATE AND status = 1 THEN 1 ELSE 0 END)
                            / NULLIF(SUM(CASE WHEN date_care = CURRENT_DATE THEN 1 ELSE 0 END), 0),
                        2) as care_progress_today
                    '),
                    DB::raw('
                        SUM(CASE WHEN date_care < CURRENT_DATE AND status = 0 THEN 1 ELSE 0 END)
                        as overdue_care_count
                    '),
                ])
                ->when($mustScopeByShop, fn($q) => $q->whereIn('shop_id', $shopIds))
                ->groupBy('user_creator_id');

            // Query chính: JOIN với subquery đã aggregate sẵn
            $staffs = User::query()
                ->select([
                    'users.pancake_user_id',
                    'users.name',
                    DB::raw('COALESCE(o.total_orders, 0) as total_orders'),
                    DB::raw('COALESCE(o.total_revenue, 0) as total_revenue'),
                    DB::raw('COALESCE(c.care_progress_today, 0) as care_progress_today'),
                    DB::raw('COALESCE(c.overdue_care_count, 0) as overdue_care_count'),
                ])
                ->leftJoinSub($orderStats, 'o', 'o.user_creator_id', '=', 'users.pancake_user_id')
                ->leftJoinSub($careStats, 'c', 'c.user_creator_id', '=', 'users.pancake_user_id')
                ->whereNotNull('users.pancake_user_id')
                ->when($mustScopeByShop, function ($query) use ($shopIds) {
                    $query->whereHas('shops', function ($shopQuery) use ($shopIds) {
                        $shopQuery->whereIn('shops.id', $shopIds);
                    });
                })
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $staffs->map(fn($s) => [
                    'id'                  => $s->pancake_user_id,
                    'name'                => $s->name,
                    'total_orders'        => $s->total_orders,
                    'total_revenue'       => $s->total_revenue,
                    'care_progress_today' => $s->care_progress_today,
                    'overdue_care_count'  => $s->overdue_care_count,
                ])->values()
            ]);
            // $datas = [];
            // $user = auth()->user();
            // $is_admin = is_admin();
            // $shop_ids = $user->shops()->pluck('shops.id');
            // $staffs = User::query()
            //             ->select([
            //                 'users.pancake_user_id',
            //                 'users.name',
            //             ])
            //             ->selectRaw('
            //                 COUNT(DISTINCT orders.id) as total_orders,
            //                 COALESCE(SUM(orders.cod), 0) as total_revenue,
                            
            //                 ROUND(
            //                     100.0 * COUNT(DISTINCT CASE 
            //                         WHEN customer_cares.date_care = CURDATE() 
            //                         AND customer_cares.status = 1 
            //                         THEN customer_cares.id 
            //                     END)
            //                     / NULLIF(COUNT(DISTINCT CASE 
            //                         WHEN customer_cares.date_care = CURDATE() 
            //                         THEN customer_cares.id 
            //                     END), 0),
            //                 2) as care_progress_today,

            //                 COUNT(DISTINCT CASE 
            //                     WHEN customer_cares.date_care < CURDATE() 
            //                     AND customer_cares.status = 0 
            //                     THEN customer_cares.id 
            //                 END) as overdue_care_count
            //             ')
            //             ->leftJoin('orders', function ($join) use ($is_admin, $shop_ids) {
            //                 $join->on('orders.user_creator_id', '=', 'users.pancake_user_id');
            //                 if (!$is_admin) {
            //                     $join->whereIn('orders.shop_id', $shop_ids);
            //                 }
            //             })
            //             ->leftJoin('customer_cares', function ($join) use ($is_admin, $shop_ids) {
            //                 $join->on('customer_cares.user_creator_id', '=', 'users.pancake_user_id');
            //                 if (!$is_admin) {
            //                     $join->whereIn('customer_cares.shop_id', $shop_ids);
            //                 }
            //             })
            //             ->whereNotNull('users.pancake_user_id')
            //             ->groupBy('users.pancake_user_id', 'users.name')
            //             ->get();
            // foreach ($staffs as $staff_item) {
            //     $datas[] = [
            //         "name"                => $staff_item->name,
            //         "id"                  => $staff_item->pancake_user_id,
            //         "total_orders"        => $staff_item->total_orders,
            //         "total_revenue"       => $staff_item->total_revenue,
            //         "care_progress_today" => $staff_item->care_progress_today,
            //         "overdue_care_count"  => $staff_item->overdue_care_count
            //     ];
            // }

            // return response()->json([
            //     "success" => true,
            //     "data"    => $datas
            // ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => true,
                "data"    => []
            ]);
        }
    }
}
