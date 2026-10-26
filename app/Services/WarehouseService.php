<?php

namespace App\Services;

use App\Enums\ActivityLogSubjectType;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WarehouseService
{
    // Known pre-shipment statuses in the existing order catalog. Unknown states fail closed.
    public const PACKABLE_STATUSES = [0, 1, 8, 11, 12, 13, 17, 20];

    public const CLOSED_STATUSES = [2, 3, 4, 5, 6, 7, 9, 15, 16];

    public function activation(): ?CarbonImmutable
    {
        $value = config('warehouse.activated_at');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        // Activation is an explicit instant, never a relative or timezone-ambiguous date.
        if (! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value)) {
            return null;
        }

        return $this->timestamp($value);
    }

    public function enabled(): bool
    {
        return ($at = $this->activation()) !== null && $at->lessThanOrEqualTo(CarbonImmutable::now('UTC'));
    }

    public function configuration(): array
    {
        $at = $this->activation();

        return [
            'enabled' => $this->enabled(), 'activated_at' => $at?->toIso8601String(),
            'configuration_valid' => ! config('warehouse.activated_at') || $at !== null,
        ];
    }

    public function sourceCreatedAt(array $payload, bool $logMissing = true): ?CarbonImmutable
    {
        $dates = [];
        foreach (['inserted_at', 'created_at'] as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
                continue;
            }
            $date = $this->timestamp($payload[$key]);
            if ($date === null || $date->greaterThan(CarbonImmutable::now('UTC'))) {
                if ($logMissing) {
                    $this->review('invalid_source_created_at', $payload);
                }

                return null;
            }
            $dates[] = $date;
        }
        if ($dates === []) {
            if ($logMissing) {
                $this->review('missing_source_created_at', $payload);
            }

            return null;
        }
        // Conflicting creation fields cannot make an old order appear new.
        usort($dates, fn ($a, $b) => $a <=> $b);

        return $dates[0];
    }

    public function canImportWithoutTracking(array $payload): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $created = $this->sourceCreatedAt($payload);
        if (! $this->knownStatus($payload['status'] ?? null)) {
            $this->review('unknown_order_status', $payload);
        }

        return ! empty($payload['id']) && $created !== null && $created->greaterThanOrEqualTo($this->activation())
            && $this->packableStatus($payload['status'] ?? null);
    }

    /** Called only at the actual creation boundary, never by lists or backfill scans. */
    public function initializeLocal(Order $order): void
    {
        if (! $this->enabled() || $order->created_at->toImmutable()->utc()->lessThan($this->activation())) {
            return;
        }
        $order->warehouse_status = Order::WAREHOUSE_WAITING;
        $order->warehouse_queued_at = now();
        $order->save();
    }

    /** Sync callers hold the shop/order locks and own the surrounding transaction. */
    public function synchronize(Order $order, array $payload, bool $isNew): void
    {
        if (! $this->enabled()) {
            return;
        }
        if ($isNew) {
            $created = $this->sourceCreatedAt($payload);
            $order->source_created_at = $created?->setTimezone(config('app.timezone'));
            $order->first_synced_at = now();
            $creator = collect(['creator', 'assigning_seller', 'assigning_care'])
                ->map(fn ($key) => $payload[$key] ?? null)
                ->first(fn ($candidate) => is_array($candidate) && isset($candidate['id']));
            if (is_array($creator) && is_string($creator['name'] ?? null)) {
                $order->source_creator_name = mb_substr($creator['name'], 0, 255);
            }
            $order->origin = 'pancake';
            if ($created !== null && $created->greaterThanOrEqualTo($this->activation())
                && $this->packableStatus($payload['status'] ?? null)) {
                $order->warehouse_status = Order::WAREHOUSE_WAITING;
                $order->warehouse_queued_at = now();
            } elseif (! $this->knownStatus($payload['status'] ?? null)) {
                $this->review('unknown_order_status', $payload);
            }
            $order->save();

            return;
        }
        // An existing row is never enrolled, even if it is synchronized much later.
        if (! $order->warehouse_queued_at || ! array_key_exists('status', $payload)) {
            return;
        }
        if ($order->warehouse_status === Order::WAREHOUSE_PACKED) {
            if (! $this->packableStatus($payload['status'])) {
                $this->review('status_changed_after_packing', $payload, $order);
            }

            return;
        }
        if ($order->warehouse_status === Order::WAREHOUSE_WAITING && ! $this->packableStatus($payload['status'])) {
            $order->warehouse_status = null;
            $order->save();
            app(ActivityLogService::class)->log(
                action: 'warehouse.order_removed', source: 'system', actorUserId: null, actorName: null,
                targetUserId: null, targetUserName: null, shopId: $order->shop_id, shopName: null,
                subjectType: ActivityLogSubjectType::ORDER, subjectId: $order->id,
                pancakeOrderId: $order->pancake_order_id,
                oldValues: ['warehouse_status' => Order::WAREHOUSE_WAITING], newValues: ['warehouse_status' => null],
                metadata: ['order_id' => $order->id, 'upstream_status' => $payload['status']],
                occurredAt: now(), idempotencyKey: 'warehouse.removed:order:'.$order->id,
            );
        }
    }

    public function complete(User $actor, int $orderId): array
    {
        abort_unless($this->enabled(), 409, 'Module Kho chưa được kích hoạt.');

        return DB::transaction(function () use ($actor, $orderId) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $this->authorizeShop($actor, $order);
            abort_unless($this->inActiveQueue($order), 409, 'Đơn không thuộc hàng đợi Kho đang được kích hoạt.');
            if ($order->warehouse_status === Order::WAREHOUSE_PACKED) {
                return ['order' => $order, 'replayed' => true];
            }
            abort_unless($order->warehouse_status === Order::WAREHOUSE_WAITING
                && $this->packableStatus($order->status), 409, 'Đơn không còn ở trạng thái chờ đóng hàng hợp lệ.');
            $order->warehouse_status = Order::WAREHOUSE_PACKED;
            $order->packed_by_user_id = $actor->id;
            $order->packed_at = now();
            $order->save();
            app(ActivityLogService::class)->log(
                action: 'warehouse.packed', source: 'user', actorUserId: $actor->id, actorName: $actor->name,
                targetUserId: null, targetUserName: null, shopId: $order->shop_id, shopName: null,
                subjectType: ActivityLogSubjectType::ORDER, subjectId: $order->id,
                pancakeOrderId: $order->pancake_order_id,
                oldValues: ['warehouse_status' => Order::WAREHOUSE_WAITING],
                newValues: ['warehouse_status' => Order::WAREHOUSE_PACKED],
                metadata: ['order_id' => $order->id, 'packed_by_user_id' => $actor->id, 'packed_at' => $order->packed_at->toIso8601String()],
                occurredAt: $order->packed_at, idempotencyKey: 'warehouse.packed:order:'.$order->id,
            );

            return ['order' => $order, 'replayed' => false];
        }, 3);
    }

    public function authorizeShop(User $actor, Order $order): void
    {
        if (! app(ShopAccessService::class)->canAccessShop($actor, (int) $order->shop_id)) {
            throw new AuthorizationException('Bạn không có quyền truy cập Kho của shop này.');
        }
    }

    public function inActiveQueue(Order $order): bool
    {
        $at = $this->activation();
        $created = $order->origin === 'local_pos' ? $order->created_at : $order->source_created_at;

        return $at !== null && $created !== null && $order->warehouse_queued_at !== null
            && $order->warehouse_queued_at->greaterThanOrEqualTo($at)
            && $created->greaterThanOrEqualTo($at);
    }

    public function packableStatus(mixed $status): bool
    {
        return (is_int($status) || (is_string($status) && ctype_digit($status)))
            && in_array((int) $status, self::PACKABLE_STATUSES, true);
    }

    private function knownStatus(mixed $status): bool
    {
        return $this->packableStatus($status) || ((is_int($status) || (is_string($status) && ctype_digit($status)))
            && in_array((int) $status, self::CLOSED_STATUSES, true));
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})?$/D', $value, $parts)) {
            return null;
        }
        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function review(string $reason, array $payload, ?Order $order = null): void
    {
        Log::warning('warehouse.review_required', ['reason' => $reason, 'upstream_order_id' => $payload['id'] ?? null,
            'order_id' => $order?->id, 'shop_id' => $order?->shop_id ?? $payload['shop_id'] ?? null]);
    }
}
