<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PermissionCheckMiddleware;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;


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
        $inputs = $request->only("role_id");
        // Sử dụng paginate để phân trang thay vì get() tất cả nếu dữ liệu lớn
        $users = User::with([
                        "shops" => function ($q) {
                            $q->select("shops.id", "shops.name");
                        },
                        "role" => function ($query) {
                            $query->select("name", "id");
                        }
                     ])
                     ->where(function ($q) use ($inputs) {
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
                        if (isset($inputs["shop_id"])) {
                            $q->whereHas("shops", function ($query) use ($inputs) {
                                $query->where("shops.id", $inputs["shop_id"]);
                            });
                        }
                     })
                     ->select("id", "name", "phone_number", "email", "role_id", "pancake_full_data")
                     ->paginate(30);
        
        return response()->json([
            'success' => true,
            'data' => $users
        ], 200);
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
            'role_id'      => 'required|exists:roles,id'
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

    /**
     * Cập nhật thông tin user (Update)
     * PUT/PATCH /api/users/{user}
     */
    public function update(Request $request, User $user)
    {
        // 1. Validate dữ liệu đầu vào (cho phép null nếu chỉ update 1 vài field)
        $validator = Validator::make($request->all(), [
            'name'         => 'sometimes|string|max:255',
            'email'        => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'password'     => 'sometimes|string|min:6',
            'phone_number' => 'sometimes|string|min:10',
            'role_id'      => 'required|exists:roles,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ',
                'errors'  => $validator->errors()
            ], 422);
        }
        $validatedData = $validator->validated();
        // 2. Nếu có update mật khẩu thì cần mã hóa lại
        if (isset($validatedData['password'])) {
            $validatedData['password'] = Hash::make($validatedData['password']);
        }

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
}
