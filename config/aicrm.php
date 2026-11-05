<?php

return [
    // Mock/Sandbox remain separate from the opt-in Live connection below.
    'mode' => env('AICRM_MODE', 'mock'),
    'base_url' => env('AICRM_API_URL'),
    // Partner contract: a raw API key, never a browser token or Bearer credential.
    'api_key' => env('AICRM_API_KEY'),
    'shipping_provider_id' => env('AICRM_SHIPPING_PROVIDER_ID'),
    'tenant_header' => env('AICRM_TENANT_HEADER') ?: null,
    'tenant_mode' => 'api_key',
    'credential_tenant' => env('AICRM_CREDENTIAL_TENANT'),
    // JSON object keyed by LOCAL shop ID. Never accept tenant IDs from the browser.
    'shop_tenants' => json_decode(env('AICRM_SHOP_TENANTS', '{}'), true) ?: [],
    'tenant_credentials' => json_decode(env('AICRM_TENANT_CREDENTIALS', '{}'), true) ?: [],
    'sandbox_enabled' => env('AICRM_SANDBOX_ENABLED', false),
    'sandbox_hosts' => array_filter(explode(',', env('AICRM_SANDBOX_HOSTS', '127.0.0.1,localhost,::1'))),
    'timeout' => (int) env('AICRM_TIMEOUT', 10),
    'connect_timeout' => (int) env('AICRM_CONNECT_TIMEOUT', 3),
    'mock_scenario' => env('AICRM_MOCK_SCENARIO', 'success'),
    // Separate read-only Local integration; never enables shipment creation or changes Live/Sandbox gates.
    'local' => [
        'enabled' => env('AICRM_LOCAL_ENABLED', false),
        'base_url' => env('AICRM_LOCAL_API_URL', 'http://127.0.0.1:8185'),
        'api_key_file' => env('AICRM_LOCAL_API_KEY_FILE'),
        'credential_tenant' => env('AICRM_LOCAL_CREDENTIAL_TENANT'),
        'shop_tenants' => json_decode(env('AICRM_LOCAL_SHOP_TENANTS', '{}'), true) ?: [],
        'expected_provider_id' => env('AICRM_LOCAL_EXPECTED_PROVIDER_ID'),
    ],
    'live' => [
        'enabled' => env('AICRM_LIVE_ENABLED', false),
        'creation_enabled' => env('AICRM_LIVE_CREATION_ENABLED', false),
        'contract_confirmed' => env('AICRM_LIVE_CONTRACT_CONFIRMED', false),
        'base_url' => env('AICRM_LIVE_API_URL'),
        'hosts' => array_values(array_filter(array_map('trim', explode(',', env('AICRM_LIVE_HOSTS', ''))))),
        'api_key' => env('AICRM_LIVE_API_KEY'),
        'tenant_mode' => env('AICRM_LIVE_TENANT_MODE', 'api_key'),
        'tenant_header' => env('AICRM_LIVE_TENANT_HEADER') ?: null,
        'credential_tenant' => env('AICRM_LIVE_CREDENTIAL_TENANT'),
        'shop_tenants' => json_decode(env('AICRM_LIVE_SHOP_TENANTS', '{}'), true) ?: [],
        'tenant_credentials' => json_decode(env('AICRM_LIVE_TENANT_CREDENTIALS', '{}'), true) ?: [],
        'shipping_provider_id' => env('AICRM_LIVE_SHIPPING_PROVIDER_ID'),
        // Paths documented in order_api (2).md; the deployment hostname stays unset.
        'auth_check_path' => env('AICRM_LIVE_AUTH_CHECK_PATH'),
        'providers_path' => env('AICRM_LIVE_PROVIDERS_PATH', '/api/v1/shipping-providers'),
        'orders_path' => env('AICRM_LIVE_ORDERS_PATH', '/api/v1/orders'),
        'order_detail_path' => env('AICRM_LIVE_ORDER_DETAIL_PATH', '/api/v1/orders/{id}'),
        'reconciliation_max_pages' => 100,
        'reconciliation_confirmed' => env('AICRM_LIVE_RECONCILIATION_CONFIRMED', false),
        // Authorize at most one new local order for the first controlled real creation.
        'approved_order_id' => env('AICRM_LIVE_APPROVED_ORDER_ID'),
        'activated_at' => env('AICRM_LIVE_ACTIVATED_AT'),
    ],
];
