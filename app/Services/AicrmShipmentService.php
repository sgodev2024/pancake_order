<?php

namespace App\Services;

use App\Models\AicrmShipment;
use App\Models\AicrmShipmentAttempt;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AicrmShipmentService
{
    public function __construct(
        private readonly WarehouseService $warehouse,
        private readonly AicrmOrderClient $client,
        private readonly AicrmOrderPayload $payload,
    ) {}

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
            $payload = $this->payload->make($order);
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
