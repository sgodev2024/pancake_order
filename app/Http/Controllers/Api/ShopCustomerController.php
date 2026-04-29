<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\Request;

class ShopCustomerController extends Controller
{
    public function index(Request $request, Shop $shop)
    {
        try {
            // 1. Khởi tạo query từ relationship
            $query = $shop->customers();

            // 2. Select các trường cụ thể cần lấy để tối ưu performance
            // (Thêm tiền tố tên bảng 'customers.id' để tránh lỗi trùng lặp cột nếu sau này có join bảng)
            $query->select([
                'customers.id', 
                'customers.name', 
                'customers.phone_numbers',
                'customers.pancake_full_data',
                'customers.created_at'
            ]);

            // 3. Xử lý các điều kiện lọc (Filters)
            // Lọc theo từ khóa tìm kiếm (Tên hoặc Số điện thoại)
            $query->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($sub) use ($search) {
                    $sub->where('customers.name', 'like', "{$search}%")
                        ->orWhere('customers.phone_numbers', 'like', "%{$search}%");
                });
            });

            $pageNumber = $request->input('page', 1);
            // 4. Sắp xếp và Phân trang (Lấy 30 records mỗi trang)
            $customers = $query->latest('customers.id')->paginate(30, ['*'], 'page', $pageNumber);

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
