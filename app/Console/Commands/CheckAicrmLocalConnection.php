<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Shop;
use App\Models\User;
use App\Services\AicrmLocalConnection;
use App\Services\AicrmShippingProviders;
use App\Services\ShopAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class CheckAicrmLocalConnection extends Command
{
    protected $signature = 'aicrm:local-check
        {--shop-id= : Shop TEST Local}
        {--actor-id= : Người có quyền warehouse-view và quyền shop}
        {--verify-rejections : Thử GET thiếu/sai key và kiểm tra binding sai tenant}';

    protected $description = 'GET thật đến AICRM loopback; không tạo vận đơn, không xuất key hoặc raw response';

    public function handle(AicrmLocalConnection $connection, ShopAccessService $access, AicrmShippingProviders $providers): int
    {
        // Check the environment before touching the application database.
        if (! app()->environment('local') || config('aicrm.local.enabled') !== true) {
            $this->error('AICRM Local đang tắt hoặc môi trường không phải local.');

            return self::FAILURE;
        }
        $shopId = filter_var($this->option('shop-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $actorId = filter_var($this->option('actor-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $shopId || ! $actorId) {
            $this->error('Cần --shop-id và --actor-id TEST hợp lệ.');

            return self::INVALID;
        }
        try {
            $actor = User::find($actorId);
            $allowed = $actor && Shop::whereKey($shopId)->exists() && $access->canAccessShop($actor, $shopId)
                && RolePermission::where('role_id', $actor->role_id)
                    ->whereIn('permission_id', Permission::where('slug', 'warehouse-view')->select('id'))->exists();
            abort_unless($allowed, 403, 'Người kiểm thử không có quyền xem Kho của shop TEST.');
            $response = $connection->providers($shopId);
            $body = $response->json();
            $shape = $response->status() === 200 && is_array($body) && array_is_list($body);
            $summary = [];
            if ($shape) {
                foreach ($body as $provider) {
                    if (! is_array($provider) || ! is_int($provider['id'] ?? null) || $provider['id'] <= 0
                        || ! is_string($provider['code'] ?? null) || ! is_bool($provider['isEnable'] ?? null)) {
                        $shape = false;
                        break;
                    }
                    // Whitelist only; never print provider token or sender details.
                    $summary[] = array_intersect_key($provider, array_flip(['id', 'code', 'name', 'isEnable']));
                }
            }
            $matches = array_values(array_filter($summary, fn ($provider) => $provider['code'] === 'VIETTEL_POST'
                && $provider['id'] === (int) config('aicrm.local.expected_provider_id')));
            $ok = $shape && count($matches) === 1;
            $selected = null;
            if ($ok && $matches[0]['isEnable'] === true) {
                try {
                    $selected = $providers->viettelPostId($response);
                    if ($selected !== $matches[0]['id']) {
                        $selected = null;
                    }
                } catch (HttpExceptionInterface) {
                    // A disabled provider is a successful read, not readiness to create.
                }
            }
            $result = [
                'mode' => 'local', 'transport' => 'real_http', 'method' => 'GET',
                'http_status' => $response->status(), 'response_format_valid' => $shape,
                'shop_id' => $shopId, 'actor_id' => $actorId,
                'configured_tenant_id' => config('aicrm.local.shop_tenants.'.$shopId),
                'tenant_scope_confirmed_by_response' => false,
                'expected_provider_found' => count($matches) === 1,
                'providers' => $summary, 'selected_shipping_provider_id' => $selected,
                'provider_ready_for_creation' => $selected !== null,
                'creation_enabled' => false,
                'live_enabled' => config('aicrm.live.enabled') === true,
                'live_creation_enabled' => config('aicrm.live.creation_enabled') === true,
                'raw_response_omitted' => true,
            ];
            if ($this->option('verify-rejections')) {
                foreach (['missing', 'invalid'] as $probe) {
                    $status = $connection->providers($shopId, $probe)->status();
                    $result['rejections'][$probe.'_key'] = ['http_status' => $status, 'request_sent' => true];
                    $ok = $ok && $status === 401;
                }
                $path = 'aicrm.local.shop_tenants.'.$shopId;
                $original = config($path);
                $rejected = false;
                try {
                    config([$path => (string) Str::uuid()]);
                    $connection->validateConfiguration($shopId);
                } catch (HttpExceptionInterface $error) {
                    $rejected = $error->getStatusCode() === 409;
                } finally {
                    config([$path => $original]);
                }
                $result['rejections']['wrong_tenant'] = ['http_status' => $rejected ? 409 : null,
                    'layer' => 'pancake_tenant_binding', 'request_sent' => false];
                $ok = $ok && $rejected;
            }
            $result['connection_result'] = $ok ? 'PASS' : 'FAIL';
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->comment($selected === null
                ? 'Provider TEST chưa đủ điều kiện tạo vận đơn. Local chỉ cho phép GET.'
                : 'Local chỉ kiểm tra GET; không có chức năng tạo vận đơn trong kết nối này.');

            return $ok ? self::SUCCESS : self::FAILURE;
        } catch (HttpExceptionInterface $error) {
            $this->error($error->getMessage());
        } catch (Throwable) {
            $this->error('Kiểm tra AICRM Local thất bại; không in exception hoặc raw response.');
        }

        return self::FAILURE;
    }
}
