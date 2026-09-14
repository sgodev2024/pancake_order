<?php

namespace App\Services;

use App\Models\CustomerCareAssignment;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerCareOrderSourceService
{
    /**
     * A correlated, zero-or-one Order query. Every consumer uses this predicate.
     * No assignment status is excluded: historical links belong to this care too.
     */
    public function resolvedOrder(): Builder
    {
        $assignments = fn () => DB::table('customer_care_assignments as source_links')
            ->selectRaw('1')
            ->whereColumn('source_links.customer_care_id', 'customer_cares.id')
            ->where('source_links.source_type', CustomerCareAssignment::SOURCE_ORDER);

        return DB::table('orders as care_source_orders')
            ->whereColumn('care_source_orders.shop_id', 'customer_cares.shop_id')
            ->whereNull('care_source_orders.deleted_at')
            ->where(function (Builder $branches) use ($assignments): void {
                $branches->where(function (Builder $direct) use ($assignments): void {
                    // The correlated guards prove this candidate is the sole valid direct
                    // order link, avoiding a UNION subquery that MariaDB 10.3 cannot use
                    // inside an IN predicate.
                    $direct->whereExists($assignments())
                        ->whereNotExists($assignments()->where(function (Builder $invalid): void {
                            $invalid->whereColumn('source_links.source_id', '!=', 'care_source_orders.id')
                                ->orWhereColumn('source_links.shop_id', '!=', 'customer_cares.shop_id')
                                ->orWhereNull('source_links.source_id')
                                ->orWhereNull('source_links.shop_id');
                        }))
                        ->where(function (Builder $identity): void {
                            $identity->whereNull('customer_cares.pancake_order_id')
                                ->orWhereRaw("TRIM(customer_cares.pancake_order_id) = ''")
                                ->orWhereColumn('care_source_orders.pancake_order_id', 'customer_cares.pancake_order_id');
                        });
                })->orWhere(function (Builder $legacy) use ($assignments): void {
                    $legacy->whereNotExists($assignments())
                        ->whereNotNull('customer_cares.pancake_order_id')
                        ->whereRaw("TRIM(customer_cares.pancake_order_id) <> ''")
                        ->whereColumn('care_source_orders.pancake_order_id', 'customer_cares.pancake_order_id')
                        ->whereNotExists(function (Builder $duplicates): void {
                            $duplicates->selectRaw('1')->from('orders as sibling_orders')
                                ->whereColumn('sibling_orders.shop_id', 'care_source_orders.shop_id')
                                ->whereColumn('sibling_orders.pancake_order_id', 'care_source_orders.pancake_order_id')
                                ->whereNull('sibling_orders.deleted_at')
                                ->whereColumn('sibling_orders.id', '!=', 'care_source_orders.id');
                        });
                });
            });
    }

    public function selectResolvedOrder(EloquentBuilder $cares): EloquentBuilder
    {
        return $cares->select('customer_cares.*')->selectSub(
            $this->resolvedOrder()->select('care_source_orders.id'),
            'resolved_source_order_id'
        );
    }

    public function filter(EloquentBuilder $cares, string $pageId): EloquentBuilder
    {
        return $cares->whereExists($this->resolvedOrder()->selectRaw('1')
            ->where('care_source_orders.pancake_order_page_id', $pageId));
    }

    public function pageOptions(EloquentBuilder $allowedCares, int $shopId): Builder
    {
        $allowedCares = (clone $allowedCares)->reorder()->selectRaw('1')
            ->whereExists($this->resolvedOrder()->selectRaw('1')
                ->whereColumn('care_source_orders.id', 'orders.id'));

        $snapshots = Order::query()->where('orders.shop_id', $shopId)->whereExists($allowedCares->toBase());

        return app(OrderPageOptionsService::class)->fromSnapshots($snapshots);
    }

    /** One batch, with compact projections; never lazy-load an unchecked source. */
    public function attach(Collection $cares): void
    {
        $ids = $cares->pluck('resolved_source_order_id')->filter()->unique();
        $orders = $ids->isEmpty() ? collect() : Order::query()->whereKey($ids)->get([
            'orders.id', 'orders.shop_id', 'orders.pancake_order_id', 'orders.status',
            'orders.pancake_order_page_id', 'orders.pancake_order_page_name',
        ])->keyBy('id');

        foreach ($cares as $care) {
            $order = $orders->get($care->getAttribute('resolved_source_order_id'));
            $care->setAttribute('order_page_id', $order?->pancake_order_page_id);
            $care->setAttribute('order_page_name', $order?->pancake_order_page_name);
            $care->offsetUnset('resolved_source_order_id');

            // Preserve the compact V1/V2 relation shape using the same exact Order.
            $compactOrder = $order === null ? null : (new Order)->forceFill([
                'id' => $order->id,
                'pancake_order_id' => $order->pancake_order_id,
                'status' => $order->status,
            ]);
            $care->setRelation('order', $compactOrder);

            $assignments = collect([$care->getRelation('activeAssignment')])
                ->merge($care->getRelation('currentAssignments'))->filter();
            foreach ($assignments as $assignment) {
                $safe = $order !== null
                    && $assignment->source_type === CustomerCareAssignment::SOURCE_ORDER
                    && (string) $assignment->source_id === (string) $order->id
                    && (string) $assignment->shop_id === (string) $care->shop_id;
                $assignment->setRelation('sourceOrder', $safe ? (new Order)->forceFill([
                    'id' => $order->id, 'status' => $order->status,
                ]) : null);
            }
        }
    }
}
