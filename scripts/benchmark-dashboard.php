<?php

use App\Http\Controllers\Api\CustomerCareController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductReportController;
use App\Models\User;
use App\Services\OrderSummaryService;
use Carbon\Carbon;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// Local controller/SQL benchmark. It never creates tokens or changes source data.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('local') || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('This benchmark is restricted to the configured local database.');
}
if (! OrderSummaryService::isReady()) {
    throw new RuntimeException('Initialize and verify summaries before measuring.');
}
$actor = User::with('role.permissions')->whereHas('role', fn ($q) => $q->where('slug', 'admin'))->firstOrFail();
Auth::shouldUse('api');
Auth::guard('api')->setUser($actor);
$latest = Carbon::parse(OrderSummaryService::latestOrderAt(null, true));
$year = $latest->year;
$month = ['date_from' => $latest->copy()->startOfMonth()->toDateString(), 'date_to' => $latest->copy()->endOfMonth()->toDateString()];
$operations = [
    'product_sales_month' => [ProductReportController::class, 'index', $month],
    'product_period_year' => [ProductReportController::class, 'periodReport', ['date_from' => "{$year}-01-01", 'date_to' => "{$year}-12-31", 'view_mode' => 'month', 'compare' => 'none']],
    'order_totals_all_time' => [OrderController::class, 'index', ['view' => 'summary', 'totals_only' => '1']],
    'care_overview' => [CustomerCareController::class, 'overview', []],
    'monthly_order_counts' => [OrderController::class, 'monthlyCounts', ['year' => $year]],
];
$queries = [];
$events = [];
DB::listen(function ($query) use (&$queries) {
    $queries[] = ['sql' => $query->sql, 'ms' => $query->time];
});
foreach ([CacheHit::class => 'hit', CacheMissed::class => 'miss'] as $eventClass => $kind) {
    Event::listen($eventClass, function ($event) use (&$events, $kind) {
        if (preg_match('/^(prod_|care-overview:|order_sales_chart_)/', $event->key)) {
            $events[] = $kind;
        }
    });
}
$report = ['measured_at' => now()->toIso8601String(), 'scope' => 'local admin controllers; excludes HTTP/auth/bootstrap/browser rendering', 'cache_store' => config('cache.default'), 'samples' => []];
foreach ($operations as $label => [$controllerClass, $method, $params]) {
    for ($iteration = 1; $iteration <= 3; $iteration++) {
        $queries = $events = [];
        $request = Request::create('/api/v1/benchmark', 'GET', $params);
        $request->setUserResolver(fn () => $actor);
        $start = hrtime(true);
        $controller = app($controllerClass);
        $response = $method === 'overview' ? $controller->$method() : $controller->$method($request);
        $elapsed = round((hrtime(true) - $start) / 1e6, 2);
        $body = $response->getData(true);
        if ($response->getStatusCode() !== 200 || ! ($body['success'] ?? false)) {
            throw new RuntimeException($label.' failed: '.($body['message'] ?? $response->getStatusCode()));
        }
        $source = count(array_filter($queries, fn ($q) => preg_match('/\b(?:from|join)\s+[`"]?orders\b/i', $q['sql'])));
        $cares = count(array_filter($queries, fn ($q) => preg_match('/\b(?:from|join)\s+[`"]?customer_cares\b/i', $q['sql'])));
        $sample = ['operation' => $label, 'iteration' => $iteration, 'ms' => $elapsed, 'sql_queries' => count($queries),
            'sql_ms' => round(array_sum(array_column($queries, 'ms')), 2), 'source_order_queries' => $source,
            'care_queries' => $cares, 'response_cache' => $events ? end($events) : 'not_used'];
        $report['samples'][] = $sample;
        echo json_encode($sample, JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
}
if (in_array('--write', $argv, true)) {
    file_put_contents(__DIR__.'/../docs/dashboard-benchmark-local.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);
}
