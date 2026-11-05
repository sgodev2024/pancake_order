<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\Shop;
use App\Models\User;
use App\Services\AicrmLiveConnection;
use App\Services\AicrmShippingProviders;
use App\Services\ShopAccessService;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class CheckAicrmLiveConnection extends Command
{
    protected $signature = 'aicrm:live-check
        {--shop-id= : Local shop ID with a confirmed tenant mapping}
        {--actor-id= : Local user with explicit warehouse-view permission and shop access}
        {--resource=providers : auth, providers, orders or detail; always GET}
        {--aicrm-order-id= : Authorized AICRM order ID for detail}';

    protected $description = 'Check an explicitly configured real AICRM connection using GET only; no response body or secrets are printed';

    public function handle(AicrmLiveConnection $connection, ShopAccessService $access, AicrmShippingProviders $providers): int
    {
        $shopId = filter_var($this->option('shop-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $actorId = filter_var($this->option('actor-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $shopId || ! $actorId) {
            $this->error('Cần --shop-id và --actor-id hợp lệ. Không có request nào được gửi.');

            return self::INVALID;
        }
        $actor = User::find($actorId);
        $allowed = $actor && Shop::whereKey($shopId)->exists() && $access->canAccessShop($actor, $shopId)
            && RolePermission::where('role_id', $actor->role_id)->whereIn('permission_id', Permission::where('slug', 'warehouse-view')->select('id'))->exists();
        if (! $allowed) {
            $this->error('Người thao tác không có quyền xem Kho của shop. Không có request nào được gửi.');

            return self::FAILURE;
        }
        try {
            $response = $connection->read((string) $this->option('resource'), $shopId, $this->option('aicrm-order-id'));
            $body = $response->json();
            $ok = $response->successful() && is_array($body) && empty($body['error']);
            $providerId = null;
            if ($ok && $this->option('resource') === 'providers') {
                try {
                    $providerId = $providers->viettelPostId($response);
                } catch (HttpExceptionInterface) {
                    $ok = false;
                }
            }
            $this->line(json_encode([
                'mode' => 'live', 'method' => 'GET', 'resource' => $this->option('resource'),
                'http_status' => $response->status(), 'json_response' => is_array($body),
                'http_json_check_passed' => $ok,
                'viettel_post_provider_id' => $providerId,
                'tenant_scope_confirmed_by_response' => false,
                'creation_enabled' => config('aicrm.live.creation_enabled') === true,
                'response_body_omitted' => true,
            ], JSON_UNESCAPED_UNICODE));
            if ($providers->cloudflareDenied($response)) {
                $this->error('Cloudflare HTTP 403 / 1010 chặn truy cập mạng. Cần AICRM xử lý quyền truy cập B2B; không tự retry hoặc vượt chặn.');
            } elseif ($response->successful() && $this->option('resource') === 'providers' && ! $ok) {
                $this->error('Chưa xác minh được đúng một VIETTEL_POST với isEnable=true và ID số nguyên trong danh sách AICRM.');
            }
            $this->comment('HTTP/JSON chỉ là bằng chứng kết nối; cần đối chiếu hợp đồng để xác nhận auth, tenant và provider.');

            return $ok ? self::SUCCESS : self::FAILURE;
        } catch (HttpExceptionInterface $error) {
            $this->error($error->getMessage());
        } catch (Throwable) {
            $this->error('Không nhận được phản hồi Live AICRM hợp lệ. Không in exception, URL credential hoặc body.');
        }

        return self::FAILURE;
    }
}
