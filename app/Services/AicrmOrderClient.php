<?php

namespace App\Services;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AicrmOrderClient
{
    public function validateAutoConfiguration(): void
    {
        $this->autoConnection();
    }

    private function autoConnection(): array
    {
        $mode = config('aicrm.mode');
        abort_unless(in_array($mode, ['mock', 'mock_http', 'sandbox', 'live', 'local'], true), 409, 'Chế độ kết nối AICRM không hợp lệ.');
        abort_if($mode !== 'live' && app()->environment('production'), 409, 'Chỉ dùng AICRM Live trên Production.');

        if ($mode === 'mock') {
            return [];
        }

        $environment = in_array($mode, ['local', 'live'], true) ? 'aicrm.'.$mode : 'aicrm';
        $base = config($environment.'.base_url');
        $url = config('services.aicrm.auto_url') ?: ($base ? rtrim($base, '/').'/api/v1/orders/auto' : null);
        $key = config('services.aicrm.api_key') ?: config($environment.'.api_key');
        $parts = is_string($url) ? parse_url($url) : false;
        $host = strtolower($parts['host'] ?? '');
        $loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        abort_unless(is_array($parts) && $host !== ''
            && (($parts['scheme'] ?? '') === 'https' || ($loopback && ($parts['scheme'] ?? '') === 'http'))
            && str_ends_with($parts['path'] ?? '', '/api/v1/orders/auto')
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment']),
            409, 'Cần cấu hình URL AICRM /api/v1/orders/auto hợp lệ.');
        abort_unless(is_string($key) && trim($key) !== '' && ! preg_match('/[\x00-\x20\x7f]/', $key),
            409, 'Chưa cấu hình X-API-Key AICRM hợp lệ.');

        if ($mode === 'local') {
            abort_unless(app()->environment('local') && config('aicrm.local.enabled') === true && $loopback,
                409, 'AICRM Local chỉ được bật với URL loopback trong môi trường local.');
            app(AicrmLocalConnection::class)->validateCreation();
        }
        if ($mode === 'mock_http') {
            abort_unless($loopback, 409, 'AICRM Mock HTTP chỉ chấp nhận URL loopback.');
        }
        if ($mode === 'sandbox') {
            abort_unless(config('aicrm.sandbox_enabled') && in_array($host, config('aicrm.sandbox_hosts'), true),
                409, 'Cần bật Sandbox và cho phép máy chủ AICRM.');
        }
        if ($mode === 'live') {
            abort_unless(($parts['scheme'] ?? '') === 'https' && ! $loopback
                && in_array($host, config('aicrm.live.hosts'), true) && ($parts['port'] ?? 443) === 443,
                409, 'AICRM Live cần HTTPS và hostname đã được xác nhận.');
        }

        return [$url, $key, $loopback];
    }

    public function createAuto(array $payload, string $reference): Response
    {
        $connection = $this->autoConnection();
        if ($connection === []) {
            if (config('aicrm.mock_scenario') === 'timeout') {
                throw new ConnectionException('Mock timeout');
            }
            [$status, $body] = app(AicrmMockApi::class)->respond($payload, $reference, config('aicrm.mock_scenario'));

            return new Response(new PsrResponse($status, ['Content-Type' => 'application/json'], json_encode($body)));
        }

        [$url, $key, $loopback] = $connection;

        // A timeout may already have created a carrier order.
        $headers = ['X-API-Key' => $key];
        if (config('aicrm.mode') === 'local') {
            $headers['X-Correlation-Id'] = $reference;
        }

        return Http::asJson()->acceptJson()->withHeaders($headers)->withoutRedirecting()
            ->connectTimeout(max(1, config('aicrm.connect_timeout')))->timeout(max(1, config('aicrm.timeout')))
            ->withOptions($loopback ? ['proxy' => ''] : [])
            ->post($url, $payload);
    }
}
