<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AicrmLiveConnection
{
    public function validateConfiguration(int $shopId): void
    {
        abort_unless(config('aicrm.live.enabled') === true, 409, 'Kết nối Live AICRM đang tắt.');
        abort_unless(config('aicrm.live.contract_confirmed') === true, 409, 'Chưa xác minh hợp đồng API Live AICRM.');
        $url = parse_url((string) config('aicrm.live.base_url'));
        $host = strtolower($url['host'] ?? '');
        abort_unless($host !== '' && ($url['scheme'] ?? '') === 'https'
            && in_array($host, config('aicrm.live.hosts', []), true)
            && ($url['port'] ?? 443) === 443
            && ! isset($url['user']) && ! isset($url['pass'])
            && ! isset($url['query']) && ! isset($url['fragment']),
            409, 'Live AICRM cần HTTPS và hostname đã được xác nhận.');
        $this->validPath($url['path'] ?? '/');
        app(AicrmTenantCredentials::class)->forShop($shopId, true);
    }

    public function validateCreation(Order $order): void
    {
        $this->validateConfiguration((int) $order->shop_id);
        abort_unless(config('aicrm.mode') === 'live' && config('aicrm.live.creation_enabled') === true,
            409, 'Tạo vận đơn thật đang tắt; chỉ cho phép kiểm tra kết nối Live bằng GET.');
        abort_unless(config('aicrm.live.reconciliation_confirmed') === true,
            409, 'Chưa xác nhận quy trình đối soát AICRM khi timeout.');
        abort_unless((string) config('aicrm.live.approved_order_id') === (string) $order->id,
            409, 'Đơn chưa được phê duyệt cho lần tạo vận đơn Live có kiểm soát.');
        $value = config('aicrm.live.activated_at');
        $at = is_string($value) && preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/D', $value)
            ? app(WarehouseService::class)->sourceCreatedAt(['created_at' => $value], false) : null;
        $created = $order->origin === 'local_pos' ? $order->created_at : $order->source_created_at;
        abort_unless($at && $created && $created->greaterThanOrEqualTo($at),
            409, 'Chỉ cho phép đơn kiểm thử mới từ thời điểm kích hoạt Live đã xác nhận.');
        $this->endpoint('orders');
    }

    public function create(array $payload, int $shopId, int $orderId): Response
    {
        $order = Order::findOrFail($orderId);
        abort_unless((int) $order->shop_id === $shopId, 403, 'Shop Live không khớp đơn.');
        // Repeat order-level gates immediately before sending; never bypass via client.
        $this->validateCreation($order);

        return $this->request($shopId)->post($this->endpoint('orders'), $payload);
    }

    public function read(string $resource, int $shopId, ?string $orderId = null, int $page = 1, int $size = 1): Response
    {
        $this->validateConfiguration($shopId);

        // Only GET, even for auth verification. No login/password or create endpoint POST.
        return $this->request($shopId)->get($this->endpoint($resource, $orderId),
            $resource === 'orders' ? ['page' => max(1, $page), 'size' => max(1, $size)] : []);
    }

    /** Explicit GET reconciliation only. Never called automatically after POST. */
    public function findOrderByTracking(int $shopId, string $tracking): array
    {
        $this->validateConfiguration($shopId);
        $tenant = app(AicrmTenantCredentials::class)->forShop($shopId, true)['tenant_id'];
        $matches = $seen = [];
        $expected = null;
        $size = 100;
        for ($page = 1; ; $page++) {
            $response = $this->read('orders', $shopId, null, $page, $size);
            $body = $response->json();
            abort_unless($response->status() === 200 && is_array($body)
                && is_array($body['content'] ?? null) && array_is_list($body['content']),
                409, 'Danh sách đơn AICRM không hợp lệ; chưa liên kết UUID.');
            foreach (['pageNumber', 'pageSize', 'totalElements', 'totalPages'] as $field) {
                abort_unless(is_int($body[$field] ?? null) && $body[$field] >= 0,
                    409, 'Phân trang AICRM không hợp lệ; chưa liên kết UUID.');
            }
            $metadata = [$body['totalElements'], $body['totalPages']];
            abort_unless($body['pageNumber'] === $page && $body['pageSize'] === $size
                && $body['totalPages'] === (int) ceil($body['totalElements'] / $size)
                && $body['totalPages'] <= max(1, (int) config('aicrm.live.reconciliation_max_pages', 100))
                && ($expected === null || $metadata === $expected)
                && count($body['content']) === min($size, max(0, $body['totalElements'] - ($page - 1) * $size)),
                409, 'Danh sách AICRM vượt giới hạn hoặc thay đổi khi phân trang; cần đối soát lại.');
            $expected = $metadata;
            foreach ($body['content'] as $row) {
                abort_unless(is_array($row) && is_string($row['id'] ?? null) && Str::isUuid($row['id'])
                    && ! isset($seen[strtolower($row['id'])]),
                    409, 'Danh sách AICRM có UUID thiếu, sai hoặc trùng; chưa liên kết.');
                $seen[strtolower($row['id'])] = true;
                if (($row['vtpOrderNumber'] ?? null) === $tracking) {
                    $matches[] = $row;
                }
            }
            if ($page >= $body['totalPages']) {
                break;
            }
        }
        abort_unless(count($matches) === 1, 409, 'Cần đúng một đơn AICRM khớp mã vận đơn trong tenant; chưa liên kết UUID.');
        $response = $this->read('detail', $shopId, $matches[0]['id']);
        $detail = $response->json();
        abort_unless($response->status() === 200 && is_array($detail)
            && ($detail['id'] ?? null) === $matches[0]['id']
            && ($detail['tenantId'] ?? null) === $tenant
            && ($detail['vtpOrderNumber'] ?? null) === $tracking
            && AicrmOrderStatus::valid($detail['status'] ?? null),
            409, 'Chi tiết AICRM không khớp UUID, tenant, mã vận đơn hoặc trạng thái; chưa liên kết.');

        return ['id' => $detail['id'], 'status' => $detail['status']];
    }

    private function endpoint(string $resource, ?string $orderId = null): string
    {
        $key = match ($resource) {
            'auth' => 'auth_check_path', 'providers' => 'providers_path',
            'orders' => 'orders_path', 'detail' => 'order_detail_path',
            default => null,
        };
        abort_unless($key, 409, 'Loại kiểm tra chỉ đọc không hợp lệ.');
        $path = config('aicrm.live.'.$key);
        abort_unless(is_string($path) && $path !== '', 409, 'Chưa cấu hình endpoint Live đã được AICRM xác nhận: '.$resource.'.');
        if ($resource === 'detail') {
            abort_unless(substr_count($path, '{id}') === 1 && is_string($orderId)
                && Str::isUuid($orderId), 409, 'Cần UUID đơn AICRM và mẫu endpoint chi tiết hợp lệ.');
            $path = str_replace('{id}', $orderId, $path);
        }
        $this->validPath($path);

        return rtrim(config('aicrm.live.base_url'), '/').$path;
    }

    private function validPath(string $path): void
    {
        $decoded = rawurldecode($path);
        abort_unless(str_starts_with($decoded, '/') && ! str_starts_with($decoded, '//')
            && ! preg_match('/[\x00-\x20\\\\?#{}%]/', $decoded)
            && ! in_array('..', explode('/', $decoded), true) && ! in_array('.', explode('/', $decoded), true),
            409, 'Đường dẫn API Live phải thuộc hostname đã xác nhận.');
    }

    private function request(int $shopId): PendingRequest
    {
        $headers = ['X-API-Key' => app(AicrmTenantCredentials::class)->forShop($shopId, true)['api_key']];

        return Http::asJson()->acceptJson()->withHeaders($headers)->withoutRedirecting()
            ->connectTimeout(max(1, config('aicrm.connect_timeout')))->timeout(max(1, config('aicrm.timeout')))
            ->withOptions(['verify' => true, 'cookies' => false]);
    }
}
