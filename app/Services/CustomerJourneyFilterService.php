<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies Journey filters to Customer rows without joining or duplicating them.
 *
 * Action, employee and date are event-level filters and therefore share one
 * EXISTS predicate. Care count and order presence are independent customer-
 * level predicates over the complete linked Journey.
 */
class CustomerJourneyFilterService
{
    private const CARE_EMPLOYEE_ACTIONS = [
        'customer_care.assigned',
        'customer_care.reassigned',
        'customer_care.reclaimed',
        'customer_care.completed',
    ];

    public function __construct(
        private readonly CustomerJourneyLinkageService $linkage
    ) {}

    /** @param array<string, mixed> $filters */
    public function apply(Builder $customers, array $filters): void
    {
        if ($this->hasEventFilters($filters)) {
            $events = $this->linkage->forCustomerQuery();

            if (! empty($filters['journey_action'])) {
                $events->where('activity_logs.action', $filters['journey_action']);
            }

            if (! empty($filters['care_user_id'])) {
                $events->whereIn('activity_logs.action', self::CARE_EMPLOYEE_ACTIONS)
                    ->where('activity_logs.target_user_id', (int) $filters['care_user_id']);
            }

            $this->applyDateRange($events, $filters);
            $customers->whereExists($events);
        }

        if (isset($filters['care_count_min'])) {
            $customers->where(
                $this->countQuery('customer_care.completed'),
                '>=',
                (int) $filters['care_count_min']
            );
        }

        if (isset($filters['care_count_max'])) {
            $customers->where(
                $this->countQuery('customer_care.completed'),
                '<=',
                (int) $filters['care_count_max']
            );
        }

        if (($filters['has_order'] ?? 'all') === 'yes') {
            $customers->whereExists(
                $this->linkage->forCustomerQuery()
                    ->where('activity_logs.action', 'order.created')
            );
        } elseif (($filters['has_order'] ?? 'all') === 'no') {
            $customers->whereNotExists(
                $this->linkage->forCustomerQuery()
                    ->where('activity_logs.action', 'order.created')
            );
        }
    }

    /** @param array<string, mixed> $filters */
    private function hasEventFilters(array $filters): bool
    {
        return ! empty($filters['journey_action'])
            || ! empty($filters['journey_date_from'])
            || ! empty($filters['journey_date_to'])
            || ! empty($filters['care_user_id']);
    }

    /** @param array<string, mixed> $filters */
    private function applyDateRange(Builder $events, array $filters): void
    {
        if (! empty($filters['journey_date_from'])) {
            $from = CarbonImmutable::createFromFormat(
                'Y-m-d',
                $filters['journey_date_from'],
                config('app.timezone')
            )->startOfDay();
            $events->whereRaw(
                'COALESCE(activity_logs.occurred_at, activity_logs.created_at) >= ?',
                [$from->format('Y-m-d H:i:s')]
            );
        }

        if (! empty($filters['journey_date_to'])) {
            $to = CarbonImmutable::createFromFormat(
                'Y-m-d',
                $filters['journey_date_to'],
                config('app.timezone')
            )->endOfDay();
            $events->whereRaw(
                'COALESCE(activity_logs.occurred_at, activity_logs.created_at) <= ?',
                [$to->format('Y-m-d H:i:s.u')]
            );
        }
    }

    private function countQuery(string $action): Builder
    {
        return $this->linkage->forCustomerQuery()
            ->where('activity_logs.action', $action)
            ->selectRaw('COUNT(*)');
    }
}
