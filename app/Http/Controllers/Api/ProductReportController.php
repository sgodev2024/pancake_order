<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ShopAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductReportController extends Controller
{
    public function __construct(private readonly ShopAccessService $shopAccessService)
    {
    }

    public function index(Request $request)
    {
        $actor = $request->user();
        $requestedShopId = $this->shopAccessService->authorizeRequestedShopId(
            $actor,
            $request->filled('shop_id') ? $request->integer('shop_id') : null
        );
        $shopIds = $requestedShopId !== null
            ? [$requestedShopId]
            : (! $this->shopAccessService->isGlobal($actor) ? $this->shopAccessService->ids($actor)->all() : null);

        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'week_mode' => ['nullable', 'in:latest'],
        ]);

        if ($shopIds === []) {
            return response()->json([
                'success' => true,
                'data' => $this->emptyReport(),
            ]);
        }

        $latestOrderAt = $this->deliveredOrders($shopIds)->max('created_at');

        if ($request->query('week_mode') === 'latest') {
            if ($latestOrderAt === null) {
                return response()->json([
                    'success' => true,
                    'data' => $this->emptyReport(),
                ]);
            }
            $start = $this->latestRankableWeek($shopIds)
                ?? Carbon::parse($latestOrderAt)->startOfWeek(Carbon::MONDAY)->startOfDay();
            $end = $start->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay();
        } elseif ($request->filled('date_from') && $request->filled('date_to')) {
            $start = Carbon::parse($request->query('date_from'))->startOfDay();
            $end = Carbon::parse($request->query('date_to'))->endOfDay();
            if ($end->lt($start)) {
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Cần chọn thời gian.',
            ], 422);
        }

        $now = Carbon::now();
        $measuredEnd = $end->copy();
        $truncated = false;
        if ($start->lte($now) && $end->gt($now)) {
            $measuredEnd = $now->copy();
            $truncated = true;
        }
        $elapsed = max($measuredEnd->getTimestamp() - $start->getTimestamp(), 0);
        $previousStart = $start->copy()->subWeek();
        $yearStart = $start->copy()->subYear();

        $cacheKey = 'prod_sales_' . md5(json_encode([
            $shopIds,
            $start->toDateString(),
            $end->toDateString(),
            $request->query('week_mode'),
            $truncated ? floor($now->timestamp / 120) : null,
        ]));

        $data = Cache::remember($cacheKey, 120, function () use ($shopIds, $start, $measuredEnd, $latestOrderAt, $truncated, $previousStart, $yearStart, $elapsed) {
            $previousQty = $this->deliveredQuantityTotal($shopIds, $previousStart, $previousStart->copy()->addSeconds($elapsed));
            $yearQty = $this->deliveredQuantityTotal($shopIds, $yearStart, $yearStart->copy()->addSeconds($elapsed));

            $rows = DB::select(
                $this->productSql(true, $shopIds, false),
                $this->bindings($shopIds, $start, $measuredEnd)
            );

            $totalQuantity = 0;
            $totalRevenue = 0;
            foreach ($rows as $row) {
                $totalQuantity += (float) $row->quantity;
                $totalRevenue += (float) ($row->revenue ?? 0);
            }

            $totalOrders = (int) $this->deliveredOrders($shopIds)
                ->whereBetween('orders.created_at', [$start->toDateTimeString(), $measuredEnd->toDateTimeString()])
                ->count();

            $items = array_map(fn ($row) => [
                'name' => $row->name,
                'quantity' => $this->quantity($row->quantity),
                'revenue' => round((float) ($row->revenue ?? 0)),
                'orders_count' => (int) $row->orders_count,
            ], array_slice($rows, 0, 10));

            return [
                'from' => $start->toDateString(),
                'to' => $measuredEnd->toDateString(),
                'latest_order_at' => $latestOrderAt,
                'truncated' => $truncated,
                'total_quantity' => $this->quantity($totalQuantity),
                'total_revenue' => round($totalRevenue),
                'total_orders' => $totalOrders,
                'quantity_vs_previous' => $this->changePercent($totalQuantity, $previousQty),
                'quantity_vs_year' => $this->changePercent($totalQuantity, $yearQty),
                'items' => $items,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    private function deliveredOrders(?array $shopIds)
    {
        return Order::query()
            ->where('orders.status', 3)
            ->whereNotNull('orders.order_number_vtp')
            ->where('orders.order_number_vtp', '<>', '')
            ->when($shopIds !== null, fn ($query) => $query->whereIn('orders.shop_id', $shopIds));
    }

    private function deliveredQuantityTotal(?array $shopIds, Carbon $start, Carbon $end): float|int
    {
        $val = $this->deliveredOrders($shopIds)
            ->whereBetween('orders.created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->sum('orders.total_quantity');

        return $this->quantity($val ?? 0);
    }

    private function latestRankableWeek(?array $shopIds): ?Carbon
    {
        if (! Schema::hasColumn('orders', 'prepaid_amount')) {
            return null;
        }

        $provinceWeeks = $this->deliveredOrders($shopIds)
            ->whereNotNull('orders.prepaid_amount')
            ->selectRaw('DATE(DATE_SUB(orders.created_at, INTERVAL WEEKDAY(orders.created_at) DAY)) as week_start, orders.province_id')
            ->groupByRaw('DATE(DATE_SUB(orders.created_at, INTERVAL WEEKDAY(orders.created_at) DAY)), orders.province_id')
            ->havingRaw('SUM(COALESCE(orders.cod, 0) + orders.prepaid_amount) > 0');

        $weekStart = DB::query()
            ->fromSub($provinceWeeks, 'province_weeks')
            ->select('week_start')
            ->groupBy('week_start')
            ->havingRaw('COUNT(*) >= 20')
            ->orderByDesc('week_start')
            ->value('week_start');

        return $weekStart ? Carbon::parse($weekStart)->startOfDay() : null;
    }

    private function topProducts(?array $shopIds, Carbon $start, Carbon $end): array
    {
        $rows = DB::select(
            $this->productSql(true, $shopIds),
            $this->bindings($shopIds, $start, $end)
        );

        return array_map(fn ($row) => [
            'name' => $row->name,
            'quantity' => $this->quantity($row->quantity),
            'revenue' => round((float) ($row->revenue ?? 0)),
            'orders_count' => (int) $row->orders_count,
        ], $rows);
    }

    private function quantityTotal(?array $shopIds, Carbon $start, Carbon $end): array
    {
        $row = DB::selectOne(
            $this->productSql(false, $shopIds),
            $this->bindings($shopIds, $start, $end)
        );

        return [
            'quantity' => $this->quantity($row->quantity ?? 0),
            'revenue' => round((float) ($row->revenue ?? 0)),
            'orders_count' => (int) ($row->orders_count ?? 0),
        ];
    }

    private function productSql(bool $grouped, ?array $shopIds, bool $limit = true): string
    {
        $shopSql = $shopIds === null
            ? ''
            : ' AND orders.shop_id IN ('.implode(',', array_fill(0, count($shopIds), '?')).')';
        $name = "COALESCE(NULLIF(TRIM(item.product_name), ''), 'Chưa rõ sản phẩm')";
        $limitSql = $limit ? ' LIMIT 10' : '';

        if (! $grouped) {
            return "SELECT COALESCE(SUM(item.quantity), 0) AS quantity,
                    COALESCE(SUM(COALESCE(item.quantity, 0) * COALESCE(item.retail_price, 0)), 0) AS revenue,
                    COUNT(DISTINCT orders.id) AS orders_count
                FROM orders
                JOIN JSON_TABLE(orders.pancake_full_data, '$.items[*]' COLUMNS (
                    product_name VARCHAR(255) PATH '$.variation_info.name',
                    quantity DECIMAL(12,2) PATH '$.quantity',
                    retail_price DECIMAL(14,2) PATH '$.variation_info.retail_price'
                )) AS item
                WHERE orders.deleted_at IS NULL
                  AND orders.status = 3
                  AND orders.order_number_vtp IS NOT NULL
                  AND orders.order_number_vtp <> ''
                  AND orders.pancake_full_data IS NOT NULL
                  AND orders.created_at BETWEEN ? AND ?
                  {$shopSql}";
        }

        return "SELECT {$name} AS name,
                SUM(COALESCE(item.quantity, 0)) AS quantity,
                SUM(COALESCE(item.quantity, 0) * COALESCE(item.retail_price, 0)) AS revenue,
                COUNT(DISTINCT orders.id) AS orders_count
            FROM orders
            JOIN JSON_TABLE(orders.pancake_full_data, '$.items[*]' COLUMNS (
                product_name VARCHAR(255) PATH '$.variation_info.name',
                quantity DECIMAL(12,2) PATH '$.quantity',
                retail_price DECIMAL(14,2) PATH '$.variation_info.retail_price'
            )) AS item
            WHERE orders.deleted_at IS NULL
              AND orders.status = 3
              AND orders.order_number_vtp IS NOT NULL
              AND orders.order_number_vtp <> ''
              AND orders.pancake_full_data IS NOT NULL
              AND orders.created_at BETWEEN ? AND ?
              {$shopSql}
            GROUP BY {$name}
            HAVING SUM(COALESCE(item.quantity, 0)) > 0
            ORDER BY quantity DESC, orders_count DESC, name ASC{$limitSql}";
    }

    private function bindings(?array $shopIds, Carbon $start, Carbon $end): array
    {
        return array_merge(
            [$start->toDateTimeString(), $end->toDateTimeString()],
            $shopIds ?? []
        );
    }

    private function quantity(mixed $value): float|int
    {
        $number = (float) $value;
        return floor($number) === $number ? (int) $number : round($number, 2);
    }

    private function changePercent(float|int $current, float|int $base): ?float
    {
        if ((float) $base == 0.0) {
            return null;
        }

        return round((((float) $current - (float) $base) / (float) $base) * 100, 1);
    }

    private function emptyReport(): array
    {
        return [
            'from' => null,
            'to' => null,
            'latest_order_at' => null,
            'truncated' => false,
            'total_quantity' => 0,
            'total_revenue' => 0,
            'total_orders' => 0,
            'quantity_vs_previous' => null,
            'quantity_vs_year' => null,
            'items' => [],
        ];
    }
    public function periodReport(Request $request)
    {
        $actor = $request->user();
        $requestedShopId = $this->shopAccessService->authorizeRequestedShopId(
            $actor,
            $request->filled('shop_id') ? $request->integer('shop_id') : null
        );
        $shopIds = $requestedShopId !== null
            ? [$requestedShopId]
            : (! $this->shopAccessService->isGlobal($actor) ? $this->shopAccessService->ids($actor)->all() : null);

        $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'view_mode' => ['required', 'in:day,month,quarter,year'],
            'compare' => ['required', 'in:none,mom,yoy'],
        ]);

        if ($shopIds === []) {
            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => null,
                    'chart' => [],
                    'products' => [],
                    'from' => $request->query('date_from'),
                    'to' => $request->query('date_to'),
                    'view_mode' => $request->query('view_mode'),
                    'compare' => $request->query('compare'),
                ],
            ]);
        }

        $start = Carbon::parse($request->query('date_from'))->startOfDay();
        $end = Carbon::parse($request->query('date_to'))->endOfDay();
        if ($end->lt($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }
        $viewMode = $request->query('view_mode');
        $compare = $request->query('compare');

        $cacheKey = 'prod_period_' . md5(json_encode([
            $shopIds,
            $start->toDateString(),
            $end->toDateString(),
            $viewMode,
            $compare,
        ]));

        $data = Cache::remember($cacheKey, 120, function () use ($shopIds, $start, $end, $viewMode, $compare) {
            $groupByStr = match ($viewMode) {
                'day' => "DATE_FORMAT(orders.created_at, '%Y-%m-%d')",
                'month' => "DATE_FORMAT(orders.created_at, '%Y-%m')",
                'quarter' => "CONCAT(YEAR(orders.created_at), '-Q', QUARTER(orders.created_at))",
                'year' => "DATE_FORMAT(orders.created_at, '%Y')",
            };

            $fetchStart = $start->copy();
            $prevStart = $start->copy();
            $prevEnd = $end->copy();

            if ($compare === 'mom') {
                if ($viewMode === 'day') { $fetchStart->subDay(); $prevStart->subDay(); $prevEnd->subDay(); }
                elseif ($viewMode === 'month') { $fetchStart->subMonth(); $prevStart->subMonth(); $prevEnd->subMonth(); }
                elseif ($viewMode === 'quarter') { $fetchStart->subQuarter(); $prevStart->subQuarter(); $prevEnd->subQuarter(); }
                elseif ($viewMode === 'year') { $fetchStart->subYear(); $prevStart->subYear(); $prevEnd->subYear(); }
            } elseif ($compare === 'yoy') {
                $fetchStart->subYear();
                $prevStart->subYear();
                $prevEnd->subYear();
            }

            $shopSql = $shopIds === null
                ? ''
                : ' AND orders.shop_id IN ('.implode(',', array_fill(0, count($shopIds), '?')).')';
            $name = "COALESCE(NULLIF(TRIM(item.product_name), ''), 'Chưa rõ sản phẩm')";

            // 1. Fast orders count per period (~50ms)
            $ordersSql = "SELECT {$groupByStr} AS period, COUNT(*) as orders_count
                          FROM orders
                          WHERE orders.deleted_at IS NULL
                            AND orders.status = 3
                            AND orders.order_number_vtp IS NOT NULL
                            AND orders.order_number_vtp <> ''
                            AND orders.created_at BETWEEN ? AND ?
                            {$shopSql}
                          GROUP BY period";
            $ordersBindings = array_merge([$fetchStart->toDateTimeString(), $end->toDateTimeString()], $shopIds ?? []);
            $ordersRows = DB::select($ordersSql, $ordersBindings);
            $periodOrdersMap = [];
            foreach ($ordersRows as $r) {
                $periodOrdersMap[$r->period] = (int) $r->orders_count;
            }

            // 2. Single JSON_TABLE query grouping by period + name
            $sql = "SELECT {$groupByStr} AS period,
                        {$name} AS name,
                        SUM(COALESCE(item.quantity, 0)) AS quantity,
                        SUM(COALESCE(item.quantity, 0) * COALESCE(item.retail_price, 0)) AS revenue
                    FROM orders
                    JOIN JSON_TABLE(orders.pancake_full_data, '$.items[*]' COLUMNS (
                        product_name VARCHAR(255) PATH '$.variation_info.name',
                        quantity DECIMAL(12,2) PATH '$.quantity',
                        retail_price DECIMAL(14,2) PATH '$.variation_info.retail_price'
                    )) AS item
                    WHERE orders.deleted_at IS NULL
                      AND orders.status = 3
                      AND orders.order_number_vtp IS NOT NULL
                      AND orders.order_number_vtp <> ''
                      AND orders.pancake_full_data IS NOT NULL
                      AND orders.created_at BETWEEN ? AND ?
                      {$shopSql}
                    GROUP BY period, {$name}";
            $bindings = array_merge([$fetchStart->toDateTimeString(), $end->toDateTimeString()], $shopIds ?? []);
            $rows = DB::select($sql, $bindings);

            $generatedPeriods = $this->generatePeriods($start, $end, $viewMode);
            $currentPeriodsSet = array_flip($generatedPeriods);

            $prevPeriodsSet = [];
            foreach ($generatedPeriods as $p) {
                $prevP = $this->getPreviousPeriodString($p, $viewMode, $compare);
                if ($prevP) {
                    $prevPeriodsSet[$prevP] = true;
                }
            }

            $periodTotals = [];
            $currentProductsMap = [];
            $prevProductsMap = [];

            foreach ($rows as $r) {
                $p = $r->period;
                $n = $r->name;
                $rev = (float) $r->revenue;
                $qty = (float) $r->quantity;

                if (! isset($periodTotals[$p])) {
                    $periodTotals[$p] = ['revenue' => 0, 'quantity' => 0];
                }
                $periodTotals[$p]['revenue'] += $rev;
                $periodTotals[$p]['quantity'] += $qty;

                if (isset($currentPeriodsSet[$p])) {
                    if (! isset($currentProductsMap[$n])) {
                        $currentProductsMap[$n] = ['quantity' => 0, 'revenue' => 0];
                    }
                    $currentProductsMap[$n]['quantity'] += $qty;
                    $currentProductsMap[$n]['revenue'] += $rev;
                }

                if (isset($prevPeriodsSet[$p])) {
                    if (! isset($prevProductsMap[$n])) {
                        $prevProductsMap[$n] = ['revenue' => 0];
                    }
                    $prevProductsMap[$n]['revenue'] += $rev;
                }
            }

            $chart = [];
            $totalRev = 0;
            $totalQty = 0;
            $totalOrders = 0;
            $prevTotalRev = 0;

            foreach ($generatedPeriods as $period) {
                $cur = $periodTotals[$period] ?? null;
                $prevP = $this->getPreviousPeriodString($period, $viewMode, $compare);
                $prev = $prevP ? ($periodTotals[$prevP] ?? null) : null;

                $rev = $cur ? round($cur['revenue']) : 0;
                $qty = $cur ? $this->quantity($cur['quantity']) : 0;
                $orders = $periodOrdersMap[$period] ?? 0;

                $pRev = null;
                if ($compare !== 'none') {
                    $pRev = $prev ? round($prev['revenue']) : 0;
                }

                [$pStart, $pEnd] = $this->getPeriodRange($period, $viewMode);

                $chart[] = [
                    'label' => $this->formatPeriodLabel($period, $viewMode),
                    'period_start' => $pStart,
                    'period_end' => $pEnd,
                    'revenue' => $rev,
                    'orders_count' => $orders,
                    'quantity' => $qty,
                    'previous_revenue' => $pRev,
                    'change_percent' => $compare !== 'none' ? $this->changePercent($rev, $pRev) : null,
                ];

                $totalRev += $rev;
                $totalQty += $qty;
                $totalOrders += $orders;
                if ($pRev !== null) {
                    $prevTotalRev += $pRev;
                }
            }

            uasort($currentProductsMap, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
            $top50 = array_slice($currentProductsMap, 0, 50, true);

            $products = [];
            $rank = 1;
            foreach ($top50 as $n => $data) {
                $rev = round($data['revenue']);
                $qty = $this->quantity($data['quantity']);
                $pRev = null;
                if ($compare !== 'none') {
                    $pRev = isset($prevProductsMap[$n]) ? round($prevProductsMap[$n]['revenue']) : 0;
                }

                $products[] = [
                    'rank' => $rank++,
                    'name' => $n,
                    'quantity' => $qty,
                    'revenue' => $rev,
                    'previous_revenue' => $pRev,
                    'change_amount' => $pRev !== null ? $rev - $pRev : null,
                    'change_percent' => $pRev !== null ? $this->changePercent($rev, $pRev) : null,
                    'contribution_percent' => $totalRev > 0 ? round(($rev / $totalRev) * 100, 1) : 0,
                ];
            }

            $summary = [
                'total_revenue' => $totalRev,
                'total_quantity' => $totalQty,
                'total_orders' => $totalOrders,
                'previous_revenue' => $compare !== 'none' ? $prevTotalRev : null,
                'change_amount' => $compare !== 'none' ? $totalRev - $prevTotalRev : null,
                'change_percent' => $compare !== 'none' ? $this->changePercent($totalRev, $prevTotalRev) : null,
            ];

            return [
                'summary' => $summary,
                'chart' => $chart,
                'products' => $products,
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'view_mode' => $viewMode,
                'compare' => $compare,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    private function generatePeriods(Carbon $start, Carbon $end, string $viewMode): array
    {
        $periods = [];
        $current = $start->copy();
        while ($current->lte($end)) {
            if ($viewMode === 'day') {
                $periods[] = $current->format('Y-m-d');
                $current->addDay();
            } elseif ($viewMode === 'month') {
                $periods[] = $current->format('Y-m');
                $current->addMonth()->startOfMonth();
            } elseif ($viewMode === 'quarter') {
                $periods[] = $current->year . '-Q' . $current->quarter;
                $current->addQuarter()->startOfQuarter();
            } elseif ($viewMode === 'year') {
                $periods[] = $current->format('Y');
                $current->addYear()->startOfYear();
            }
        }
        return array_unique($periods);
    }

    private function getPreviousPeriodString(string $period, string $viewMode, string $compare): ?string
    {
        if ($compare === 'none') return null;
        if ($viewMode === 'day') {
            $date = Carbon::createFromFormat('Y-m-d', $period);
            return ($compare === 'mom' ? $date->subDay() : $date->subYear())->format('Y-m-d');
        } elseif ($viewMode === 'month') {
            $date = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
            return ($compare === 'mom' ? $date->subMonth() : $date->subYear())->format('Y-m');
        } elseif ($viewMode === 'quarter') {
            [$year, $q] = explode('-Q', $period);
            $year = (int)$year;
            $q = (int)$q;
            if ($compare === 'mom') {
                $q--;
                if ($q < 1) { $q = 4; $year--; }
                return "{$year}-Q{$q}";
            } else {
                return ($year - 1) . "-Q{$q}";
            }
        } elseif ($viewMode === 'year') {
            if ($compare === 'mom' || $compare === 'yoy') {
                return ((int)$period - 1) . "";
            }
        }
        return null;
    }

    private function formatPeriodLabel(string $period, string $viewMode): string
    {
        if ($viewMode === 'day') return Carbon::createFromFormat('Y-m-d', $period)->format('d/m/Y');
        elseif ($viewMode === 'month') return 'T' . Carbon::createFromFormat('Y-m', $period)->format('n/Y');
        elseif ($viewMode === 'quarter') {
            [$year, $q] = explode('-Q', $period);
            return "Q{$q}/{$year}";
        }
        elseif ($viewMode === 'year') return $period;
        return $period;
    }

    private function getPeriodRange(string $period, string $viewMode): array
    {
        if ($viewMode === 'day') {
            $date = Carbon::createFromFormat('Y-m-d', $period);
            return [$date->toDateString(), $date->toDateString()];
        } elseif ($viewMode === 'month') {
            $date = Carbon::createFromFormat('Y-m', $period);
            return [$date->copy()->startOfMonth()->toDateString(), $date->copy()->endOfMonth()->toDateString()];
        } elseif ($viewMode === 'quarter') {
            [$year, $q] = explode('-Q', $period);
            $date = Carbon::create((int)$year, ((int)$q - 1) * 3 + 1, 1);
            return [$date->copy()->startOfQuarter()->toDateString(), $date->copy()->endOfQuarter()->toDateString()];
        } elseif ($viewMode === 'year') {
            $date = Carbon::createFromFormat('Y', $period);
            return [$date->copy()->startOfYear()->toDateString(), $date->copy()->endOfYear()->toDateString()];
        }
        return [$period, $period];
    }

    public function monthlyQuantities(Request $request)
    {
        $actor = $request->user();
        $requestedShopId = $this->shopAccessService->authorizeRequestedShopId(
            $actor,
            $request->filled('shop_id') ? $request->integer('shop_id') : null
        );
        $shopIds = $requestedShopId !== null
            ? [$requestedShopId]
            : (! $this->shopAccessService->isGlobal($actor) ? $this->shopAccessService->ids($actor)->all() : null);

        $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2030'],
        ]);

        $year = $request->integer('year', (int) date('Y'));
        $hasOrdersInYear = Order::query()
            ->where('status', 3)
            ->whereNotNull('order_number_vtp')
            ->where('order_number_vtp', '<>', '')
            ->whereYear('created_at', $year)
            ->when($shopIds !== null, fn ($q) => $q->whereIn('shop_id', $shopIds))
            ->exists();

        if (! $hasOrdersInYear) {
            $latestYear = Order::query()
                ->where('status', 3)
                ->whereNotNull('order_number_vtp')
                ->where('order_number_vtp', '<>', '')
                ->when($shopIds !== null, fn ($q) => $q->whereIn('shop_id', $shopIds))
                ->selectRaw('YEAR(MAX(created_at)) as yr')
                ->value('yr');
            if ($latestYear) {
                $year = (int) $latestYear;
            }
        }

        $cacheKey = 'prod_mq_' . md5(json_encode([$shopIds, $year]));

        $data = Cache::remember($cacheKey, 120, function () use ($shopIds, $year) {
            $shopSql = $shopIds === null
                ? ''
                : ' AND orders.shop_id IN ('.implode(',', array_fill(0, count($shopIds), '?')).')';

            $sql = "SELECT MONTH(orders.created_at) as month, SUM(COALESCE(orders.total_quantity, 0)) as total_qty
                    FROM orders
                    WHERE orders.deleted_at IS NULL
                      AND orders.status = 3
                      AND orders.order_number_vtp IS NOT NULL
                      AND orders.order_number_vtp <> ''
                      AND YEAR(orders.created_at) = ?
                      {$shopSql}
                    GROUP BY MONTH(orders.created_at)
                    ORDER BY month ASC";

            $bindings = array_merge([$year], $shopIds ?? []);
            $rows = DB::select($sql, $bindings);

            $monthMap = [];
            $latestMonthWithData = 0;
            foreach ($rows as $row) {
                $m = (int) $row->month;
                $monthMap[$m] = (float) $row->total_qty;
                if ((float) $row->total_qty > 0) {
                    $latestMonthWithData = max($latestMonthWithData, $m);
                }
            }

            $months = [];
            $upCount = 0;
            $upQtyTotal = 0;
            $downCount = 0;
            $downQtyTotal = 0;
            $totalYearQty = 0;
            $prevQty = null;

            for ($m = 1; $m <= 12; $m++) {
                $qty = $monthMap[$m] ?? 0;
                $diff = null;
                $percent = null;
                $trend = 'base';

                if ($m > $latestMonthWithData && $latestMonthWithData > 0) {
                    $trend = 'future';
                } elseif ($prevQty !== null) {
                    $diff = $qty - $prevQty;
                    if ($prevQty > 0) {
                        $percent = round((($qty - $prevQty) / $prevQty) * 100, 1);
                    } elseif ($qty > 0) {
                        $percent = 100.0;
                    } else {
                        $percent = 0.0;
                    }

                    if ($diff > 0) {
                        $trend = 'up';
                        $upCount++;
                        $upQtyTotal += $diff;
                    } elseif ($diff < 0) {
                        $trend = 'down';
                        $downCount++;
                        $downQtyTotal += abs($diff);
                    } else {
                        $trend = 'same';
                    }
                }

                $months[] = [
                    'month' => $m,
                    'label' => "T{$m}",
                    'quantity' => $qty,
                    'diff' => $diff,
                    'percent' => $percent,
                    'trend' => $trend,
                ];

                $totalYearQty += $qty;
                if ($m <= $latestMonthWithData) {
                    $prevQty = $qty;
                }
            }

            return [
                'year' => $year,
                'total_quantity' => $totalYearQty,
                'summary' => [
                    'up_count' => $upCount,
                    'up_qty_total' => $upQtyTotal,
                    'down_count' => $downCount,
                    'down_qty_total' => $downQtyTotal,
                ],
                'months' => $months,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
