<?php

namespace App\Http\Controllers\Api;

use App\Exports\OpportunityTemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\OpportunityImport;
use App\Models\CustomerCare;
use App\Models\ImportedOpportunity;
use App\Models\User;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ImportedOpportunityController extends Controller
{
    public function index(Request $request)
    {
        try {
            $inputs = $request->only("shop_id", "page", "date_from", "date_to");
            $user = auth()->user();
            $queries = ImportedOpportunity::query();
            $queries->where("status", 0);
            $queries->with(["shop" => fn($q) => $q->select("id", "name")]);

            if (isset($inputs["date_from"])) {
                $queries->where("created_at", ">=", $inputs["date_from"] . " 00:00:00");
            }
            if (isset($inputs["date_to"])) {
                $queries->where("created_at", "<=", $inputs["date_to"] . " 23:59:59");
            }
            if (isset($inputs["shop_id"])) {
                $queries->where("shop_id", $inputs["shop_id"]);
            } else {
                if (!$user->isAdmin()) {
                    $shop_ids = $user->shops()->pluck("shops.id");
                    $queries->whereIn("shop_id", $shop_ids);
                }
            }

            $queries->latest("created_at");
            $opportunities = $queries->paginate(30, ['*'], 'page', $inputs["page"] ?? 1);

            return response()->json([
                "success" => true,
                "data" => [
                    'orders' => $opportunities->items(),
                    'current_page' => $opportunities->currentPage(),
                    'per_page' => $opportunities->perPage(),
                    'total_items' => $opportunities->total(),
                    'total_pages' => $opportunities->lastPage(),
                ],
            ]);
        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => 'Đã có lỗi xảy ra: ' . $th->getMessage()], 500);
        }
    }

    public function downloadTemplate()
    {
        return Excel::download(new OpportunityTemplateExport, 'mau-import-co-hoi.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                "shop_id" => "required|exists:shops,id",
                "file" => "required|file|mimes:xlsx,xls,csv",
            ]);

            $import = new OpportunityImport((int) $request->shop_id, auth()->id());
            Excel::import($import, $request->file('file'));

            return response()->json([
                "success" => true,
                "message" => "Đã import thành công {$import->importedCount} khách hàng",
                "data" => ["imported_count" => $import->importedCount],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => 'Đã có lỗi xảy ra: ' . $th->getMessage()], 500);
        }
    }

    public function assign(Request $request)
    {
        try {
            $inputs = $request->only("pancake_user_ids", "ids");
            $ids = $inputs["ids"] ?? [];
            $pancake_user_id = $inputs["pancake_user_ids"][0] ?? null;

            if (empty($ids) || !$pancake_user_id) {
                return response()->json(["success" => false, "message" => "Thiếu dữ liệu phân công"]);
            }

            $user = User::where("pancake_user_id", $pancake_user_id)->first();
            $shop_ids = $user->shops()->pluck("shops.id");

            $opportunities = ImportedOpportunity::whereIn('id', $ids)
                ->whereIn("shop_id", $shop_ids)
                ->where("status", 0)
                ->get();

            if ($opportunities->isEmpty()) {
                return response()->json(["success" => false,
                    "message" => "Các khách hàng bạn phân công không thuộc cửa hàng mà " . $user->name . " nằm trong"]);
            }

            $customer_cares = [];
            foreach ($opportunities as $opportunity) {
                $customer_cares[] = [
                    "shop_id" => $opportunity->shop_id,
                    "pancake_customer_id" => "IMPORT-" . $opportunity->id,
                    "customer_phones" => json_encode([$opportunity->phone]),
                    "customer_name" => $opportunity->name,
                    "customer_addresss" => $opportunity->address,
                    "pancake_order_id" => null,
                    "date_care" => now()->addDays(3)->format('Y-m-d'),
                    "user_creator_id" => $pancake_user_id,
                    "created_at" => now(),
                    "updated_at" => now(),
                ];
            }
            CustomerCare::insert($customer_cares);

            ImportedOpportunity::whereIn('id', $opportunities->pluck('id'))->update(["status" => 1]);

            $total_opportunities = count($opportunities);
            $total_ids = count($ids);

            return response()->json(["success" => true,
                "message" => $total_opportunities == $total_ids
                    ? "Phân công thành công"
                    : "Phân công thành công " . $total_opportunities . " khách hàng. Còn lại " . ($total_ids - $total_opportunities) . " khách hàng không thuộc cửa hàng mà " . $user->name . " nằm trong",
            ]);
        } catch (\Throwable $th) {
            return response()->json(["success" => false, "message" => $th->getMessage()]);
        }
    }
}
