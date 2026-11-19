<?php

declare(strict_types=1);

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
    public function __construct(
        private readonly WarehouseService $warehouse,
        private readonly AicrmOrderPayload $payload,
        private readonly AicrmOrderClient $client,
    ) {}

    /** Reasons are safe to expose: never expose API keys or upstream bodies. */
    public function creationBlockers(Order $order): array
    {
        $reasons = [];
        $add = static function (string $code, string $message, string $remedy) use (&$reasons): void {
            $reasons[] = compact('code', 'message', 'remedy');
        };

        if (! $this->warehouse->enabled() || ! $this->warehouse->inActiveQueue($order)) {
            $add('warehouse_inactive', 'Đơn không thuộc hàng đợi Kho đang hoạt động.', 'Kiểm tra cấu hình Kho và ngày tạo đơn.');
        }

        if ($order->warehouse_status !== Order::WAREHOUSE_PACKED || ! $order->packed_at) {
            $add('not_packed', 'Đơn chưa hoàn tất đóng hàng.', 'Hoàn tất đóng hàng trước khi tạo vận đơn.');
        }

        if (! $this->warehouse->packableStatus($order->status)) {
            $add('order_status', 'Trạng thái đơn gốc không cho phép tạo vận đơn.', 'Kiểm tra trạng thái đơn.');
        }

        if (filled($order->order_number_vtp) || filled(data_get($order->pancake_full_data, 'partner.order_number_vtp'))) {
            $add('existing_tracking', 'Đơn đã có mã vận đơn.', 'Dùng mã hiện có, không tạo thêm.');
        }

        $shipment = $order->aicrmShipment;
        if ($shipment && $shipment->status === 'failed'
            && $shipment->attempts()->latest('id')->first()?->error_code !== 'aicrm_api_key_rejected') {
            $add('previous_failure_unverified', 'Lần tạo trước chưa được xác nhận là an toàn để gửi lại.', 'Kiểm tra lịch sử AICRM trước khi thử lại.');
        }
        if ($shipment && (
            $shipment->status !== 'failed'
            || $shipment->mode !== config('aicrm.mode')
            || (int) $shipment->shop_id !== (int) $order->shop_id
        )) {
            $add('existing_shipment', 'Đơn đã có vận đơn hoặc đang chờ đối soát.', 'Không gửi lại nếu processing/unknown; không đổi shop/môi trường.');
        }

        $this->appendLiveBlockers($order, $add);

        try {
            $this->client->validateAutoConfiguration();
        } catch (HttpExceptionInterface $error) {
            $add('configuration', $error->getMessage(), config('aicrm.mode') === 'local'
                ? 'Kiểm tra cấu hình AICRM DEV và xác nhận upstream Viettel Post là Sandbox/mock.'
                : 'Cấu hình URL /auto và X-API-Key trên Laravel Backend.');
        }

        try {
            if (in_array(config('aicrm.mode'), ['live', 'sandbox', 'local'], true)) {
                $this->payload->validateRecipient($order);
            }
        } catch (ValidationException $error) {
            $add('invalid_recipient', collect($error->errors())->flatten()->implode(' '), 'Kiểm tra thông tin giao hàng.');
        } catch (HttpExceptionInterface $error) {
            $add('invalid_recipient', $error->getMessage(), 'Kiểm tra thông tin giao hàng.');
        }

        try {
            $this->payload->make($order);
        } catch (ValidationException $error) {
            $add('invalid_payload', collect($error->errors())->flatten()->implode(' '), 'Kiểm tra người nhận, sản phẩm, tổng tiền và COD.');
        }

        return $reasons;
    }

    public function canSimulate(Order $order): bool
    {
        return app()->environment('local')
            && config('aicrm.simulation_enabled') === true
            && in_array((int) $order->shop_id, config('aicrm.simulation_shop_ids', []), true)
            && $this->warehouse->enabled()
            && $this->warehouse->inActiveQueue($order)
            && $order->warehouse_status === Order::WAREHOUSE_PACKED
            && $order->packed_at
            && $this->warehouse->packableStatus($order->status)
            && ! $order->aicrmShipment
            && blank($order->order_number_vtp)
            && blank(data_get($order->pancake_full_data, 'partner.order_number_vtp'));
    }

    public function simulate(User $actor, int $orderId): array
    {
        abort_unless(app()->environment('local'), 403, 'Mô phỏng TEST/Mock chỉ có trên Local.');

        return DB::transaction(function () use ($actor, $orderId): array {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $this->warehouse->authorizeShop($actor, $order);
            abort_unless($this->canSimulate($order), 409, 'Chỉ mô phỏng đơn TEST đã đóng gói, chưa có vận đơn, thuộc shop được phép.');

            $payload = $this->payload->make($order, true);

            app(ActivityLogService::class)->log(
                action: 'shipment.simulated', source: 'user', actorUserId: $actor->id, actorName: $actor->name,
                targetUserId: null, targetUserName: null, shopId: $order->shop_id, shopName: null,
                subjectType: 'order', subjectId: $order->id,
                metadata: ['order_id' => $order->id, 'mode' => 'simulation', 'network_sent' => false],
                occurredAt: now(),
            );

            return [
                'mode' => 'simulation',
                'status' => 'simulated',
                'tracking_number' => null,
                'order_id' => $order->id,
                'network_sent' => false,
                'payload_fields' => array_keys($payload),
                'payload' => $payload,
                'totalPrice' => $payload['totalPrice'],
                'moneyCollection' => $payload['moneyCollection'],
            ];
        }, 3);
    }

    public function create(User $actor, int $orderId): array
    {
        // First COMMIT 'processing'. Never hold a database transaction open over HTTP.
        // Concurrent requests are locked out even if this PHP process crashes later.
        $reservation = DB::transaction(function () use ($actor, $orderId): array {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $this->warehouse->authorizeShop($actor, $order);

            abort_unless(
                $this->warehouse->enabled()
                    && $this->warehouse->inActiveQueue($order)
                    && $order->warehouse_status === Order::WAREHOUSE_PACKED
                    && $order->packed_at,
                409,
                'Chỉ tạo vận đơn sau khi hoàn tất đóng hàng.'
            );

            $shipment = AicrmShipment::where('order_id', $orderId)->lockForUpdate()->first();
            abort_if(
                $shipment && (
                    $shipment->mode !== config('aicrm.mode')
                    || (int) $shipment->shop_id !== (int) $order->shop_id
                ),
                409,
                'Cần đối soát trước khi đổi môi trường hoặc shop của vận đơn.'
            );

            if ($shipment?->status === 'succeeded') {
                return ['shipment' => $shipment, 'replayed' => true];
            }

            abort_if(
                $shipment && in_array($shipment->status, ['processing', 'unknown'], true),
                409,
                'Đơn đang gửi hoặc chưa rõ kết quả AICRM. Cần đối soát trước khi gửi lại.'
            );

            // Only an explicit pre-service API-key rejection is safe to retry.
            // Legacy 'failed' results may already have reached Viettel Post.
            if ($shipment && $shipment->status === 'failed') {
                abort_unless(
                    $shipment->attempts()->latest('id')->first()?->error_code === 'aicrm_api_key_rejected',
                    409,
                    'Lần tạo trước chưa được xác minh là thất bại an toàn. Cần đối soát trước khi gửi lại.'
                );
            }

            abort_if(
                filled($order->order_number_vtp)
                    || filled(data_get($order->pancake_full_data, 'partner.order_number_vtp')),
                409,
                'Đơn đã có mã vận đơn Viettel Post.'
            );

            abort_unless(
                $this->warehouse->packableStatus($order->status),
                409,
                'Trạng thái đơn gốc không cho phép tạo vận đơn.'
            );

            $this->assertLivePermitted($order);
            $this->client->validateAutoConfiguration();
            if (in_array(config('aicrm.mode'), ['live', 'sandbox', 'local'], true)) {
                $this->payload->validateRecipient($order);
            }
            $payload = $this->payload->make($order);

            $shipment ??= new AicrmShipment([
                'order_id' => $order->id,
                'shop_id' => $order->shop_id,
                'mode' => config('aicrm.mode'),
                'reference' => (string) Str::uuid(),
            ]);

            $shipment->fill([
                'status' => 'processing',
                'tracking_number' => null,
                'last_error' => null,
                'submitted_at' => now(),
                'completed_at' => null,
            ])->save();

            $attempt = $shipment->attempts()->create([
                'actor_user_id' => $actor->id,
                'status' => 'processing',
                'request_payload' => $payload,
            ]);

            return compact('shipment', 'attempt', 'payload') + ['replayed' => false];
        }, 3);

        if ($reservation['replayed']) {
            return $reservation;
        }

        $shipment = $reservation['shipment'];
        $httpStatus = null;
        $tracking = null;
        $status = 'unknown';
        $code = 'aicrm_unconfirmed';
        $message = 'Chưa xác nhận được kết quả AICRM. Cần đối soát; không tự gửi lại.';

        try {
            $response = $this->client->createAuto($reservation['payload'], $shipment->reference);

            $httpStatus = $response->status();
            $body = $response->json();

            $candidate = is_array($body) ? data_get($body, 'data.ORDER_NUMBER') : null;
            $confirmedSuccess = $response->successful()
                && is_array($body)
                && ($body['error'] ?? null) === false
                && ($body['status'] ?? null) === 200
                && is_string($candidate)
                && trim($candidate) !== ''
                && mb_strlen(trim($candidate)) <= 255;

            if ($confirmedSuccess) {
                $status = 'succeeded';
                $tracking = trim($candidate);
                $code = null;
                $message = null;
            } elseif (
                $httpStatus === 401
                && is_array($body)
                && ($body['error'] ?? null) === 'Chưa xác định được Tenant. Vui lòng cung cấp JWT hoặc X-API-Key.'
            ) {
                // This exact 401 is returned BEFORE orderService.autoCreateViettelPostOrder().
                $status = 'failed';
                $code = 'aicrm_api_key_rejected';
                $message = 'AICRM từ chối API Key trước khi tạo đơn. Kiểm tra cấu hình Backend.';
            }
            // All other outcomes stay 'unknown', including upstream 4xx/5xx:
            // Java may have invoked Viettel Post before returning those statuses.
        } catch (Throwable) {
            // Never persist raw exception/URL/headers/PII. Network request MAY have arrived.
            $status = 'unknown';
            $code = 'aicrm_connection_or_client_error';
            $message = 'Lỗi kết nối/timeout AICRM. Phải đối soát trước khi gửi lại.';
        }

        DB::transaction(function () use (
            $shipment, $reservation, $status, $tracking, $code, $message, $httpStatus
        ): void {
            $order = Order::whereKey($shipment->order_id)->lockForUpdate()->firstOrFail();
            $locked = AicrmShipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            $locked->fill([
                'status' => $status,
                'tracking_number' => $tracking,
                'last_error' => $message,
                'completed_at' => now(),
                // Do not infer AICRM UUID or delivery status from HTTP response status.
            ])->save();

            AicrmShipmentAttempt::whereKey($reservation['attempt']->id)->update([
                'status' => $status,
                'http_status' => $httpStatus,
                'error_code' => $code,
                'error_message' => $message,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            if (
                $status === 'succeeded'
                && in_array($shipment->mode, ['sandbox', 'live', 'local'], true)
                && blank($order->order_number_vtp)
            ) {
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
        abort_if($shipment->tenant_id === null, 409, 'Chưa tích hợp API đối soát cho vận đơn tạo qua /auto.');

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

    /** @param callable(string, string, string):void $add */
    private function appendLiveBlockers(Order $order, callable $add): void
    {
        if (config('aicrm.mode') !== 'live') {
            return;
        }

        foreach ([
            'enabled' => 'Kết nối Live',
            'creation_enabled' => 'Tạo vận đơn Live',
            'contract_confirmed' => 'Xác nhận hợp đồng API',
            'reconciliation_confirmed' => 'Xác nhận quy trình đối soát timeout',
        ] as $key => $label) {
            if (config('aicrm.live.'.$key) !== true) {
                $add('live_'.$key, $label.' đang tắt.', 'Cần người phụ trách xác nhận và bật riêng trên Backend.');
            }
        }

        if ((string) config('aicrm.live.approved_order_id') !== (string) $order->id) {
            $add('order_not_approved', 'Đơn chưa được phê duyệt cho Live.', 'Chỉ phê duyệt đúng một ID thử nghiệm trước khi mở rộng.');
        }

        try {
            app(AicrmLiveConnection::class)->validateActivation($order);
        } catch (HttpExceptionInterface $error) {
            $add('live_activation', $error->getMessage(), 'Kiểm tra thời điểm kích hoạt Live và ngày tạo đơn.');
        }
    }

    private function assertLivePermitted(Order $order): void
    {
        $blockers = [];
        $this->appendLiveBlockers($order, static function ($code, $message, $remedy) use (&$blockers): void {
            $blockers[] = $message;
        });
        abort_if($blockers !== [], 409, implode(' ', $blockers));
    }
}
