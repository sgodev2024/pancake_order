<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PermissionCheckMiddleware;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CustomerController extends Controller implements HasMiddleware
{
    /**
     * Khai báo middleware cho Controller
     */
    public static function middleware(): array
    {
        return [
            // Khai báo lần lượt từng middleware và chỉ định áp dụng cho method 'store'
            new Middleware(PermissionCheckMiddleware::class . ':list-customer'),
        ];
    }

    public function index(Request $request)
    {
        try {
            $inputs = $request->only(
                "page",
                "page_size",
                "shop_id",
                "date_start",
                "date_end",
                "search"
            );
            // 1. Khởi tạo query từ relationship
            $query = Customer::query();
            $query->where("shop_id", $inputs["shop_id"]);
            $query->with(["shop" => function ($q) {
                $q->select("shops.id", "shops.name");
            }]);
            // 2. Select các trường cụ thể cần lấy để tối ưu performance
            // (Thêm tiền tố tên bảng 'customers.id' để tránh lỗi trùng lặp cột nếu sau này có join bảng)
            $query->select([
                'id',
                'shop_id',
                'name', 
                'phone_numbers',
                'pancake_full_data',
                'created_at'
            ]);

            // 3. Xử lý các điều kiện lọc (Filters)
            // Lọc theo từ khóa tìm kiếm (Tên hoặc Số điện thoại)
            $query->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($sub) use ($search) {
                    $sub->where(function ($q_sub) use ($search) {
                        $q_sub->where('name', 'like', "{$search}%")
                              ->orWhere('phone_numbers', 'like', "%{$search}%");
                    });
                });
            });
            if (isset($inputs["date_start"])) {
                $query->where("created_at", ">=", $inputs["date_start"] . " 00:00:00");
            }
            if (isset($inputs["date_end"])) {
                $query->where("created_at", "<=", $inputs["date_end"] . " 23:59:59");
            }
            if (!is_admin()) {
                $user = auth()->user();
                $query->where(function ($q) use ($user) {
                            $q->where("user_creator_id", $user->pancake_user_id)
                            ->orWhere("user_care_id", $user->pancake_user_id)
                            ->orWhere("user_assigning_seller_id", $user->pancake_user_id);
                        });
            }
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
                    'per_page'     => $customers->perPage(),
                    'total_items'  => $customers->total(),
                    'total_pages'  => $customers->lastPage(),
                ],
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Đã có lỗi xảy ra: ' . $th->getMessage()
            ], 500);
        }
    }
}
