<?php

namespace App\Services;

use App\Data\ActivityLogEvent;
use App\Enums\ActivityLogAction;
use App\Enums\ActivityLogSubjectType;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Writes one order.created event for an actually-created local Order.
 */
class OrderCreatedEventWriter
{
    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {}

    public function write(
        Order $order,
        string $ingestionPath,
        DateTimeInterface|string|null $occurredAt = null,
        ?Customer $customer = null
    ): ActivityLog {
        $orderId = (int) $order->getKey();
        $shopId = (int) $order->shop_id;

        if ($orderId < 1 || $shopId < 1) {
            throw new InvalidArgumentException(
                'An order.created event requires persisted local order and shop IDs.'
            );
        }

        $ingestionPath = trim($ingestionPath);
        if ($ingestionPath === '') {
            throw new InvalidArgumentException('Order creation ingestion path is required.');
        }

        $customer = $this->resolveCustomer($order, $customer);
        $pancakeCustomerId = $this->nullableIdentifier($order->pancake_customer_id);

        return $this->activityLogService->write(new ActivityLogEvent(
            action: ActivityLogAction::ORDER_CREATED,
            source: 'system',
            subjectType: ActivityLogSubjectType::ORDER,
            subjectId: (string) $orderId,
            shopId: $shopId,
            pancakeOrderId: $order->pancake_order_id,
            pancakeCustomerId: $pancakeCustomerId,
            occurredAt: $occurredAt ?? $order->created_at,
            idempotencyKey: sprintf(
                'order.created:shop:%d:order:%d',
                $shopId,
                $orderId
            ),
            metadata: [
                'order_id' => $orderId,
                'shop_id' => $shopId,
                'customer_id' => $customer?->getKey(),
                'pancake_order_id' => $order->pancake_order_id,
                'pancake_customer_id' => $pancakeCustomerId,
                'amount' => $this->normalizeAmount($order->cod),
                'amount_source' => 'cod',
                'cash_amount' => $this->normalizeAmount($order->cash),
                'quantity' => $this->normalizeQuantity($order->total_quantity),
                'ingestion_path' => $ingestionPath,
                'items' => $this->itemSnapshot($order),
            ]
        ));
    }

    private function resolveCustomer(Order $order, ?Customer $customer): ?Customer
    {
        $pancakeCustomerId = $this->nullableIdentifier($order->pancake_customer_id);

        if ($pancakeCustomerId === null) {
            return null;
        }

        if ($customer !== null
            && (int) $customer->shop_id === (int) $order->shop_id
            && (string) $customer->pancake_customer_id === $pancakeCustomerId) {
            return $customer;
        }

        return Customer::query()
            ->where('shop_id', $order->shop_id)
            ->where('pancake_customer_id', $pancakeCustomerId)
            ->first();
    }

    private function nullableIdentifier(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeAmount(mixed $value): int|float|null
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }

    private function normalizeQuantity(mixed $value): int
    {
        return max(0, (int) $value);
    }

    /**
     * Keep only a bounded, non-sensitive product summary from the stored
     * Pancake items. The raw order payload remains on Order, never in the log.
     *
     * @return list<array{product_id?: string, name?: string, quantity: int|float}>
     */
    private function itemSnapshot(Order $order): array
    {
        $payload = $order->pancake_full_data;
        $items = is_array($payload) && is_array($payload['items'] ?? null)
            ? $payload['items']
            : [];

        $snapshot = [];

        foreach (array_slice($items, 0, 50) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $variationInfo = is_array($item['variation_info'] ?? null)
                ? $item['variation_info']
                : [];
            $productId = $this->nullableIdentifier(
                $item['product_id'] ?? $item['variation_id'] ?? $variationInfo['id'] ?? null
            );
            $name = $this->nullableIdentifier(
                $item['name'] ?? $variationInfo['name'] ?? null
            );
            $quantity = is_numeric($item['quantity'] ?? null)
                ? (float) $item['quantity']
                : 0;
            $quantity = floor($quantity) === $quantity ? (int) $quantity : $quantity;

            $summary = ['quantity' => max(0, $quantity)];

            if ($productId !== null) {
                $summary['product_id'] = substr($productId, 0, 191);
            }

            if ($name !== null) {
                $summary['name'] = substr($name, 0, 255);
            }

            $snapshot[] = $summary;
        }

        return $snapshot;
    }
}
