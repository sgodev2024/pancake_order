<?php

return [
    'auto_reclaim' => [
        'enabled' => (bool) env('AUTO_RECLAIM_CUSTOMER_CARE_ENABLED', false),
        'limit' => (int) env('AUTO_RECLAIM_CUSTOMER_CARE_LIMIT', 500),
    ],
];
