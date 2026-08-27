<?php

namespace App\Enums;

enum ActivityLogAction: string
{
    case CUSTOMER_ENTERED_SYSTEM = 'customer.entered_system';
    case CUSTOMER_CARE_ASSIGNED = 'customer_care.assigned';
    case CUSTOMER_CARE_REASSIGNED = 'customer_care.reassigned';
    case CUSTOMER_CARE_RECLAIMED = 'customer_care.reclaimed';
    case CUSTOMER_CARE_COMPLETED = 'customer_care.completed';
    case ORDER_CREATED = 'order.created';

    /**
     * The stable local subject type expected for a newly written Journey
     * event. Legacy activity-log rows are intentionally not rewritten.
     *
     * @return list<string>
     */
    public function expectedSubjectTypes(): array
    {
        return match ($this) {
            self::CUSTOMER_ENTERED_SYSTEM => ['customer'],
            self::CUSTOMER_CARE_ASSIGNED,
            self::CUSTOMER_CARE_REASSIGNED,
            self::CUSTOMER_CARE_RECLAIMED => ['customer_care_assignment'],
            self::CUSTOMER_CARE_COMPLETED => ['customer_care'],
            self::ORDER_CREATED => ['order'],
        };
    }
}
