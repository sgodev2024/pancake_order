<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PermissionCheckMiddleware;
use Illuminate\Http\Request;
use App\Models\LoyaltyTier;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class LoyaltyTierController extends Controller
{
    public static function middleware(): array
    {
        return [
            // Khai báo lần lượt từng middleware và chỉ định áp dụng cho method 'store'
            new Middleware(PermissionCheckMiddleware::class . ':create-update-destroy-loyalty-tier', only: ['index', 'store', 'update', 'destroy', 'show'])
        ];
    }

    // GET /api/v1/loyalty-tiers
    public function index(Request $request): JsonResponse
    {
        $type = $request->type ?? NULL;
        $tiers = LoyaltyTier::all();
        if ($type == "report") {
            $tiers = LoyaltyTier::with("customers")->get();
        }
        
        return response()->json(['success' => true, 'data' => $tiers]);
    }

    // POST /api/v1/loyalty-tiers
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'             => 'required|string|max:100|unique:loyalty_tiers,name',
            'discount_percent' => 'required|numeric|min:0|max:100',
            'min_order_value'  => 'required|numeric|min:0',
            'max_order_value'  => 'nullable|numeric|gt:min_order_value'
        ]);
        $validated = $validator->validated();
        $tier = LoyaltyTier::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tạo hạng khách hàng thành công.',
            'data'    => $tier,
        ], 201);
    }

    // GET /api/v1/loyalty-tiers/{id}
    public function show(LoyaltyTier $loyaltyTier): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $loyaltyTier]);
    }

    // PUT /api/v1/loyalty-tiers/{id}
    public function update(Request $request, LoyaltyTier $loyaltyTier): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'             => 'sometimes|string|max:100|unique:loyalty_tiers,name,' . $loyaltyTier->id,
            'discount_percent' => 'sometimes|numeric|min:0|max:100',
            'min_order_value'  => 'sometimes|numeric|min:0',
            'max_order_value'  => 'nullable|numeric|gt:min_order_value',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ',
                'errors'  => $validator->errors()
            ], 422);
        }
        $validated = $validator->validated();
        $loyaltyTier->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật hạng khách hàng thành công.',
            'data'    => $loyaltyTier->fresh(),
        ]);
    }

    // DELETE /api/v1/loyalty-tiers/{id}
    public function destroy(LoyaltyTier $loyaltyTier): JsonResponse
    {
        $loyaltyTier->delete();

        return response()->json([
            'success' => true,
            'message' => 'Xóa hạng khách hàng thành công.',
        ]);
    }

    public function getTotalDiscount()
    {
        try {
            $user = auth()->user();
            $shop_ids = $user->shops()->pluck('shops.id');
            $is_admin = is_admin();
            $result = Order::query()
                            ->selectRaw('
                                SUM(cod) as total_cod,
                                SUM(cod * discount_percent / 100) as total_discount
                            ')
                            ->where(function ($query) use ($shop_ids, $is_admin) {
                                if (!$is_admin) {
                                    $query->whereIn("shop_id", $shop_ids);
                                }
                            })
                            ->first();

            return response()->json([
                "success" => true,
                "data"    => [
                    "total_discount" => $result->total_discount ?? 0,
                    "discount_rate_percent" => ($result->total_discount/$result->total_cod) * 100
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => true,
                "data"    => [
                    "total_discount" => 0,
                    "discount_rate_percent" => 0
                ]
            ]);
        }
    }

    /**
     * Lấy danh sách hạng
     * Lấy tổng số khách hàng trong hạng đó
     * Lấy tổng số tiền đã được discount đối với hạng đó
     */
    public function overview()
    {
        try {
            $user = auth()->user();
            $data = [];
            $shop_ids = $user->shops()->pluck('shops.id');
            $is_admin = is_admin();
            $tiers = LoyaltyTier::query()
                    ->select([
                        'loyalty_tiers.id',
                        'loyalty_tiers.name',
                        'loyalty_tiers.discount_percent',
                        'loyalty_tiers.min_order_value',
                        'loyalty_tiers.max_order_value'
                    ])
                    ->where("loyalty_tiers.is_active", 1)
                    ->selectRaw('
                        COUNT(DISTINCT customers.id) as customer_count,
                        COALESCE(SUM(orders.cod * orders.discount_percent / 100), 0) as total_discount_amount
                    ')
                    ->leftJoin('customers', function ($join) use ($is_admin, $shop_ids) {
                        $join->on('customers.loyalty_tier_id', '=', 'loyalty_tiers.id');
                        if (!$is_admin) {
                            $join->whereIn('customers.shop_id', $shop_ids);
                        }
                    })
                    ->leftJoin('orders', function ($join) use ($is_admin, $shop_ids) {
                        $join->on('orders.pancake_customer_id', '=', 'customers.pancake_customer_id');
                        if (!$is_admin) {
                            $join->whereIn('orders.shop_id', $shop_ids);
                        }
                    })
                    ->groupBy(
                        'loyalty_tiers.id',
                        'loyalty_tiers.name',
                        'loyalty_tiers.discount_percent',
                        'loyalty_tiers.min_order_value',
                        'loyalty_tiers.max_order_value',
                    )
                    ->get();
            foreach ($tiers as $item) {
                $data[] = [
                    "id"                    => $item->id,
                    "name"                  => $item->name,
                    "discount_percent"      => $item->discount_percent,
                    "min_order_value"       => $item->min_order_value,
                    "max_order_value"       => $item->max_order_value,
                    "customers_count"       => $item->customer_count,
                    "total_discount_amount" => $item->total_discount_amount
                ];
            }

            return response()->json([
                "success" => true,
                "data"    => $data
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => true,
                "data"    => []
            ]);
        }
    }

    /**
     * Tổng số khách hàng chuẩn bị tăng hạng
     * Số khách hàng chuẩn bị tăng hạng theo từng hạng
     * Tổng số tiền chi tiêu phải đạt 70% của mức min
     */
    public function getCustomerPenddingUpgrade()
    {
        try {
            $user = auth()->user();
            $shop_ids = $user->shops()->pluck('shops.id');
            $is_admin = is_admin();
            $data = [];
            $tiers = LoyaltyTier::query()
                            ->select([
                                'loyalty_tiers.id',
                                'loyalty_tiers.name',
                                'loyalty_tiers.min_order_value',
                                'loyalty_tiers.max_order_value',
                            ])
                            ->selectRaw('
                                COUNT(customers.id) as about_to_upgrade_count
                            ')
                            ->leftJoin('customers',  function ($join) use ($is_admin, $shop_ids){
                                $join->on('customers.purchased_amount', '>=', DB::raw('loyalty_tiers.min_order_value * 0.7'))
                                    ->on('customers.purchased_amount', '<', 'loyalty_tiers.max_order_value')
                                    ->on('customers.loyalty_tier_id', '!=', 'loyalty_tiers.id');
                                if (!$is_admin) {
                                    $join->whereIn('customers.shop_id', $shop_ids);
                                }
                            })
                            ->groupBy(
                                'loyalty_tiers.id',
                                'loyalty_tiers.name',
                                'loyalty_tiers.min_order_value',
                                'loyalty_tiers.max_order_value',
                            )
                            ->get();
            // Tổng số khách chuẩn bị tăng hạng
            $totalAboutToUpgrade = $tiers->sum('about_to_upgrade_count');
            foreach ($tiers as $tier) {
                $data[] = [
                    "name"                   => $tier->name,                   // Tên hạng
                    "min_order_value"        => $tier->min_order_value,        // Ngưỡng vào hạng
                    "about_to_upgrade_count" => $tier->about_to_upgrade_count, // Số khách sắp tăng lên hạng này

                ];
            }

            return response()->json([
                "success" => true,
                "data"    => [
                    "total_customer_pendding_upgrade" => $totalAboutToUpgrade,
                    "loyalty_tier_detail"             => $data
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => true,
                "data"    => [
                    "total_customer_pendding_upgrade" => 0,
                    "loyalty_tier_detail"             => []
                ]
            ]);
        }
    }
}
