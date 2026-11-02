<?php

namespace App\Services;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Reads verified daily projections, retaining exact source reads at partial-day edges. */
class ProductSalesQuery
{
    public static function rows(?array $shopIds, Carbon $start, Carbon $end, string $viewMode = 'all'): array
    {
        if ($shopIds === [] || $end->lt($start)) {
            return [];
        }
        [$wholeDays, $sourceRanges] = self::splitRange($start, $end);
        $groups = [];

        if ($wholeDays !== null) {
            $rows = DB::table('daily_product_sales_summaries')
                ->where('status', 3)->where('has_vtp', 1)
                ->whereBetween('report_date', $wholeDays)
                ->when($shopIds !== null, fn ($query) => $query->whereIn('shop_id', $shopIds))
                ->get(['report_date', 'product_key', 'product_name', 'quantity', 'revenue', 'orders_count']);
            foreach ($rows as $row) {
                self::addProduct($groups, self::period($row->report_date, $viewMode), $row->product_key,
                    $row->product_name, self::scaled($row->quantity, 100), self::scaled($row->revenue, 10000), (int) $row->orders_count);
            }
        }

        foreach ($sourceRanges as [$from, $to]) {
            self::deliveredOrders($shopIds, $from, $to)->toBase()
                ->chunkById(200, function ($orders) use (&$groups, $viewMode) {
                    foreach ($orders as $order) {
                        $contribution = OrderSummaryService::contribution($order);
                        if ($contribution === null) {
                            continue;
                        }
                        $period = self::period($contribution['date'], $viewMode);
                        foreach ($contribution['products'] as $product) {
                            self::addProduct($groups, $period, $product['product_key'], $product['product_name'],
                                $product['quantity'], $product['revenue'], 1);
                        }
                    }
                }, 'orders.id', 'id');
        }

        $rows = array_values($groups);
        usort($rows, fn ($left, $right) => ($right['quantity'] <=> $left['quantity'])
            ?: ($right['orders_count'] <=> $left['orders_count'])
            ?: strcmp($left['name'], $right['name'])
            ?: strcmp($left['product_key'], $right['product_key'])
            ?: strcmp($left['period'], $right['period']));

        return array_map(function ($row) {
            $row['quantity'] /= 100;
            $row['revenue'] /= 10000;

            return (object) $row;
        }, $rows);
    }

    public static function deliveredValue(?array $shopIds, Carbon $start, Carbon $end, string $metric = 'count'): float|int
    {
        if (! in_array($metric, ['count', 'quantity'], true)) {
            throw new \InvalidArgumentException('Unknown delivered metric.');
        }
        $total = array_sum(self::dailyDeliveredValues($shopIds, $start, $end, $metric));

        return $metric === 'count' ? (int) $total : $total / 100;
    }

    public static function orderCountsByPeriod(?array $shopIds, Carbon $start, Carbon $end, string $viewMode): array
    {
        $periods = [];
        foreach (self::dailyDeliveredValues($shopIds, $start, $end, 'count') as $day => $count) {
            $period = self::period($day, $viewMode);
            $periods[$period] = ($periods[$period] ?? 0) + $count;
        }
        ksort($periods);

        return $periods;
    }

    public static function monthlyQuantities(?array $shopIds, int $year): array
    {
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = $start->copy()->endOfYear();
        $months = [];
        foreach (self::dailyDeliveredValues($shopIds, $start, $end, 'quantity') as $day => $quantity) {
            $month = (int) substr($day, 5, 2);
            $months[$month] = ($months[$month] ?? 0) + $quantity;
        }
        ksort($months);

        $rows = [];
        foreach ($months as $month => $quantity) {
            $rows[] = (object) ['month' => $month, 'total_qty' => $quantity / 100];
        }

        return $rows;
    }

    public static function hasDeliveredInYear(?array $shopIds, int $year): bool
    {
        if ($shopIds === []) {
            return false;
        }
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = $start->copy()->endOfYear();
        if (! in_array(0, $shopIds ?? [], true) && OrderSummaryService::isReady()) {
            return self::dailyOrderSummaries($shopIds)
                ->whereBetween('report_date', [$start->toDateString(), $end->toDateString()])
                ->where('delivered_vtp_count', '>', 0)->exists();
        }

        return self::deliveredOrders($shopIds, $start, $end)->exists();
    }

