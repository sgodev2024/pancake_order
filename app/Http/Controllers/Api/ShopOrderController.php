<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\Request;

class ShopOrderController extends Controller
{
    public function index(Request $request, Shop $shop)
    {
        try {
            // 1. Khởi tạo query từ relationship
            $query = $shop->orders();

            // 2. Select các trường cụ thể cần lấy để tối ưu performance
            // (Thêm tiền tố tên bảng 'customers.id' để tránh lỗi trùng lặp cột nếu sau này có join bảng)
            $query->select([
                'orders.id', 
                'orders.order_number_vtp', 
                'orders.total_quantity',
                'orders.cod',
                'orders.cash',
                'orders.note',
                'orders.created_at',
                'orders.status',
                'orders.status_vtp',
                'orders.pancake_full_data',
                'orders.received_at_shop',
                'orders.customer_name',
                'orders.customer_phone',
                'orders.customer_address'
            ]);

            // 3. Xử lý các điều kiện lọc (Filters)
            // Lọc theo từ khóa tìm kiếm (Tên hoặc Số điện thoại)
            // $query->when($request->filled('search'), function ($q) use ($request) {
            //     $search = $request->search;
            //     $q->where(function ($sub) use ($search) {
            //         $sub->where('customers.name', 'like', "{$search}%")
            //             ->orWhere('customers.phone_numbers', 'like', "%{$search}%");
            //     });
            // });

            $pageNumber = $request->input('page', 1);
            // 4. Sắp xếp và Phân trang (Lấy 30 records mỗi trang)
            $orders = $query->latest('orders.created_at')->paginate(30, ['*'], 'page', $pageNumber);

            return response()->json([
                "success" => true,
                "data" => [
                    'orders' => $orders->items(),
                    'current_page' => $orders->currentPage(),
                    'per_page'     => $orders->perPage(),
                    'total_items'  => $orders->total(),
                    'total_pages'  => $orders->lastPage(),
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
