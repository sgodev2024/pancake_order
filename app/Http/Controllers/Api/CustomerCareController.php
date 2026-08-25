<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\CustomerCareAssignmentService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerCareController extends Controller
{
    public function __construct(
        private readonly CustomerCareAssignmentService $customerCareAssignmentService,
        private readonly ActivityLogService $activityLogService
    ) {
    }

    public function index(Request $request)
    {
        try {
            $inputs = $request->only(
                "type",
                "page",
                "shop_id",
                "status",
                "user_id",
                "is_accept",
                "is_confirm_care",
                "search"
            );
            $user = auth()->user();
            $result = $this->buildQuery($inputs["type"], $user, $inputs)
                            ->with([
                                "activeAssignment.assignee:id,name",
                                "activeAssignment.sourceOrder:id,status",
                                "shop" => function ($q) {
                                    $q->select("shops.id", "shops.name")
                                      ->with([
                                        "managers" => function ($query) {
                                            $query->select("users.id", "users.name");
                                        }
                                      ]);
                                },
                                "order" => function ($q) {
                                    $q->select("id", "pancake_order_id", "status");
                                },
                                "user_creator",
                                "user_care",
                                "user_assigning"
                            ])
                           ->paginate(30, ['*'], 'page', $inputs["page"]);

            $this->attachAssignableOrderIds($result->getCollection());

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
        $query = CustomerCare::query();
        if (in_array($type, [
            'customer_care_today',
            'customer_care_pending',
            'customer_care_in_week',
            'customer_care_expire',
        ], true)) {
            $query->actionable();
        }
        if (isset($inputs["is_accept"])) {
            $query->where("is_accept", $inputs["is_accept"]);
        }
        if (isset($inputs["shop_id"])) {
            $query->where("shop_id", $inputs["shop_id"]);
        }
        if (isset($inputs["is_confirm_care"])) {
            $query->where("is_confirm_care", $inputs["is_confirm_care"]);
        }
        if (isset($inputs["user_id"])) {
            $query->where(function ($q) use ($inputs) {
                    $q->where("user_creator_id", $inputs["user_id"]);
                    //   ->orWhere("user_care_id", $inputs["user_id"])
                    //   ->orWhere("user_assigning_seller_id", $inputs["user_id"]);
                });
        }
        $today = date("Y-m-d");
        if (isset($inputs["status"])) {
            $query->where("status", $inputs["status"]);
        }
        if (!empty($inputs["search"])) {
            $search = $inputs["search"];
            $query->where(function ($q) use ($search) {
                $q->where("customer_cares.customer_name", "like", "{$search}%")
                  ->orWhere("customer_cares.customer_phones", "like", "{$search}%")
                  ->orWhere("customer_cares.pancake_order_id", "like", "{$search}%");
            });
        }
        switch ($type) {
            case 'customer_care_today':
                $query->where("date_care", $today);
                break;
            case 'customer_care_pending':
                $query->where("date_care", '>', $today)->oldest("date_care");
                break;
            case 'customer_care_in_week':
                $query->whereBetween("date_care", [
                    Carbon::now()->startOfWeek()->format("Y-m-d"),
                    Carbon::now()->endOfWeek()->format("Y-m-d"),
                ])->oldest("date_care");
                break;
            case 'customer_care_expire':
                $query->where("date_care", "<", $today)
                        ->where(function ($q) {
                            $q->where("status", 0)
                                ->orWhereRaw("time_care > CONCAT(date_care, ' 23:59:59')");
                        })
                        ->oldest("date_care");
                break;
            case 'customer_care_edit':
                $query->where("total_edit", ">", 1)->where("is_accept", 1);
                break;
            case 'chance': // trang cơ hội: lấy những thằng chưa chăm sóc + chưa phân công 
                $query->where("status", 0);
                break;
        }

        return $query->where(fn($q) => $this->applyAccessFilter($q, $user, $type));
    }

    private function applyAccessFilter($q, $user, $type = NULL): void
    {
        if ($type == "chance") {
            $q->orWhereDoesntHave("users");
        }
        if ($user->isAdmin()) return;
        $shopIds = $user->shops()->pluck('shops.id');
        $q->whereIn("shop_id", $shopIds);

        if ($user->isManagerSale() || $user->isManagerCskh()) {
            return;
        }

        $currentTaskTypes = [
            'customer_care_today',
            'customer_care_pending',
            'customer_care_in_week',
            'customer_care_expire',
        ];

        if (in_array($type, $currentTaskTypes, true)) {
            $q->where(function ($query) use ($user) {
                $query->whereHas('activeAssignment', function ($assignmentQuery) use ($user) {
                    $assignmentQuery->where('assignee_user_id', $user->getKey());
                })->orWhere(function ($legacyQuery) use ($user) {
                    $legacyQuery->whereDoesntHave('activeAssignment')
                        ->where(function ($ownershipQuery) use ($user) {
                            $ownershipQuery->where('user_creator_id', $user->pancake_user_id)
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

        $q->where(function ($query) use ($user, $type) {
            $query->where('user_creator_id', $user->pancake_user_id)
                ->orWhere('user_care_id', $user->pancake_user_id)
                ->orWhere('user_assigning_seller_id', $user->pancake_user_id);

            if ($type !== 'chance') {
                $query->orWhereHas('users', function ($userQuery) use ($user) {
                    $userQuery->where('users.pancake_user_id', $user->pancake_user_id);
                });
            }
        });
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
            $note = $request->note ?? NULL;
            $time_care = $request->date ?? NULL;
            $is_admin   = auth()->user()->isAdmin() || auth()->user()->isManagerCskh();
            $is_care_completion = (int) $request->input('status') === 1;
            $customer_care = DB::transaction(function () use (
                $customer_care,
                $request,
                $note,
                $time_care,
                $is_admin,
                $is_care_completion
            ) {
                $lockedCustomerCare = CustomerCare::query()
                    ->whereKey($customer_care->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $activeAssignment = $is_care_completion
                    ? $this->guardCurrentCareMutation($lockedCustomerCare)
                    : null;

                if ($is_care_completion && $activeAssignment?->cared_at !== null) {
                    throw new DomainException('CustomerCare này đã được hoàn tất.');
                }

                $persistedCareTime = $is_care_completion
                    ? now(config('app.timezone'))
                    : ($lockedCustomerCare->time_care !== null
                        ? $lockedCustomerCare->time_care
                        : $time_care);

                $lockedCustomerCare->update([
                    "status"     => $request->status,
                    "note"       => $note,
                    "time_care"  => $persistedCareTime,
                    "is_accept"  => ($lockedCustomerCare->total_edit == 0 || $is_admin) ? 1 : 0,
                    "total_edit" => $lockedCustomerCare->total_edit + 1
                ]);

                $lockedCustomerCare->refresh();
                if ($is_care_completion) {
                    $this->customerCareAssignmentService->markAsCared($lockedCustomerCare);
                }

                if (!empty($request->next_date_care)) {
                    CustomerCare::create([
                        "shop_id"                   => $lockedCustomerCare->shop_id,
                        "pancake_customer_id"       => $lockedCustomerCare->pancake_customer_id,
                        "pancake_order_id"          => $lockedCustomerCare->pancake_order_id,
                        "customer_phones"           => $lockedCustomerCare->customer_phones,
                        "customer_name"             => $lockedCustomerCare->customer_name,
                        "customer_addresss"         => $lockedCustomerCare->customer_addresss,
                        "date_care"                 => $request->next_date_care,
                        "user_creator_id"           => $lockedCustomerCare->user_creator_id
                        // "user_care_id"              => $lockedCustomerCare->user_care_id,
                        // "user_assigning_seller_id"  => $lockedCustomerCare->user_assigning_seller_id
                    ]);
                }

                return $lockedCustomerCare;
            });

            return response()->json([
                "success" => true,
                "message" => ($customer_care->status == 1 && $request->status == 0 && !$is_admin) ? "Đợi duyệt" : "Cập nhật thành công"
            ]);
        } catch (AuthorizationException $exception) {
            return response()->json([
                "success" => false,
                "message" => $exception->getMessage(),
            ], 403);
        } catch (DomainException $exception) {
            return response()->json([
                "success" => false,
                "message" => $exception->getMessage(),
            ], 409);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => $th->getMessage()
            ]);
        }
    }

    public function accept($id, Request $request)
    {
        try {
            $customer_care = CustomerCare::find($id);
            $customer_care->update([
                "is_accept"      => $request->is_accept,
                "reason"         => $request->reason ?? NULL,
                "user_accept_id" => auth()->id()
            ]);

            return response()->json([
                "success" => true,
                "message" => "Duyệt thành công"
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => true,
                "message" => $th->getMessage()
            ]);
        }
    }

    public function getHistory($id)
    {
        try {
            $customer_care = CustomerCare::find($id);

            return response()->json([
                "success" => true,
                "data"    => CustomerCare::where("pancake_customer_id", $customer_care->pancake_customer_id)
                                          ->with([
                                                "shop" => function ($q) {
                                                    $q->select("shops.id", "shops.name");
                                                },
                                                "user_creator"
                                          ])
                                          ->get()
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => true,
                "message" => $th->getMessage()
            ]);
        }
    }

    public function getOrder($id)
    {
        try {
            $customer_care = CustomerCare::find($id);

            return response()->json([
                "success" => true,
                "data"    => Order::where("pancake_customer_id", $customer_care->pancake_customer_id)
                                   ->with([
                                        "shop" => function ($q) {
                                            $q->select("shops.id", "shops.name");
                                        },
                                        "user_creator",
                                        "user_care",
                                        "user_assigning"
                                   ])
                                  ->get()
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => true,
                "message" => $th->getMessage()
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

    /**Số lượng có thể sẽ khác so với khi đi vào trong chi tiết từng cục vì trong chi tiết không where vào is_accept = 1, mục đích để có thể duyệt sửa bên trong */
    public function overview()
    {
        try {
            $today = today()->format("Y-m-d");
            $startOfWeek = Carbon::now()->startOfWeek()->format("Y-m-d");
            $endOfWeek = Carbon::now()->endOfWeek()->format("Y-m-d");
            $user = auth()->user();
            $shop_ids = $user->shops()->pluck('shops.id');
            $taskBaseQuery = CustomerCare::query();
            $this->applyOverviewAccessScope($taskBaseQuery, $user, $shop_ids, true);

            $taskOverview = (clone $taskBaseQuery)->actionable()->selectRaw("
                SUM(CASE WHEN date_care = ? AND is_accept = 1 THEN 1 ELSE 0 END) as customer_care_today,
                SUM(CASE WHEN date_care = ? AND status = 1 AND is_accept = 1 THEN 1 ELSE 0 END) as customer_care_today_done,

                SUM(CASE WHEN date_care > ? AND is_accept = 1 THEN 1 ELSE 0 END) as customer_care_pending,
                SUM(CASE WHEN date_care > ? AND status = 1 AND is_accept = 1 THEN 1 ELSE 0 END) as customer_care_pending_done,

                SUM(CASE WHEN date_care BETWEEN ? AND ? AND is_accept = 1 THEN 1 ELSE 0 END) as customer_care_in_week,
                SUM(CASE WHEN date_care BETWEEN ? AND ? AND is_accept = 1 AND status = 1 THEN 1 ELSE 0 END) as customer_care_in_week_done,

                SUM(CASE
                    WHEN date_care < ?
                    AND (status = 0 OR time_care > CONCAT(date_care, ' 23:59:59'))
                    AND is_accept = 1
                    THEN 1 ELSE 0
                END) as customer_care_expire,

                SUM(CASE
                    WHEN date_care < ?
                    AND status = 1
                    AND time_care > CONCAT(date_care, ' 23:59:59')
                    AND is_accept = 1
                    THEN 1 ELSE 0
                END) as customer_care_expire_done
            ", [
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
            $editOverview = $editBaseQuery->selectRaw("
                SUM(CASE WHEN total_edit > 1 THEN 1 ELSE 0 END) as customer_care_edit,
                SUM(CASE WHEN total_edit > 1 AND is_accept = 1 THEN 1 ELSE 0 END) as customer_care_edit_accepted
            ")->first();
            $date_start = date("Y-m-d 00:00:00");
            $date_end   = date("Y-m-d 23:59:59");
            $query = Order::whereBetween("created_at", [$date_start, $date_end])
                            ->where(function ($q) use ($user, $shop_ids) {
                                if (!$user->isAdmin()) {
                                    $q->whereIn("shop_id", $shop_ids);
                                    if (!$user->isManagerSale() && !$user->isManagerCskh()) {
                                        $q->where(function ($q1) use ($user, $shop_ids) {
                                            $user_id = $user->pancake_user_id ?? $user->id;
                                            $q1->where(function ($q2) use ($user_id) {
                                                $q2->where("user_creator_id", $user_id)
                                                    ->orWhere("user_care_id", $user_id)
                                                    ->orWhere("user_assigning_seller_id", $user_id);
                                            });
                                        });
                                    }
                                }
                            })
                            ->selectRaw("
                                COUNT(*) as total_order_today,
                                COALESCE(SUM(cod), 0) as total_revenue
                            ")
                            ->first();

            return response()->json([
                "success" => true,
                "data" => [
                    "customer_care_today"         => (int) $taskOverview->customer_care_today,
                    "customer_care_today_done"    => (int) $taskOverview->customer_care_today_done,
                    "customer_care_pending"       => (int) $taskOverview->customer_care_pending,
                    "customer_care_pending_done"  => (int) $taskOverview->customer_care_pending_done,
                    "customer_care_in_week"       => (int) $taskOverview->customer_care_in_week,
                    "customer_care_in_week_done"  => (int) $taskOverview->customer_care_in_week_done,
                    "customer_care_expire"        => (int) $taskOverview->customer_care_expire,
                    "customer_care_expire_done"   => (int) $taskOverview->customer_care_expire_done,
                    "customer_care_edit"          => (int) $editOverview->customer_care_edit,
                    "customer_care_edit_accepted" => (int) $editOverview->customer_care_edit_accepted,
                    "total_order_today"           => $query->total_order_today,
                    "total_revenue"               => $query->total_revenue
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => "Vui lòng thử lại"
            ]);
        }
    }

    public function assign(Request $request, $order_id)
    {
        try {
            $inputs = $request->only(
                "pancake_user_ids",
                "is_multiple",
                "order_ids"
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
            $pancake_user_id = $inputs["pancake_user_ids"][0] ?? null; // chỉ lấy 1 item thôi, vì bên FE là radio

            if ($order_ids->isEmpty() || ! $pancake_user_id) {
                return response()->json([
                    "success" => false,
                    "message" => "Thiếu dữ liệu phân công",
                ]);
            }

            $user = User::where("pancake_user_id", $pancake_user_id)->first();

            if (! $user) {
                return response()->json([
                    "success" => false,
                    "message" => "Không tìm thấy người được phân công",
                ]);
            }

            if (! $user->canReceiveCustomerCareAssignments()) {
                return response()->json([
                    "success" => false,
                    "message" => "Người được phân công phải thuộc bộ phận CSKH.",
                ], 422);
            }

            $actor = auth()->user();
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

                foreach ($lockedOrders as $order_item) {
                    if ($order_item->shop === null) {
                        throw new DomainException('Không thể xác định cửa hàng của cơ hội để lên lịch CSKH.');
                    }

                    $scheduledOn = CustomerCareAssignment::calculateScheduledCareDate(
                        $assignedAt,
                        $order_item->shop->normalizedCareCycleDays()
                    );
                    $customerCare = CustomerCare::create([
                        "shop_id" => $order_item->shop_id,
                        "pancake_customer_id" => $order_item->pancake_customer_id,
                        "customer_phones" => $order_item->customer_phone,
                        "customer_name" => $order_item->customer_name,
                        "customer_addresss" => $order_item->customer_addresss,
                        "pancake_order_id" => $order_item->pancake_order_id,
                        "date_care" => $scheduledOn->toDateString(),
                        "user_creator_id" => $user->pancake_user_id,
                    ]);

                    $assignment = $this->customerCareAssignmentService->create(
                        $customerCare,
                        (int) $order_item->shop_id,
                        CustomerCareAssignment::SOURCE_ORDER,
                        (int) $order_item->id,
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
                        $order_item->shop_id,
                        $order_item->shop?->name,
                        "order",
                        $order_item->id,
                        $order_item->pancake_order_id,
                        $order_item->pancake_customer_id,
                        null,
                        ["assigned_user_id" => $user->id],
                        [
                            "customer_care_id" => $customerCare->id,
                            "assignment_id" => $assignment->id,
                            "source_type" => $assignment->source_type,
                            "source_id" => $assignment->source_id,
                            "assigned_at" => $assignment->assigned_at->toISOString(),
                            "assignee_user_id" => $assignment->assignee_user_id,
                            "assignee_pancake_user_id" => $assignment->assignee_pancake_user_id,
                        ]
                    );
                }

                return $lockedOrders->count();
            }, 3);
            $total_order_id = $order_ids->count();
            
            return response()->json([
                "success" => true,
                "message" => $total_orders == $total_order_id ? 
                            "Phân công thành công" : 
                            "Phân công thành công " . $total_orders . " khách hàng. Còn lại " . ($total_order_id - $total_orders) . " khách hàng không thuộc cửa hạng mà " . $user->name . " nằm trong"
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => $th->getMessage()
            ]);
        }
    }

    private function guardCurrentCareMutation(CustomerCare $customerCare): CustomerCareAssignment
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

        $actor = auth()->user();

        if ($actor === null) {
            throw new AuthorizationException('Bạn chưa đăng nhập.');
        }

        $hasShopAccess = $actor->isAdmin()
            || $actor->shops()->whereKey($assignment->shop_id)->exists();

        if (! $hasShopAccess) {
            throw new AuthorizationException('Bạn không có quyền truy cập cửa hàng của CustomerCare này.');
        }

        if ($actor->isAdmin() || $actor->isManagerCskh()) {
            return $assignment;
        }

        if (! $actor->isStaffCskh()
            || (int) $assignment->assignee_user_id !== (int) $actor->getKey()) {
            throw new AuthorizationException('Chỉ nhân sự CSKH được phân công mới có thể hoàn tất CustomerCare này.');
        }

        return $assignment;
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
    public function confirmCare($customer_care_id)
    {
        try {
            $user = auth()->user();
            if ($user->isAdmin()) {
                return response()->json([
                    "success" => false,
                    "message" => "Nhận CSKH chỉ dành cho nhân viên của cửa hàng"
                ]);
            }
            DB::beginTransaction();
            $customer_care = CustomerCare::find($customer_care_id);
            if (!$customer_care) {
                return response()->json([
                    "success" => false,
                    "message" => "Lịch chăm sóc này không tồn tại"
                ]);
            }
            $customer_care->update([
                "is_confirm_care" => true,
                "user_creator_id" => auth()->user()->pancake_user_id
            ]);
            // $customer_care->users()->detach();
            DB::commit();
            
            return response()->json([
                "success" => true,
                "message" => "Nhận thành công"
            ]);
        } catch (\Throwable $th) {
            DB::rollback();
            
            return response()->json([
                "success" => false,
                "message" => $th->getMessage()
            ]);
        }
    }

    public function customerAssignedByCustomer(Request $request)
    {
        try {
            $inputs = $request->only(
                "status",
                "user_id",
                "shop_id",
                "page"
            );
            $user = auth()->user();
            $query = CustomerCare::query();
            if (!$user->isAdmin()) {
                $query->whereIn("shop_id", $user->shops()->pluck("shops.id"));
            }
            if (isset($inputs["status"])) {
                $query->where("status", $inputs["status"]);
            }
            if (isset($inputs["shop_id"])) {
                $query->where("shop_id", $inputs["shop_id"]);
            }
            $query->with(["users" => function ($q) {
                $q->select("users.pancake_user_id", "users.id", "users.name");
            }]);
            $query->whereHas("users", function ($q) use ($inputs) {
                if (isset($inputs["user_id"])) {
                    $q->where("users.pancake_user_id", $inputs["user_id"]);
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
            $result = $query->paginate(30, ['customer_cares.*'], 'page', $inputs["page"] ?? 1);

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
                "message" => $th->getMessage()
            ], 500);
        }
    }

    public function customerAssignedByStaff(Request $request)
    {
        try {
            $inputs = $request->only(
                "page",
                "user_id",
                "shop_id"
            );
            $user = auth()->user();
            $shop_ids = $user->shops()->pluck("shops.id");
            $today = now()->toDateString();
            $shopFilter = $inputs["shop_id"] ?? null;

            $query = User::query()->where("id", "!=", $user->id)->whereColumn('id', 'pancake_user_id');

            if (!$user->isAdmin()) {
                $query->whereHas('shops', function ($q) use ($shop_ids) {
                    $q->whereIn('shops.id', $shop_ids);
                });
            }

            if (isset($inputs["shop_id"])) {
                $query->whereHas('shops', function ($q) use ($inputs) {
                    $q->where('shops.id', $inputs["shop_id"]);
                });
            }

            if (isset($inputs["user_id"])) {
                $query->where("id", $inputs["user_id"]);
            }

            $applyShop = fn ($q) => $shopFilter
                ? $q->actionable()
                    ->where('customer_cares.shop_id', $shopFilter)
                    ->where("is_accept", 1)
                : $q->actionable()->where("is_accept", 1);

            $query->withCount([
                'customerCareAssign as today_total' => fn ($q) =>
                    $applyShop($q->where('date_care', $today)),

                'customerCareAssign as today_done' => fn ($q) =>
                    $applyShop($q->where('date_care', $today)->where('status', 1)),

                'customerCareAssign as upcoming_total' => fn ($q) =>
                    $applyShop($q->where('date_care', '>', $today)),

                'customerCareAssign as upcoming_done' => fn ($q) =>
                    $applyShop($q->where('date_care', '>', $today)->where('status', 1)),

                'customerCareAssign as expired_total' => fn ($q) =>
                    $applyShop(
                        $q->where('date_care', '<', $today)
                        ->where(function ($q) {
                            $q->where('status', 0)
                                ->orWhereRaw("time_care > CONCAT(date_care, ' 23:59:59')");
                        })
                    ),

                'customerCareAssign as expired_done' => fn ($q) =>
                    $applyShop(
                        $q->where('date_care', '<', $today)
                        ->where('status', 1)
                        ->whereRaw("time_care > CONCAT(date_care, ' 23:59:59')")
                    ),
            ]);

            $result = $query->paginate(30, ['*'], 'page', $inputs["page"] ?? 1);

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
                "message" => $th->getMessage()
            ], 500);
        }
    }
}
