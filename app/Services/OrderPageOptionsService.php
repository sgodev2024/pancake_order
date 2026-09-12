<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class OrderPageOptionsService
{
    public function __construct(private readonly ShopAccessService $shopAccess) {}

    public function query(User $user, int $shopId): Builder
    {
        $this->shopAccess->authorizeRequestedShopId($user, $shopId);

        $snapshots = Order::query()
            ->where('shop_id', $shopId)
            ->whereNotNull('pancake_order_page_id')
            ->whereRaw("TRIM(pancake_order_page_id) <> ''");

        // Match OrderController@index: options must not reveal hidden staff Orders,
        // including newer names belonging to another employee's snapshot.
        if (! $this->shopAccess->isGlobal($user) && ! $user->isManagerSale() && ! $user->isManagerCskh()) {
            $snapshots->where(function ($query) use ($user): void {
                $query->where('user_creator_id', $user->pancake_user_id)
                    ->orWhere('user_care_id', $user->pancake_user_id);
            });
        }

        $snapshots->select(['pancake_order_page_id', 'pancake_order_page_name'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY pancake_order_page_id ORDER BY created_at DESC, id DESC) AS snapshot_rank');

        // Keep ID-only pages filterable. Do not substitute a catalog/older name.
        return DB::query()->fromSub($snapshots->toBase(), 'page_snapshots')
            ->where('snapshot_rank', 1)
            ->selectRaw("pancake_order_page_id AS id, COALESCE(NULLIF(TRIM(pancake_order_page_name), ''), pancake_order_page_id) AS name")
            ->orderBy('name')->orderBy('id');
    }
}
