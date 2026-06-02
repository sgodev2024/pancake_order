<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PermissionCheckMiddleware;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class UserController extends Controller implements HasMiddleware
{
    /**
     * Khai báo middleware cho Controller
     */
    public static function middleware(): array
    {
        return [
            // Khai báo lần lượt từng middleware và chỉ định áp dụng cho method 'store'
            new Middleware(PermissionCheckMiddleware::class . ':list-staff', only: ['index', 'show']),
            new Middleware(PermissionCheckMiddleware::class . ':create-staff', only: ['store']),
            new Middleware(PermissionCheckMiddleware::class . ':update-staff', only: ['update']),
            new Middleware(PermissionCheckMiddleware::class . ':delete-staff', only: ['destroy']),
        ];
    }

    /**
     * Lấy danh sách tất cả users (Index)
     * GET /api/users
     */
    public function index(Request $request)
    {
        try {
            $inputs = $request->only("role_id", "date", "page", "search", "is_all", "page_name", "shop_id");
            $user = auth()->user();
            // Sử dụng paginate để phân trang thay vì get() tất cả nếu dữ liệu lớn
            $queries = User::with([
                            "shops" => function ($q) use ($inputs) {
                                if (isset($inputs["shop_id"])) {
                                    $q->where("shops.id", $inputs["shop_id"]);
                                }
                                $q->select("shops.id", "shops.name");
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
                $queries->withCount(['orders' => function ($q) use ($inputs) {
                            if (isset($inputs["date"])) {
                                $q->whereBetween("orders.created_at", [
                                    $inputs["date"] . " 00:00:00",
                                    $inputs["date"] . " 23:59:59"
                                ]);
                            }
                        }])
                        ->withSum(['orders' => function ($q) use ($inputs) {
                            if (isset($inputs["date"])) {
                                $q->whereBetween("orders.created_at", [
                                    $inputs["date"] . " 00:00:00",
                                    $inputs["date"] . " 23:59:59"
                                ]);
                            }
                        }], 'cod');
            }    
            $queries->where(function ($q) use ($inputs, $user) {
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
                if (!is_admin()) {
                    $q->whereHas("shops", function ($query) use ($user){
                        $query->whereIn("shops.id", $user->shops()->pluck('shops.id'));
                    });
                }
                if (isset($inputs["shop_id"])) {
                    $q->whereHas("shops", function ($query) use ($inputs) {
                        $query->where("shops.id", $inputs["shop_id"]);
                    });
                }
            })
            ->latest();
            $userIds = (clone $queries)->pluck('users.pancake_user_id');
            $total_cod = Order::whereIn('user_creator_id', $userIds)
                                ->when(isset($inputs["date"]), function ($q) use ($inputs) {
                                    $q->whereBetween("created_at", [
                                        $inputs["date"] . " 00:00:00",
                                        $inputs["date"] . " 23:59:59"
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
        try {
            $inputs = $request->only("page");
            $orders = Order::where("user_creator_id", $pancake_user_id)
                           ->select(
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
            'phone_number' => 'required|string|min:10',
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
        $user = User::create($validatedData);
        // Hàm sync() sẽ tự động:
        // 1. Thêm những ID mới
        // 2. Xóa những ID cũ không có trong mảng gửi lên
        // 3. Giữ lại những ID đang có
        $shopIds = $validatedData['shop_ids'];
        if (!empty($shopIds)) {
            $user->shops()->attach($shopIds);
        }
        // 4. Trả về response (HTTP 201 Created)
        return response()->json([
            'success' => true,
            'message' => 'Tạo user thành công',
            'data' => $user
        ], 201);
    }

    /**
     * Lấy thông tin 1 user cụ thể (Show - bổ sung cho đủ bộ RESTful)
     * GET /api/users/{user}
     */
    public function show(User $user)
    {
        return response()->json([
            'success' => true,
            'data' => $user
        ], 200);
    }

    public function updateProfile(Request $request)
    {
        try {
            $user = auth()->user();
            $validator = $this->validateData($request, $user->id);
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dữ liệu không hợp lệ',
                    'errors'  => $validator->errors()
                ], 422);
            }

            return $this->updateUser($validator, $user);
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
        try {
            $inputs = $request->only("shop_id");
            $queries = User::query();
            $user = auth()->user();
            
            if (isset($inputs["shop_id"])) {
                $queries->whereHas("shops", function ($query) use ($inputs) {
                    $query->where("shops.id", $inputs["shop_id"]);
                });
            } else {
                if (!is_admin()) {
                    $queries->whereHas("shops", function ($query) use ($user) {
                        $query->whereIn("shops.id", $user->shops()->pluck('shops.id'));
                    });
                }
            }
            return response()->json([
                "success" => true,
                "data"    => $queries->select("id", "name", "pancake_user_id")->latest()->get()
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => $th->getMessage()
            ]);
        }
    }

    public function getMornitoring()
    {
        try {
            $user     = auth()->user();
            $is_admin = is_admin();
            $shop_ids = $user->shops()->pluck('shops.id');

            // Subquery 1: Thống kê orders
            $orderStats = DB::table('orders')
                ->select([
                    'user_creator_id',
                    DB::raw('COUNT(id) as total_orders'),
                    DB::raw('COALESCE(SUM(cod), 0) as total_revenue'),
                ])
                ->when(!$is_admin, fn($q) => $q->whereIn('shop_id', $shop_ids))
                ->groupBy('user_creator_id');

            // Subquery 2: Thống kê customer_cares
            $careStats = DB::table('customer_cares')
                ->select([
                    'user_creator_id',
                    DB::raw('
                        ROUND(
                            100.0 * SUM(CASE WHEN date_care = CURDATE() AND status = 1 THEN 1 ELSE 0 END)
                            / NULLIF(SUM(CASE WHEN date_care = CURDATE() THEN 1 ELSE 0 END), 0),
                        2) as care_progress_today
                    '),
                    DB::raw('
                        SUM(CASE WHEN date_care < CURDATE() AND status = 0 THEN 1 ELSE 0 END) 
                        as overdue_care_count
                    '),
                ])
                ->when(!$is_admin, fn($q) => $q->whereIn('shop_id', $shop_ids))
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
