<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Read model for the MVP Customer Journey timeline.
 *
 * Every candidate is constrained to the requested local shop first. The
 * linkage predicates then use local Customer/Order/CustomerCare/Assignment
 * identities. External customer IDs are only used as a same-shop fallback for
 * legacy order events that have no local metadata.customer_id.
 */
class CustomerJourneyService
{
    /** @var list<string> */
    public const ACTIONS = [
        'customer.entered_system',
        'customer_care.assigned',
        'customer_care.reassigned',
        'customer_care.reclaimed',
        'customer_care.completed',
        'order.created',
    ];

    public function __construct(
        private readonly CustomerJourneyTransformer $transformer
    ) {}

    /**
     * @return array{paginator: LengthAwarePaginator, care_count: int, order_count: int}
     */
    public function paginate(Customer $customer, int $page, int $pageSize, ?string $action = null): array
    {
        $query = $this->linkedActivityLogs($customer);

        $careCount = (clone $query)
            ->where('activity_logs.action', 'customer_care.completed')
            ->count();
        $orderCount = (clone $query)
            ->where('activity_logs.action', 'order.created')
            ->count();

        $timelineQuery = clone $query;
        if ($action !== null) {
            $timelineQuery->where('activity_logs.action', $action);
        }

        $paginator = $timelineQuery
            ->orderByRaw('COALESCE(activity_logs.occurred_at, activity_logs.created_at) asc')
            ->orderBy('activity_logs.id')
            ->paginate($pageSize, ['activity_logs.*'], 'page', $page);

        $paginator->setCollection(
            $paginator->getCollection()
                ->map(fn (ActivityLog $log): array => $this->transformer->transform($log))
                ->values()
        );

        return [
            'paginator' => $paginator,
            'care_count' => $careCount,
            'order_count' => $orderCount,
        ];
    }

