<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OrderSummaryService
{
    private static int $trackedWrites = 0;

    public static function trackSourceWrite(callable $write): mixed
    {
        self::$trackedWrites++;
        try {
            return $write();
        } finally {
            self::$trackedWrites--;
        }
    }

    public static function sourceWritten(): void
    {
        // Unknown bulk writes must never leave a trusted but stale projection.
        // Known model/import writes persist the exact affected order IDs instead.
        if (self::$trackedWrites === 0 && self::installed()) {
            DB::table('order_summary_state')->where('id', 1)->update(['ready' => false, 'updated_at' => now()]);
        }
    }

    public static function installed(): bool
    {
        return Schema::hasTable('order_summary_state');
    }

    public static function isReady(): bool
    {
        return self::installed()
            && DB::table('order_summary_state')->where('id', 1)->where('schema_version', 2)->where('ready', true)->exists()
            && ! DB::table('order_summary_pending')->exists();
    }

    public static function latestOrderAt(?array $shopIds = null, bool $deliveredOnly = false): ?string
    {
        return DB::table('order_summary_contributions')
            ->when($shopIds !== null, fn ($q) => $q->whereIn('shop_id', $shopIds))
            ->when($deliveredOnly, fn ($q) => $q->where('delivered_vtp', true))->max('source_created_at');
    }

    /** Written in the source transaction; a failed projection remains retryable. */
    public static function queueOrder(int $orderId): void
    {
        if (! self::installed()) {
            return;
        }
        DB::table('order_summary_pending')->updateOrInsert(['order_id' => $orderId], ['created_at' => now(), 'updated_at' => now()]);
        DB::afterCommit(function () use ($orderId) {
            try {
                self::syncOrder($orderId);
            } catch (\Throwable $error) {
                Log::error('Order summary update pending', ['order_id' => $orderId, 'error' => $error->getMessage()]);
            }
        });
    }

    public static function syncOrder(int $orderId): void
    {
        if (! self::installed()) {
            return;
        }
        DB::transaction(function () use ($orderId) {
            $state = DB::table('order_summary_state')->where('id', 1)->lockForUpdate()->first();
            if (! $state || ! $state->ready || (int) $state->schema_version !== 2) {
                return;
            }
            // Lock the latest source, rather than applying a possibly stale event.
            $source = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            $oldRow = DB::table('order_summary_contributions')->where('order_id', $orderId)->first();
            $old = $oldRow ? json_decode($oldRow->contribution, true, flags: JSON_THROW_ON_ERROR) : null;
            $new = $source ? self::contribution($source) : null;
            if ($source && $source->deleted_at === null && ! $source->created_at) {
                throw new \RuntimeException('An active order without created_at cannot be projected.');
            }
            $fingerprint = $new ? hash('sha256', json_encode($new, JSON_THROW_ON_ERROR)) : null;
            if (($oldRow->fingerprint ?? null) !== $fingerprint) {
                if ($old) {
                    self::apply($old, -1);
                }
                if ($new) {
                    self::apply($new, 1);
                    DB::table('order_summary_contributions')->updateOrInsert(['order_id' => $orderId], self::ledgerRow($new));
                } else {
                    DB::table('order_summary_contributions')->where('order_id', $orderId)->delete();
                }
                self::refreshProductNames(array_filter([$old, $new]));
            }
            DB::table('order_summary_pending')->where('order_id', $orderId)->delete();
            ReportDataVersion::afterCommit('orders');
        }, 3);
    }

    public static function drainPending(int $limit = 1000): int
    {
        if (! self::installed() || ! DB::table('order_summary_state')->where('id', 1)->where('ready', true)->exists()) {
            return 0;
        }
        $ids = DB::table('order_summary_pending')->orderBy('order_id')->limit($limit)->pluck('order_id');
        foreach ($ids as $id) {
            self::syncOrder((int) $id);
        }

        return $ids->count();
    }

    /** Only the explicit CLI rebuild may replace the derived tables. */
    public static function rebuildAll(?callable $progress = null): void
    {
        if (! self::installed()) {
            throw new \RuntimeException('Run the incremental summary migration first.');
        }
        $lock = Cache::lock('order-summary-full-rebuild', 3600);
        if (! $lock->get()) {
            throw new \RuntimeException('An order summary rebuild is already running.');
        }
        try {
            self::buildSnapshot($progress);
        } finally {
            $lock->release();
        }
    }

    private static function buildSnapshot(?callable $progress): void
    {
        DB::table('order_summary_state')->where('id', 1)->update(['ready' => false, 'updated_at' => now()]);
        ReportDataVersion::bump('orders');
        DB::transaction(function () use ($progress) {
            DB::table('order_summary_state')->where('id', 1)->lockForUpdate()->first();
            // One consistent source snapshot. New commits leave pending records.
            if (DB::table('orders')->whereNull('deleted_at')->whereNull('created_at')->exists()) {
                throw new \RuntimeException('Orders without created_at cannot be represented by daily summaries.');
            }
            DB::table('daily_order_summaries')->delete();
            DB::table('daily_product_sales_summaries')->delete();
            DB::table('order_summary_contributions')->delete();
            $days = [];
            $products = [];
            DB::table('orders')->whereNull('deleted_at')->chunkById(100, function ($orders) use (&$days, &$products, $progress) {
                $ledger = [];
                foreach ($orders as $order) {
                    $item = self::contribution($order);
                    $ledger[] = ['order_id' => $order->id] + self::ledgerRow($item);
                    foreach (array_unique([$item['shop_id'], 0]) as $shopId) {
                        $key = $item['date'].':'.$shopId;
                        $days[$key] ??= ['report_date' => $item['date'], 'shop_id' => $shopId] + array_fill_keys(array_keys($item['metrics']), 0);
                        foreach ($item['metrics'] as $field => $amount) {
                            $days[$key][$field] += $amount;
                        }
                    }
                    foreach ($item['products'] as $product) {
                        $dimensions = self::productDimensions($item, $product);
                        $key = implode(':', array_values($dimensions));
                        $products[$key] ??= $dimensions + $product;
                        if (! isset($products[$key]['initialized'])) {
                            $products[$key]['quantity'] = $products[$key]['revenue'] = $products[$key]['orders_count'] = 0;
                            $products[$key]['initialized'] = true;
                        }
                        $products[$key]['quantity'] += $product['quantity'];
                        $products[$key]['revenue'] += $product['revenue'];
                        $products[$key]['orders_count']++;
                        if (strcmp($product['product_name'], $products[$key]['product_name']) > 0) {
                            $products[$key]['product_name'] = $product['product_name'];
                        }
                    }
                }
                if ($ledger) {
                    DB::table('order_summary_contributions')->insert($ledger);
                }
                if ($progress) {
                    $progress($orders->count());
                }
            });
            foreach (array_chunk(array_values($days), 250) as $chunk) {
                DB::table('daily_order_summaries')->insert(array_map(self::formatDay(...), $chunk));
            }
            foreach (array_chunk(array_values($products), 250) as $chunk) {
                DB::table('daily_product_sales_summaries')->insert(array_map(self::formatProduct(...), $chunk));
            }
            DB::table('order_summary_state')->where('id', 1)->update(['ready' => true, 'schema_version' => 2, 'updated_at' => now()]);
        }, 3);
        while (self::drainPending() > 0) {
        }
        ReportDataVersion::bump('orders');
    }

    public static function rebuildDates(array $dates): void
    {
        if (! self::installed()) {
            return;
        }
        foreach (array_unique(array_filter($dates)) as $date) {
            $ids = DB::table('orders')->whereBetween('created_at', [$date.' 00:00:00', $date.' 23:59:59'])->pluck('id')
                ->merge(DB::table('order_summary_contributions')->where('report_date', $date)->pluck('order_id'))->unique();
            foreach ($ids as $id) {
                self::queueOrder((int) $id);
            }
        }
    }

    public static function rebuildSingleDate(string $date): void
    {
        self::rebuildDates([$date]);
    }

    public static function syncIncremental(?Carbon $checkpoint = null): void
    {
        foreach (DB::table('orders')->where('updated_at', '>=', ($checkpoint ?? now()->subMinutes(15))->toDateTimeString())->pluck('id') as $id) {
            self::queueOrder((int) $id);
        }
        self::drainPending();
    }

    public static function contribution(object $order): ?array
    {
        if (($order->deleted_at ?? null) !== null || ! $order->created_at) {
            return null;
        }
        $status = $order->status === null ? null : (int) $order->status;
        $cod = self::units($order->cod ?? 0);
        $hasVtp = ($order->order_number_vtp ?? null) !== null && rtrim((string) $order->order_number_vtp, ' ') !== '';
        $delivered = $status === 3 && $hasVtp;
        $metrics = ['total_items' => 1, 'total_revenue' => $cod, 'total_prepaid_amount' => self::units($order->prepaid_amount ?? 0),
            'delivered_vtp_count' => (int) $delivered, 'delivered_vtp_quantity' => $delivered ? self::units($order->total_quantity ?? 0) : 0];
        foreach (['shipping' => [2, 8, 9], 'success' => [3, 16], 'cancel' => [6, 7], 'return' => [4, 5, 15]] as $name => $statuses) {
            $matches = in_array($status, $statuses, true);
            $metrics[$name.'_count'] = (int) $matches;
            $metrics[$name.'_amount'] = $matches ? $cod : 0;
        }
        $pending = $status !== null && ! in_array($status, [2, 3, 4, 5, 6, 7, 8, 9, 15, 16], true);
        $metrics['pending_count'] = (int) $pending;
        $metrics['pending_amount'] = $pending ? $cod : 0;
        $payload = is_array($order->pancake_full_data) ? $order->pancake_full_data : json_decode($order->pancake_full_data ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        $products = [];
        foreach (($payload['items'] ?? []) as $position => $item) {
            $productId = isset($item['product_id']) && (string) $item['product_id'] !== '' ? (string) $item['product_id'] : null;
            $variationValue = $item['variation_id'] ?? $item['variation_info']['id'] ?? null;
            $variationId = $variationValue !== null && (string) $variationValue !== '' ? (string) $variationValue : null;
            $identity = [(int) $order->shop_id, $productId, $variationId];
            if ($productId === null && $variationId === null) {
                $identity[] = [$order->id, $position];
            }
            $key = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
            $name = mb_substr(trim((string) ($item['variation_info']['name'] ?? ''), ' '), 0, 255);
            $products[$key] ??= ['product_key' => $key, 'product_id' => $productId, 'variation_id' => $variationId,
                'product_name' => $name === '' ? 'Chưa rõ sản phẩm' : $name, 'quantity' => 0, 'revenue' => 0, 'orders_count' => 1];
            if (strcmp($name === '' ? 'Chưa rõ sản phẩm' : $name, $products[$key]['product_name']) > 0) {
                $products[$key]['product_name'] = $name === '' ? 'Chưa rõ sản phẩm' : $name;
            }
            $quantity = self::units($item['quantity'] ?? 0);
            $products[$key]['quantity'] += $quantity;
            $products[$key]['revenue'] += $quantity * self::units($item['variation_info']['retail_price'] ?? 0);
        }
        ksort($products);

        return ['date' => substr((string) $order->created_at, 0, 10), 'created_at' => (string) $order->created_at,
            'shop_id' => (int) $order->shop_id, 'status' => $status ?? 0, 'has_vtp' => (int) $hasVtp, 'metrics' => $metrics, 'products' => $products];
    }

    private static function units(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private static function ledgerRow(array $item): array
    {
        return ['shop_id' => $item['shop_id'], 'report_date' => $item['date'], 'source_created_at' => $item['created_at'],
            'delivered_vtp' => $item['metrics']['delivered_vtp_count'], 'fingerprint' => hash('sha256', json_encode($item, JSON_THROW_ON_ERROR)),
            'contribution' => json_encode($item, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()];
    }

    private static function productDimensions(array $item, array $product): array
    {
        return ['report_date' => $item['date'], 'shop_id' => $item['shop_id'], 'status' => $item['status'], 'has_vtp' => $item['has_vtp'], 'product_key' => $product['product_key']];
    }

    private static function scaled(string $field): bool
    {
        return in_array($field, ['total_revenue', 'total_prepaid_amount', 'delivered_vtp_quantity'], true) || str_ends_with($field, '_amount');
    }

    private static function formatDay(array $row): array
    {
        foreach ($row as $field => $value) {
            if (self::scaled($field)) {
                $row[$field] = number_format($value / 100, 2, '.', '');
            }
        }

        return $row + ['created_at' => now(), 'updated_at' => now()];
    }

    private static function formatProduct(array $row): array
    {
        unset($row['initialized']);
        $row['quantity'] = number_format($row['quantity'] / 100, 2, '.', '');
        $row['revenue'] = number_format($row['revenue'] / 10000, 4, '.', '');

        return $row + ['created_at' => now(), 'updated_at' => now()];
    }

    private static function apply(array $item, int $sign): void
    {
        foreach (array_unique([$item['shop_id'], 0]) as $shopId) {
            $dimensions = ['report_date' => $item['date'], 'shop_id' => $shopId];
            $row = DB::table('daily_order_summaries')->where($dimensions)->first();
            $metrics = $dimensions;
            foreach ($item['metrics'] as $field => $amount) {
                $current = self::scaled($field) ? self::units($row->$field ?? 0) : (int) ($row->$field ?? 0);
                $metrics[$field] = $current + $sign * $amount;
            }
            if ($metrics['total_items'] === 0) {
                DB::table('daily_order_summaries')->where($dimensions)->delete();
            } else {
                DB::table('daily_order_summaries')->updateOrInsert($dimensions, self::formatDay($metrics));
            }
        }
        foreach ($item['products'] as $product) {
            $dimensions = self::productDimensions($item, $product);
            $row = DB::table('daily_product_sales_summaries')->where($dimensions)->first();
            $product['quantity'] = self::units($row->quantity ?? 0) + $sign * $product['quantity'];
            $product['revenue'] = (int) round((float) ($row->revenue ?? 0) * 10000) + $sign * $product['revenue'];
            $product['orders_count'] = (int) ($row->orders_count ?? 0) + $sign;
            if ($product['orders_count'] === 0) {
                DB::table('daily_product_sales_summaries')->where($dimensions)->delete();
            } else {
                DB::table('daily_product_sales_summaries')->updateOrInsert($dimensions, self::formatProduct($dimensions + $product));
            }
        }
    }

    private static function refreshProductNames(array $items): void
    {
        $groups = [];
        foreach ($items as $item) {
            $group = implode(':', [$item['date'], $item['shop_id'], $item['status'], $item['has_vtp']]);
            $groups[$group] ??= ['report_date' => $item['date'], 'shop_id' => $item['shop_id'], 'status' => $item['status'], 'has_vtp' => $item['has_vtp'], 'keys' => []];
            $groups[$group]['keys'] += array_fill_keys(array_keys($item['products']), true);
        }
        foreach ($groups as $group) {
            $labels = [];
            $ledger = DB::table('order_summary_contributions')->where('report_date', $group['report_date'])->where('shop_id', $group['shop_id'])->get(['contribution']);
            foreach ($ledger as $row) {
                $item = json_decode($row->contribution, true, flags: JSON_THROW_ON_ERROR);
                if ($item['status'] !== $group['status'] || $item['has_vtp'] !== $group['has_vtp']) {
                    continue;
                }
                foreach ($item['products'] as $key => $product) {
                    if (isset($group['keys'][$key]) && (! isset($labels[$key]) || strcmp($product['product_name'], $labels[$key]) > 0)) {
                        $labels[$key] = $product['product_name'];
                    }
                }
            }
            unset($group['keys']);
            foreach ($labels as $key => $label) {
                DB::table('daily_product_sales_summaries')->where($group)->where('product_key', $key)->update(['product_name' => $label]);
            }
        }
    }
}
