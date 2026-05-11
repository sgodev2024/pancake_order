<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerCare;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CustomerCareController extends Controller
{
    public function index(Request $request)
    {
        try {
            $inputs = $request->only("type", "page", "shop_id");
            $user = auth()->user();
            $result = $this->buildQuery($inputs["type"], $user, $inputs)
                           ->with(["shop" => fn($q) => $q->select("id", "name")])
                           ->paginate(30, ['*'], 'page', $inputs["page"]);

            return response()->json([
                "success" => true,
                "data"    => [
                    "customers"    => $result->items(),
                    'current_page' => $result->currentPage(),
                    'per_page'     => $result->perPage(),
                    'total_items'  => $result->total(),
                    'total_pages'  => $result->lastPage(),
                ],
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => "Vui lòng thử lại" . $th->getMessage()
            ]);
        }
    }

    private function buildQuery(string $type, $user, array $inputs)
    {
        $query = CustomerCare::query()->latest("date_care");
        // Apply date filter theo type
        match ($type) {
            'customer_care_today'   => $query->where("date_care", date("Y-m-d")),
            'customer_care_pending' => $query->where("date_care", '>', date("Y-m-d")),
            'customer_care_in_week' => $query->whereBetween("date_care", [
                                            Carbon::now()->startOfWeek()->format("Y-m-d"),
                                            Carbon::now()->endOfWeek()->format("Y-m-d"),
                                        ]),
            'customer_care_expire'  => $query->where("date_care", "<=", date("Y-m-d"))
                                            ->where("status", 0),
        };

        return $query->where(fn($q) => $this->applyAccessFilter($q, $user, $inputs["shop_id"]));
    }

    private function applyAccessFilter($q, $user, $shopId): void
    {
        if (is_admin()) return;

        $isManager = $user->shops()
                        ->where('shop_id', $shopId)
                        ->wherePivot('is_manager', 1)
                        ->exists();

        if (!$isManager) {
            $q->where("user_creator_id", $user->pancake_user_id)
            ->orWhere("user_care_id", $user->pancake_user_id)
            ->orWhere("user_assigning_seller_id", $user->pancake_user_id);
        }
    }

    public function update(Request $request, CustomerCare $customer_care)
    {
        try {
            if (!$customer_care) {
                return response()->json([
                    "success" => false,
                    "message" => "Không tồn tại"
                ]);
            }
            $customer_care->update([
                "status" => $request->status
            ]);

            return response()->json([
                "success" => true,
                "message" => "Cập nhật thành công"
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => "Vui lòng thử lại"
            ]);
        }
    }

    public function destroy(CustomerCare $customer_care)
    {
        $customer_care->delete();

        // Trả về response
        return response()->json([
            'success' => true,
            'message' => 'Đã xóa thành công'
        ], 200); // Có thể dùng 204 No Content nếu không muốn trả về body
    }

    public function overview()
    {
        try {
            $today = today()->format("Y-m-d");
            $startOfWeek = Carbon::now()->startOfWeek()->format("Y-m-d");
            $endOfWeek = Carbon::now()->endOfWeek()->format("Y-m-d");
            $user = auth()->user();
            $shop_ids = $user->shops()->pluck('shops.id');
            $overview = CustomerCare::selectRaw("
                SUM(CASE WHEN date_care = ? THEN 1 ELSE 0 END) as customer_care_today,
                SUM(CASE WHEN date_care > ? THEN 1 ELSE 0 END) as customer_care_pending,
                SUM(CASE WHEN date_care BETWEEN ? AND ? THEN 1 ELSE 0 END) as customer_care_in_week,
                SUM(CASE WHEN date_care <= ? AND status = 0 THEN 1 ELSE 0 END) as customer_care_expire
            ", [
                $today,           // customer_care_today
                $today,           // customer_care_pending
                $startOfWeek,     // customer_care_in_week (start)
                $endOfWeek,       // customer_care_in_week (end)
                $today,           // customer_care_expire
            ])
            ->when(!is_admin(), function ($q) use ($user, $shop_ids) {
                $q->whereIn("shop_id", $shop_ids)
                  ->where(function ($q2) use ($user) {
                    $q2->where("user_creator_id", $user->pancake_user_id)
                        ->orWhere("user_care_id", $user->pancake_user_id)
                        ->orWhere("user_assigning_seller_id", $user->pancake_user_id);
                });
            })
            ->first();
            $date_start = date("Y-m-d 00:00:00");
            $date_end   = date("Y-m-d 23:59:59");
            $total_order_today = Order::whereBetween("created_at", [$date_start, $date_end])
                                    ->where(function ($q) use ($user, $shop_ids) {
                                        if (!is_admin()) {
                                            $q->whereIn("shop_id", $shop_ids)
                                            ->where(function($q2) use ($user) {
                                                $q2->where("user_creator_id", $user->pancake_user_id)
                                                    ->orWhere("user_care_id", $user->pancake_user_id)
                                                    ->orWhere("user_assigning_seller_id", $user->pancake_user_id);
                                            });
                                        }
                                    })
                                    ->count();

            return response()->json([
                "success" => true,
                "data" => [
                    "customer_care_today"   => (int) $overview->customer_care_today,
                    "customer_care_pending" => (int) $overview->customer_care_pending,
                    "customer_care_in_week" => (int) $overview->customer_care_in_week,
                    "customer_care_expire"  => (int) $overview->customer_care_expire,
                    "total_order_today"     => $total_order_today
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => "Vui lòng thử lại"
            ]);
        }
    }
}