    private function linkedActivityLogs(Customer $customer): Builder
    {
        $customerId = (int) $customer->getKey();
        $shopId = (int) $customer->shop_id;
        $linkedOrderIds = $this->linkedOrderIds($customer);
        $shopOrderIds = $this->shopOrderIds($shopId);
        $shopCustomerCareIds = $this->shopCustomerCareIds($shopId);
        $shopAssignmentIds = $this->shopAssignmentIds($shopId);
        $linkedOrderAssignmentIds = $this->linkedOrderAssignmentIds($linkedOrderIds, $shopId);

        return ActivityLog::query()
            ->with([
                'actor' => fn ($query) => $query->select('users.id', 'users.name'),
                'targetUser' => fn ($query) => $query->select('users.id', 'users.name'),
                'shop' => fn ($query) => $query->select('shops.id', 'shops.name'),
            ])
            ->where('activity_logs.shop_id', $shopId)
            ->whereIn('activity_logs.action', self::ACTIONS)
            ->where(function (Builder $journey) use (
                $customerId,
                $shopOrderIds,
                $shopCustomerCareIds,
                $shopAssignmentIds,
                $linkedOrderIds,
                $linkedOrderAssignmentIds,
                $customer
            ): void {
                // Preferred local customer identity in metadata. The subject
                // must still be a local row of the expected type.
                $journey->where(function (Builder $direct) use (
                    $customerId,
                    $shopOrderIds,
                    $shopCustomerCareIds,
                    $shopAssignmentIds
                ): void {
                    $this->whereMetadataCustomerId($direct, $customerId);
                    $direct->where(function (Builder $subject) use (
                        $customerId,
                        $shopOrderIds,
                        $shopCustomerCareIds,
                        $shopAssignmentIds
                    ): void {
                        $subject->where(function (Builder $entered) use ($customerId): void {
                            $entered->where('activity_logs.action', 'customer.entered_system')
                                ->where('activity_logs.subject_type', 'customer')
                                ->where('activity_logs.subject_id', (string) $customerId);
                        })->orWhere(function (Builder $order) use ($shopOrderIds): void {
                            $order->where('activity_logs.action', 'order.created')
                                ->where('activity_logs.subject_type', 'order')
                                ->whereIn('activity_logs.subject_id', $shopOrderIds);
                        })->orWhere(function (Builder $care) use ($shopCustomerCareIds): void {
                            $care->where('activity_logs.action', 'customer_care.completed')
                                ->where('activity_logs.subject_type', 'customer_care')
                                ->whereIn('activity_logs.subject_id', $shopCustomerCareIds);
                        })->orWhere(function (Builder $assignment) use ($shopAssignmentIds): void {
                            $assignment->whereIn('activity_logs.action', [
                                'customer_care.assigned',
                                'customer_care.reassigned',
                                'customer_care.reclaimed',
                            ])->where('activity_logs.subject_type', 'customer_care_assignment')
                                ->whereIn('activity_logs.subject_id', $shopAssignmentIds);
                        });
                    });
                })->orWhere(function (Builder $entered) use ($customerId): void {
                    // Legacy entry rows can be linked by their local subject
                    // even when metadata.customer_id was not persisted.
                    $entered->where('activity_logs.action', 'customer.entered_system')
                        ->where('activity_logs.subject_type', 'customer')
                        ->where('activity_logs.subject_id', (string) $customerId);
                })->orWhere(function (Builder $orderFallback) use ($linkedOrderIds, $customer): void {
                    // External fallback is deliberately narrow: same-shop
                    // activity (the outer predicate), same-shop local Order,
                    // matching external customer ID, and no local ID present.
                    if ($this->hasExternalCustomerId($customer)) {
                        $orderFallback->where('activity_logs.action', 'order.created')
                            ->where('activity_logs.subject_type', 'order')
                            ->whereIn('activity_logs.subject_id', $linkedOrderIds)
                            ->where('activity_logs.pancake_customer_id', (string) $customer->pancake_customer_id)
                            ->where(function (Builder $withoutLocalId): void {
                                $withoutLocalId->whereNull('activity_logs.metadata->customer_id')
                                    ->orWhere('activity_logs.metadata->customer_id', '');
                            });
                    } else {
                        $orderFallback->whereRaw('1 = 0');
                    }
                })->orWhere(function (Builder $assignment) use ($linkedOrderAssignmentIds): void {
                    // Assignment/reassignment/reclaim events are linked by
                    // the local CCA subject and its local Order source.
                    $assignment->whereIn('activity_logs.action', [
                        'customer_care.assigned',
                        'customer_care.reassigned',
                        'customer_care.reclaimed',
                    ])->where('activity_logs.subject_type', 'customer_care_assignment')
                        ->whereIn('activity_logs.subject_id', $linkedOrderAssignmentIds);
                })->orWhere(function (Builder $completed) use (
                    $shopCustomerCareIds,
                    $linkedOrderAssignmentIds
                ): void {
                    // Completion rows use the care as subject and the local
                    // assignment ID/source Order as the durable link.
                    $completed->where('activity_logs.action', 'customer_care.completed')
                        ->where('activity_logs.subject_type', 'customer_care')
                        ->whereIn('activity_logs.subject_id', $shopCustomerCareIds)
                        ->whereIn('activity_logs.metadata->assignment_id', $linkedOrderAssignmentIds);
                });
            });
    }

    private function whereMetadataCustomerId(Builder $query, int $customerId): void
    {
        $query->where(function (Builder $metadata) use ($customerId): void {
            $metadata->where('activity_logs.metadata->customer_id', $customerId)
                ->orWhere('activity_logs.metadata->customer_id', (string) $customerId);
        });
    }

    private function linkedOrderIds(Customer $customer): Builder
    {
        return Order::withTrashed()
            ->select('orders.id')
            ->where('orders.shop_id', (int) $customer->shop_id)
            ->where('orders.pancake_customer_id', (string) $customer->pancake_customer_id);
    }

    private function shopOrderIds(int $shopId): Builder
    {
        return Order::withTrashed()
            ->select('orders.id')
            ->where('orders.shop_id', $shopId);
    }

    private function shopCustomerCareIds(int $shopId): Builder
    {
        return CustomerCare::query()
            ->select('customer_cares.id')
            ->where('customer_cares.shop_id', $shopId);
    }

    private function shopAssignmentIds(int $shopId): Builder
    {
        return CustomerCareAssignment::query()
            ->select('customer_care_assignments.id')
            ->where('customer_care_assignments.shop_id', $shopId);
    }

    private function linkedOrderAssignmentIds(Builder $linkedOrderIds, int $shopId): Builder
    {
        return CustomerCareAssignment::query()
            ->select('customer_care_assignments.id')
            ->where('customer_care_assignments.shop_id', $shopId)
            ->where('customer_care_assignments.source_type', CustomerCareAssignment::SOURCE_ORDER)
            ->whereIn('customer_care_assignments.source_id', $linkedOrderIds);
    }

    private function hasExternalCustomerId(Customer $customer): bool
    {
        return trim((string) $customer->pancake_customer_id) !== '';
    }
}
