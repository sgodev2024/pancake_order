<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
                "date_to"
            );
            $user = auth()->user();
            // 1. Khởi tạo query từ relationship
            $query = Order::query();
            $query->with([
                "shop" => function ($query) {
                    $query->select("id", "name");
                }
            ]);
            $query->where("cod", ">", 0);
            if (isset($inputs["shop_id"])) {
                $query->where("shop_id", $inputs["shop_id"]);
            } else {
                if (!is_admin()) {
                    $query->whereIn("shop_id", $user->shops()->select("shops.id"));
                }
            }
            if (!is_admin()) {
                $query->where(function ($q) use ($user) {
                            $q->where("user_creator_id", $user->pancake_user_id)
                                ->orWhere("user_care_id", $user->pancake_user_id);
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
                'pancake_order_id'
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
}
