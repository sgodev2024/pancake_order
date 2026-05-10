<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerCare;
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
            'customer_care_today'   => $query->where("date_care", today()),
            'customer_care_pending' => $query->where("date_care", today()),
            'customer_care_in_week' => $query->whereBetween("date_care", [
                                            Carbon::now()->startOfWeek()->format("Y-m-d"),
                                            Carbon::now()->endOfWeek()->format("Y-m-d"),
                                        ]),
            'customer_care_expire'  => $query->where("date_care", "<=", today())
                                            ->where("status", 1),
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
}
