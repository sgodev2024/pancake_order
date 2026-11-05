<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Real loopback transport for Local GET checks only; deliberately has no create method. */
class AicrmLocalConnection
{
    public function validateConfiguration(int $shopId): array
    {
        abort_unless(app()->environment('local') && config('aicrm.local.enabled') === true,
            409, 'Kết nối AICRM Local chỉ được bật trong môi trường local.');
        $base = config('aicrm.local.base_url');
        $url = is_string($base) ? parse_url($base) : false;
        abort_unless(is_array($url) && in_array(strtolower($url['host'] ?? ''), ['127.0.0.1', 'localhost'], true)
            && in_array($url['scheme'] ?? '', ['http', 'https'], true)
            && in_array($url['path'] ?? '', ['', '/'], true)
            && ($url['port'] ?? 80) > 0 && ($url['port'] ?? 80) <= 65535
            && ! isset($url['user']) && ! isset($url['pass'])
            && ! isset($url['query']) && ! isset($url['fragment']),
            409, 'AICRM Local chỉ chấp nhận origin loopback 127.0.0.1 hoặc localhost.');
        $tenant = config('aicrm.local.shop_tenants.'.$shopId);
        abort_unless(is_string($tenant) && Str::isUuid($tenant)
            && $tenant === config('aicrm.local.credential_tenant'),
            409, 'Tenant TEST của shop không khớp tenant đã xác nhận của API Key Local.');
        $directory = realpath(storage_path('app/private/aicrm-local'));
        $configuredFile = config('aicrm.local.api_key_file');
        $file = is_string($configuredFile) && $configuredFile !== '' ? realpath($configuredFile) : false;
        abort_unless(is_string($directory) && is_string($file)
            && str_starts_with($file, $directory.DIRECTORY_SEPARATOR)
            && is_file($file) && is_readable($file) && filesize($file) > 0 && filesize($file) <= 512
            && (fileperms($file) & 0077) === 0,
            409, 'Cần file API Key TEST riêng, quyền 0600, trong storage/app/private/aicrm-local.');
        $key = trim((string) file_get_contents($file));
        abort_unless($key !== '' && ! preg_match('/[\x00-\x20\x7f]/', $key),
            409, 'API Key TEST Local thiếu hoặc không hợp lệ.');

        return ['base_url' => rtrim($base, '/'), 'tenant_id' => $tenant, 'api_key' => $key];
    }

    public function providers(int $shopId, string $authentication = 'configured'): Response
    {
        $connection = $this->validateConfiguration($shopId);
        abort_unless(in_array($authentication, ['configured', 'missing', 'invalid'], true), 409);
        $key = match ($authentication) {
            'configured' => $connection['api_key'],
            'invalid' => 'invalid-local-test-'.bin2hex(random_bytes(16)),
            'missing' => null,
        };

        return Http::asJson()->acceptJson()
            ->withHeaders($key === null ? [] : ['X-API-Key' => $key])
            ->withoutRedirecting()
            ->connectTimeout(max(1, (int) config('aicrm.connect_timeout', 3)))
            ->timeout(max(1, (int) config('aicrm.timeout', 10)))
            ->withOptions(['verify' => true, 'cookies' => false, 'debug' => false, 'proxy' => ''])
            ->get($connection['base_url'].'/api/v1/shipping-providers');
    }
}
