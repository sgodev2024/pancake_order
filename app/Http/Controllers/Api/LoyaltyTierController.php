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
            $result = Order::query()
                            ->selectRaw('
                                SUM(cod) as total_cod,
                                SUM(cod * discount_percent / 100) as total_discount
                            ')
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
}
