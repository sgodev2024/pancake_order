<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ProductReportController;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderSummaryService;
use App\Services\ShopAccessService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductReportPerformanceTest extends TestCase
{
    private Order $mainOrder;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'product_report_testing',
            'database.connections.product_report_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('product_report_testing');
        DB::setDefaultConnection('product_report_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Carbon::setTestNow('2026-11-02 15:00:00');
        Cache::flush();
        $this->createSchema();
        $this->seedOrders();
        OrderSummaryService::rebuildAll();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('product_report_testing');
        parent::tearDown();
    }

    public function test_sales_use_delivered_orders_once_and_keep_distinct_products_with_equal_names(): void
    {
        $report = $this->invoke('index', ['date_from' => '2026-10-15', 'date_to' => '2026-10-15']);
        $this->assertSame(['from', 'to', 'latest_order_at', 'truncated', 'total_quantity', 'total_revenue', 'total_orders', 'quantity_vs_previous', 'quantity_vs_year', 'items'], array_keys($report));
        $this->assertSame('2026-10-15', $report['from']);
        $this->assertSame('2026-10-16 10:00:00', $report['latest_order_at']);
        $this->assertFalse($report['truncated']);
        $this->assertSame(10, $report['total_quantity']);
        $this->assertSame(140, $report['total_revenue']);
        $this->assertSame(4, $report['total_orders']);
        $this->assertSame(150, $report['quantity_vs_year']);
        $this->assertCount(3, $report['items']);
        $this->assertSame(['name', 'quantity', 'revenue', 'orders_count'], array_keys($report['items'][0]));
        $quantities = array_column($report['items'], 'quantity');
        sort($quantities);
        $this->assertSame([2, 4, 4], $quantities);
        $this->assertSame(['Same name'], array_values(array_unique(array_column($report['items'], 'name'))));
        $counts = array_column($report['items'], 'orders_count');
        sort($counts);
        $this->assertSame([1, 1, 2], $counts);
    }

    public function test_warm_product_endpoints_do_not_read_source_orders_or_max_timestamp(): void
    {
        $calls = [
            ['index', ['date_from' => '2026-10-15', 'date_to' => '2026-10-15']],
            ['periodReport', ['date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'view_mode' => 'month', 'compare' => 'mom']],
            ['monthlyQuantities', ['year' => 2027]],
        ];
        foreach ($calls as [$method, $filters]) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $cold = $this->invoke($method, $filters);
                $coldQueries = DB::getQueryLog();
                DB::flushQueryLog();
                $warm = $this->invoke($method, $filters);
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }
            $this->assertSame($cold, $warm);
            $this->assertNoSourceReads($coldQueries);
            $this->assertNoSourceReads($queries);
        }
    }

    public function test_latest_month_and_reversed_date_filters_keep_their_existing_ranges(): void
    {
        $month = $this->invoke('index', ['month_mode' => 'latest']);
        $this->assertSame('2026-10-01', $month['from']);
        $this->assertSame('2026-10-31', $month['to']);
        $this->assertSame(180, $month['total_revenue']);
        $this->assertSame(13, $month['total_quantity']);
        $this->assertSame(6, $month['total_orders']);
        $reversed = $this->invoke('index', ['date_from' => '2026-10-16', 'date_to' => '2026-10-14']);
        $this->assertSame('2026-10-14', $reversed['from']);
        $this->assertSame('2026-10-16', $reversed['to']);
        $this->assertSame(180, $reversed['total_revenue']);
        $this->assertSame(13, $reversed['total_quantity']);
        $this->assertSame(6, $reversed['total_orders']);
    }

    public function test_period_modes_preserve_totals_comparisons_and_response_shape(): void
    {
        $cases = [
            ['day', '2026-10-15', '2026-10-15', 140, 10, 4, 10, 40],
            ['month', '2026-10-01', '2026-10-31', 180, 13, 6, 30, 40],
            ['quarter', '2026-10-01', '2026-12-31', 180, 13, 6, 50, 50],
            ['year', '2026-01-01', '2026-12-31', 230, 18, 8, 50, 50],
        ];
        foreach ($cases as [$mode, $from, $to, $revenue, $quantity, $orders, $previous, $yearPrevious]) {
            foreach (['none', 'mom', 'yoy'] as $compare) {
                $report = $this->invoke('periodReport', ['date_from' => $from, 'date_to' => $to, 'view_mode' => $mode, 'compare' => $compare]);
                $this->assertSame(['summary', 'chart', 'products', 'from', 'to', 'view_mode', 'compare'], array_keys($report));
                $this->assertSame($mode, $report['view_mode']);
                $this->assertSame($compare, $report['compare']);
                $this->assertSame($from, $report['from']);
                $this->assertSame($to, $report['to']);
                $this->assertCount(1, $report['chart']);
                $this->assertSame(['label', 'period_start', 'period_end', 'revenue', 'orders_count', 'quantity', 'previous_revenue', 'change_percent'], array_keys($report['chart'][0]));
                $this->assertSame($revenue, $report['summary']['total_revenue']);
                $this->assertSame($quantity, $report['summary']['total_quantity']);
                $this->assertSame($orders, $report['summary']['total_orders']);
                $prior = $compare === 'none' ? null : ($compare === 'mom' ? $previous : $yearPrevious);
                $this->assertSame($prior, $report['summary']['previous_revenue']);
                $this->assertSame($prior === null ? null : $revenue - $prior, $report['summary']['change_amount']);
                $this->assertEquals($prior === null ? null : round(($revenue - $prior) / $prior * 100, 1), $report['summary']['change_percent']);
                $this->assertSame($report['summary']['total_revenue'], array_sum(array_column($report['chart'], 'revenue')));
                $this->assertSame($report['summary']['total_orders'], array_sum(array_column($report['chart'], 'orders_count')));
                $this->assertGreaterThanOrEqual(3, count($report['products']));
                $this->assertSame(['rank', 'name', 'quantity', 'revenue', 'previous_revenue', 'change_amount', 'change_percent', 'contribution_percent'], array_keys($report['products'][0]));
            }
        }
    }

    public function test_summary_and_exact_source_fallback_return_identical_json(): void
    {
        foreach ([
            ['index', ['date_from' => '2026-10-15', 'date_to' => '2026-10-15']],
            ['periodReport', ['date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'view_mode' => 'month', 'compare' => 'yoy']],
            ['monthlyQuantities', ['year' => 2026]],
        ] as [$method, $filters]) {
            DB::table('order_summary_state')->where('id', 1)->update(['ready' => true]);
            Cache::flush();
            $summary = $this->invoke($method, $filters);
            DB::table('order_summary_state')->where('id', 1)->update(['ready' => false]);
            Cache::flush();
            $source = $this->invoke($method, $filters);
            $this->assertSame($summary, $source);
        }
    }

    public function test_monthly_quantities_use_order_quantity_and_fallback_to_latest_delivered_year(): void
    {
        $monthly = $this->invoke('monthlyQuantities', ['year' => 2027]);
        $this->assertSame(2026, $monthly['year']);
        $this->assertCount(12, $monthly['months']);
        $this->assertSame(25, $monthly['total_quantity']);
        $this->assertSame(2, $monthly['months'][6]['quantity']);
        $this->assertSame(3, $monthly['months'][8]['quantity']);
        $this->assertSame(20, $monthly['months'][9]['quantity']);
        $this->assertSame('future', $monthly['months'][10]['trend']);
        $this->assertSame('future', $monthly['months'][11]['trend']);
        $this->assertSame(2025, $this->invoke('monthlyQuantities', ['year' => 2025])['year']);
        $this->assertSame(5, $this->invoke('monthlyQuantities', ['year' => 2025])['total_quantity']);
    }

    public function test_source_commit_invalidates_warm_product_and_monthly_responses(): void
    {
        $filters = ['date_from' => '2026-10-15', 'date_to' => '2026-10-15'];
        $this->assertSame(140, $this->invoke('index', $filters)['total_revenue']);
        $periodFilters = $filters + ['view_mode' => 'day', 'compare' => 'none'];
        $this->assertSame(140, $this->invoke('periodReport', $periodFilters)['summary']['total_revenue']);
        $this->assertSame(25, $this->invoke('monthlyQuantities', ['year' => 2026])['total_quantity']);
        DB::transaction(function () {
            $this->mainOrder->update(['total_quantity' => 10, 'pancake_full_data' => ['items' => [$this->item('p1', 10, 10)]]]);
        });
        $updated = $this->invoke('index', $filters);
        $this->assertSame(170, $updated['total_revenue']);
        $this->assertSame(15, $updated['total_quantity']);
        $this->assertSame(4, $updated['total_orders']);
        $this->assertSame(170, $this->invoke('periodReport', $periodFilters)['summary']['total_revenue']);
        $this->assertSame(30, $this->invoke('monthlyQuantities', ['year' => 2026])['total_quantity']);
    }

    public function test_scope_empty_results_and_director_global_access_are_preserved(): void
    {
        $filters = ['date_from' => '2026-10-15', 'date_to' => '2026-10-15'];
        $staff = $this->invoke('index', $filters, 'staff-sale', 100);
        $this->assertSame(3, $staff['total_orders']);
        $this->assertSame(80, $staff['total_revenue']);
        $this->assertSame(6, $staff['total_quantity']);
        $this->assertSame($this->invoke('index', $filters), $this->invoke('index', $filters, 'director', 200));
        $empty = $this->invoke('index', $filters, 'staff-sale', 101);
        $this->assertSame(0, $empty['total_orders']);
        $this->assertSame([], $empty['items']);
        $noOrders = $this->invoke('index', ['date_from' => '2023-01-01', 'date_to' => '2023-01-02']);
        $this->assertSame(0, $noOrders['total_revenue']);
        $this->assertSame([], $noOrders['items']);
        $period = $this->invoke('periodReport', $filters + ['view_mode' => 'day', 'compare' => 'none'], 'staff-sale', 101);
        $this->assertNull($period['summary']);
        $this->assertSame([], $period['chart']);
        $monthly = $this->invoke('monthlyQuantities', ['year' => 2026], 'staff-sale', 101);
        $this->assertSame(0, $monthly['total_quantity']);
        $this->assertCount(12, $monthly['months']);
    }

    public function test_explicit_unauthorized_shop_is_rejected(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->invoke('index', ['shop_id' => 22, 'date_from' => '2026-10-15', 'date_to' => '2026-10-15'], 'staff-sale', 100);
    }

    private function invoke(string $method, array $filters, string $role = 'admin', int $id = 1): array
    {
        $actor = new User;
        $actor->forceFill(['id' => $id, 'role_id' => $role === 'admin' ? 1 : ($role === 'director' ? 2 : 3)]);
        $actor->setRelation('role', new Role(['slug' => $role]));
        $request = Request::create('/api/v1/test-products', 'GET', $filters);
        $request->setUserResolver(fn () => $actor);
        $response = (new ProductReportController(new ShopAccessService))->{$method}($request);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $json = $response->getData(true);
        $this->assertTrue($json['success']);

        return $json['data'];
    }

    private function assertNoSourceReads(array $queries): void
    {
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:from|join)\s+["\x60]?orders["\x60]?\b/i', $query['query']);
        }
    }

    private function seedOrders(): void
    {
        DB::table('shops')->insert([['id' => 11], ['id' => 22]]);
        DB::table('shop_users')->insert(['shop_id' => 11, 'user_id' => 100, 'is_manager' => false]);
        $this->order('2025-10-15 10:00:00', 11, [$this->item('p1', 4, 10)], 4);
        $this->order('2025-11-01 10:00:00', 11, [$this->item('p1', 1, 10)], 1);
        $this->order('2026-07-10 10:00:00', 11, [$this->item('p1', 2, 10)], 2);
        $this->order('2026-09-15 10:00:00', 11, [$this->item('p1', 3, 10)], 3);
        $this->order('2026-10-14 10:00:00', 11, [$this->item('p1', 1, 10)], 1);
        $this->mainOrder = $this->order('2026-10-15 10:00:00', 11, [$this->item('p1', 1, 10), $this->item('p1', 2, 10), $this->item('p2', 2, 20)], 5);
        $this->order('2026-10-15 11:00:00', 11, [$this->item('p1', 1, 10)], 1);
        $this->order('2026-10-15 12:00:00', 22, [$this->item('p3', 4, 15)], 4);
        $this->order('2026-10-15 13:00:00', 11, [], 7);
        $this->order('2026-10-15 14:00:00', 11, [$this->item('p1', 99, 10)], 99, ['status' => 16]);
        $this->order('2026-10-15 15:00:00', 11, [$this->item('p1', 77, 10)], 77, ['order_number_vtp' => null]);
        $this->order('2026-10-15 16:00:00', 11, [$this->item('p1', 88, 10)], 88, ['deleted_at' => '2026-10-16 00:00:00']);
        $this->order('2026-10-16 10:00:00', 22, [$this->item('p3', 2, 15)], 2);
    }

    private function item(string $id, int $quantity, int $price): array
    {
        return ['product_id' => $id, 'variation_id' => 'v-'.$id, 'quantity' => $quantity,
            'variation_info' => ['name' => 'Same name', 'retail_price' => $price]];
    }

    private function order(string $at, int $shop, array $items, int $quantity, array $extra = []): Order
    {
        $order = new Order;
        $order->forceFill(array_replace([
            'shop_id' => $shop, 'status' => 3, 'order_number_vtp' => 'VTP-1', 'cod' => 100,
            'prepaid_amount' => 0, 'total_quantity' => $quantity, 'pancake_full_data' => ['items' => $items],
            'created_at' => $at, 'updated_at' => $at,
        ], $extra))->save();

        return $order;
    }

    private function createSchema(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->integer('status')->nullable();
            $table->string('order_number_vtp')->nullable();
            $table->decimal('cod', 20, 2)->default(0);
            $table->decimal('prepaid_amount', 20, 2)->default(0);
            $table->decimal('total_quantity', 18, 2)->default(0);
            $table->json('pancake_full_data')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->softDeletes();
        });
        Schema::create('shop_users', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_manager');
        });
        (require database_path('migrations/2026_11_02_000001_create_dashboard_daily_summaries_tables.php'))->up();
        (require database_path('migrations/2026_11_02_000002_make_order_summaries_incremental.php'))->up();
    }
}
