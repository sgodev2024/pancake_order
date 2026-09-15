<?php

namespace App\Services;

use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerCareOrderSourceService
{
    /** Apply the exact resolved-order predicate before pagination. */
    public function filter(EloquentBuilder $cares, string $pageId): EloquentBuilder
    {
        return $this->whereHasResolvedOrder($cares, $pageId);
    }

    /**
     * Return page options from the same exact order contract as list display and
     * filtering. The candidate Order is fixed by its primary key before any
     * direct/legacy validation runs.
     */
    public function pageOptions(EloquentBuilder $allowedCares, int $shopId): Collection
    {
        [$direct, $legacy] = $this->pageOptionSnapshotQueries($allowedCares, $shopId);
        $snapshots = $direct->toBase()->get()->concat($legacy->toBase()->get());

        return $snapshots
            ->sort(function ($left, $right): int {
                $created = strcmp(
                    (string) $right->snapshot_created_at,
                    (string) $left->snapshot_created_at
                );

                return $created !== 0
                    ? $created
                    : ((int) $right->snapshot_order_id <=> (int) $left->snapshot_order_id);
            })
            ->unique(fn ($snapshot) => (string) $snapshot->id)
            ->map(fn ($snapshot): array => [
                'id' => (string) $snapshot->id,
                'name' => trim((string) $snapshot->name) !== ''
                    ? $snapshot->name
                    : (string) $snapshot->id,
            ])
            ->sort(function (array $left, array $right): int {
                $name = strcmp($left['name'], $right['name']);

                return $name !== 0 ? $name : strcmp($left['id'], $right['id']);
            })
            ->values();
    }

    /**
     * Resolve display Orders after pagination. At most one assignment query and
     * one compact Order query are issued for the whole page; unresolved rows
     * fail closed and never fall back heuristically.
     */
    public function attach(Collection $cares): void
    {
        if ($cares->isEmpty()) {
            return;
        }

        $resolved = $this->resolveBatch($cares);

        foreach ($cares as $care) {
            $order = $resolved->get($care->getKey());
            $care->setAttribute('order_page_id', $order?->pancake_order_page_id);
            $care->setAttribute('order_page_name', $order?->pancake_order_page_name);

            // Preserve the compact V1/V2 relation shape using the same exact Order.
            $compactOrder = $order === null ? null : (new Order)->forceFill([
                'id' => $order->id,
                'pancake_order_id' => $order->pancake_order_id,
                'status' => $order->status,
            ]);
            $care->setRelation('order', $compactOrder);

            $assignments = collect();
            if ($care->relationLoaded('assignments')) {
                $assignments = $assignments->merge($care->getRelation('assignments'));
            }
            if ($care->relationLoaded('activeAssignments')) {
                $assignments = $assignments->merge($care->getRelation('activeAssignments'));
            }

            foreach ($assignments->filter() as $assignment) {
                $safe = $order !== null
                    && $assignment->source_type === CustomerCareAssignment::SOURCE_ORDER
                    && (string) $assignment->source_id === (string) $order->id
                    && (string) $assignment->shop_id === (string) $care->shop_id;
                $assignment->setRelation('sourceOrder', $safe ? (new Order)->forceFill([
                    'id' => $order->id,
                    'status' => $order->status,
                ]) : null);
            }
        }
    }

    private function whereHasResolvedOrder(
        EloquentBuilder $cares,
        ?string $pageId = null,
        ?string $candidateOrderColumn = null
    ): EloquentBuilder {
        return $cares->where(function (EloquentBuilder $resolved) use ($pageId, $candidateOrderColumn): void {
            $resolved->whereExists($this->directOrder($pageId, $candidateOrderColumn))
                ->orWhereExists($this->legacyOrder($pageId, $candidateOrderColumn));
        });
    }

    /**
     * Start option discovery from already-scoped cares. This avoids evaluating
     * a correlated care predicate once for every Order in the shop.
     *
     * @return array{EloquentBuilder, EloquentBuilder}
     */
    private function pageOptionSnapshotQueries(EloquentBuilder $allowedCares, int $shopId): array
    {
        $allowedIds = (clone $allowedCares)->reorder()->select('customer_cares.id');
        $base = CustomerCare::query()->joinSub(
            $allowedIds->toBase(),
            'allowed_cares',
            'allowed_cares.id',
            '=',
            'customer_cares.id'
        );
        $columns = [
            'option_orders.pancake_order_page_id as id',
            'option_orders.pancake_order_page_name as name',
            'option_orders.created_at as snapshot_created_at',
            'option_orders.id as snapshot_order_id',
        ];

        $direct = (clone $base)
            ->join('customer_care_assignments as option_links', function ($join): void {
                $join->on('option_links.customer_care_id', '=', 'customer_cares.id')
                    ->where('option_links.source_type', CustomerCareAssignment::SOURCE_ORDER);
            })
            ->join('orders as option_orders', function ($join): void {
                $join->on('option_orders.id', '=', 'option_links.source_id')
                    ->whereNull('option_orders.deleted_at');
            })
            ->where('customer_cares.shop_id', $shopId)
            ->whereColumn('option_links.shop_id', 'customer_cares.shop_id')
            ->whereColumn('option_orders.shop_id', 'customer_cares.shop_id')
            ->whereNotNull('option_orders.pancake_order_page_id')
            ->whereRaw("TRIM(option_orders.pancake_order_page_id) <> ''")
            ->select($columns);
        $this->whereHasResolvedOrder($direct, null, 'option_orders.id');

        $legacy = (clone $base)
            ->join('orders as option_orders', function ($join): void {
                $join->on('option_orders.shop_id', '=', 'customer_cares.shop_id')
                    ->on('option_orders.pancake_order_id', '=', 'customer_cares.pancake_order_id')
                    ->whereNull('option_orders.deleted_at');
            })
            ->where('customer_cares.shop_id', $shopId)
            ->whereNotNull('option_orders.pancake_order_page_id')
            ->whereRaw("TRIM(option_orders.pancake_order_page_id) <> ''")
            ->whereNotExists(function (Builder $directLinks): void {
                $directLinks->selectRaw('1')
                    ->from('customer_care_assignments as option_direct_links')
                    ->whereColumn('option_direct_links.customer_care_id', 'customer_cares.id')
                    ->where('option_direct_links.source_type', CustomerCareAssignment::SOURCE_ORDER);
            })
            ->select($columns);
        $this->whereHasResolvedOrder($legacy, null, 'option_orders.id');

        return [$direct, $legacy];
    }

    /** Direct assignments resolve through Orders.id and reject any invalid peer. */
    private function directOrder(?string $pageId, ?string $candidateOrderColumn): Builder
    {
        $query = DB::table('customer_care_assignments as direct_links')
            ->selectRaw('1')
            ->join('orders as direct_orders', function ($join): void {
                $join->on('direct_orders.id', '=', 'direct_links.source_id')
                    ->whereNull('direct_orders.deleted_at');
            })
            ->whereColumn('direct_links.customer_care_id', 'customer_cares.id')
            ->where('direct_links.source_type', CustomerCareAssignment::SOURCE_ORDER)
            ->whereColumn('direct_links.shop_id', 'customer_cares.shop_id')
            ->whereColumn('direct_orders.shop_id', 'customer_cares.shop_id')
            ->where(function (Builder $identity): void {
                $identity->whereNull('customer_cares.pancake_order_id')
                    ->orWhereRaw("TRIM(customer_cares.pancake_order_id) = ''")
                    ->orWhereColumn('direct_orders.pancake_order_id', 'customer_cares.pancake_order_id');
            })
            ->whereNotExists(function (Builder $invalid): void {
                $invalid->selectRaw('1')
                    ->from('customer_care_assignments as invalid_direct_links')
                    ->whereColumn('invalid_direct_links.customer_care_id', 'customer_cares.id')
                    ->where('invalid_direct_links.source_type', CustomerCareAssignment::SOURCE_ORDER)
                    ->where(function (Builder $mismatch): void {
                        $mismatch->whereColumn('invalid_direct_links.source_id', '!=', 'direct_links.source_id')
                            ->orWhereColumn('invalid_direct_links.shop_id', '!=', 'customer_cares.shop_id')
                            ->orWhereNull('invalid_direct_links.source_id')
                            ->orWhereNull('invalid_direct_links.shop_id');
                    });
            });

        if ($pageId !== null) {
            $query->where('direct_orders.pancake_order_page_id', $pageId);
        }
        if ($candidateOrderColumn !== null) {
            $query->whereColumn('direct_orders.id', $candidateOrderColumn);
        }

        return $query;
    }

    /** Legacy resolution is allowed only when no direct Order assignment exists. */
    private function legacyOrder(?string $pageId, ?string $candidateOrderColumn): Builder
    {
        $query = DB::table('orders as legacy_orders')
            ->selectRaw('1')
            ->whereColumn('legacy_orders.shop_id', 'customer_cares.shop_id')
            ->whereColumn('legacy_orders.pancake_order_id', 'customer_cares.pancake_order_id')
            ->whereNull('legacy_orders.deleted_at')
            ->whereNotNull('customer_cares.pancake_order_id')
            ->whereRaw("TRIM(customer_cares.pancake_order_id) <> ''")
            ->whereNotExists(function (Builder $direct): void {
                $direct->selectRaw('1')
                    ->from('customer_care_assignments as legacy_direct_links')
                    ->whereColumn('legacy_direct_links.customer_care_id', 'customer_cares.id')
                    ->where('legacy_direct_links.source_type', CustomerCareAssignment::SOURCE_ORDER);
            })
            ->whereNotExists(function (Builder $duplicates): void {
                $duplicates->selectRaw('1')
                    ->from('orders as sibling_orders')
                    ->whereColumn('sibling_orders.shop_id', 'legacy_orders.shop_id')
                    ->whereColumn('sibling_orders.pancake_order_id', 'legacy_orders.pancake_order_id')
                    ->whereNull('sibling_orders.deleted_at')
                    ->whereColumn('sibling_orders.id', '!=', 'legacy_orders.id');
            });

        if ($pageId !== null) {
            $query->where('legacy_orders.pancake_order_page_id', $pageId);
        }
        if ($candidateOrderColumn !== null) {
            $query->whereColumn('legacy_orders.id', $candidateOrderColumn);
        }

        return $query;
    }

    /** @return Collection<int, Order> keyed by CustomerCare primary key */
    private function resolveBatch(Collection $cares): Collection
    {
        $caresById = $cares->keyBy(fn (CustomerCare $care) => (int) $care->getKey());
        $assignmentsAreLoaded = $caresById->every(
            fn (CustomerCare $care) => $care->relationLoaded('assignments')
        );
        $linksByCare = $assignmentsAreLoaded
            ? $caresById->mapWithKeys(fn (CustomerCare $care) => [
                (int) $care->getKey() => $care->getRelation('assignments')
                    ->where('source_type', CustomerCareAssignment::SOURCE_ORDER)
                    ->values(),
            ])
            : DB::table('customer_care_assignments')
                ->whereIn('customer_care_id', $caresById->keys())
                ->where('source_type', CustomerCareAssignment::SOURCE_ORDER)
                ->get(['customer_care_id', 'shop_id', 'source_id'])
                ->groupBy(fn ($link) => (int) $link->customer_care_id);

        $directIdsByCare = collect();
        $legacyCares = collect();

        foreach ($caresById as $careId => $care) {
            $links = $linksByCare->get($careId, collect());
            if ($links->isEmpty()) {
                if ($this->hasLegacyIdentity($care)) {
                    $legacyCares->put($careId, $care);
                }

                continue;
            }

            $sourceIds = $links->pluck('source_id')->filter()->unique()->values();
            $invalid = $sourceIds->count() !== 1
                || $links->contains(fn ($link) => $link->source_id === null
                    || $link->shop_id === null
                    || (int) $link->shop_id !== (int) $care->shop_id);
            if (! $invalid) {
                $directIdsByCare->put($careId, (int) $sourceIds->first());
            }
        }

        $directIds = $directIdsByCare->values()->unique()->values();
        $legacyByShop = $legacyCares->groupBy(fn (CustomerCare $care) => (int) $care->shop_id)
            ->map(fn (Collection $shopCares) => $shopCares->pluck('pancake_order_id')->unique()->values());

        if ($directIds->isEmpty() && $legacyByShop->isEmpty()) {
            return collect();
        }

        $orders = Order::query()
            ->where(function (EloquentBuilder $candidates) use ($directIds, $legacyByShop): void {
                $hasBranch = false;
                if ($directIds->isNotEmpty()) {
                    $candidates->whereIn('orders.id', $directIds);
                    $hasBranch = true;
                }

                foreach ($legacyByShop as $shopId => $pancakeOrderIds) {
                    $method = $hasBranch ? 'orWhere' : 'where';
                    $candidates->{$method}(function (EloquentBuilder $legacy) use ($shopId, $pancakeOrderIds): void {
                        $legacy->where('orders.shop_id', $shopId)
                            ->whereIn('orders.pancake_order_id', $pancakeOrderIds);
                    });
                    $hasBranch = true;
                }
            })
            ->get([
                'orders.id',
                'orders.shop_id',
                'orders.pancake_order_id',
                'orders.status',
                'orders.pancake_order_page_id',
                'orders.pancake_order_page_name',
            ]);

        $ordersById = $orders->keyBy(fn (Order $order) => (int) $order->getKey());
        $legacyOrders = $orders->groupBy(fn (Order $order) => $this->legacyKey($order->shop_id, $order->pancake_order_id));
        $resolved = collect();

        foreach ($directIdsByCare as $careId => $sourceId) {
            $care = $caresById->get($careId);
            $order = $ordersById->get($sourceId);
            if ($order !== null
                && (int) $order->shop_id === (int) $care->shop_id
                && $this->identityMatches($care, $order)) {
                $resolved->put($careId, $order);
            }
        }

        foreach ($legacyCares as $careId => $care) {
            $matches = $legacyOrders->get($this->legacyKey($care->shop_id, $care->pancake_order_id), collect());
            if ($matches->count() === 1) {
                $resolved->put($careId, $matches->first());
            }
        }

        return $resolved;
    }

    private function hasLegacyIdentity(CustomerCare $care): bool
    {
        return $care->pancake_order_id !== null && trim((string) $care->pancake_order_id) !== '';
    }

    private function identityMatches(CustomerCare $care, Order $order): bool
    {
        return ! $this->hasLegacyIdentity($care)
            || (string) $care->pancake_order_id === (string) $order->pancake_order_id;
    }

    private function legacyKey($shopId, $pancakeOrderId): string
    {
        return (string) $shopId."\0".(string) $pancakeOrderId;
    }
}
