<?php

namespace App\Services;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AicrmOrderClient
{
    public function validateConfiguration(int $shopId): void
    {
        $mode = config('aicrm.mode');
        abort_unless(in_array($mode, ['mock', 'mock_http', 'sandbox', 'live'], true), 409, 'Chế độ kết nối AICRM không hợp lệ.');
        if ($mode === 'live') {
            app(AicrmLiveConnection::class)->validateConfiguration($shopId);

            return;
        }
        abort_if(app()->environment('production'), 409, 'Tạo vận đơn AICRM chưa được mở trên Production.');
        if ($mode === 'mock') {
            return;
        }
        $url = parse_url((string) config('aicrm.base_url'));
        $host = strtolower($url['host'] ?? '');
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        $allowed = $mode === 'mock_http' ? $local
            : config('aicrm.sandbox_enabled') && in_array($host, config('aicrm.sandbox_hosts'), true);
        abort_unless($allowed && $host !== ''
            && (($url['scheme'] ?? '') === 'https' || ($local && ($url['scheme'] ?? '') === 'http'))
            && empty($url['user']) && empty($url['pass']) && empty($url['query']) && empty($url['fragment']),
            409, 'Cần cấu hình URL và cho phép máy chủ Sandbox AICRM.');
        app(AicrmTenantCredentials::class)->forShop($shopId, false);
    }

    public function create(array $payload, int $shopId, string $reference, ?int $orderId = null): Response
    {
        $this->validateConfiguration($shopId);
        if (config('aicrm.mode') === 'live') {
            return app(AicrmLiveConnection::class)->create($payload, $shopId, $orderId ?? 0);
        }
        if (config('aicrm.mode') === 'mock') {
            if (config('aicrm.mock_scenario') === 'timeout') {
                throw new ConnectionException('Mock timeout');
            }
            [$status, $body] = app(AicrmMockApi::class)->respond($payload, $reference, config('aicrm.mock_scenario'));

            return new Response(new PsrResponse($status, ['Content-Type' => 'application/json'], json_encode($body)));
        }
        $headers = ['X-API-Key' => app(AicrmTenantCredentials::class)->forShop($shopId, false)['api_key']];

        // Never retry POST automatically; AICRM v1 does not document idempotency.
        // Never follow a redirect that could forward credentials to another host.
        return Http::asJson()->acceptJson()->withHeaders($headers)->withoutRedirecting()
            ->connectTimeout(max(1, config('aicrm.connect_timeout')))->timeout(max(1, config('aicrm.timeout')))
            ->withOptions(['verify' => true, 'cookies' => false])
            ->post(rtrim(config('aicrm.base_url'), '/').'/api/v1/orders', $payload);
    }
}
