<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderController;
use App\Models\Role;
use App\Models\User;
use App\Services\ReportDataVersion;
use App\Services\ShopAccessService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class OrderDashboardSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'order_dashboard_testing',
            'database.connections.order_dashboard_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('order_dashboard_testing');
        DB::setDefaultConnection('order_dashboard_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('YEAR', fn ($date) => (int) substr($date, 0, 4), 1);
        $pdo->sqliteCreateFunction('MONTH', fn ($date) => (int) substr($date, 5, 2), 1);
        Carbon::setTestNow('2026-03-01 12:00:00');
        Cache::flush();
        $this->createSchema();
        $this->seedOrders();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_all_time_and_one_sided_totals_match_source_without_reading_orders(): void
    {
        foreach ([[], ['shop_id' => 11], ['date_from' => '2026-02-01'], ['date_to' => '2026-01-31'], ['date_from' => '2026-01-01', 'date_to' => '2026-02-28'], ['date_from' => '2026-02-28', 'date_to' => '2026-01-01'], ['date_from' => '2027-01-01', 'date_to' => '2027-01-31'], ['month_mode' => 'latest']] as $filters) {
            $this->setReady(false);
            $source = $this->overview('admin', $filters);
            $this->setReady(true);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $summary = $this->overview('admin', $filters);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertEquals($source, $summary, json_encode($filters));
            $this->assertFalse($this->queriesReadOrders($queries));
        }
    }

    public function test_manager_trend_uses_only_authorized_shops(): void
    {
        $filters = ['date_from' => '2026-01-01', 'date_to' => '2026-02-28'];
        $this->setReady(false);
        $source = $this->overview('manager-sale', $filters);
        $this->setReady(true);
        $summary = $this->overview('manager-sale', $filters);
        $this->assertEquals($source, $summary);
        $this->assertSame(2, $summary['data']['total_items']);
        $this->assertEquals(300, $summary['data']['total_revenue']);
        $this->assertEquals(100, array_sum($summary['data']['trend']['current']));
    }

    public function test_individual_staff_and_zero_valued_filters_keep_exact_source_totals(): void
    {
        $this->setReady(true);
        foreach ([
            ['staff-sale', [], 1, 100],
            ['admin', ['status' => '0'], 1, 200],
            ['admin', ['received_at_shop' => '0'], 2, 300],
            ['admin', ['cod' => '100'], 1, 100],
            ['admin', ['cod' => '0'], 0, 0],
            ['admin', ['user_id' => ''], 0, 0],
            ['admin', ['shop_id' => 0], 0, 0],
        ] as [$role, $filters, $count, $revenue]) {
            $data = $this->overview($role, $filters)['data'];
            $this->assertSame($count, $data['total_items']);
            $this->assertEquals($revenue, $data['total_revenue']);
        }
    }

    public function test_unverified_or_pending_summaries_fall_back_to_source(): void
    {
        $this->setReady(false);
        DB::table('daily_order_summaries')->update(['total_items' => 999]);
        $this->assertSame(3, $this->overview('admin')['data']['total_items']);
        $this->setReady(true);
        DB::table('order_summary_pending')->insert(['order_id' => 1, 'updated_at' => now()]);
        $this->assertSame(3, $this->overview('admin')['data']['total_items']);
    }

    public function test_non_standard_date_inputs_keep_source_predicate_behavior(): void
    {
        foreach ([['date_from' => '2026-01-01T10:00:00'], ['date_from' => ''], ['date_to' => '2026-02-30']] as $filters) {
            $this->setReady(false);
            $source = $this->overview('admin', $filters);
            $this->setReady(true);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $result = $this->overview('admin', $filters);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertEquals($source, $result);
            $this->assertTrue($this->queriesReadOrders($queries));
        }
    }

    public function test_monthly_counts_match_source_and_keep_exact_status_fallback(): void
    {
        $this->setReady(false);
        $source = $this->invoke('monthlyCounts', 'admin', ['year' => 2026]);
        $this->setReady(true);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $summary = $this->invoke('monthlyCounts', 'admin', ['year' => 2026]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertEquals($source, $summary);
        $this->assertFalse($this->queriesReadOrders($queries));
        $this->assertCount(12, $summary['data']['months']);
        $status = $this->invoke('monthlyCounts', 'admin', ['year' => 2026, 'status' => 0]);
        $this->assertSame(1, $status['data']['summary']['total_orders']);
        $this->assertEquals(200, $status['data']['summary']['total_cod']);
        $zeroShop = $this->invoke('monthlyCounts', 'admin', ['year' => 2026, 'shop_id' => 0]);
        $this->assertSame(0, $zeroShop['data']['summary']['total_orders']);
        $this->assertSame([], $zeroShop['data']['available_years']);
    }

    public function test_sales_chart_cache_hit_does_not_look_up_latest_source_order(): void
    {
        $this->setReady(true);
        $first = $this->invoke('salesChartData', 'admin');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $second = $this->invoke('salesChartData', 'admin');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertEquals($first, $second);
        $this->assertFalse($this->queriesReadOrders($queries));
        $sql = strtolower(implode(' ', array_column($queries, 'query')));
        $this->assertStringNotContainsString('max(', $sql);
        $this->assertStringNotContainsString('daily_product_sales_summaries', $sql);
    }

    public function test_sales_chart_cache_is_invalidated_by_order_generation(): void
    {
        $this->setReady(true);
        $first = $this->invoke('salesChartData', 'admin');
        $this->assertEquals(200, $first['data']['total_revenue']);
        DB::table('daily_product_sales_summaries')->update(['revenue' => 350]);
        ReportDataVersion::bump('orders');
        $updated = $this->invoke('salesChartData', 'admin');
        $this->assertEquals(350, $updated['data']['total_revenue']);
    }

    private function overview(string $role, array $filters = []): array
    {
        return $this->invoke('index', $role, ['view' => 'summary', 'totals_only' => 1] + $filters);
    }

    private function invoke(string $method, string $role, array $filters = []): array
    {
        $user = (new User(['pancake_user_id' => 'own']))->setRelation('role', new Role(['slug' => $role]));
        $access = Mockery::mock(ShopAccessService::class)->makePartial();
        $access->shouldReceive('ids')->andReturn(collect([11]));
        $request = Request::create('/api/v1/orders', 'GET', $filters);
        $request->setUserResolver(fn () => $user);
        $controller = new class($access) extends OrderController
        {
            // SQLite cannot run MySQL JSON_TABLE. Supply its result rows while
            // exercising the real request/cache/date/response processing.
            protected function salesChartRows(string $sql, array $bindings): array
            {
                return DB::table('daily_product_sales_summaries')->get()->all();
            }
        };
        $response = $controller->{$method}($request);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return $response->getData(true);
    }

    private function setReady(bool $ready): void
    {
        DB::table('order_summary_state')->updateOrInsert(['id' => 1], ['schema_version' => 2, 'ready' => $ready]);
    }

    private function queriesReadOrders(array $queries): bool
    {
        foreach ($queries as $query) {
            if (preg_match('/\bfrom\s+["`]?orders["`]?\b/i', $query['query'])) {
                return true;
            }
        }

        return false;
    }

    private function createSchema(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->integer('shop_id');
            $table->string('user_creator_id')->nullable();
            $table->string('user_care_id')->nullable();
            $table->integer('status');
            $table->integer('received_at_shop')->default(0);
            $table->decimal('cod', 20, 2);
            $table->decimal('prepaid_amount', 20, 2)->default(0);
            $table->json('pancake_full_data')->nullable();
            $table->string('order_number_vtp')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('pancake_order_id')->nullable();
            $table->string('pancake_order_page_id')->nullable();
            $table->string('pancake_order_source_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('daily_order_summaries', function (Blueprint $table) {
            $table->date('report_date');
            $table->integer('shop_id');
            foreach (['total_items', 'total_revenue', 'total_prepaid_amount', 'shipping_count', 'shipping_amount', 'success_count', 'success_amount', 'cancel_count', 'cancel_amount', 'return_count', 'return_amount', 'pending_count', 'pending_amount'] as $column) {
                $table->decimal($column, 20, 2)->default(0);
            }
        });
        Schema::create('daily_product_sales_summaries', function (Blueprint $table) {
            $table->date('report_date');
            $table->integer('shop_id');
            $table->integer('status');
            $table->string('product_name');
            $table->decimal('quantity');
            $table->decimal('revenue');
            $table->integer('orders_count');
        });
        Schema::create('order_summary_state', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('schema_version');
            $table->boolean('ready');
            $table->timestamps();
        });
        Schema::create('order_summary_pending', function (Blueprint $table) {
            $table->integer('order_id')->primary();
            $table->timestamps();
        });
        Schema::create('order_summary_contributions', function (Blueprint $table) {
            $table->integer('order_id')->primary();
            $table->integer('shop_id');
            $table->date('report_date');
            $table->timestamp('source_created_at');
            $table->boolean('delivered_vtp')->default(false);
        });
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
        });
    }

    private function seedOrders(): void
    {
        $rows = [
            ['id' => 1, 'shop_id' => 11, 'created_at' => '2026-01-01 10:00:00', 'status' => 3, 'cod' => 100, 'user_creator_id' => 'own', 'received_at_shop' => 0],
            ['id' => 2, 'shop_id' => 11, 'created_at' => '2026-02-01 10:00:00', 'status' => 0, 'cod' => 200, 'user_creator_id' => 'other', 'received_at_shop' => 0],
            ['id' => 3, 'shop_id' => 12, 'created_at' => '2026-02-01 11:00:00', 'status' => 16, 'cod' => 900, 'user_creator_id' => 'other', 'received_at_shop' => 1],
        ];
        DB::table('orders')->insert($rows);
        foreach ($rows as $row) {
            DB::table('order_summary_contributions')->insert([
                'order_id' => $row['id'], 'shop_id' => $row['shop_id'], 'report_date' => substr($row['created_at'], 0, 10), 'source_created_at' => $row['created_at'],
            ]);
        }
        foreach ([
            ['2026-01-01', 11, 1, 100, 1, 100, 0, 0], ['2026-01-01', 0, 1, 100, 1, 100, 0, 0],
            ['2026-02-01', 11, 1, 200, 0, 0, 1, 200], ['2026-02-01', 12, 1, 900, 1, 900, 0, 0], ['2026-02-01', 0, 2, 1100, 1, 900, 1, 200],
        ] as [$date, $shop, $count, $revenue, $success, $successAmount, $pending, $pendingAmount]) {
            DB::table('daily_order_summaries')->insert([
                'report_date' => $date, 'shop_id' => $shop, 'total_items' => $count, 'total_revenue' => $revenue,
                'success_count' => $success, 'success_amount' => $successAmount, 'pending_count' => $pending, 'pending_amount' => $pendingAmount,
            ]);
        }
        DB::table('shops')->insert([['id' => 11, 'name' => 'Shop 11'], ['id' => 12, 'name' => 'Shop 12']]);
        DB::table('daily_product_sales_summaries')->insert([
            'report_date' => '2026-02-01', 'shop_id' => 11, 'status' => 0, 'product_name' => 'Product', 'quantity' => 2, 'revenue' => 200, 'orders_count' => 1,
        ]);
    }
}
