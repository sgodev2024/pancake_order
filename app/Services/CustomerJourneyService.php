<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
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
        private readonly CustomerJourneyTransformer $transformer,
        private readonly CustomerJourneyLinkageService $linkage
    ) {}

    /**
     * @return array{paginator: LengthAwarePaginator, care_count: int, order_count: int}
     */
    public function paginate(Customer $customer, int $page, int $pageSize, ?string $action = null): array
    {
        $query = $this->linkage->forCustomer($customer);

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
}
