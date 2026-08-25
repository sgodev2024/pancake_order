<?php

namespace App\Http\Controllers\Api;

use App\Exports\OpportunityTemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\OpportunityImport;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\ImportedOpportunity;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\CustomerCareAssignmentService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ImportedOpportunityController extends Controller
{
    public function __construct(
        private readonly CustomerCareAssignmentService $customerCareAssignmentService,
        private readonly ActivityLogService $activityLogService
    ) {
    }

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
            $ids = collect($inputs['ids'] ?? [])
                ->filter(fn ($id) => is_int($id) || is_string($id))
                ->unique()
                ->values();
            $pancake_user_id = $inputs['pancake_user_ids'][0] ?? null;

            if ($ids->isEmpty() || ! $pancake_user_id) {
                return response()->json(['success' => false, 'message' => 'Thiếu dữ liệu phân công']);
            }

            $user = User::where("pancake_user_id", $pancake_user_id)->first();

            if (! $user) {
                return response()->json(["success" => false, "message" => "Không tìm thấy người được phân công"]);
            }

            if (! $user->canReceiveCustomerCareAssignments()) {
                return response()->json([
                    "success" => false,
                    "message" => "Người được phân công phải thuộc bộ phận CSKH.",
                ], 422);
            }

            $actor = auth()->user();
            $assignedAt = now();

            $total_opportunities = DB::transaction(function () use ($ids, $user, $actor, $assignedAt) {
                $lockedOpportunities = ImportedOpportunity::query()
                    ->whereIn('id', $ids)
                    ->where('status', 0)
                    ->with(['shop' => fn ($query) => $query->select('id', 'name', 'care_cycle_days')])
                    ->lockForUpdate()
                    ->get();

                if ($lockedOpportunities->count() !== $ids->count()) {
                    throw new DomainException('Một hoặc nhiều cơ hội không tồn tại hoặc đã được phân công.');
                }

                $sourceShopIds = $lockedOpportunities->pluck('shop_id')->unique()->values();

                if (! $actor->isAdmin()) {
                    $actorShopIds = $actor->shops()
                        ->whereIn('shops.id', $sourceShopIds)
                        ->pluck('shops.id');

                    if ($actorShopIds->count() !== $sourceShopIds->count()) {
                        throw new DomainException('Bạn không có quyền phân công cơ hội thuộc cửa hàng này.');
                    }
                }

                $assigneeShopIds = $user->shops()
                    ->whereIn('shops.id', $sourceShopIds)
                    ->pluck('shops.id');

                if ($assigneeShopIds->count() !== $sourceShopIds->count()) {
                    throw new DomainException(
                        "Các khách hàng bạn phân công không thuộc cửa hàng mà {$user->name} nằm trong"
                    );
                }

                foreach ($lockedOpportunities as $opportunity) {
                    if ($opportunity->shop === null) {
                        throw new DomainException('Không thể xác định cửa hàng của cơ hội nhập để lên lịch CSKH.');
                    }

                    $scheduledOn = CustomerCareAssignment::calculateScheduledCareDate(
                        $assignedAt,
                        $opportunity->shop->normalizedCareCycleDays()
                    );
                    $pancakeCustomerId = "IMPORT-" . $opportunity->id;
                    $customerCare = CustomerCare::create([
                        "shop_id" => $opportunity->shop_id,
                        "pancake_customer_id" => $pancakeCustomerId,
                        "customer_phones" => $opportunity->phone,
                        "customer_name" => $opportunity->name,
                        "customer_addresss" => $opportunity->address,
                        "pancake_order_id" => null,
                        "date_care" => $scheduledOn->toDateString(),
                        "user_creator_id" => $user->pancake_user_id,
                    ]);

                    $assignment = $this->customerCareAssignmentService->create(
                        $customerCare,
                        (int) $opportunity->shop_id,
                        CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY,
                        (int) $opportunity->id,
                        $user,
                        $assignedAt,
                        $scheduledOn
                    );

                    $this->activityLogService->log(
                        "customer_care.assigned",
                        "user",
                        $actor->id,
                        $actor->name,
                        $user->id,
                        $user->name,
                        $opportunity->shop_id,
                        $opportunity->shop?->name,
                        CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY,
                        $opportunity->id,
                        null,
                        $pancakeCustomerId,
                        null,
                        ["assigned_user_id" => $user->id],
                        [
                            "customer_care_id" => $customerCare->id,
                            "assignment_id" => $assignment->id,
                            "source_type" => CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY,
                            "source_id" => $opportunity->id,
                            "assigned_at" => $assignment->assigned_at->toISOString(),
                            "assignee_user_id" => $assignment->assignee_user_id,
                            "assignee_pancake_user_id" => $assignment->assignee_pancake_user_id,
                        ]
                    );
                }

                ImportedOpportunity::whereIn('id', $lockedOpportunities->pluck('id'))
                    ->update(["status" => 1]);

                return $lockedOpportunities->count();
            });

            $total_ids = $ids->count();

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
