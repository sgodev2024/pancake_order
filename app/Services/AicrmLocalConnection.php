<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Loopback AICRM transport using a dedicated DEV API key. */
class AicrmLocalConnection
{
    public function validateConfiguration(): array
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
        $key = config('aicrm.local.api_key');
        abort_unless(is_string($key) && trim($key) !== '' && ! preg_match('/[\x00-\x20\x7f]/', $key),
            409, 'AICRM_LOCAL_API_KEY thiếu hoặc không hợp lệ.');

        return ['base_url' => rtrim($base, '/'), 'api_key' => $key];
    }

    public function validateCreation(): void
    {
        abort_unless(config('aicrm.local.upstream_verified') === true, 409,
            'Chưa xác minh AICRM DEV sử dụng upstream Viettel Post Sandbox/mock. Chỉ được xem trước payload.');
    }

    public function create(array $payload): Response
    {
        $connection = $this->validateConfiguration();
        $this->validateCreation();

        // A timeout may already have created a carrier order: never retry this POST.
        return $this->request($connection['api_key'])->post($connection['base_url'].'/api/v1/orders/auto', $payload);
    }

    public function providers(string $authentication = 'configured'): Response
    {
        $connection = $this->validateConfiguration();
        abort_unless(in_array($authentication, ['configured', 'missing', 'invalid'], true), 409);
        $key = match ($authentication) {
            'configured' => $connection['api_key'],
            'invalid' => 'invalid-local-test-'.bin2hex(random_bytes(16)),
            'missing' => null,
        };

        return $this->request($key)->get($connection['base_url'].'/api/v1/shipping-providers');
    }

    private function request(?string $key): PendingRequest
    {
        return Http::asJson()->acceptJson()
            ->withHeaders($key === null ? [] : ['X-API-Key' => $key])
            ->withoutRedirecting()
            ->connectTimeout(max(1, (int) config('aicrm.connect_timeout', 3)))
            ->timeout(max(1, (int) config('aicrm.timeout', 10)))
            ->withOptions(['verify' => true, 'cookies' => false, 'debug' => false, 'proxy' => '']);
    }
}
