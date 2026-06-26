<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerCare;
use App\Models\Order;
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
                "is_confirm_care"
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
        $query = CustomerCare::query()->latest("date_care");
        if (isset($inputs["is_accept"])) {
            $query->where("is_accept", $inputs["is_accept"]);
        }
        if (isset($inputs["shop_id"])) {
            $query->where("shop_id", $inputs["shop_id"]);
        } else {
            if (!is_admin()) {
                $query->whereIn("shop_id", $user->shops()->select("shops.id"));
            }
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
        if (isset($inputs["status"])) {
            $query->where("status", $inputs["status"]);
        }
        match ($type) {
            'customer_care_today'   => $query->where("date_care", date("Y-m-d")),
            'customer_care_pending' => $query->where("date_care", '>', date("Y-m-d")),
            'customer_care_in_week' => $query->whereBetween("date_care", [
                                            Carbon::now()->startOfWeek()->format("Y-m-d"),
                                            Carbon::now()->endOfWeek()->format("Y-m-d"),
                                        ]),
            'customer_care_expire'  => $query->where("date_care", "<", date("Y-m-d"))
                                             ->where("status", 0),
            'customer_care_edit'    => $query->where("total_edit", ">", 1)->where("is_accept", 0)
        };

        return $query->where(fn($q) => $this->applyAccessFilter($q, $user));
    }

    private function applyAccessFilter($q, $user): void
    {
        if (is_admin() || is_manager()) return;
        $q->whereHas("assigned", function ($q) use ($user) {
            $q->where("users.pancake_user_id", $user->pancake_user_id);
        });
        /*
        $q->where(function ($query) use ($user) {
            $query->where(function ($query1) use ($user) {
                        $query1->where("user_creator_id", $user->pancake_user_id);
                            //    ->orWhere("user_care_id", $user->pancake_user_id)
                            //    ->orWhere("user_assigning_seller_id", $user->pancake_user_id);
                  })
                  ->orWhereHas("users", function ($q) use ($user) {
                    $q->where("users.pancake_user_id", $user->pancake_user_id);
                  });
        });
        */
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
            $is_admin   = is_admin($user_id);
            $customer_care->update([
                "status"     => $request->status,
                "note"       => $note,
                "time_care"  => $time_care,
                "is_accept"  => ($customer_care->total_edit == 0 || $is_manager || $is_admin) ? 1 : 0,
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
                    "user_creator_id"           => $customer_care->user_creator_id,
                    "user_care_id"              => $customer_care->user_care_id,
                    "user_assigning_seller_id"  => $customer_care->user_assigning_seller_id
                ]);
            }

            return response()->json([
                "success" => true,
                "message" => ($customer_care->status == 1 && $request->status == 0 && !$is_admin && !$is_manager) ? "Đợi Admin hoặc quản lý duyệt" : "Cập nhật thành công"
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
                                                }
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
                SUM(CASE WHEN date_care = ? AND status = 1 THEN 1 ELSE 0 END) as customer_care_today_done,

                SUM(CASE WHEN date_care > ? THEN 1 ELSE 0 END) as customer_care_pending,
                SUM(CASE WHEN date_care > ? AND status = 1 THEN 1 ELSE 0 END) as customer_care_pending_done,

                SUM(CASE WHEN date_care BETWEEN ? AND ? THEN 1 ELSE 0 END) as customer_care_in_week,
                SUM(CASE WHEN date_care BETWEEN ? AND ? AND status = 1 THEN 1 ELSE 0 END) as customer_care_in_week_done,

                SUM(CASE WHEN date_care < ? AND status = 0 THEN 1 ELSE 0 END) as customer_care_expire,
                SUM(CASE WHEN date_care < ? AND status = 1 THEN 1 ELSE 0 END) as customer_care_expire_done,

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
            ->when(!is_admin() && !is_manager(), function ($q) use ($user, $shop_ids) {
                $q->whereIn("shop_id", $shop_ids)
                  ->whereHas("assigned", function ($q3) use ($user) {
                    $q3->where("users.pancake_user_id", $user->pancake_user_id);
                  });
                /*
                  ->where(function ($q2) use ($user) {
                    $q2->where("user_creator_id", $user->pancake_user_id)
                        ->orWhere("user_care_id", $user->pancake_user_id)
                        ->orWhere("user_assigning_seller_id", $user->pancake_user_id)
                        ->orWhereHas("users", function ($q3) use ($user) {
                            $q3->where("users.pancake_user_id", $user->pancake_user_id);
                        });
                
                });
                */
            })
            ->where("is_accept", 1)
            ->first();
            $date_start = date("Y-m-d 00:00:00");
            $date_end   = date("Y-m-d 23:59:59");
            $query = Order::whereBetween("created_at", [$date_start, $date_end])
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
                                    ->selectRaw("
                                        COUNT(*) as total_order_today,
                                        COALESCE(SUM(cod), 0) as total_revenue
                                    ")
                                    ->first();
            $total_customer = Customer::select("id")->where(function ($query, $user) {
                if (!is_admin()) {
                    $query->whereIn("shop_id", $user->shops()->select("shops.id"));
                }
            })->count();
                                    
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
                    "total_revenue"               => $query->total_revenue,
                    "total_customer"              => $total_customer
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
                "customer_care_ids",
                "is_multiple"
            );
            if (!$inputs["is_multiple"]) {
                $customer_care = CustomerCare::find($customer_care_id);
                if (!$customer_care) {
                    return response()->json([
                        "success" => false,
                        "message" => "Lịch chăm sóc này không tồn tại"
                    ]);
                }
                $customer_care->assigned()->sync(
                    collect($inputs['pancake_user_ids'])->mapWithKeys(fn($user_id) => [
                        $user_id => ['pancake_customer_id' => $customer_care->pancake_customer_id]
                    ])->toArray()
                );
            } else {
                $userIds = $inputs['pancake_user_ids'];
                $customer_cares = CustomerCare::whereIn("id", $inputs["customer_care_ids"])->get();
                foreach ($customer_cares as $item) {
                    $syncData = array_fill_keys($userIds, ['pancake_customer_id' => $item->pancake_customer_id]);
                    $item->assigned()->sync($syncData);
                }
            }

            return response()->json([
                "success" => true,
                "message" => "Phân công thành công"
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => $th->getMessage()
            ]);
        }
    }

    /** User confirm nhận cskh */
    public function confirmCare($customer_care_id)
    {
        try {
            if (is_admin()) {
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
            $customer_care->assigned()->detach();
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
}
