<?php

namespace App\Services;

use App\Exceptions\OrderSummaryPendingException;
use Illuminate\Support\Facades\DB;

/** Explicit CLI audit only; never invoked by a dashboard GET request. */
class OrderSummaryReconciler
{
    private const COUNTS = ['total_items', 'shipping_count', 'success_count', 'cancel_count', 'return_count', 'pending_count', 'delivered_vtp_count'];

    private const AMOUNTS = ['total_revenue', 'total_prepaid_amount', 'shipping_amount', 'success_amount', 'cancel_amount', 'return_amount', 'pending_amount', 'delivered_vtp_quantity'];

    /** @return array{ok: bool, differences: int, samples: array, day_groups: int, product_groups: int, orders: int} */
    public function verify(): array
    {
        return DB::transaction(function () {
            // Serialize against projection writers; audit one source snapshot.
            DB::table('order_summary_state')->where('id', 1)->lockForUpdate()->first();
            if (DB::table('order_summary_pending')->exists()) {
                throw new OrderSummaryPendingException('Pending source changes exist; run --pending before --verify.');
            }
            $result = ['ok' => true, 'differences' => 0, 'samples' => [], 'day_groups' => 0, 'product_groups' => 0, 'orders' => 0];
            $missingDates = DB::table('orders')->whereNull('deleted_at')->whereNull('created_at')->count();
            if ($missingDates) {
                $this->difference($result, "source: {$missingDates} active orders have no created_at");
            }

            $expectedDays = $this->sourceDays();
            $actualDays = [];
            foreach (DB::table('daily_order_summaries')->get() as $row) {
                $key = $row->report_date.'|'.(int) $row->shop_id;
                if (isset($actualDays[$key])) {
                    $this->difference($result, "day {$key}: duplicate summary row");
                }
                foreach (self::COUNTS as $field) {
                    $actualDays[$key][$field] = (int) $row->$field;
                }
                foreach (self::AMOUNTS as $field) {
                    $actualDays[$key][$field] = (int) round((float) $row->$field * 100);
                }
            }
            $this->compare($expectedDays, $actualDays, 'day', $result);
            $result['day_groups'] = count($expectedDays);

            [$expectedProducts, $expectedLedger] = $this->sourceProducts();
            $actualProducts = [];
            foreach (DB::table('daily_product_sales_summaries')->get() as $row) {
                $key = implode('|', [$row->report_date, $row->shop_id, $row->status, $row->has_vtp, $row->product_key]);
                if (isset($actualProducts[$key])) {
                    $this->difference($result, "product {$key}: duplicate summary row");
                }
                $actualProducts[$key] = [
                    'quantity' => (int) round((float) $row->quantity * 100),
                    'revenue' => (int) round((float) $row->revenue * 10000),
                    'orders_count' => (int) $row->orders_count,
                    'product_id' => $row->product_id === null ? null : (string) $row->product_id,
                    'variation_id' => $row->variation_id === null ? null : (string) $row->variation_id,
                    'product_name' => (string) $row->product_name,
                ];
            }
            $this->compare($expectedProducts, $actualProducts, 'product', $result);
            $result['product_groups'] = count($expectedProducts);
            $result['orders'] = count($expectedLedger);

            $actualLedger = [];
            foreach (DB::table('order_summary_contributions')->get(['order_id', 'shop_id', 'report_date', 'source_created_at', 'delivered_vtp']) as $row) {
                $actualLedger[$row->order_id] = ['shop_id' => (int) $row->shop_id, 'report_date' => $row->report_date,
                    'source_created_at' => (string) $row->source_created_at, 'delivered_vtp' => (int) $row->delivered_vtp];
            }
            $this->compare($expectedLedger, $actualLedger, 'ledger', $result);

            return $result;
        });
    }

