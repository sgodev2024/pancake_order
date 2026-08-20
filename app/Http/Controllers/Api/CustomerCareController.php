<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerCare;
use App\Models\Order;
use App\Models\User;
use App\Services\ActivityLogService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerCareController extends Controller
{
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
                                "shop" => function ($q) {
                                    $q->select("shops.id", "shops.name")
                                      ->with([
                                        "users" => function ($query) {
                                            $query->select("users.id", "users.role_id", "users.name")
                                                 ->where("users.role_id", 2);
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
        $q->whereIn("shop_id", $shopIds)
          ->where(function ($query) use ($user, $type) {
            $query->where(function ($q) use ($user, $type) {
                if (!$user->isManagerSale() && !$user->isManagerCskh()) {
                    $q->where(function ($query1) use ($user) {
                            $query1->where("user_creator_id", $user->pancake_user_id);
                                //    ->orWhere("user_care_id", $user->pancake_user_id)
                                //    ->orWhere("user_assigning_seller_id", $user->pancake_user_id);
                    });
                    if ($type != "chance") {
                        $q->orWhereHas("users", function ($q) use ($user) {
                            $q->where("users.pancake_user_id", $user->pancake_user_id);
                        });
                    }
                    
                }
            });    
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
            // if ($customer_care->status == 1 && $request->status == 0) {
            //     $note = NULL;
            //     $time_care = NULL;
            // }
            $user_id = auth()->id();
            $is_manager = is_manager($user_id);
            $is_admin   = auth()->user()->isAdmin() || auth()->user()->isManagerCskh();
            $customer_care->update([
                "status"     => $request->status,
                "note"       => $note,
                "time_care"  => $time_care,
                "is_accept"  => ($customer_care->total_edit == 0 || $is_admin) ? 1 : 0,
                "total_edit" => $customer_care->total_edit + 1
            ]);
            if (!empty($request->next_date_care)) {
                CustomerCare::create([
                    "shop_id"                   => $customer_care->shop_id,
                    "pancake_customer_id"       => $customer_care->pancake_customer_id,
                    "pancake_order_id"          => $customer_care->pancake_order_id,
                    "customer_phones"           => $customer_care->customer_phones,
                    "customer_name"             => $customer_care->customer_name,
                    "customer_addresss"         => $customer_care->customer_addresss,
                    "date_care"                 => $request->next_date_care,
                    "user_creator_id"           => $customer_care->user_creator_id
                    // "user_care_id"              => $customer_care->user_care_id,
                    // "user_assigning_seller_id"  => $customer_care->user_assigning_seller_id
                ]);
            }

            return response()->json([
                "success" => true,
                "message" => ($customer_care->status == 1 && $request->status == 0 && !$is_admin) ? "Đợi duyệt" : "Cập nhật thành công"
            ]);
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
            $overview = CustomerCare::selectRaw("
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
                END) as customer_care_expire_done,

                SUM(CASE WHEN total_edit > 1  THEN 1 ELSE 0 END) as customer_care_edit,
                SUM(CASE WHEN total_edit > 1 AND is_accept = 1 THEN 1 ELSE 0 END) as customer_care_edit_accepted
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
            ->when(!$user->isAdmin(), function ($q) use ($user, $shop_ids) {
                // $q->whereIn("shop_id", $shop_ids)
                //   ->where(function ($q2) use ($user) {
                //     $q2->where("user_creator_id", $user->pancake_user_id)
                //         ->orWhere("user_care_id", $user->pancake_user_id)
                //         ->orWhere("user_assigning_seller_id", $user->pancake_user_id)
                //         ->orWhereHas("users", function ($q3) use ($user) {
                //             $q3->where("users.pancake_user_id", $user->pancake_user_id);
                //         });
                // });
                $q->whereIn("shop_id", $shop_ids);
                if (!$user->isManagerSale() && !$user->isManagerCskh()) {
                    $q->where(function ($q1) use ($user){
                        $q1->where(function ($q2) use ($user) {
                                $q2->where("user_creator_id", $user->pancake_user_id)
                                    ->orWhere("user_care_id", $user->pancake_user_id)
                                    ->orWhere("user_assigning_seller_id", $user->pancake_user_id);
                            })
                            ->orWhereHas("users", function ($q3) use ($user) {
                                $q3->where("users.pancake_user_id", $user->pancake_user_id);
                            });
                    });
                }
            })
            // ->where("is_accept", 1) // những cái đã được duyệt sửa
            ->first();
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
                    "customer_care_today"         => (int) $overview->customer_care_today,
                    "customer_care_today_done"    => (int) $overview->customer_care_today_done,
                    "customer_care_pending"       => (int) $overview->customer_care_pending,
                    "customer_care_pending_done"  => (int) $overview->customer_care_pending_done,
                    "customer_care_in_week"       => (int) $overview->customer_care_in_week,
                    "customer_care_in_week_done"  => (int) $overview->customer_care_in_week_done,
                    "customer_care_expire"        => (int) $overview->customer_care_expire,
                    "customer_care_expire_done"   => (int) $overview->customer_care_expire_done,
                    "customer_care_edit"          => (int) $overview->customer_care_edit,
                    "customer_care_edit_accepted" => (int) $overview->customer_care_edit_accepted,
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

    public function assign(Request $request, $customer_care_id)
    {
        try {
            $inputs = $request->only(
                "pancake_user_ids",
                "is_multiple",
                "order_ids"
            );

            $is_multiple = filter_var($inputs['is_multiple'] ?? false, FILTER_VALIDATE_BOOLEAN);

            // Xác định danh sách customer_care cần phân công
            $order_ids = $is_multiple
                ? ($inputs['order_ids'] ?? [])
                : [$customer_care_id];
            $pancake_user_id = $inputs["pancake_user_ids"][0]; // chỉ lấy 1 item thôi, vì bên FE là radio
            $user = User::where("pancake_user_id", $pancake_user_id)->first();
            $actor = auth()->user();
            $shop_ids = $user->shops()->pluck("shops.id");
            $orders = Order::whereIn('id', $order_ids)
                           ->whereIn("shop_id", $shop_ids)
                            ->with(['shop' => function ($query) {
                                $query->select('id', 'name');
                            }])
                            ->get();

            if ($orders->isEmpty()) {
                return response()->json([
                    "success" => false,
                    "message" => "Các khách hàng bạn phân công không thuộc cửa hạng mà " . $user->name . " nằm trong"
                ]);
            }
            $customer_cares = [];
            foreach ($orders as $order_item) {
                $customer_cares[] = [
                    "shop_id"             => $order_item->shop_id,
                    "pancake_customer_id" => $order_item->pancake_customer_id,
                    "customer_phones"     => $order_item->customer_phone,
                    "customer_name"       => $order_item->customer_name,
                    "customer_addresss"   => $order_item->customer_addresss,
                    "pancake_order_id"    => $order_item->pancake_order_id,
                    "date_care"           => now()->addDays(3)->format('Y-m-d'),
                    "user_creator_id"     => $pancake_user_id,
                    "created_at"          => now(),
                    "updated_at"          => now()
                ];
                // $customer_care->users()->sync(
                //     collect($inputs['pancake_user_ids'])->mapWithKeys(fn($user_id) => [
                //         $user_id => ['pancake_customer_id' => $customer_care->pancake_customer_id]
                //     ])->toArray()
                // );
            }
            $activityLogService = new ActivityLogService();

            DB::transaction(function () use ($customer_cares, $orders, $activityLogService, $actor, $user) {
                CustomerCare::insert($customer_cares);

                foreach ($orders as $order_item) {
                    $activityLogService->log(
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
                        null
                    );
                }
            });
            $total_orders = count($orders);
            $total_order_id = count($inputs["order_ids"]);
            
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
                ? $q->where('customer_cares.shop_id', $shopFilter)->where("is_accept", 1)
                : $q->where("is_accept", 1);

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