    private static function dailyDeliveredValues(?array $shopIds, Carbon $start, Carbon $end, string $metric): array
    {
        if ($shopIds === [] || $end->lt($start)) {
            return [];
        }
        [$wholeDays, $sourceRanges] = self::splitRange($start, $end, ! in_array(0, $shopIds ?? [], true));
        $days = [];
        $summaryColumn = $metric === 'count' ? 'delivered_vtp_count' : 'delivered_vtp_quantity';
        $scale = $metric === 'count' ? 1 : 100;
        if ($wholeDays !== null) {
            $rows = self::dailyOrderSummaries($shopIds)->whereBetween('report_date', $wholeDays)
                ->where('delivered_vtp_count', '>', 0)
                ->selectRaw("report_date as day, SUM({$summaryColumn}) as total")
                ->groupBy('report_date')->get();
            foreach ($rows as $row) {
                $days[$row->day] = ($days[$row->day] ?? 0) + self::scaled($row->total, $scale);
            }
        }
        foreach ($sourceRanges as [$from, $to]) {
            $expression = $metric === 'count' ? 'COUNT(*)' : 'SUM(COALESCE(orders.total_quantity, 0))';
            $rows = self::deliveredOrders($shopIds, $from, $to)->toBase()
                ->selectRaw("DATE(orders.created_at) as day, {$expression} as total")
                ->groupByRaw('DATE(orders.created_at)')->get();
            foreach ($rows as $row) {
                $days[$row->day] = ($days[$row->day] ?? 0) + self::scaled($row->total, $scale);
            }
        }
        ksort($days);

        return $days;
    }

    private static function deliveredOrders(?array $shopIds, Carbon $start, Carbon $end)
    {
        return Order::query()->where('orders.status', 3)
            ->whereNotNull('orders.order_number_vtp')->where('orders.order_number_vtp', '<>', '')
            ->when($shopIds !== null, fn ($query) => $query->whereIn('orders.shop_id', $shopIds))
            ->whereBetween('orders.created_at', [$start->toDateTimeString(), $end->toDateTimeString()]);
    }

    private static function dailyOrderSummaries(?array $shopIds)
    {
        $query = DB::table('daily_order_summaries');

        return $shopIds === null ? $query->where('shop_id', 0) : $query->whereIn('shop_id', $shopIds);
    }

    /** @return array{0: ?array{string, string}, 1: array<array{Carbon, Carbon}>} */
    private static function splitRange(Carbon $start, Carbon $end, bool $allowSummary = true): array
    {
        if (! $allowSummary || ! OrderSummaryService::isReady()) {
            return [null, [[$start, $end]]];
        }

        // The source API predicates have second precision. A previous period
        // may begin at noon or stop mid-day, which a daily row cannot represent.
        $wholeStart = $start->copy()->startOfDay();
        if ($start->format('H:i:s') !== '00:00:00') {
            $wholeStart->addDay();
        }
        $wholeEnd = $end->copy()->startOfDay();
        if ($end->format('H:i:s') !== '23:59:59') {
            $wholeEnd->subDay();
        }
        if ($wholeStart->gt($wholeEnd)) {
            return [null, [[$start, $end]]];
        }

        $sourceRanges = [];
        if ($start->toDateTimeString() < $wholeStart->toDateTimeString()) {
            $sourceRanges[] = [$start, $wholeStart->copy()->subSecond()];
        }
        $afterWholeDays = $wholeEnd->copy()->addDay()->startOfDay();
        if ($end->toDateTimeString() >= $afterWholeDays->toDateTimeString()) {
            $sourceRanges[] = [$afterWholeDays, $end];
        }

        return [[$wholeStart->toDateString(), $wholeEnd->toDateString()], $sourceRanges];
    }

    private static function period(string $date, string $viewMode): string
    {
        return match ($viewMode) {
            'all' => 'all',
            'day' => substr($date, 0, 10),
            'month' => substr($date, 0, 7),
            'quarter' => substr($date, 0, 4).'-Q'.(intdiv((int) substr($date, 5, 2) - 1, 3) + 1),
            'year' => substr($date, 0, 4),
            default => throw new \InvalidArgumentException('Unknown product period.'),
        };
    }

    private static function scaled(mixed $value, int $scale): int
    {
        return (int) round((float) $value * $scale);
    }

    private static function addProduct(array &$groups, string $period, string $key, string $name, int $quantity, int $revenue, int $orders): void
    {
        $group = $period.':'.$key;
        $groups[$group] ??= ['period' => $period, 'product_key' => $key, 'name' => $name, 'quantity' => 0, 'revenue' => 0, 'orders_count' => 0];
        if (strcmp($name, $groups[$group]['name']) > 0) {
            $groups[$group]['name'] = $name;
        }
        $groups[$group]['quantity'] += $quantity;
        $groups[$group]['revenue'] += $revenue;
        $groups[$group]['orders_count'] += $orders;
    }
}
