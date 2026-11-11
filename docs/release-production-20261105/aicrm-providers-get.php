<?php

// Run manually on Pancake's server only after approval. Exactly one GET; no raw body/headers.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$options = getopt('', ['root:', 'shop-id:']);
$root = realpath($options['root'] ?? dirname(__DIR__, 2));
$out = ['method' => 'GET', 'requests_sent' => 0, 'post_requests_sent' => 0, 'http_status' => null,
    'providers' => [], 'viettel_post_provider_id' => null, 'response_body_omitted' => true];
$envHash = $root && is_file($root.'/.env') ? hash_file('sha256', $root.'/.env') : null;
try {
    if (! $root || ! is_file($root.'/vendor/autoload.php')) {
        throw new RuntimeException('invalid_root');
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (! $app->environment('production') || config('aicrm.live.creation_enabled') !== false) {
        $out['error'] = 'Requires APP_ENV=production and effective AICRM_LIVE_CREATION_ENABLED=false. No request sent.';
        throw new RuntimeException('guard');
    }
    $url = rtrim((string) config('aicrm.live.base_url'), '/');
    if ($url !== 'https://api-vtp.aicrm.vn'
        || ! in_array('api-vtp.aicrm.vn', config('aicrm.live.hosts', []), true)
        || config('aicrm.live.providers_path') !== '/api/v1/shipping-providers') {
        $out['error'] = 'Requires the confirmed HTTPS host and exact shipping-providers path. No request sent.';
        throw new RuntimeException('guard');
    }
    $key = config('aicrm.live.api_key');
    if (isset($options['shop-id'])) {
        $shop = filter_var($options['shop-id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $shop) {
            throw new RuntimeException('invalid_shop');
        }
        $credential = app(App\Services\AicrmTenantCredentials::class)->forShop($shop, true);
        $key = $credential['api_key'];
        $out['shop_id'] = $shop;
    } elseif ((array) config('aicrm.live.tenant_credentials', []) !== []) {
        $out['error'] = 'Tenant credential map is configured: select one authorized shop with --shop-id. No fallback to a shared key.';
        throw new RuntimeException('guard');
    }
    if (! is_string($key) || $key === '' || preg_match('/[\x00-\x20\x7f]/', $key)) {
        $out['error'] = 'API Key missing/invalid in effective Backend config; check config cache privately. No request sent.';
        throw new RuntimeException('guard');
    }
    $out['server_hostname'] = gethostname();
    $out['checked_at_utc'] = gmdate(DATE_ATOM);
    $out['endpoint'] = $url.'/api/v1/shipping-providers';
    $out['creation_enabled'] = false;
    $out['config_cached'] = $app->configurationIsCached();
    $client = new GuzzleHttp\Client();
    $out['requests_sent'] = 1;
    $response = $client->request('GET', $out['endpoint'], [
        'headers' => ['X-API-Key' => $key, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
        'verify' => true, 'allow_redirects' => false, 'cookies' => false, 'http_errors' => false,
        'connect_timeout' => 10, 'timeout' => 30, 'proxy' => '', 'debug' => false, 'stream' => true,
    ]);
    $out['http_status'] = $response->getStatusCode();
    $stream = $response->getBody();
    $raw = $stream->read(1024 * 1024 + 1);
    $stream->close();
    $body = strlen($raw) <= 1024 * 1024 ? json_decode($raw, true) : null;
    $out['json_response'] = is_array($body);
    if ($out['http_status'] === 200 && is_array($body) && array_is_list($body)) {
        $matches = [];
        foreach ($body as $provider) {
            if (! is_array($provider) || ! is_int($provider['id'] ?? null) || $provider['id'] <= 0) {
                continue;
            }
            $code = $provider['code'] ?? null;
            $code = is_string($code) && preg_match('/^[A-Z0-9_]{1,40}$/D', $code) ? $code : null;
            $enabled = is_bool($provider['isEnable'] ?? null) ? $provider['isEnable'] : null;
            $out['providers'][] = ['id' => $provider['id'], 'code' => $code, 'enabled' => $enabled];
            if ($code === 'VIETTEL_POST' && $enabled === true) {
                $matches[] = $provider['id'];
            }
        }
        if (count($matches) === 1) {
            $out['viettel_post_provider_id'] = $matches[0];
        } else {
            $out['error'] = 'Expected exactly one enabled VIETTEL_POST provider.';
        }
    } elseif ($out['http_status'] === 403 && ($body['error_code'] ?? null) === 1010) {
        $out['error_code'] = 1010;
        $out['error'] = 'Cloudflare denied network access. No retries or bypass attempted.';
    } elseif (in_array($out['http_status'], [401, 403], true)) {
        $out['error'] = 'Authentication/authorization rejected; raw response omitted.';
    } elseif ($out['http_status'] >= 300 && $out['http_status'] < 400) {
        $out['error'] = 'Redirect refused; the key was not forwarded.';
    } else {
        $out['error'] = 'Unexpected HTTP status or provider JSON schema; raw response omitted.';
    }
    unset($key, $credential, $raw, $body, $response, $client);
} catch (Throwable $error) {
    $out['error'] ??= 'Configuration, network, TLS or timeout error; exception details omitted to protect credentials.';
}
$out['env_unchanged'] = $envHash !== null && hash_equals($envHash, hash_file('sha256', $root.'/.env'));
$out['tenant_verified_by_response'] = false;
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(isset($out['error']) ? 1 : 0);
