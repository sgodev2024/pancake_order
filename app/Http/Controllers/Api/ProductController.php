<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PermissionCheckMiddleware;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\Middleware;

class ProductController extends Controller
{

    public static function middleware(): array
    {
        return [
            // Khai báo lần lượt từng middleware và chỉ định áp dụng cho method 'store'
            new Middleware(PermissionCheckMiddleware::class . ':list-product', only: ['index']),
            // new Middleware(PermissionCheckMiddleware::class . ':list-shop', only: ['index']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $inputs = $request->only(
                "page",
                "page_size",
                "shop_id",
                "search"
            );
            // 1. Khởi tạo query từ relationship
            $query = Product::query();
            if (isset($inputs["shop_id"])) {
                $query->where("shop_id", $inputs["shop_id"]);
            }
            // 3. Xử lý các điều kiện lọc (Filters)
            // Lọc theo từ khóa tìm kiếm (Tên hoặc Số điện thoại)
            $query->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "{$search}%");
                });
            });
            
            $pageNumber = $inputs["page"];
            $page_size  = $inputs["page_size"] ?? 30;
            // 4. Sắp xếp và Phân trang (Lấy 30 records mỗi trang)
            $products = $query->latest()->paginate($page_size, ['*'], 'page', $pageNumber);

            // 5. Trả về Response
            return response()->json([
                'success' => true,
                'data'    => [
                    "products" => $products->items(),
                    'current_page' => $products->currentPage(),
                    'per_page'     => $products->perPage(),
                    'total_items'  => $products->total(),
                    'total_pages'  => $products->lastPage(),
                ],
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => $th->getMessage()
            ]);
        }
    }
}
