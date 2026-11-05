<?php

namespace App\Services;

use Illuminate\Support\Str;

class AicrmTenantCredentials
{
    /** Resolve only server configuration; an API key is scoped to exactly one tenant. */
    public function forShop(int $shopId, bool $live): array
    {
        $prefix = $live ? 'aicrm.live.' : 'aicrm.';
        abort_unless(config($prefix.'tenant_header') === null
            && config($prefix.'tenant_mode', 'api_key') === 'api_key',
            409, 'AICRM xác định tenant bằng X-API-Key; không hỗ trợ header tenant riêng.');
        $tenant = config($prefix.'shop_tenants.'.$shopId);
        abort_unless(is_string($tenant) && Str::isUuid($tenant),
            409, 'Shop chưa được ánh xạ UUID tenant AICRM hợp lệ.');
        $credentials = config($prefix.'tenant_credentials', []);
        abort_unless(is_array($credentials), 409, 'Cấu hình credential tenant AICRM không hợp lệ.');
        // A configured map never falls back to the shared key for an unmapped tenant.
        $credential = $credentials !== [] ? ($credentials[$tenant] ?? null) : (
            config($prefix.'credential_tenant') === $tenant ? [
                'api_key' => config($prefix.'api_key'),
                'shipping_provider_id' => config($prefix.'shipping_provider_id'),
            ] : null
        );
        abort_unless(is_array($credential), 409, 'Chưa có API Key riêng cho tenant của shop.');
        $key = $credential['api_key'] ?? null;
        abort_unless(is_string($key) && trim($key) !== '' && ! preg_match('/[\x00-\x20\x7f]/', $key),
            409, 'Chưa cấu hình API Key AICRM hợp lệ cho tenant của shop.');
        foreach ($credentials as $otherTenant => $other) {
            abort_if($otherTenant !== $tenant && is_array($other) && ($other['api_key'] ?? null) === $key,
                409, 'Không được dùng cùng API Key AICRM cho nhiều tenant.');
        }
        abort_if(filled(config($prefix.'credential_tenant')) && config($prefix.'credential_tenant') !== $tenant
            && config($prefix.'api_key') === $key,
            409, 'API Key AICRM đã được cấu hình cho tenant khác.');

        return ['tenant_id' => $tenant, 'api_key' => $key, 'shipping_provider_id' => $credential['shipping_provider_id'] ?? null];
    }
}
