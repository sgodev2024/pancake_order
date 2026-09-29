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
use App\Services\CustomerCareWriteAccessService;
use App\Services\ShopAccessService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class ImportedOpportunityController extends Controller
{
    public function __construct(
        private readonly CustomerCareAssignmentService $customerCareAssignmentService,
        private readonly ActivityLogService $activityLogService,
        private readonly ShopAccessService $shopAccessService,
        private readonly CustomerCareWriteAccessService $customerCareWriteAccessService
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $requestedShopId = $this->shopAccessService->authorizeRequestedShopId(
            $user,
            $request->filled('shop_id') ? $request->integer('shop_id') : null
        );

        try {
            $inputs = $request->only('page', 'date_from', 'date_to');
            $queries = ImportedOpportunity::query();
            $queries->where('status', 0);
            $queries->with(['shop' => fn ($q) => $q->select('id', 'name')]);

            if (isset($inputs['date_from'])) {
                $queries->where('created_at', '>=', $inputs['date_from'].' 00:00:00');
            }
            if (isset($inputs['date_to'])) {
                $queries->where('created_at', '<=', $inputs['date_to'].' 23:59:59');
            }
            if ($requestedShopId !== null) {
                $queries->where('shop_id', $requestedShopId);
            } elseif (! $this->shopAccessService->isGlobal($user)) {
                $queries->whereIn('shop_id', $this->shopAccessService->ids($user));
            }

            $queries->latest('created_at');
            $opportunities = $queries->paginate(30, ['*'], 'page', $inputs['page'] ?? 1);

            return response()->json([
                'success' => true,
                'data' => [
                    'orders' => $opportunities->items(),
                    'current_page' => $opportunities->currentPage(),
                    'per_page' => $opportunities->perPage(),
                    'total_items' => $opportunities->total(),
                    'total_pages' => $opportunities->lastPage(),
                ],
            ]);
        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => 'Đã có lỗi xảy ra: '.$th->getMessage()], 500);
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
                'shop_id' => 'required|exists:shops,id',
            ]);
            $actor = $request->user();
            if ($actor === null) {
                throw new AuthorizationException('Unauthenticated.');
            }
            // There is no dedicated import permission in the current permission
            // catalog. view-chance is the narrowest existing opportunity feature.
            if (! $actor->isAdmin() && ! can_access('view-chance')) {
                throw new AuthorizationException('You do not have opportunity import access.');
            }
            $this->shopAccessService->authorizeRequestedShopId($actor, (int) $request->input('shop_id'));
            $request->validate([
                'file' => 'required|file|mimes:xlsx,xls,csv',
            ]);

            $import = new OpportunityImport((int) $request->shop_id, $actor->getKey());
            DB::transaction(function () use ($import, $request) {
                Excel::import($import, $request->file('file'));

                $failureMessages = $import->failureMessages();
                if ($failureMessages !== []) {
                    $message = "Không thể import vì file Excel có thông tin chưa hợp lệ:\n"
                        .implode("\n", $failureMessages);
                    throw ValidationException::withMessages(['file' => [$message]]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => "Đã import thành công {$import->importedCount} khách hàng",
                'data' => ['imported_count' => $import->importedCount],
            ]);
        } catch (AuthorizationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->errors()['file'][0] ?? $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => 'Đã có lỗi xảy ra: '.$th->getMessage()], 500);
        }
    }

    public function assign(Request $request)
    {
        try {
            $inputs = $request->only('pancake_user_ids', 'ids');
            $ids = collect($inputs['ids'] ?? [])
                ->filter(fn ($id) => is_int($id) || is_string($id))
                ->unique()
                ->values();
            $pancake_user_id = $inputs['pancake_user_ids'][0] ?? null;

            if ($ids->isEmpty() || ! $pancake_user_id) {
                return response()->json(['success' => false, 'message' => 'Thiếu dữ liệu phân công']);
            }

            $user = User::where('pancake_user_id', $pancake_user_id)->first();

            if (! $user) {
                return response()->json(['success' => false, 'message' => 'Không tìm thấy người được phân công']);
            }

            if (! $user->canReceiveCustomerCareAssignments()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Người được phân công phải thuộc bộ phận CSKH.',
                ], 422);
            }

            $actor = auth()->user();
            if ($actor === null) {
                throw new AuthorizationException('Unauthenticated.');
            }
            $this->customerCareWriteAccessService->authorizeFeature($actor);
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

                foreach ($sourceShopIds as $sourceShopId) {
                    if (! $this->shopAccessService->canAccessShop($actor, (int) $sourceShopId)) {
                        throw new AuthorizationException('Bạn không có quyền phân công cơ hội thuộc cửa hàng này.');
                    }
                }

                foreach ($sourceShopIds as $sourceShopId) {
                    if (! $this->shopAccessService->canAccessShop($user, (int) $sourceShopId)) {
                        throw new DomainException(
                            "Các khách hàng bạn phân công không thuộc cửa hàng mà {$user->name} nằm trong"
                        );
                    }
                }

                foreach ($lockedOpportunities as $opportunity) {
                    if ($opportunity->shop === null) {
                        throw new DomainException('Không thể xác định cửa hàng của cơ hội nhập để lên lịch CSKH.');
                    }

                    $scheduledOn = CustomerCareAssignment::calculateScheduledCareDate(
                        $assignedAt,
                        $opportunity->shop->normalizedCareCycleDays()
                    );
                    $pancakeCustomerId = 'IMPORT-'.$opportunity->id;
                    $customerCare = CustomerCare::create([
                        'shop_id' => $opportunity->shop_id,
                        'pancake_customer_id' => $pancakeCustomerId,
                        'customer_phones' => $opportunity->phone,
                        'customer_name' => $opportunity->name,
                        'customer_addresss' => $opportunity->address,
                        'pancake_order_id' => null,
                        'date_care' => $scheduledOn->toDateString(),
                        'user_creator_id' => $actor->pancake_user_id,
                    ]);

                    $assignment = $this->customerCareAssignmentService->createWithJourneyEvent(
                        $customerCare,
                        (int) $opportunity->shop_id,
                        CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY,
                        (int) $opportunity->id,
                        $user,
                        $assignedAt,
                        $scheduledOn,
                        $actor,
                        $opportunity->shop?->name
                    );
                }

                ImportedOpportunity::whereIn('id', $lockedOpportunities->pluck('id'))
                    ->update(['status' => 1]);

                return $lockedOpportunities->count();
            });

            $total_ids = $ids->count();

            return response()->json(['success' => true,
                'message' => $total_opportunities == $total_ids
                    ? 'Phân công thành công'
                    : 'Phân công thành công '.$total_opportunities.' khách hàng. Còn lại '.($total_ids - $total_opportunities).' khách hàng không thuộc cửa hàng mà '.$user->name.' nằm trong',
            ]);
        } catch (AuthorizationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (\Throwable $th) {
            return response()->json(['success' => false, 'message' => $th->getMessage()]);
        }
    }
}
