<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $validator = Validator::make($request->only(
            "page",
            "page_size",
            "search",
            "from_date",
            "to_date",
            "shop_id",
            "actor_user_id",
            "target_user_id",
            "action",
            "order_id",
            "customer_id"
        ), [
            "page" => "nullable|integer|min:1",
            "page_size" => "nullable|integer|min:1|max:100",
            "search" => "nullable|string|max:255",
            "from_date" => "nullable|date_format:Y-m-d",
            "to_date" => "nullable|date_format:Y-m-d",
            "shop_id" => "nullable|integer|min:1",
            "actor_user_id" => "nullable|integer|min:1",
            "target_user_id" => "nullable|integer|min:1",
            "action" => "nullable|string|max:100",
            "order_id" => "nullable|string|max:255",
            "customer_id" => "nullable|string|max:255"
        ]);

        if ($validator->fails()) {
            return response()->json([
                "success" => false,
                "message" => "Dữ liệu không hợp lệ",
                "errors" => $validator->errors()
            ], 422);
        }

        $inputs = $validator->validated();

        if (
            isset($inputs["from_date"], $inputs["to_date"])
            && $inputs["from_date"] > $inputs["to_date"]
        ) {
            return response()->json([
                "success" => false,
                "message" => "Dữ liệu không hợp lệ",
                "errors" => [
                    "to_date" => ["Ngày kết thúc phải lớn hơn hoặc bằng ngày bắt đầu."]
                ]
            ], 422);
        }

        try {
            $user = auth()->user();
            $query = ActivityLog::query()
                ->with([
                    "actor" => function ($query) {
                        $query->select("users.id", "users.name");
                    },
                    "targetUser" => function ($query) {
                        $query->select("users.id", "users.name");
                    },
                    "shop" => function ($query) {
                        $query->select("shops.id", "shops.name");
                    }
                ]);

            if (!$user->isAdmin()) {
                $query->whereIn("activity_logs.shop_id", $user->shops()->select("shops.id"));
            }

            if (isset($inputs["shop_id"])) {
                $query->where("activity_logs.shop_id", $inputs["shop_id"]);
            }

            if (isset($inputs["actor_user_id"])) {
                $query->where("activity_logs.actor_user_id", $inputs["actor_user_id"]);
            }

            if (isset($inputs["target_user_id"])) {
                $query->where("activity_logs.target_user_id", $inputs["target_user_id"]);
            }

            if (isset($inputs["action"])) {
                $query->where("activity_logs.action", $inputs["action"]);
            }

            if (isset($inputs["order_id"])) {
                $query->where("activity_logs.pancake_order_id", $inputs["order_id"]);
            }

            if (isset($inputs["customer_id"])) {
                $query->where("activity_logs.pancake_customer_id", $inputs["customer_id"]);
            }

            if (isset($inputs["from_date"])) {
                $query->where(
                    "activity_logs.created_at",
                    ">=",
                    Carbon::createFromFormat("Y-m-d", $inputs["from_date"])->startOfDay()
                );
            }

            if (isset($inputs["to_date"])) {
                $query->where(
                    "activity_logs.created_at",
                    "<=",
                    Carbon::createFromFormat("Y-m-d", $inputs["to_date"])->endOfDay()
                );
            }

            if (isset($inputs["search"]) && trim($inputs["search"]) !== "") {
                $search = trim($inputs["search"]) . "%";

                $query->where(function ($query) use ($search) {
                    $query->where("activity_logs.actor_name", "like", $search)
                        ->orWhere("activity_logs.target_user_name", "like", $search)
                        ->orWhere("activity_logs.shop_name", "like", $search)
                        ->orWhere("activity_logs.pancake_order_id", "like", $search)
                        ->orWhere("activity_logs.pancake_customer_id", "like", $search);
                });
            }

            $pageSize = $inputs["page_size"] ?? 30;
            $logs = $query
                ->orderByDesc("activity_logs.created_at")
                ->orderByDesc("activity_logs.id")
                ->paginate($pageSize, ["activity_logs.*"], "page", $inputs["page"] ?? 1);

            $logs->setCollection($logs->getCollection()->map(
                fn (ActivityLog $log) => $this->transformLog($log)
            ));

            return response()->json([
                "success" => true,
                "data" => [
                    "logs" => $logs->items(),
                    "current_page" => $logs->currentPage(),
                    "per_page" => $logs->perPage(),
                    "total_items" => $logs->total(),
                    "total_pages" => $logs->lastPage()
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => "Đã có lỗi xảy ra."
            ], 500);
        }
    }

    private function transformLog(ActivityLog $log): array
    {
        $actorName = $log->actor_name ?? $log->actor?->name;
        $targetUserName = $log->target_user_name ?? $log->targetUser?->name;
        $shopName = $log->shop_name ?? $log->shop?->name;

        return [
            "id" => $log->id,
            "created_at" => $log->created_at,
            "action" => $log->action,
            "source" => $log->source,
            "description" => $this->formatDescription($log, $actorName, $targetUserName),
            "actor" => [
                "id" => $log->actor_user_id,
                "name" => $actorName
            ],
            "target_user" => [
                "id" => $log->target_user_id,
                "name" => $targetUserName
            ],
            "shop" => [
                "id" => $log->shop_id,
                "name" => $shopName
            ],
            "subject" => [
                "type" => $log->subject_type,
                "id" => $log->subject_id
            ],
            "pancake_order_id" => $log->pancake_order_id,
            "pancake_customer_id" => $log->pancake_customer_id,
            "old_values" => $log->old_values,
            "new_values" => $log->new_values,
            "metadata" => $log->metadata
        ];
    }

    private function formatDescription(ActivityLog $log, ?string $actorName, ?string $targetUserName): ?string
    {
        if ($log->action !== "customer_care.assigned") {
            return null;
        }

        $actorName ??= "Người dùng không xác định";
        $targetUserName ??= "Người dùng không xác định";
        $orderLabel = $log->pancake_order_id ?? $log->subject_id ?? "không xác định";

        return $actorName . " phân công đơn " . $orderLabel . " cho " . $targetUserName;
    }
}
