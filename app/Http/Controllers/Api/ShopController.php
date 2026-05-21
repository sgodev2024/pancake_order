<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PermissionCheckMiddleware;
use App\Jobs\CustomerByShopChunkJob;
use App\Jobs\GetEmployeeByShopJob;
use App\Jobs\OrderByShopChunkJob;
use App\Jobs\ProductByShopChunkJob;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Routing\Controllers\Middleware;

class ShopController extends Controller implements HasMiddleware
{
    protected $apiUrl;

    /**
     * Khai báo middleware cho Controller
     */
    public static function middleware(): array
    {
        return [
            // Khai báo lần lượt từng middleware và chỉ định áp dụng cho method 'store'
            new Middleware(PermissionCheckMiddleware::class . ':create-shop', only: ['store']),
            new Middleware(PermissionCheckMiddleware::class . ':update-shop', only: ['update']),
            // new Middleware(PermissionCheckMiddleware::class . ':list-shop', only: ['index']),
        ];
    }

    public function __construct()
    {
        $this->apiUrl = env("PANCAKE_API_V1");
    }

    public function index(Request $request)
    {
        try {
            $inputs = $request->only("date_from", "date_to");
            return response()->json([
                "success" => true,
                "data"    => Shop::select("id", "name", "pancake_shop_id", "care_cycle_days")
                                 ->with([
                                    "users" => function ($q) {
                                        $q->select("users.id", "users.name", "users.email", "users.role_id")
                                        ->with(["role" => function ($query) {
                                            $query->select("roles.id", "roles.name");
                                        }]);
                                    }
                                 ])
                                 ->withSum([
                                    "orders as total_cod" => function ($q) use ($inputs) {
                                        if (!empty($inputs["date_from"])) {
                                            $q->whereDate("created_at", ">=", $inputs["date_from"]);
                                        }
                                        if (!empty($inputs["date_to"])) {
                                            $q->whereDate("created_at", "<=", $inputs["date_to"]);
                                        }
                                    }
                                ], "cod")
                                 ->where(function ($q) use ($inputs) {
                                    if (!is_admin()) {
                                        // Lọc các shop mà danh sách users của nó có chứa user đang đăng nhập
                                        $q->whereHas('users', function ($userQuery) {
                                            $userQuery->where('users.id', auth()->id());
                                        });
                                    }
                                 })
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

    public function store(Request $request)
    {
        try {
            $inputs = $request->only("api_key");
            $response = Http::get($this->apiUrl . "shops?api_key={$inputs['api_key']}")->json();
            if (!empty($response["shops"])) {
                $shops = $response["shops"];
                // $apiShopIds = array_column($shops, 'id');
                // $existingShopIds = Shop::whereIn('pancake_shop_id', $apiShopIds)
                //                         ->pluck('pancake_shop_id')
                //                         ->toArray();
                foreach ($shops as $shop_item) {
                    $shop = Shop::where("pancake_shop_id", $shop_item["id"])->first();
                    if (!$shop) {
                        $shop = Shop::create([
                            "avatar_url"        => $shop_item["avatar_url"],
                            "pancake_shop_id"   => $shop_item["id"],
                            "name"              => $shop_item["name"],
                            "api_key"           => $inputs["api_key"],
                            "care_cycle_days"   => $inputs["care_cycle_days"] ?? 5,
                            "pancake_full_data" => $shop_item,
                            "created_at"        => now(),
                            "updated_at"        => now()
                        ]);
                        $this->getEmployee($shop);
                        $this->getCustomer($shop);
                        $this->getOrder($shop);
                        $this->getProduct($shop);
                    }
                }
            }
            return response()->json([
                "success" => true,
                "message" => "Lấy dữ liệu thành công"
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => $th->getMessage()
            ]);
        }
    }

    public function update(Request $request, Shop $shop)
    {
        try {
            $shop->update([
                "care_cycle_days" => $request->care_cycle_days
            ]);

            return response()->json([
                "success" => true,
                "message" => "Cập nhật thành công"
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => $th->getMessage()
            ]);
        }
    }

    public function getCustomer($shop)
    {
        Log::info("======================getCustomer=====================");
        try {
            $page_size = 500;
            $response = Http::get($this->apiUrl . "shops/{$shop->pancake_shop_id}/customers?api_key={$shop->api_key}&page_size={$page_size}&page_number=1")->json();
            if (!empty($response["total_pages"])) {
                $total_pages = $response["total_pages"];
                $pages = collect(range(1, $total_pages));
                $chunks = $pages->chunk(5);
                foreach ($chunks as $index => $chunk) {
                    CustomerByShopChunkJob::dispatch(
                        $shop->id,
                        $shop->pancake_shop_id,
                        $shop->api_key,
                        $chunk,
                        $page_size,
                        $this->apiUrl
                    )->onQueue("get-customer-chunk");
                }
            }

            return;
        } catch (\Throwable $th) {
            Log::info($th->getMessage());
        }
    }

    public function getProduct($shop)
    {
        Log::info("======================getProduct=====================");
        try {
            $page_size = 500;
            $response = Http::get($this->apiUrl . "shops/{$shop->pancake_shop_id}/products/variations?api_key={$shop->api_key}&page_size={$page_size}&page_number=1")->json();
            if (!empty($response["total_pages"])) {
                $total_pages = $response["total_pages"];
                $pages = collect(range(1, $total_pages));
                $chunks = $pages->chunk(5);
                foreach ($chunks as $index => $chunk) {
                    ProductByShopChunkJob::dispatch(
                        $shop->id,
                        $shop->pancake_shop_id,
                        $shop->api_key,
                        $chunk,
                        $page_size,
                        $this->apiUrl
                    )->onQueue("get-product-chunk");
                }
            }
            
            return;
        } catch (\Throwable $th) {
            Log::info($th->getMessage());
        }
    }

    public function getOrder($shop)
    {
        Log::info("======================getOrder=====================");
        try {
            $page_size = 500;
            $response = Http::get($this->apiUrl . "shops/{$shop->pancake_shop_id}/orders?api_key={$shop->api_key}&page_size={$page_size}&page_number=1")->json();
            if (!empty($response["total_pages"])) {
                $total_pages = $response["total_pages"];
                $pages = collect(range(1, $total_pages));
                $chunks = $pages->chunk(5);
                foreach ($chunks as $index => $chunk) {
                    OrderByShopChunkJob::dispatch(
                        $shop->id,
                        $shop->pancake_shop_id,
                        $shop->api_key,
                        $chunk,
                        $page_size,
                        $this->apiUrl
                    )->onQueue("get-order-chunk");
                }
            }
            
            return;
        } catch (\Throwable $th) {
            Log::info($th->getMessage());
        }
    }

    public function updateEmployeeFromPancake(Shop $shop)
    {
        try {
            $this->getEmployee($shop);

            return response()->json([
                "success" => true,
                "message" => "Dữ liệu đang được cập nhật lại, vui lòng đợi trong ít phút"
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => $th->getMessage()
            ]);
        }
    }

    public function getEmployee($shop)
    {
        Log::info("======================getEmployee=====================");
        $response = Http::get($this->apiUrl . "shops/{$shop->pancake_shop_id}/users?api_key={$shop->api_key}")->json();
        if (!empty($response["data"])) {
            GetEmployeeByShopJob::dispatch(
                $response["data"],
                $shop->id
            )->onQueue("get-employee");
        }
        return;
    }
}
