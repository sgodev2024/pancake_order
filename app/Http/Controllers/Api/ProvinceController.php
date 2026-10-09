<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminOnlyMiddleware;
use App\Models\Order;
use App\Models\Province;
use App\Models\Shop;
use App\Services\ShopAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProvinceController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ShopAccessService $shopAccessService)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware(AdminOnlyMiddleware::class . ':allow-director', only: ['index']),
        ];
    }

    public function index()
    {
        return response()->json([
            "success" => true,
            "data"    => Province::all()
        ]);
    }

    public function report(Request $request)
    {
        try {
            $shop = NULL;
            $inputs = $request->only("date_from", "date_to", "province_id", "shop_id");
            $provinces = Province::query();
            if (isset($inputs["shop_id"])) {
                $shop = Shop::select("id", "name", "avatar_url")->whereId($inputs["shop_id"])->first();
            }
            if (isset($inputs["province_id"])) {
                $provinces->where("id", $inputs["province_id"]);
            }
            $provinces->withCount(["orders" => function ($q) use ($inputs) {
                if (isset($inputs["shop_id"])) {
                    $q->where("orders.shop_id", $inputs["shop_id"]);
                }
                if (isset($inputs["date_from"])) {
                    $q->where("created_at", ">=", $inputs["date_from"] . " 00:00:00");
                }
                if (isset($inputs["date_to"])) {
                    $q->where("created_at", "<=", $inputs["date_to"] . " 23:59:59");
                }
            }]);
            $provinces->withSum([
                "orders as total_cod" => function ($q) use ($inputs) {
                    if (isset($inputs["shop_id"])) {
                        $q->where("orders.shop_id", $inputs["shop_id"]);
                    }
                    if (!empty($inputs["date_from"])) {
                        $q->where("created_at", ">=", $inputs["date_from"] . " 00:00:00");
                    }
                    if (!empty($inputs["date_to"])) {
                        $q->where("created_at", "<=", $inputs["date_to"] . " 23:59:59");
                    }
                }
            ], "cod");

            return response()->json([
                "success" => true,
                "data"    => [
                    "provinces" => $provinces->get(),
                    "shop"      => $shop
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => true,
                "message" => $th->getMessage()
            ]);
        }
    }

    public function charts(Request $request)
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
            'month_mode' => ['nullable', 'in:latest'],
        ]);

        $hasPrepaid = Schema::hasColumn('orders', 'prepaid_amount');
        $latestOrderAt = $this->waybillOrders($shopIds)->max('created_at');

        if ($request->query('month_mode') === 'latest') {
            if ($latestOrderAt === null) {
                return response()->json([
                    'success' => true,
                    'data' => $this->emptyChart(),
                ]);
            }
            $start = $this->latestRankableMonth($shopIds, $hasPrepaid)
                ?? Carbon::parse($latestOrderAt)->startOfMonth()->startOfDay();
            $end = $start->copy()->endOfMonth()->endOfDay();
        } elseif ($request->query('week_mode') === 'latest' || (! $request->filled('date_from') && ! $request->filled('date_to'))) {
            if ($latestOrderAt === null) {
                return response()->json([
                    'success' => true,
                    'data' => $this->emptyChart(),
                ]);
            }
            $start = Carbon::now()->startOfWeek(Carbon::MONDAY)->startOfDay();
            $end = $start->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay();
        } elseif ($request->filled('date_from') && $request->filled('date_to')) {
            $start = Carbon::parse($request->query('date_from'))->startOfDay();
            $end = Carbon::parse($request->query('date_to'))->endOfDay();
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

        $isMonthMode = $request->query('month_mode') === 'latest';
        if ($isMonthMode) {
            $previousStart = $start->copy()->subMonth();
            $yearStart = $start->copy()->subYear();
            $isFullMonth = $start->copy()->startOfMonth()->isSameDay($start) && $end->copy()->endOfMonth()->isSameDay($end);
            if ($isFullMonth && ! $truncated) {
                $previousEnd = $previousStart->copy()->endOfMonth()->endOfDay();
                $yearEnd = $yearStart->copy()->endOfMonth()->endOfDay();
            } else {
                $previousEnd = $previousStart->copy()->addSeconds($elapsed);
                $yearEnd = $yearStart->copy()->addSeconds($elapsed);
            }
        } else {
            $previousStart = $start->copy()->subWeek();
            $previousEnd = $previousStart->copy()->addSeconds($elapsed);
            $yearStart = $start->copy()->subYear();
            $yearEnd = $yearStart->copy()->addSeconds($elapsed);
        }

        $totals = $this->regionTotals($shopIds, $hasPrepaid, $start, $measuredEnd);
        $previous = $this->regionTotals($shopIds, $hasPrepaid, $previousStart, $previousEnd);
        $year = $this->regionTotals($shopIds, $hasPrepaid, $yearStart, $yearEnd);
        $items = $this->regionRows($shopIds, $hasPrepaid, $start, $measuredEnd);

        $anchorWeek = ($request->filled('date_from') && $request->filled('date_to'))
            ? Carbon::parse($request->query('date_to'))->startOfWeek(Carbon::MONDAY)->startOfDay()
            : $start->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $trendStart = $anchorWeek->copy()->subWeeks(9)->startOfDay();

        $trendOrderValue = $hasPrepaid
            ? 'SUM(CASE WHEN orders.prepaid_amount IS NULL THEN 0 ELSE COALESCE(orders.cod, 0) + orders.prepaid_amount END)'
            : 'NULL';
        $trendCollected = $hasPrepaid
            ? 'SUM(CASE WHEN orders.status = 16 AND orders.prepaid_amount IS NOT NULL THEN COALESCE(orders.cod, 0) + orders.prepaid_amount ELSE 0 END)'
            : 'NULL';

        $trendRows = $this->waybillOrders($shopIds)
            ->whereBetween('orders.created_at', [$trendStart->toDateTimeString(), $anchorWeek->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay()->toDateTimeString()])
            ->selectRaw("
                DATE(DATE_SUB(orders.created_at, INTERVAL WEEKDAY(orders.created_at) DAY)) as week_start,
                COUNT(DISTINCT CASE WHEN orders.status = 3 THEN orders.id END) as delivered_count,
                {$this->deliveredExpression($hasPrepaid)} as delivered_value,
                {$trendOrderValue} as order_value,
                {$trendCollected} as collected_value
            ")
            ->groupByRaw('DATE(DATE_SUB(orders.created_at, INTERVAL WEEKDAY(orders.created_at) DAY))')
            ->get()
            ->mapWithKeys(fn ($row) => [Carbon::parse($row->week_start)->toDateString() => [
                'delivered_count' => (int) ($row->delivered_count ?? 0),
                'delivered' => (float) ($row->delivered_value ?? 0),
                'order_value' => (float) ($row->order_value ?? 0),
                'collected' => (float) ($row->collected_value ?? 0),
            ]]);

        $labels = [];
        $current = [];
        $deliveredCounts = [];
        $orderValueTrend = [];
        $collectedTrend = [];

        for ($week = $trendStart->copy(); $week->lte($anchorWeek); $week->addWeek()) {
            $point = $trendRows[$week->toDateString()] ?? null;
            $labels[] = $week->format('d/m');
            $deliveredCounts[] = (int) ($point['delivered_count'] ?? 0);
            $current[] = (float) ($point['delivered'] ?? 0);
            $orderValueTrend[] = (float) ($point['order_value'] ?? 0);
            $collectedTrend[] = (float) ($point['collected'] ?? 0);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'from' => $start->toDateString(),
                'to' => $measuredEnd->toDateString(),
                'latest_order_at' => $latestOrderAt,
                'truncated' => $truncated,
                'prepaid_available' => $hasPrepaid,
                'total_orders' => (int) ($totals->orders_count ?? 0),
                'province_count' => (int) ($totals->province_count ?? 0),
                'order_value' => $this->money($totals->order_value ?? null),
                'delivered_value' => $this->money($totals->delivered_value ?? null),
                'collected_value' => $this->money($totals->collected_value ?? null),
                'shipping_orders' => (int) ($totals->shipping_orders ?? 0),
                'prepaid_coverage' => $this->coverage($totals->prepaid_known ?? 0, $totals->orders_count ?? 0),
                'delivered_vs_previous' => $this->changePercent($totals->delivered_value ?? null, $previous->delivered_value ?? null),
                'delivered_vs_year' => $this->changePercent($totals->delivered_value ?? null, $year->delivered_value ?? null),
                'items' => $items,
                'trend' => [
                    'labels' => $labels,
                    'current' => $current,
                    'delivered_count' => $deliveredCounts,
                    'order_value' => $orderValueTrend,
                    'collected' => $collectedTrend,
                ],
            ],
        ]);
    }

    private function waybillOrders(?array $shopIds)
    {
        return Order::query()
            ->whereNotNull('orders.order_number_vtp')
            ->where('orders.order_number_vtp', '<>', '')
            ->when($shopIds !== null, fn ($query) => $query->whereIn('orders.shop_id', $shopIds));
    }

    private function deliveredExpression(bool $hasPrepaid): string
    {
        if (! $hasPrepaid) {
            return 'NULL';
        }

        return 'SUM(CASE WHEN orders.status = 3 AND orders.prepaid_amount IS NOT NULL THEN COALESCE(orders.cod, 0) + orders.prepaid_amount ELSE 0 END)';
    }

    private function regionTotals(?array $shopIds, bool $hasPrepaid, Carbon $start, Carbon $end)
    {
        $value = $hasPrepaid
            ? 'SUM(CASE WHEN orders.prepaid_amount IS NULL THEN 0 ELSE COALESCE(orders.cod, 0) + orders.prepaid_amount END)'
            : 'NULL';
        $collected = $hasPrepaid
            ? 'SUM(CASE WHEN orders.status = 16 AND orders.prepaid_amount IS NOT NULL THEN COALESCE(orders.cod, 0) + orders.prepaid_amount ELSE 0 END)'
            : 'NULL';
        $known = $hasPrepaid ? 'SUM(orders.prepaid_amount IS NOT NULL)' : '0';

        return $this->waybillOrders($shopIds)
            ->whereBetween('orders.created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->selectRaw("COUNT(orders.id) as orders_count, COUNT(DISTINCT orders.province_id) as province_count, {$known} as prepaid_known, {$value} as order_value, {$this->deliveredExpression($hasPrepaid)} as delivered_value, {$collected} as collected_value, SUM(CASE WHEN orders.status IN (2, 8, 9) THEN 1 ELSE 0 END) as shipping_orders")
            ->first();
    }

    private function regionRows(?array $shopIds, bool $hasPrepaid, Carbon $start, Carbon $end)
    {
        $value = $hasPrepaid
            ? 'SUM(CASE WHEN orders.prepaid_amount IS NULL THEN 0 ELSE COALESCE(orders.cod, 0) + orders.prepaid_amount END)'
            : 'NULL';
        $collected = $hasPrepaid
            ? 'SUM(CASE WHEN orders.status = 16 AND orders.prepaid_amount IS NOT NULL THEN COALESCE(orders.cod, 0) + orders.prepaid_amount ELSE 0 END)'
            : 'NULL';
        $known = $hasPrepaid ? 'SUM(orders.prepaid_amount IS NOT NULL)' : '0';

        return $this->waybillOrders($shopIds)
            ->whereBetween('orders.created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->leftJoin('provinces', 'provinces.id', '=', 'orders.province_id')
            ->groupBy('orders.province_id')
            ->selectRaw("orders.province_id as id, COALESCE(NULLIF(MAX(provinces.name), ''), 'Chưa rõ tỉnh') as name, COUNT(orders.id) as orders_count, {$known} as prepaid_known, {$value} as order_value, {$this->deliveredExpression($hasPrepaid)} as delivered_value, {$collected} as collected_value, SUM(CASE WHEN orders.status IN (2, 8, 9) THEN 1 ELSE 0 END) as shipping_orders")
            ->orderByDesc('delivered_value')
            ->orderByDesc('order_value')
            ->orderBy('name')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => $row->id,
                    'name' => $row->name,
                    'orders_count' => (int) $row->orders_count,
                    'shipping_orders' => (int) $row->shipping_orders,
                    'order_value' => $this->money($row->order_value),
                    'delivered_value' => $this->money($row->delivered_value),
                    'collected_value' => $this->money($row->collected_value),
                    'prepaid_coverage' => $this->coverage($row->prepaid_known, $row->orders_count),
                ];
            })
            ->values();
    }

    private function money(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function coverage(mixed $known, mixed $total): ?float
    {
        $orders = (int) $total;
        if ($orders === 0) {
            return null;
        }

        return round(((int) $known / $orders) * 100, 1);
    }

    private function changePercent(mixed $current, mixed $base): ?float
    {
        if ($current === null || $base === null || (float) $base == 0.0) {
            return null;
        }

        return round((((float) $current - (float) $base) / (float) $base) * 100, 1);
    }

    private function emptyChart(): array
    {
        return [
            'from' => null,
            'to' => null,
            'latest_order_at' => null,
            'truncated' => false,
            'prepaid_available' => Schema::hasColumn('orders', 'prepaid_amount'),
            'total_orders' => 0,
            'province_count' => 0,
            'order_value' => null,
            'delivered_value' => null,
            'collected_value' => null,
            'shipping_orders' => 0,
            'prepaid_coverage' => null,
            'delivered_vs_previous' => null,
            'delivered_vs_year' => null,
            'items' => [],
            'trend' => ['labels' => [], 'current' => [], 'delivered_count' => [], 'order_value' => [], 'collected' => []],
        ];
    }

    private function latestRankableWeek(?array $shopIds, bool $hasPrepaid): ?Carbon
    {
        if (! $hasPrepaid) {
            return null;
        }

        $provinceWeeks = $this->waybillOrders($shopIds)
            ->where('orders.status', 3)
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

    private function latestRankableMonth(?array $shopIds, bool $hasPrepaid): ?Carbon
    {
        if (! $hasPrepaid) {
            return null;
        }

        $provinceMonths = $this->waybillOrders($shopIds)
            ->where('orders.status', 3)
            ->whereNotNull('orders.prepaid_amount')
            ->selectRaw("DATE_FORMAT(orders.created_at, '%Y-%m-01') as month_start, orders.province_id")
            ->groupByRaw("DATE_FORMAT(orders.created_at, '%Y-%m-01'), orders.province_id")
            ->havingRaw('SUM(COALESCE(orders.cod, 0) + orders.prepaid_amount) > 0');

        $monthStart = DB::query()
            ->fromSub($provinceMonths, 'province_months')
            ->select('month_start')
            ->groupBy('month_start')
            ->havingRaw('COUNT(*) >= 20')
            ->orderByDesc('month_start')
            ->value('month_start');

        return $monthStart ? Carbon::parse($monthStart)->startOfDay() : null;
    }
}
