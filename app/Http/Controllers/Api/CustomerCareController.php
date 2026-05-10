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
            $inputs = $request->only("type", "page");
            switch ($inputs["type"]) {
                case 'customer_care_today':
                    $result = CustomerCare::where("date_care", date("Y-m-d"))
                                          ->latest("date_care")
                                          ->paginate(30, ['*'], 'page', $inputs["page"]);
                    break;
                case 'customer_care_pending':
                    $result = CustomerCare::where("date_care", "=", date("Y-m-d"))->latest("date_care")
                                          ->latest("date_care")
                                          ->paginate(30, ['*'], 'page', $inputs["page"]);
                    break;
                case 'customer_care_in_week':
                    $start_of_week = Carbon::now()->startOfWeek()->format("Y-m-d");
                    $end_of_week = Carbon::now()->endOfWeek()->format("Y-m-d");
                    $result = CustomerCare::whereBetween("date_care", [$start_of_week, $end_of_week])
                                          ->latest("date_care")
                                          ->paginate(30, ['*'], 'page', $inputs["page"]);
                    break;
                case 'customer_care_expire':
                    $result = CustomerCare::whereBetween("date_care", "<=", date("Y-m-d"))
                                         ->where("status", 1)
                                         ->latest("date_care")
                                         ->paginate(30, ['*'], 'page', $inputs["page"]);
                    break;
            }

            return response()->json([
                "success" => true,
                "data"    => [
                    "customers" => $result->items(),
                    'current_page' => $result->currentPage(),
                    'per_page'     => $result->perPage(),
                    'total_items'  => $result->total(),
                    'total_pages'  => $result->lastPage(),
                ],
            ]);
        } catch (\Throwable $th) {
            //throw $th;
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