    private function sourceDays(): array
    {
        $delivered = "status = 3 AND LENGTH(RTRIM(COALESCE(order_number_vtp, ''))) > 0";
        $query = DB::table('orders')->whereNull('deleted_at')->whereNotNull('created_at')
            ->selectRaw('DATE(created_at) AS report_date, COALESCE(shop_id, 0) AS shop_id, COUNT(*) AS total_items')
            ->selectRaw('COALESCE(SUM(ROUND(COALESCE(cod, 0) * 100)), 0) AS total_revenue')
            ->selectRaw('COALESCE(SUM(ROUND(COALESCE(prepaid_amount, 0) * 100)), 0) AS total_prepaid_amount')
            ->selectRaw("SUM(CASE WHEN {$delivered} THEN 1 ELSE 0 END) AS delivered_vtp_count")
            ->selectRaw("SUM(CASE WHEN {$delivered} THEN ROUND(COALESCE(total_quantity, 0) * 100) ELSE 0 END) AS delivered_vtp_quantity");
        foreach (['shipping' => 'status IN (2, 8, 9)', 'success' => 'status IN (3, 16)', 'cancel' => 'status IN (6, 7)',
            'return' => 'status IN (4, 5, 15)', 'pending' => 'status NOT IN (2, 3, 4, 5, 6, 7, 8, 9, 15, 16)'] as $name => $condition) {
            $query->selectRaw("SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END) AS {$name}_count")
                ->selectRaw("SUM(CASE WHEN {$condition} THEN ROUND(COALESCE(cod, 0) * 100) ELSE 0 END) AS {$name}_amount");
        }
        $days = [];
        foreach ($query->groupByRaw('DATE(created_at), COALESCE(shop_id, 0)')->get() as $row) {
            $metrics = [];
            foreach ([...self::COUNTS, ...self::AMOUNTS] as $field) {
                $metrics[$field] = (int) $row->$field;
            }
            if ((int) $row->shop_id !== 0) {
                $days[$row->report_date.'|'.$row->shop_id] = $metrics;
            }
            $global = $row->report_date.'|0';
            $days[$global] ??= array_fill_keys(array_keys($metrics), 0);
            foreach ($metrics as $field => $amount) {
                $days[$global][$field] += $amount;
            }
        }

        return $days;
    }

    /** Independent source iteration also works on SQLite without JSON_TABLE. */
    private function sourceProducts(): array
    {
        $products = [];
        $ledger = [];
        DB::table('orders')->whereNull('deleted_at')->whereNotNull('created_at')
            ->select(['id', 'shop_id', 'status', 'order_number_vtp', 'created_at', 'pancake_full_data'])
            ->chunkById(200, function ($orders) use (&$products, &$ledger) {
                foreach ($orders as $order) {
                    $date = substr((string) $order->created_at, 0, 10);
                    $hasVtp = (int) (strlen(rtrim((string) $order->order_number_vtp, ' ')) > 0);
                    $ledger[$order->id] = ['shop_id' => (int) $order->shop_id, 'report_date' => $date,
                        'source_created_at' => (string) $order->created_at, 'delivered_vtp' => (int) ($order->status !== null && (int) $order->status === 3 && $hasVtp)];
                    $payload = json_decode($order->pancake_full_data ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                    $seen = [];
                    foreach (($payload['items'] ?? []) as $position => $item) {
                        $productId = isset($item['product_id']) && (string) $item['product_id'] !== '' ? (string) $item['product_id'] : null;
                        $variationValue = $item['variation_id'] ?? $item['variation_info']['id'] ?? null;
                        $variationId = $variationValue !== null && (string) $variationValue !== '' ? (string) $variationValue : null;
                        $identity = [(int) $order->shop_id, $productId, $variationId];
                        if ($productId === null && $variationId === null) {
                            $identity[] = [$order->id, $position];
                        }
                        $productKey = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
                        $key = implode('|', [$date, (int) $order->shop_id, (int) ($order->status ?? 0), $hasVtp, $productKey]);
                        $name = mb_substr(trim((string) ($item['variation_info']['name'] ?? ''), ' '), 0, 255);
                        $name = $name === '' ? 'Chưa rõ sản phẩm' : $name;
                        $products[$key] ??= ['quantity' => 0, 'revenue' => 0, 'orders_count' => 0,
                            'product_id' => $productId, 'variation_id' => $variationId, 'product_name' => $name];
                        if (strcmp($name, $products[$key]['product_name']) > 0) {
                            $products[$key]['product_name'] = $name;
                        }
                        $quantity = (int) round((float) ($item['quantity'] ?? 0) * 100);
                        $price = (int) round((float) ($item['variation_info']['retail_price'] ?? 0) * 100);
                        $products[$key]['quantity'] += $quantity;
                        $products[$key]['revenue'] += $quantity * $price;
                        if (! isset($seen[$key])) {
                            $products[$key]['orders_count']++;
                            $seen[$key] = true;
                        }
                    }
                }
            });

        return [$products, $ledger];
    }

    private function compare(array $expected, array $actual, string $kind, array &$result): void
    {
        foreach ($expected as $key => $metrics) {
            if (! isset($actual[$key])) {
                $this->difference($result, "{$kind} {$key}: missing summary row");

                continue;
            }
            foreach ($metrics as $field => $value) {
                if ($value !== $actual[$key][$field]) {
                    $this->difference($result, "{$kind} {$key}: {$field} source=".json_encode($value).' summary='.json_encode($actual[$key][$field]));
                }
            }
        }
        foreach (array_diff_key($actual, $expected) as $key => $_) {
            $this->difference($result, "{$kind} {$key}: unexpected summary row");
        }
    }

    private function difference(array &$result, string $message): void
    {
        $result['ok'] = false;
        $result['differences']++;
        if (count($result['samples']) < 20) {
            $result['samples'][] = $message;
        }
    }
}
