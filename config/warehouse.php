<?php

return [
    // Explicit ISO-8601 instant with timezone (e.g. 2026-11-01T08:00:00+07:00).
    // Blank/invalid/future values do not activate the module. No automatic historical backfill.
    'activated_at' => env('WAREHOUSE_ACTIVATED_AT'),
];
