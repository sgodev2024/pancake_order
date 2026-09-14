<?php

namespace App\Services;

use App\Models\CustomerCare;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Shared list scope: options and rows must use identical access/type rules. */
class CustomerCareListQuery
{
    public function query(string $type, $user, array $inputs)
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
        if (isset($inputs['is_accept'])) {
            $query->where('is_accept', $inputs['is_accept']);
        }
        if (isset($inputs['shop_id'])) {
            $query->where('shop_id', $inputs['shop_id']);
        }
        if (isset($inputs['is_confirm_care'])) {
            $query->where('is_confirm_care', $inputs['is_confirm_care']);
        }
        if (isset($inputs['user_id'])) {
            $query->where(function ($q) use ($inputs) {
                $q->where('user_creator_id', $inputs['user_id']);
                //   ->orWhere("user_care_id", $inputs["user_id"])
                //   ->orWhere("user_assigning_seller_id", $inputs["user_id"]);
            });
        }
        $today = date('Y-m-d');
        if (isset($inputs['status'])) {
            $query->where('status', $inputs['status']);
        }
        if (! empty($inputs['search'])) {
            $search = $inputs['search'];
            $query->where(function ($q) use ($search) {
                $q->where('customer_cares.customer_name', 'like', "{$search}%")
                    ->orWhere('customer_cares.customer_phones', 'like', "{$search}%")
                    ->orWhere('customer_cares.pancake_order_id', 'like', "{$search}%");
            });
        }
        switch ($type) {
            case 'customer_care_today':
                $query->where('date_care', $today);
                break;
            case 'customer_care_pending':
                $query->where('date_care', '>', $today)->oldest('date_care');
                break;
            case 'customer_care_in_week':
                $query->whereBetween('date_care', [
                    Carbon::now()->startOfWeek()->format('Y-m-d'),
                    Carbon::now()->endOfWeek()->format('Y-m-d'),
                ])->oldest('date_care');
                break;
            case 'customer_care_expire':
                $query->where('date_care', '<', $today)
                    ->where(function ($q) {
                        $q->where('status', 0)
                            ->orWhereDate('time_care', '>', DB::raw('date_care'));
                    })
                    ->oldest('date_care');
                break;
            case 'customer_care_edit':
                $query->where('total_edit', '>', 1)->where('is_accept', 1);
                break;
            case 'chance': // trang cơ hội: lấy những thằng chưa chăm sóc + chưa phân công
                $query->where('status', 0);
                break;
        }

        return $query->where(fn ($q) => $this->applyAccessFilter($q, $user, $type));
    }

    private function applyAccessFilter($q, $user, $type = null): void
    {
        if ($type == 'chance') {
            $q->orWhereDoesntHave('users');
        }
        if ($user->isAdmin()) {
            return;
        }
        $shopIds = $user->shops()->pluck('shops.id');
        $q->whereIn('shop_id', $shopIds);

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
}
