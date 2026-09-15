<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CustomerCareListAccessMiddleware;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\CustomerCareAssignmentService;
use App\Services\CustomerCareCompletionEventWriter;
use App\Services\CustomerCareListQuery;
use App\Services\CustomerCareOrderSourceService;
use App\Services\CustomerCareReclaimService;
use App\Services\CustomerCareWriteAccessService;
use App\Services\ShopAccessService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerCareController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly CustomerCareAssignmentService $customerCareAssignmentService,
        private readonly ActivityLogService $activityLogService,
        private readonly ShopAccessService $shopAccessService,
        private readonly CustomerCareWriteAccessService $customerCareWriteAccessService,
        private readonly ?CustomerCareCompletionEventWriter $customerCareCompletionEventWriter = null
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware(CustomerCareListAccessMiddleware::class, only: ['index', 'getHistory', 'getOrder']),
        ];
    }

    public function index(Request $request)
    {
        $request->validate([
            'order_page_id' => ['nullable', 'string', 'max:255'],
            'context' => ['nullable', 'in:v2'],
        ]);
        $user = $request->user();
        $isV2Summary = $request->query('context') === 'v2';
        $requestedShopId = $this->shopAccessService->authorizeRequestedShopId(
            $user,
            $request->filled('shop_id') ? $request->integer('shop_id') : null
        );

        try {
            $inputs = $request->only(
                'type',
                'page',
                'shop_id',
                'status',
                'user_id',
                'is_accept',
                'is_confirm_care',
                'search',
                'order_page_id'
            );
            $inputs['shop_id'] = $requestedShopId;
            $sources = app(CustomerCareOrderSourceService::class);
            $query = app(CustomerCareListQuery::class)->query($inputs['type'], $user, $inputs);
            if ($request->filled('order_page_id')) {
                $sources->filter($query, $inputs['order_page_id']);
            }

            if ($isV2Summary) {
                // Keep relation keys internally, then hide them from the V2 JSON shape.
                $query->select([
                    'customer_cares.id',
                    'customer_cares.shop_id',
                    'customer_cares.pancake_customer_id',
                    'customer_cares.customer_phones',
                    'customer_cares.customer_name',
                    'customer_cares.customer_addresss',
                    'customer_cares.pancake_order_id',
                    'customer_cares.date_care',
                    'customer_cares.time_care',
                    'customer_cares.note',
                    'customer_cares.user_creator_id',
                    'customer_cares.user_care_id',
                    'customer_cares.user_assigning_seller_id',
                    'customer_cares.status',
                    'customer_cares.reason',
                    'customer_cares.is_accept',
                ]);
            }

            $assignmentColumns = [
                'id',
                'customer_care_id',
                'shop_id',
                'source_type',
                'source_id',
                'assignee_user_id',
                'status',
                'cared_at',
            ];
            $relations = [
                'shop' => function ($q) {
                    $q->select('shops.id', 'shops.name')
                        ->with([
                            'managers' => function ($query) {
                                $query->select('users.id', 'users.name');
                            },
                        ]);
                },
            ];
            if ($isV2Summary) {
                $relations['assignments'] = fn ($assignment) => $assignment->select($assignmentColumns);
            } else {
                $relations[] = 'activeAssignments.assignee:id,name';
                $relations[] = 'user_creator';
                $relations[] = 'user_care';
                $relations[] = 'user_assigning';
            }

            $result = $query
                ->with($relations)
                ->paginate(30, ['*'], 'page', $inputs['page']);

            if ($isV2Summary) {
                $this->attachV2UsersAndAssignmentAssignees($result->getCollection());
            }
            $sources->attach($result->getCollection());
            $this->attachCurrentAssignments($result->getCollection());
            if ($isV2Summary) {
                $this->prepareV2Summary($result->getCollection());
            } else {
                $this->attachLegacyV1DisplayCompatibility($result->getCollection());
                $this->attachAssignableOrderIds($result->getCollection());
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'customers' => $result->items(),
                    'current_page' => $result->currentPage(),
                    'per_page' => $result->perPage(),
                    'total_items' => $result->total(),
                    'total_pages' => $result->lastPage(),
                ],
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng thử lại'.$th->getMessage(),
            ]);
        }
    }

    public function update(Request $request, CustomerCare $customer_care)
    {
        try {
            if (! $customer_care) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tồn tại',
                ]);
            }
            $actor = $request->user() ?? auth()->user();
            if ($actor === null) {
                throw new AuthorizationException('Unauthenticated.');
            }
            $note = $request->note ?? null;
            $time_care = $request->date ?? null;
            $is_admin = $actor->isAdmin() || $actor->isManagerCskh();
            $is_care_completion = (int) $request->input('status') === 1;
            $customer_care = DB::transaction(function () use (
                $customer_care,
                $request,
                $note,
                $time_care,
                $is_admin,
                $is_care_completion,
                $actor
            ) {
                $lockedCustomerCare = CustomerCare::query()
                    ->whereKey($customer_care->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->customerCareWriteAccessService->authorize(
                    $actor,
                    $lockedCustomerCare,
                    $is_care_completion ? 'completion' : 'update'
                );
                $this->customerCareWriteAccessService->ensureSourceConsistency($lockedCustomerCare);

                $activeAssignment = $is_care_completion
                    ? $this->guardCurrentCareMutation($lockedCustomerCare, $actor)
                    : null;

                if ($is_care_completion
                    && ((int) $lockedCustomerCare->status === 1
                        || $activeAssignment?->cared_at !== null)) {
                    throw new DomainException('CustomerCare này đã được hoàn tất.');
                }

                $persistedCareTime = $is_care_completion
                    ? now(config('app.timezone'))
                    : ($lockedCustomerCare->time_care !== null
                        ? $lockedCustomerCare->time_care
                        : $time_care);

                $lockedCustomerCare->update([
                    'status' => $request->status,
                    'note' => $note,
                    'time_care' => $persistedCareTime,
                    'is_accept' => ($lockedCustomerCare->total_edit == 0 || $is_admin) ? 1 : 0,
                    'total_edit' => $lockedCustomerCare->total_edit + 1,
                ]);

                $lockedCustomerCare->refresh();
                if ($is_care_completion) {
                    $completedAssignment = $this->customerCareAssignmentService->markAsCared(
                        $lockedCustomerCare,
                        $persistedCareTime
                    );

                    if ($completedAssignment === null) {
                        throw new DomainException(
                            'CustomerCare không có phân công đang hoạt động để ghi nhận hoàn tất.'
                        );
                    }

                    $this->completionEventWriter()->write(
                        $lockedCustomerCare,
                        $completedAssignment,
                        $actor,
                        $persistedCareTime
                    );
                }

                if (! empty($request->next_date_care)) {
                    CustomerCare::create([
                        'shop_id' => $lockedCustomerCare->shop_id,
                        'pancake_customer_id' => $lockedCustomerCare->pancake_customer_id,
                        'pancake_order_id' => $lockedCustomerCare->pancake_order_id,
                        'customer_phones' => $lockedCustomerCare->customer_phones,
                        'customer_name' => $lockedCustomerCare->customer_name,
                        'customer_addresss' => $lockedCustomerCare->customer_addresss,
                        'date_care' => $request->next_date_care,
                        'user_creator_id' => $lockedCustomerCare->user_creator_id,
                        // "user_care_id"              => $lockedCustomerCare->user_care_id,
                        // "user_assigning_seller_id"  => $lockedCustomerCare->user_assigning_seller_id
                    ]);
                }

                return $lockedCustomerCare;
            });

            return response()->json([
                'success' => true,
                'message' => ($customer_care->status == 1 && $request->status == 0 && ! $is_admin) ? 'Đợi duyệt' : 'Cập nhật thành công',
            ]);
        } catch (AuthorizationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 403);
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 409);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage(),
            ]);
        }
    }

    public function accept($id, Request $request)
    {
        try {
            $actor = $request->user() ?? auth()->user();
            if ($actor === null) {
                throw new AuthorizationException('Unauthenticated.');
            }
            $request->validate([
                'is_accept' => ['required', 'boolean'],
                'reason' => ['nullable', 'string'],
            ]);
            DB::transaction(function () use ($id, $request, $actor) {
                $customerCare = CustomerCare::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $this->customerCareWriteAccessService->authorize($actor, $customerCare, 'accept');
                $this->customerCareWriteAccessService->ensureSourceConsistency($customerCare);
                $customerCare->update([
                    'is_accept' => $request->boolean('is_accept'),
                    'reason' => $request->input('reason'),
                    'user_accept_id' => $actor->getKey(),
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Duyệt thành công',
            ]);
        } catch (AuthorizationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        } catch (ValidationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function getHistory(Request $request, $id)
    {
        $customerCare = $this->authorizeCustomerCareRead($request, $id);
        $historyQuery = CustomerCare::query()
            ->with([
                'shop:id,name',
                'user_creator',
            ]);

        if (! $this->shopAccessService->isGlobal($request->user())) {
            $historyQuery->where('shop_id', $customerCare->shop_id);
        }

        if ($this->hasUsablePancakeCustomerId($customerCare)) {
            $historyQuery->where('pancake_customer_id', $customerCare->pancake_customer_id);
        } else {
            // A blank external customer ID is not a safe grouping key.
            $historyQuery->whereKey($customerCare->getKey());
        }

        $this->applyCustomerCareReadScope($historyQuery, $request->user());

        return response()->json([
            'success' => true,
            'data' => $historyQuery->get(),
        ]);
    }

    public function getOrder(Request $request, $id)
    {
        $customerCare = $this->authorizeCustomerCareRead($request, $id);
        $actor = $request->user();

        $this->ensureCustomerCareOrderLinkIsConsistent($customerCare, $actor);

        $ordersQuery = Order::query()
            ->with([
                'shop:id,name',
                'user_creator',
                'user_care',
                'user_assigning',
            ]);

        if ($this->hasUsablePancakeCustomerId($customerCare)) {
            $ordersQuery->where('pancake_customer_id', $customerCare->pancake_customer_id);
        } else {
            // A blank external customer ID is not a safe grouping key.
            $ordersQuery->whereRaw('1 = 0');
        }

        if (! $this->shopAccessService->isGlobal($actor)) {
            $ordersQuery->where('shop_id', $customerCare->shop_id);
            $this->applyOrderReadScope($ordersQuery, $actor);
        }

        return response()->json([
            'success' => true,
            'data' => $ordersQuery->get(),
        ]);
    }

    private function authorizeCustomerCareRead(Request $request, int|string $id): CustomerCare
    {
        $customerCare = CustomerCare::query()->findOrFail($id);
        $actor = $request->user();

        if (! $this->shopAccessService->canAccessShop($actor, (int) $customerCare->shop_id)) {
            throw new AuthorizationException('You do not have access to this CustomerCare shop.');
        }

        if ($actor->isAdmin() || $actor->isManagerCskh()) {
            return $customerCare;
        }

        if (! $actor->isStaffCskh()) {
            throw new AuthorizationException('You do not have CustomerCare read access.');
        }

        $activeAssignments = CustomerCareAssignment::query()
            ->where('customer_care_id', $customerCare->getKey())
            ->where('status', CustomerCareAssignment::STATUS_ACTIVE)
            ->get(['id', 'shop_id', 'assignee_user_id']);

        if ($activeAssignments->isNotEmpty()) {
            if ($activeAssignments->count() !== 1
                || (int) $activeAssignments->first()->shop_id !== (int) $customerCare->shop_id
                || (int) $activeAssignments->first()->assignee_user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('This CustomerCare task is not assigned to you.');
            }

            return $customerCare;
        }

        $actorPancakeUserId = trim((string) $actor->pancake_user_id);
        $hasLegacyOwnership = $actorPancakeUserId !== '' && (
            $customerCare->user_creator_id === $actorPancakeUserId
            || $customerCare->user_care_id === $actorPancakeUserId
            || $customerCare->user_assigning_seller_id === $actorPancakeUserId
            || $customerCare->users()
                ->where('users.pancake_user_id', $actorPancakeUserId)
                ->exists()
        );

        if (! $hasLegacyOwnership) {
            throw new AuthorizationException('This CustomerCare task is not assigned to you.');
        }

        return $customerCare;
    }

    private function ensureCustomerCareOrderLinkIsConsistent(CustomerCare $customerCare, User $actor): void
    {
        if ($this->shopAccessService->isGlobal($actor)
            || $customerCare->pancake_order_id === null
            || trim((string) $customerCare->pancake_order_id) === '') {
            return;
        }

        $hasCrossShopSourceOrder = Order::query()
            ->where('pancake_order_id', $customerCare->pancake_order_id)
            ->where('shop_id', '!=', $customerCare->shop_id)
            ->exists();

        if ($hasCrossShopSourceOrder) {
            throw new HttpException(409, 'CustomerCare source order belongs to a different shop.');
        }
    }

    private function applyCustomerCareReadScope($query, User $actor): void
    {
        if ($actor->isAdmin() || $actor->isManagerCskh()) {
            return;
        }

        $actorPancakeUserId = trim((string) $actor->pancake_user_id);
        if ($actorPancakeUserId === '') {
            $query->whereRaw('1 = 0');

            return;
        }

        $activeAssignmentExists = static function ($assignmentQuery): void {
            $assignmentQuery->selectRaw('1')
                ->from('customer_care_assignments as direct_read_active_assignment')
                ->whereColumn(
                    'direct_read_active_assignment.customer_care_id',
                    'customer_cares.id'
                )
                ->where(
                    'direct_read_active_assignment.status',
                    CustomerCareAssignment::STATUS_ACTIVE
                );
        };

        $query->where(function ($scope) use ($actor, $actorPancakeUserId, $activeAssignmentExists) {
            $scope->where(function ($assignedScope) use ($actor) {
                $assignedScope->whereExists(function ($assignmentQuery) use ($actor) {
                    $assignmentQuery->selectRaw('1')
                        ->from('customer_care_assignments as direct_read_actor_assignment')
                        ->whereColumn(
                            'direct_read_actor_assignment.customer_care_id',
                            'customer_cares.id'
                        )
                        ->whereColumn(
                            'direct_read_actor_assignment.shop_id',
                            'customer_cares.shop_id'
                        )
                        ->where(
                            'direct_read_actor_assignment.status',
                            CustomerCareAssignment::STATUS_ACTIVE
                        )
                        ->where(
                            'direct_read_actor_assignment.assignee_user_id',
                            $actor->getKey()
                        );
                })->whereRaw(
                    '(SELECT COUNT(*) FROM customer_care_assignments AS direct_read_assignment_count
                      WHERE direct_read_assignment_count.customer_care_id = customer_cares.id
                        AND direct_read_assignment_count.status = ?) = 1',
                    [CustomerCareAssignment::STATUS_ACTIVE]
                );
            })->orWhere(function ($legacyScope) use ($actorPancakeUserId, $activeAssignmentExists) {
                $legacyScope->whereNotExists($activeAssignmentExists)
                    ->where(function ($ownershipScope) use ($actorPancakeUserId) {
                        $ownershipScope->where('user_creator_id', $actorPancakeUserId)
                            ->orWhere('user_care_id', $actorPancakeUserId)
                            ->orWhere('user_assigning_seller_id', $actorPancakeUserId)
                            ->orWhereHas('users', function ($userQuery) use ($actorPancakeUserId) {
                                $userQuery->where('users.pancake_user_id', $actorPancakeUserId);
                            });
                    });
            });
        });
    }

    private function applyOrderReadScope($query, User $actor): void
    {
        if ($actor->isAdmin() || $actor->isManagerSale() || $actor->isManagerCskh()) {
            return;
        }

        $query->where(function ($scope) use ($actor) {
            $scope->where('user_creator_id', $actor->pancake_user_id)
                ->orWhere('user_care_id', $actor->pancake_user_id);
        });
    }

    private function hasUsablePancakeCustomerId(CustomerCare $customerCare): bool
    {
        return $customerCare->pancake_customer_id !== null
            && trim((string) $customerCare->pancake_customer_id) !== '';
    }

    public function destroy(Request $request, CustomerCare $customer_care)
    {
        $actor = $request->user() ?? auth()->user();
        if ($actor === null) {
            throw new AuthorizationException('Unauthenticated.');
        }
        DB::transaction(function () use ($actor, $customer_care) {
            $lockedCustomerCare = CustomerCare::query()->whereKey($customer_care->getKey())->lockForUpdate()->firstOrFail();
            $this->customerCareWriteAccessService->authorize($actor, $lockedCustomerCare, 'delete');
            $this->customerCareWriteAccessService->ensureSourceConsistency($lockedCustomerCare);
            $lockedCustomerCare->delete();
        });

        // Trả về response
        return response()->json([
            'success' => true,
            'message' => 'Đã xóa thành công',
        ], 200); // Có thể dùng 204 No Content nếu không muốn trả về body
    }

    /**Số lượng có thể sẽ khác so với khi đi vào trong chi tiết từng cục vì trong chi tiết không where vào is_accept = 1, mục đích để có thể duyệt sửa bên trong */
    public function overview()
    {
        try {
            $today = today()->format('Y-m-d');
            $startOfWeek = Carbon::now()->startOfWeek()->format('Y-m-d');
            $endOfWeek = Carbon::now()->endOfWeek()->format('Y-m-d');
            $user = auth()->user();
            $shop_ids = $user->shops()->pluck('shops.id');
            $taskBaseQuery = CustomerCare::query();
            $this->applyOverviewAccessScope($taskBaseQuery, $user, $shop_ids, true);

            $taskOverview = (clone $taskBaseQuery)->actionable()->selectRaw('
                SUM(CASE WHEN date_care = ? AND is_accept = 1 THEN 1 ELSE 0 END) as customer_care_today,
                SUM(CASE WHEN date_care = ? AND status = 1 AND is_accept = 1 THEN 1 ELSE 0 END) as customer_care_today_done,

                SUM(CASE WHEN date_care > ? AND is_accept = 1 THEN 1 ELSE 0 END) as customer_care_pending,
                SUM(CASE WHEN date_care > ? AND status = 1 AND is_accept = 1 THEN 1 ELSE 0 END) as customer_care_pending_done,

                SUM(CASE WHEN date_care BETWEEN ? AND ? AND is_accept = 1 THEN 1 ELSE 0 END) as customer_care_in_week,
                SUM(CASE WHEN date_care BETWEEN ? AND ? AND is_accept = 1 AND status = 1 THEN 1 ELSE 0 END) as customer_care_in_week_done,

                SUM(CASE
                    WHEN date_care < ?
                    AND (status = 0 OR DATE(time_care) > date_care)
                    AND is_accept = 1
                    THEN 1 ELSE 0
                END) as customer_care_expire,

                SUM(CASE
                    WHEN date_care < ?
                    AND status = 1
                    AND DATE(time_care) > date_care
                    AND is_accept = 1
                    THEN 1 ELSE 0
                END) as customer_care_expire_done
            ', [
                // today
                $today,
                $today,

                // pending
                $today,
                $today,

                // week
                $startOfWeek,
                $endOfWeek,

                $startOfWeek,
                $endOfWeek,

                // expire
                $today,
                $today,
            ])
                ->first();
            $editBaseQuery = CustomerCare::query();
            $this->applyOverviewAccessScope($editBaseQuery, $user, $shop_ids, false);
            $editOverview = $editBaseQuery->selectRaw('
                SUM(CASE WHEN total_edit > 1 THEN 1 ELSE 0 END) as customer_care_edit,
                SUM(CASE WHEN total_edit > 1 AND is_accept = 1 THEN 1 ELSE 0 END) as customer_care_edit_accepted
            ')->first();
            $date_start = date('Y-m-d 00:00:00');
            $date_end = date('Y-m-d 23:59:59');
            $query = Order::whereBetween('created_at', [$date_start, $date_end])
                ->where(function ($q) use ($user, $shop_ids) {
                    if (! $user->isAdmin()) {
                        $q->whereIn('shop_id', $shop_ids);
                        if (! $user->isManagerSale() && ! $user->isManagerCskh()) {
                            $q->where(function ($q1) use ($user) {
                                $user_id = $user->pancake_user_id ?? $user->id;
                                $q1->where(function ($q2) use ($user_id) {
                                    $q2->where('user_creator_id', $user_id)
                                        ->orWhere('user_care_id', $user_id)
                                        ->orWhere('user_assigning_seller_id', $user_id);
                                });
                            });
                        }
                    }
                })
                ->selectRaw('
                                COUNT(*) as total_order_today,
                                COALESCE(SUM(cod), 0) as total_revenue
                            ')
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'customer_care_today' => (int) $taskOverview->customer_care_today,
                    'customer_care_today_done' => (int) $taskOverview->customer_care_today_done,
                    'customer_care_pending' => (int) $taskOverview->customer_care_pending,
                    'customer_care_pending_done' => (int) $taskOverview->customer_care_pending_done,
                    'customer_care_in_week' => (int) $taskOverview->customer_care_in_week,
                    'customer_care_in_week_done' => (int) $taskOverview->customer_care_in_week_done,
                    'customer_care_expire' => (int) $taskOverview->customer_care_expire,
                    'customer_care_expire_done' => (int) $taskOverview->customer_care_expire_done,
                    'customer_care_edit' => (int) $editOverview->customer_care_edit,
                    'customer_care_edit_accepted' => (int) $editOverview->customer_care_edit_accepted,
                    'total_order_today' => $query->total_order_today,
                    'total_revenue' => $query->total_revenue,
                ],
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng thử lại',
            ]);
        }
    }

    public function assign(Request $request, $order_id)
    {
        try {
            $inputs = $request->only(
                'pancake_user_ids',
                'is_multiple',
                'order_ids'
            );

            $is_multiple = filter_var($inputs['is_multiple'] ?? false, FILTER_VALIDATE_BOOLEAN);

            // Public route is kept for compatibility; this parameter is an Order ID.
            $order_ids = $is_multiple
                ? ($inputs['order_ids'] ?? [])
                : [$order_id];
            $order_ids = collect(is_array($order_ids) ? $order_ids : [])
                ->filter(fn ($id) => is_int($id) || is_string($id))
                ->unique()
                ->values();
            $pancake_user_id = $inputs['pancake_user_ids'][0] ?? null; // chỉ lấy 1 item thôi, vì bên FE là radio

            if ($order_ids->isEmpty() || ! $pancake_user_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Thiếu dữ liệu phân công',
                ]);
            }

            $user = User::where('pancake_user_id', $pancake_user_id)->first();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy người được phân công',
                ]);
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

            $total_orders = DB::transaction(function () use ($order_ids, $actor, $user, $assignedAt) {
                $requestedOrders = Order::query()
                    ->whereIn('id', $order_ids)
                    ->get(['id', 'pancake_order_id']);

                if ($requestedOrders->count() !== $order_ids->count()) {
                    throw new DomainException('Một hoặc nhiều cơ hội không tồn tại.');
                }

                $logicalOrderIds = $requestedOrders
                    ->pluck('pancake_order_id')
                    ->unique()
                    ->values();

                if ($logicalOrderIds->count() !== $order_ids->count()
                    || $logicalOrderIds->contains(null)
                    || $logicalOrderIds->contains('')) {
                    throw new DomainException('Không thể phân công nhiều bản ghi của cùng một đơn Pancake.');
                }

                $lockedLogicalOrders = Order::query()
                    ->whereIn('pancake_order_id', $logicalOrderIds)
                    ->whereNull('deleted_at')
                    ->with(['shop' => function ($query) {
                        $query->select('id', 'name', 'care_cycle_days');
                    }])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $lockedOrders = $lockedLogicalOrders
                    ->whereIn('id', $order_ids)
                    ->values();

                if ($lockedOrders->count() !== $order_ids->count()) {
                    throw new DomainException('Một hoặc nhiều cơ hội không tồn tại.');
                }

                if ($lockedOrders->contains(fn (Order $order) => (int) $order->status !== 3)) {
                    throw new DomainException('Chỉ đơn hàng ở trạng thái Đã nhận mới được phân công từ Cơ hội.');
                }

                $hasActiveLogicalAssignment = CustomerCareAssignment::query()
                    ->where('source_type', CustomerCareAssignment::SOURCE_ORDER)
                    ->where('status', CustomerCareAssignment::STATUS_ACTIVE)
                    ->whereIn('source_id', $lockedLogicalOrders->pluck('id'))
                    ->lockForUpdate()
                    ->exists();

                if ($hasActiveLogicalAssignment) {
                    throw new DomainException('Đơn Pancake này đã có phân công CSKH đang hoạt động.');
                }

                $sourceShopIds = $lockedOrders->pluck('shop_id')->unique()->values();

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

                foreach ($lockedOrders as $order_item) {
                    if ($order_item->shop === null) {
                        throw new DomainException('Không thể xác định cửa hàng của cơ hội để lên lịch CSKH.');
                    }

                    $scheduledOn = CustomerCareAssignment::calculateScheduledCareDate(
                        $assignedAt,
                        $order_item->shop->normalizedCareCycleDays()
                    );
                    $customerCare = CustomerCare::create([
                        'shop_id' => $order_item->shop_id,
                        'pancake_customer_id' => $order_item->pancake_customer_id,
                        'customer_phones' => $order_item->customer_phone,
                        'customer_name' => $order_item->customer_name,
                        'customer_addresss' => $order_item->customer_address,
                        'pancake_order_id' => $order_item->pancake_order_id,
                        'date_care' => $scheduledOn->toDateString(),
                        'user_creator_id' => $actor->pancake_user_id,
                    ]);

                    $assignment = $this->customerCareAssignmentService->createWithJourneyEvent(
                        $customerCare,
                        (int) $order_item->shop_id,
                        CustomerCareAssignment::SOURCE_ORDER,
                        (int) $order_item->id,
                        $user,
                        $assignedAt,
                        $scheduledOn,
                        $actor,
                        $order_item->shop?->name
                    );
                }

                return $lockedOrders->count();
            }, 3);
            $total_order_id = $order_ids->count();

            return response()->json([
                'success' => true,
                'message' => $total_orders == $total_order_id ?
                            'Phân công thành công' :
                            'Phân công thành công '.$total_orders.' khách hàng. Còn lại '.($total_order_id - $total_orders).' khách hàng không thuộc cửa hạng mà '.$user->name.' nằm trong',
            ]);
        } catch (AuthorizationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage(),
            ]);
        }
    }

    private function guardCurrentCareMutation(CustomerCare $customerCare, User $actor): CustomerCareAssignment
    {
        $activeAssignments = CustomerCareAssignment::query()
            ->where('customer_care_id', $customerCare->getKey())
            ->where('customer_care_assignments.status', CustomerCareAssignment::STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();

        if ($activeAssignments->count() !== 1) {
            throw new DomainException(
                'CustomerCare này không có đúng một phân công đang hoạt động để hoàn tất.'
            );
        }

        $assignment = $activeAssignments->first();

        if ((int) $assignment->shop_id !== (int) $customerCare->shop_id) {
            throw new DomainException(
                'Phân công CSKH không thuộc cùng cửa hàng với CustomerCare.'
            );
        }

        return $assignment;
    }

    private function completionEventWriter(): CustomerCareCompletionEventWriter
    {
        return $this->customerCareCompletionEventWriter
            ?? new CustomerCareCompletionEventWriter($this->activityLogService);
    }

    private function attachAssignableOrderIds($customerCares): void
    {
        if ($customerCares->isEmpty()) {
            return;
        }

        $careIds = $customerCares->pluck('id');
        $pancakeOrderIds = $customerCares->pluck('pancake_order_id')
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->unique()
            ->values();

        $activeSourcesByCare = CustomerCareAssignment::query()
            ->join('orders as assignment_orders', 'assignment_orders.id', '=', 'customer_care_assignments.source_id')
            ->whereIn('customer_care_assignments.customer_care_id', $careIds)
            ->where('customer_care_assignments.source_type', CustomerCareAssignment::SOURCE_ORDER)
            ->where('customer_care_assignments.status', CustomerCareAssignment::STATUS_ACTIVE)
            ->whereNull('assignment_orders.deleted_at')
            ->get([
                'customer_care_assignments.customer_care_id',
                'customer_care_assignments.source_id',
            ])
            ->groupBy('customer_care_id');

        $uniqueOrdersByPancakeId = $pancakeOrderIds->isEmpty()
            ? collect()
            : Order::query()
                ->whereIn('pancake_order_id', $pancakeOrderIds)
                ->whereNull('deleted_at')
                ->selectRaw('pancake_order_id, MIN(id) as id, COUNT(*) as order_count')
                ->groupBy('pancake_order_id')
                ->get()
                ->keyBy('pancake_order_id');

        foreach ($customerCares as $customerCare) {
            $activeSources = $activeSourcesByCare->get($customerCare->id, collect());

            if ($activeSources->count() === 1) {
                $customerCare->setAttribute('assignable_order_id', (int) $activeSources->first()->source_id);

                continue;
            }

            if ($activeSources->isNotEmpty()) {
                $customerCare->setAttribute('assignable_order_id', null);

                continue;
            }

            $uniqueOrder = $uniqueOrdersByPancakeId->get($customerCare->pancake_order_id);
            $customerCare->setAttribute(
                'assignable_order_id',
                $uniqueOrder !== null && (int) $uniqueOrder->order_count === 1
                    ? (int) $uniqueOrder->id
                    : null
            );
        }
    }

    private function prepareV2Summary($customerCares): void
    {
        foreach ($customerCares as $customerCare) {
            $customerCare->unsetRelation('assignments');
            $customerCare->makeHidden([
                'user_creator_id',
                'user_care_id',
                'user_assigning_seller_id',
            ]);

            foreach (['user_creator', 'user_care', 'user_assigning'] as $relation) {
                if ($customerCare->relationLoaded($relation) && $customerCare->getRelation($relation) !== null) {
                    $customerCare->getRelation($relation)->setVisible(['id', 'name']);
                }
            }

            if ($customerCare->relationLoaded('shop') && $customerCare->getRelation('shop') !== null) {
                $shop = $customerCare->getRelation('shop');
                $shop->setVisible(['id', 'name', 'managers']);
                if ($shop->relationLoaded('managers')) {
                    $shop->getRelation('managers')->each(
                        fn ($manager) => $manager->setVisible(['id', 'name'])
                    );
                }
            }

            foreach (['activeAssignment', 'currentAssignment'] as $relation) {
                if (! $customerCare->relationLoaded($relation) || $customerCare->getRelation($relation) === null) {
                    continue;
                }

                $assignment = $customerCare->getRelation($relation);
                $assignment->setVisible([
                    'id',
                    'customer_care_id',
                    'shop_id',
                    'source_type',
                    'source_id',
                    'assignee_user_id',
                    'status',
                    'cared_at',
                    'assignee',
                    'source_order',
                ]);
                if ($assignment->relationLoaded('assignee') && $assignment->getRelation('assignee') !== null) {
                    $assignment->getRelation('assignee')->setVisible(['id', 'name']);
                }
                if ($assignment->relationLoaded('sourceOrder') && $assignment->getRelation('sourceOrder') !== null) {
                    $assignment->getRelation('sourceOrder')->setVisible(['id', 'status']);
                }
            }

            if ($customerCare->relationLoaded('order') && $customerCare->getRelation('order') !== null) {
                $customerCare->getRelation('order')->setVisible(['id', 'status']);
            }
        }
    }

    private function attachV2UsersAndAssignmentAssignees($customerCares): void
    {
        if ($customerCares->isEmpty()) {
            return;
        }

        $pancakeUserIds = $customerCares->flatMap(fn ($customerCare) => [
            $customerCare->user_creator_id,
            $customerCare->user_care_id,
            $customerCare->user_assigning_seller_id,
        ])->filter(fn ($id) => $id !== null && $id !== '')->unique()->values();
        $assigneeIds = $customerCares->flatMap(
            fn ($customerCare) => $customerCare->getRelation('assignments')->pluck('assignee_user_id')
        )->filter()->unique()->values();

        $users = $pancakeUserIds->isEmpty() && $assigneeIds->isEmpty()
            ? collect()
            : User::query()
                ->where(function ($query) use ($pancakeUserIds, $assigneeIds): void {
                    if ($pancakeUserIds->isNotEmpty()) {
                        $query->whereIn('pancake_user_id', $pancakeUserIds);
                    }
                    if ($assigneeIds->isNotEmpty()) {
                        $method = $pancakeUserIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                        $query->{$method}('id', $assigneeIds);
                    }
                })
                ->get(['id', 'name', 'pancake_user_id']);
        $usersById = $users->keyBy(fn ($user) => (string) $user->getKey());
        $usersByPancakeId = $users->keyBy(fn ($user) => (string) $user->pancake_user_id);

        foreach ($customerCares as $customerCare) {
            foreach ([
                'user_creator' => 'user_creator_id',
                'user_care' => 'user_care_id',
                'user_assigning' => 'user_assigning_seller_id',
            ] as $relation => $foreignKey) {
                $customerCare->setRelation(
                    $relation,
                    $usersByPancakeId->get((string) $customerCare->getAttribute($foreignKey))
                );
            }

            foreach ($customerCare->getRelation('assignments') as $assignment) {
                $assignment->setRelation(
                    'assignee',
                    $usersById->get((string) $assignment->assignee_user_id)
                );
            }
        }
    }

    private function attachCurrentAssignments($customerCares): void
    {
        if ($customerCares->isEmpty()) {
            return;
        }

        foreach ($customerCares as $customerCare) {
            $assignmentRelation = $customerCare->relationLoaded('assignments')
                ? 'assignments'
                : 'activeAssignments';
            $activeAssignments = $customerCare->getRelation($assignmentRelation)
                ->where('status', CustomerCareAssignment::STATUS_ACTIVE);
            $currentAssignments = $activeAssignments->whereNull('cared_at');
            $activeAssignmentCount = $activeAssignments->count();
            $isAmbiguous = $activeAssignmentCount > 1;

            if ($assignmentRelation === 'activeAssignments') {
                $customerCare->unsetRelation('activeAssignments');
            }
            $customerCare->setRelation(
                'activeAssignment',
                $activeAssignmentCount === 1 ? $activeAssignments->first() : null
            );
            $customerCare->setRelation(
                'currentAssignment',
                $activeAssignmentCount === 1 && $currentAssignments->count() === 1
                    ? $currentAssignments->first()
                    : null
            );
            $customerCare->setAttribute('current_assignment_ambiguous', $isAmbiguous);
        }
    }

    /**
     * The legacy V1 table reads user_assigning for "NV chăm sóc" and
     * shop.users for "Người quản lý".  Those two fields are compatibility
     * display data on this list only; CustomerCareAssignment remains the
     * authorization and ownership source of truth.
     */
    private function attachLegacyV1DisplayCompatibility($customerCares): void
    {
        if ($customerCares->isEmpty()) {
            return;
        }

        $assignmentCounts = CustomerCareAssignment::query()
            ->whereIn('customer_care_id', $customerCares->pluck('id'))
            ->selectRaw('customer_care_id, COUNT(*) as assignment_count')
            ->groupBy('customer_care_id')
            ->pluck('assignment_count', 'customer_care_id');

        foreach ($customerCares as $customerCare) {
            $legacyUserAssigning = $customerCare->getRelation('user_assigning');
            $displayUser = $this->resolveLegacyV1CareStaff(
                $customerCare,
                (int) $assignmentCounts->get($customerCare->id, 0) > 0
            );

            // Preserve V2's "Người phân công" value while repurposing the
            // V1-only stale field as a display alias for care staff.
            $customerCare->unsetRelation('user_assigning');
            $customerCare->setAttribute(
                'legacy_user_assigning',
                $this->compactUserIdentity($legacyUserAssigning)
            );
            $customerCare->setAttribute(
                'user_assigning',
                $this->compactUserIdentity($displayUser)
            );

            $shop = $customerCare->getRelation('shop');
            if ($shop !== null) {
                // V1 incorrectly labels shop.users as managers.  Only expose
                // the configured manager relation under that compatibility key.
                $shop->setRelation(
                    'users',
                    $shop->relationLoaded('managers') ? $shop->getRelation('managers') : collect()
                );
            }
        }
    }

    /**
     * Resolve the V1 care-staff alias without changing current ownership.
     * Ambiguous assignments and reclaimed/non-current CCA rows intentionally
     * do not fall through to a legacy user.
     */
    private function resolveLegacyV1CareStaff(CustomerCare $customerCare, bool $hasAssignment): ?User
    {
        if ($customerCare->getAttribute('current_assignment_ambiguous')) {
            return null;
        }

        $currentAssignment = $customerCare->getRelation('currentAssignment');
        if ($this->isCurrentAssignmentForCustomerCare($currentAssignment, $customerCare)) {
            return $currentAssignment->getRelation('assignee');
        }

        $activeAssignment = $customerCare->getRelation('activeAssignment');
        if (
            (int) $customerCare->status === 1
            && $this->isCompletedAssignmentForCustomerCare($activeAssignment, $customerCare)
        ) {
            return $activeAssignment->getRelation('assignee');
        }

        return $hasAssignment ? null : $customerCare->getRelation('user_care');
    }

    private function isCurrentAssignmentForCustomerCare(?CustomerCareAssignment $assignment, CustomerCare $customerCare): bool
    {
        return $assignment !== null
            && (int) $assignment->customer_care_id === (int) $customerCare->id
            && $assignment->status === CustomerCareAssignment::STATUS_ACTIVE
            && $assignment->cared_at === null
            && $assignment->getRelation('assignee') !== null;
    }

    private function isCompletedAssignmentForCustomerCare(?CustomerCareAssignment $assignment, CustomerCare $customerCare): bool
    {
        return $assignment !== null
            && (int) $assignment->customer_care_id === (int) $customerCare->id
            && $assignment->status === CustomerCareAssignment::STATUS_ACTIVE
            && $assignment->cared_at !== null
            && $assignment->getRelation('assignee') !== null;
    }

    private function compactUserIdentity(?User $user): ?array
    {
        return $user === null
            ? null
            : [
                'id' => $user->getKey(),
                'name' => $user->name,
            ];
    }

    private function applyOverviewAccessScope($query, $user, $shopIds, bool $currentTasks): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $query->whereIn('shop_id', $shopIds);

        if ($user->isManagerSale() || $user->isManagerCskh()) {
            return;
        }

        if ($currentTasks) {
            $query->where(function ($ownershipQuery) use ($user) {
                $ownershipQuery->whereHas('activeAssignment', function ($assignmentQuery) use ($user) {
                    $assignmentQuery->where('assignee_user_id', $user->getKey());
                })->orWhere(function ($legacyQuery) use ($user) {
                    $legacyQuery->whereDoesntHave('activeAssignment')
                        ->where(function ($legacyOwnershipQuery) use ($user) {
                            $legacyOwnershipQuery->where('user_creator_id', $user->pancake_user_id)
                                ->orWhere('user_care_id', $user->pancake_user_id)
                                ->orWhere('user_assigning_seller_id', $user->pancake_user_id)
                                ->orWhereHas('users', function ($userQuery) use ($user) {
                                    $userQuery->where('users.pancake_user_id', $user->pancake_user_id);
                                });
                        });
                });
            });

            return;
        }

        $query->where(function ($legacyOwnershipQuery) use ($user) {
            $legacyOwnershipQuery->where('user_creator_id', $user->pancake_user_id)
                ->orWhere('user_care_id', $user->pancake_user_id)
                ->orWhere('user_assigning_seller_id', $user->pancake_user_id)
                ->orWhereHas('users', function ($userQuery) use ($user) {
                    $userQuery->where('users.pancake_user_id', $user->pancake_user_id);
                });
        });
    }

    /**
     * Xác nhận cskh khi được phân công
     */
    public function confirmCare($customer_care_id, ?Request $request = null)
    {
        try {
            $actor = $request?->user() ?? auth()->user();
            if ($actor === null) {
                throw new AuthorizationException('Unauthenticated.');
            }
            DB::transaction(function () use ($customer_care_id, $actor) {
                $customerCare = CustomerCare::query()->whereKey($customer_care_id)->lockForUpdate()->firstOrFail();
                $this->customerCareWriteAccessService->authorize($actor, $customerCare, 'confirm');
                $this->customerCareWriteAccessService->ensureSourceConsistency($customerCare);
                $customerCare->update(['is_confirm_care' => true]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Nhận thành công',
            ]);
        } catch (AuthorizationException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 409);
        } catch (ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Lịch chăm sóc này không tồn tại'], 404);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage(),
            ]);
        }
    }

    /**
     * Manually return one active CustomerCare assignment to its opportunity pool.
     */
    public function reclaim(Request $request, int $id)
    {
        try {
            $reason = $request->input('reason');
            if (is_string($reason)) {
                $reason = trim($reason);
                $request->merge(['reason' => $reason === '' ? null : $reason]);
            }

            $validated = $request->validate([
                'reason' => ['nullable', 'string', 'max:500'],
            ]);

            $actor = $request->user();
            if ($actor === null) {
                throw new AuthorizationException('Unauthenticated.');
            }

            $result = app(CustomerCareReclaimService::class)->reclaimManually(
                $id,
                $actor,
                $validated['reason'] ?? null
            );

            if (($result['result'] ?? null) === CustomerCareReclaimService::RESULT_NOT_FOUND) {
                return response()->json([
                    'success' => false,
                    'message' => 'Lịch chăm sóc này không tồn tại',
                ], 404);
            }

            if (($result['result'] ?? null) !== CustomerCareReclaimService::RESULT_RECLAIMED) {
                return response()->json([
                    'success' => false,
                    'message' => 'Khách hàng không còn ở trạng thái có thể thu hồi.',
                ], 409);
            }

            return response()->json([
                'success' => true,
                'message' => 'Đã thu hồi khách hàng.',
                'data' => $result,
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ',
                'errors' => $exception->errors(),
            ], 422);
        } catch (AuthorizationException) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền thu hồi khách hàng này.',
            ], 403);
        } catch (\Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể thu hồi khách hàng. Vui lòng thử lại.',
            ], 500);
        }
    }

    public function customerAssignedByCustomer(Request $request)
    {
        $user = $request->user();
        $requestedShopId = $this->shopAccessService->authorizeRequestedShopId(
            $user,
            $request->filled('shop_id') ? $request->integer('shop_id') : null
        );
        $effectiveShopIds = $requestedShopId !== null
            ? collect([$requestedShopId])
            : (! $this->shopAccessService->isGlobal($user)
                ? $this->shopAccessService->ids($user)
                : null);

        try {
            $inputs = $request->only(
                'status',
                'user_id',
                'shop_id',
                'page'
            );
            $query = CustomerCare::query();
            if ($effectiveShopIds !== null) {
                $query->whereIn('shop_id', $effectiveShopIds);
            }
            if (isset($inputs['status'])) {
                $query->where('status', $inputs['status']);
            }
            $query->with(['users' => function ($q) {
                $q->select('users.pancake_user_id', 'users.id', 'users.name');
            }]);
            $query->whereHas('users', function ($q) use ($inputs) {
                if (isset($inputs['user_id'])) {
                    $q->where('users.pancake_user_id', $inputs['user_id']);
                }
            });
            // $query->select('customer_cares.*');
            $query->addSelect([
                'latest_care_time' => CustomerCare::query()
                    ->from('customer_cares as c2')
                    ->select('c2.time_care')
                    ->whereColumn('c2.pancake_customer_id', 'customer_cares.pancake_customer_id')
                    ->where('c2.status', 1)
                    ->whereNotNull('c2.time_care')
                    ->orderByDesc('c2.time_care')
                    ->limit(1),
            ]);
            $result = $query->paginate(30, ['customer_cares.*'], 'page', $inputs['page'] ?? 1);

            return response()->json([
                'success' => true,
                'data' => [
                    'customers' => $result->items(),
                    'current_page' => $result->currentPage(),
                    'per_page' => $result->perPage(),
                    'total_items' => $result->total(),
                    'total_pages' => $result->lastPage(),
                ],
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function customerAssignedByStaff(Request $request)
    {
        $user = $request->user();
        $requestedShopId = $this->shopAccessService->authorizeRequestedShopId(
            $user,
            $request->filled('shop_id') ? $request->integer('shop_id') : null
        );
        $effectiveShopIds = $requestedShopId !== null
            ? collect([$requestedShopId])
            : (! $this->shopAccessService->isGlobal($user)
                ? $this->shopAccessService->ids($user)
                : null);

        try {
            $inputs = $request->only(
                'page',
                'user_id',
                'shop_id'
            );
            $today = now()->toDateString();

            $query = User::query()->where('id', '!=', $user->id)->whereColumn('id', 'pancake_user_id');

            if ($effectiveShopIds !== null) {
                $query->whereHas('shops', function ($q) use ($effectiveShopIds) {
                    $q->whereIn('shops.id', $effectiveShopIds);
                });
            }

            if (isset($inputs['user_id'])) {
                $query->where('id', $inputs['user_id']);
            }

            $applyShop = fn ($q) => $q->actionable()
                ->when($effectiveShopIds !== null, function ($shopQuery) use ($effectiveShopIds) {
                    $shopQuery->whereIn('customer_cares.shop_id', $effectiveShopIds);
                })
                ->where('is_accept', 1);

            $query->withCount([
                'customerCareAssign as today_total' => fn ($q) => $applyShop($q->where('date_care', $today)),

                'customerCareAssign as today_done' => fn ($q) => $applyShop($q->where('date_care', $today)->where('status', 1)),

                'customerCareAssign as upcoming_total' => fn ($q) => $applyShop($q->where('date_care', '>', $today)),

                'customerCareAssign as upcoming_done' => fn ($q) => $applyShop($q->where('date_care', '>', $today)->where('status', 1)),

                'customerCareAssign as expired_total' => fn ($q) => $applyShop(
                    $q->where('date_care', '<', $today)
                        ->where(function ($q) {
                            $q->where('status', 0)
                                ->orWhereDate('time_care', '>', DB::raw('date_care'));
                        })
                ),

                'customerCareAssign as expired_done' => fn ($q) => $applyShop(
                    $q->where('date_care', '<', $today)
                        ->where('status', 1)
                        ->whereDate('time_care', '>', DB::raw('date_care'))
                ),
            ]);

            $result = $query->paginate(30, ['*'], 'page', $inputs['page'] ?? 1);

            return response()->json([
                'success' => true,
                'data' => [
                    'customers' => $result->items(),
                    'current_page' => $result->currentPage(),
                    'per_page' => $result->perPage(),
                    'total_items' => $result->total(),
                    'total_pages' => $result->lastPage(),
                ],
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage(),
            ], 500);
        }
    }
}
