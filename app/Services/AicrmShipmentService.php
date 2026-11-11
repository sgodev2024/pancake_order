<?php

namespace App\Services;

use App\Models\AicrmShipment;
use App\Models\AicrmShipmentAttempt;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AicrmShipmentService
{
    private array $providerChecks = [];

    public function __construct(
        private readonly WarehouseService $warehouse,
        private readonly AicrmOrderClient $client,
        private readonly AicrmOrderPayload $payload,
    ) {}

    /** Reasons are safe to expose; never return credentials or upstream error bodies. */
    public function creationBlockers(Order $order): array
    {
        $reasons = [];
        $add = function (string $code, string $message, string $remedy) use (&$reasons) {
            $reasons[] = compact('code', 'message', 'remedy');
        };
        if (! $this->warehouse->enabled() || ! $this->warehouse->inActiveQueue($order)) {
            $add('warehouse_inactive', 'Đơn không thuộc hàng đợi Kho đang hoạt động.', 'Kiểm tra cấu hình Kho và ngày tạo đơn.');
        }
        if ($order->warehouse_status !== Order::WAREHOUSE_PACKED || ! $order->packed_at) {
            $add('not_packed', 'Đơn chưa hoàn tất đóng hàng.', 'Hoàn tất đóng hàng trước khi tạo vận đơn.');
        }
        if (! $this->warehouse->packableStatus($order->status)) {
            $add('order_status', 'Trạng thái đơn gốc không cho phép tạo vận đơn.', 'Kiểm tra trạng thái đơn trước khi tiếp tục.');
        }
        if (filled($order->order_number_vtp) || filled(data_get($order->pancake_full_data, 'partner.order_number_vtp'))) {
            $add('existing_tracking', 'Đơn đã có mã vận đơn.', 'Dùng mã hiện có, không tạo thêm.');
        }
        $shipment = $order->aicrmShipment;
        if ($shipment && ($shipment->status !== 'failed' || $shipment->mode !== config('aicrm.mode') || (int) $shipment->shop_id !== (int) $order->shop_id)) {
            $add('existing_shipment', 'Đơn đã có shipment hoặc đang chờ đối soát.', 'Xem lịch sử; không gửi lại khi processing/unknown hoặc đổi môi trường.');
        }
        if (config('aicrm.mode') === 'live') {
            foreach (['enabled' => 'Kết nối Live', 'creation_enabled' => 'Tạo vận đơn Live',
                'contract_confirmed' => 'Xác nhận hợp đồng API', 'reconciliation_confirmed' => 'Xác nhận quy trình đối soát timeout'] as $key => $label) {
                if (config('aicrm.live.'.$key) !== true) {
                    $add('live_'.$key, $label.' đang tắt.', 'Cần người phụ trách xác nhận và bật riêng trên Backend; không bật từ trình duyệt.');
                }
            }
            if ((string) config('aicrm.live.approved_order_id') !== (string) $order->id) {
                $add('order_not_approved', 'Đơn chưa được phê duyệt cho Live.', 'Chỉ phê duyệt đúng một ID sau khi kiểm tra người nhận và tiền hàng.');
            }
        }
        if (in_array(config('aicrm.mode'), ['live', 'sandbox'], true)) {
            try {
                $this->payload->validateRecipient($order);
            } catch (ValidationException $error) {
                $add('invalid_recipient', collect($error->errors())->flatten()->implode(' '), 'Dùng đơn có thông tin giao hàng đã được xác nhận; không tự sửa dữ liệu test.');
            } catch (HttpExceptionInterface $error) {
                $add('invalid_recipient', $error->getMessage(), 'Dùng đơn có người nhận hợp lệ qua luồng có audit.');
            }
        }
        try {
            if (config('aicrm.mode') !== 'mock') {
                app(AicrmTenantCredentials::class)->forShop((int) $order->shop_id, config('aicrm.mode') === 'live');
            }
            $this->client->validateConfiguration((int) $order->shop_id);
            $tenant = config('aicrm.mode') === 'mock' ? null
                : app(AicrmTenantCredentials::class)->forShop((int) $order->shop_id, config('aicrm.mode') === 'live')['tenant_id'];
            if ($shipment && $shipment->tenant_id !== $tenant) {
                $add('tenant_changed', 'Tenant shipment không khớp cấu hình hiện tại.', 'Đối soát đúng tenant trước khi tiếp tục.');
            }
            $remote = in_array(config('aicrm.mode'), ['live', 'sandbox'], true);
            if (! $remote) {
                $this->payload->make($order);
            }
            if ($remote && $reasons === []) {
                if (config('aicrm.mode') === 'live') {
                    app(AicrmLiveConnection::class)->validateCreation($order);
                }
                if (! array_key_exists($order->shop_id, $this->providerChecks)) {
                    try {
                        $id = $this->client->shippingProviderId((int) $order->shop_id);
                        $this->providerChecks[$order->shop_id] = ['id' => $id, 'error' => null];
                    } catch (Throwable $error) {
                        $this->providerChecks[$order->shop_id] = ['id' => null, 'error' => $error instanceof HttpExceptionInterface
                            ? $error->getMessage() : 'Không kiểm tra được provider AICRM; chưa gửi POST.'];
                    }
                }
                if ($message = $this->providerChecks[$order->shop_id]['error']) {
                    $add('provider_not_ready', $message, 'Kiểm tra quyền API Key và đúng một provider VIETTEL_POST đang bật tại AICRM, rồi tải lại.');
                } else {
                    $this->payload->make($order, shippingProviderId: $this->providerChecks[$order->shop_id]['id']);
                }
            }
        } catch (ValidationException $error) {
            $add('invalid_payload', collect($error->errors())->flatten()->implode(' '), 'Kiểm tra sản phẩm, số lượng, tổng tiền và COD của đơn.');
        } catch (HttpExceptionInterface $error) {
            $add('configuration', $error->getMessage(), 'Kiểm tra URL, credential và binding riêng cho Shop trên Backend.');
        }

        return $reasons;
    }

    public function canSimulate(Order $order): bool
    {
        return app()->environment('local') && config('aicrm.simulation_enabled') === true
            && in_array((int) $order->shop_id, config('aicrm.simulation_shop_ids', []), true)
            && $this->warehouse->enabled() && $this->warehouse->inActiveQueue($order)
            && $order->warehouse_status === Order::WAREHOUSE_PACKED && $order->packed_at
            && $this->warehouse->packableStatus($order->status)
            && ! $order->aicrmShipment && blank($order->order_number_vtp)
            && blank(data_get($order->pancake_full_data, 'partner.order_number_vtp'));
    }

    public function simulate(User $actor, int $orderId): array
    {
        abort_unless(app()->environment('local'), 403, 'Mô phỏng TEST/Mock chỉ có trên Local.');

        return DB::transaction(function () use ($actor, $orderId) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $this->warehouse->authorizeShop($actor, $order);
            abort_unless($this->canSimulate($order), 409, 'Chỉ mô phỏng đơn TEST đã packed, chưa có shipment/tracking và thuộc shop được phép.');
            $payload = $this->payload->make($order, true);
            app(ActivityLogService::class)->log(
                action: 'shipment.simulated', source: 'user', actorUserId: $actor->id, actorName: $actor->name,
                targetUserId: null, targetUserName: null, shopId: $order->shop_id, shopName: null,
                subjectType: 'order', subjectId: $order->id,
                metadata: ['order_id' => $order->id, 'mode' => 'simulation', 'network_sent' => false],
                occurredAt: now(),
            );

            return ['mode' => 'simulation', 'status' => 'simulated', 'tracking_number' => null,
                'order_id' => $order->id, 'network_sent' => false, 'payload_fields' => array_keys($payload),
                'provider_source' => 'mock_fixture_not_verified', 'quantity' => $payload['quantity'],
                'totalPrice' => $payload['totalPrice'], 'moneyCollection' => $payload['moneyCollection']];
        }, 3);
    }

    public function create(User $actor, int $orderId): array
    {
        // Commit the reservation BEFORE HTTP. Concurrent clicks see processing;
        // a crash after sending cannot silently make the order eligible again.
        $reservation = DB::transaction(function () use ($actor, $orderId) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $this->warehouse->authorizeShop($actor, $order);
            abort_unless($this->warehouse->enabled() && $this->warehouse->inActiveQueue($order)
                && $order->warehouse_status === Order::WAREHOUSE_PACKED && $order->packed_at,
                409, 'Chỉ tạo vận đơn sau khi Hoàn tất đóng hàng.');
            $shipment = AicrmShipment::where('order_id', $orderId)->lockForUpdate()->first();
            abort_if($shipment && ($shipment->mode !== config('aicrm.mode') || (int) $shipment->shop_id !== (int) $order->shop_id),
                409, 'Cần đối soát trước khi đổi môi trường hoặc shop của vận đơn.');
            $tenant = config('aicrm.mode') === 'mock' ? null
                : app(AicrmTenantCredentials::class)->forShop((int) $order->shop_id, config('aicrm.mode') === 'live')['tenant_id'];
            abort_if($shipment && $shipment->tenant_id !== $tenant,
                409, 'Tenant AICRM đã đổi hoặc vận đơn cũ chưa xác minh tenant. Cần đối soát trước khi tiếp tục.');
            if ($shipment?->status === 'succeeded') {
                return ['shipment' => $shipment, 'replayed' => true];
            }
            abort_if($shipment && in_array($shipment->status, ['processing', 'unknown'], true),
                409, 'Đơn đang gửi hoặc chưa rõ kết quả AICRM. Cần đối soát trước khi gửi lại.');
            abort_if(filled($order->order_number_vtp) || filled(data_get($order->pancake_full_data, 'partner.order_number_vtp')),
                409, 'Đơn đã có mã vận đơn Viettel Post.');
            abort_unless($this->warehouse->packableStatus($order->status), 409, 'Trạng thái đơn gốc không còn cho phép tạo vận đơn.');
            $this->client->validateConfiguration((int) $order->shop_id);
            if (config('aicrm.mode') === 'live') {
                app(AicrmLiveConnection::class)->validateCreation($order);
            }
            $providerId = null;
            if (in_array(config('aicrm.mode'), ['live', 'sandbox'], true)) {
                $this->payload->validateRecipient($order);
                // Fresh GET with the same shop credential as POST, under the order lock.
                // Never fall back to an ENV/browser ID or reserve an attempt on failure.
                $providerId = $this->client->shippingProviderId((int) $order->shop_id);
            }
            $payload = $this->payload->make($order, shippingProviderId: $providerId);
            $shipment ??= new AicrmShipment([
                'order_id' => $order->id, 'shop_id' => $order->shop_id,
                'mode' => config('aicrm.mode'), 'reference' => (string) Str::uuid(),
            ] + ($tenant === null ? [] : ['tenant_id' => $tenant]));
            $shipment->fill(['status' => 'processing', 'last_error' => null, 'submitted_at' => now(), 'completed_at' => null])->save();
            $attempt = $shipment->attempts()->create([
                'actor_user_id' => $actor->id, 'status' => 'processing', 'request_payload' => $payload,
            ]);

            return compact('shipment', 'attempt', 'payload') + ['replayed' => false];
        }, 3);
        if ($reservation['replayed']) {
            return $reservation;
        }
        $shipment = $reservation['shipment'];
        $httpStatus = null;
        $tracking = null;
        $remoteOrderId = $remoteStatus = null;
        try {
            $response = $this->client->create($reservation['payload'], $shipment->shop_id, $shipment->reference, $shipment->order_id);
            $httpStatus = $response->status();
            $body = $response->json();
            $number = is_array($body) ? data_get($body, 'data.ORDER_NUMBER') : null;
            // The POST DTO has neither an AICRM UUID nor a shipping status.
            // In particular, envelope status=200 must never become remote_status.
            if ($response->successful() && is_array($body) && ($body['error'] ?? null) === false
                && ($body['status'] ?? null) === 200 && is_string($number) && trim($number) !== '' && mb_strlen($number) <= 255) {
                $status = 'succeeded';
                $tracking = trim($number);
                $code = $message = null;
            } elseif (in_array($httpStatus, [400, 401, 403, 422], true) && blank($number) && empty($body['data'])) {
                $status = 'failed';
                $networkDenied = app(AicrmShippingProviders::class)->cloudflareDenied($response);
                $code = $networkDenied ? 'aicrm_network_denied' : 'aicrm_rejected';
                $message = $networkDenied
                    ? 'Cloudflare HTTP 403 / 1010 chặn truy cập mạng. Cần AICRM xử lý quyền truy cập B2B.'
                    : match ($httpStatus) {
                        401, 403 => 'AICRM từ chối xác thực hoặc quyền tenant. Kiểm tra cấu hình trước khi thử lại.',
                        default => 'AICRM từ chối dữ liệu người nhận hoặc đơn hàng. Kiểm tra dữ liệu trước khi thử lại.',
                    };
            } else {
                $status = 'unknown';
                $code = 'unconfirmed_response';
                $message = 'Chưa xác định AICRM đã tạo vận đơn hay chưa. Cần đối soát; không tự gửi lại.';
            }
        } catch (Throwable) {
            // Do not persist exception messages: URLs, credentials and recipient PII
            // can be embedded by HTTP clients and upstream services.
            $status = 'unknown';
            $code = 'connection_or_client_error';
            $message = 'Lỗi kết nối hoặc timeout AICRM. Cần đối soát trước khi gửi lại.';
        }
        DB::transaction(function () use ($shipment, $reservation, $status, $tracking, $code, $message, $httpStatus, $remoteOrderId, $remoteStatus) {
            // Keep lock ordering consistent with reservation and existing warehouse writes.
            $order = Order::whereKey($shipment->order_id)->lockForUpdate()->firstOrFail();
            $locked = AicrmShipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $locked->fill(['status' => $status, 'tracking_number' => $tracking, 'last_error' => $message, 'completed_at' => now(),
                'remote_order_id' => $remoteOrderId, 'remote_status' => $remoteStatus])->save();
            AicrmShipmentAttempt::whereKey($reservation['attempt']->id)->update([
                'status' => $status, 'http_status' => $httpStatus, 'error_code' => $code,
                'error_message' => $message, 'completed_at' => now(), 'updated_at' => now(),
                'remote_order_id' => $remoteOrderId, 'remote_status' => $remoteStatus,
            ]);
            // Mock numbers must never enter legacy carrier sync / reporting flows.
            if ($status === 'succeeded' && in_array($shipment->mode, ['sandbox', 'live'], true) && blank($order->order_number_vtp)) {
                $order->order_number_vtp = $tracking;
                $order->save();
            }
        }, 3);

        return ['shipment' => $shipment->fresh(), 'replayed' => false];
    }

    public function reconcile(User $actor, int $orderId): AicrmShipment
    {
        $order = Order::findOrFail($orderId);
        $this->warehouse->authorizeShop($actor, $order);
        $shipment = AicrmShipment::where('order_id', $orderId)->firstOrFail();
        abort_unless(config('aicrm.mode') === 'live' && $shipment->mode === 'live'
            && $shipment->status === 'succeeded' && filled($shipment->tracking_number)
            && (int) $shipment->shop_id === (int) $order->shop_id,
            409, 'Chỉ liên kết UUID cho vận đơn Live đã nhận mã thành công. Đơn unknown vẫn khóa gửi lại.');
        $tenant = app(AicrmTenantCredentials::class)->forShop((int) $order->shop_id, true)['tenant_id'];
        abort_unless($shipment->tenant_id === $tenant, 409, 'Tenant cấu hình không khớp tenant khi tạo vận đơn.');
        $remote = app(AicrmLiveConnection::class)->findOrderByTracking((int) $order->shop_id, $shipment->tracking_number);

        return DB::transaction(function () use ($shipment, $order, $tenant, $remote) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked = AicrmShipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'succeeded' && $locked->mode === 'live'
                && (int) $lockedOrder->shop_id === (int) $shipment->shop_id
                && $locked->tenant_id === $tenant && $locked->tracking_number === $shipment->tracking_number
                && app(AicrmTenantCredentials::class)->forShop((int) $lockedOrder->shop_id, true)['tenant_id'] === $tenant
                && ($locked->remote_order_id === null || $locked->remote_order_id === $remote['id']),
                409, 'Vận đơn hoặc tenant đã thay đổi trong khi đối soát; chưa liên kết UUID.');
            $locked->fill(['remote_order_id' => $remote['id'], 'remote_status' => $remote['status']])->save();

            return $locked;
        }, 3);
    }
}
