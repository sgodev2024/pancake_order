<?php

namespace App\Enums;

enum ActivityLogSubjectType: string
{
    case CUSTOMER = 'customer';
    case ORDER = 'order';
    case CUSTOMER_CARE = 'customer_care';
    case CUSTOMER_CARE_ASSIGNMENT = 'customer_care_assignment';
}
