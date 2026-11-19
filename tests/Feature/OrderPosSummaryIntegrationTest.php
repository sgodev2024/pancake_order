<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\OrderController;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderService;
use App\Services\OrderSummaryService;
use App\Services\ReportDataVersion;
use App\Services\ShopAccessService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrderPosSummaryIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'pos_summary_testing',
            'database.connections.pos_summary_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('pos_summary_testing');
        DB::setDefaultConnection('pos_summary_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Cache::flush();
        Carbon::setTestNow('2026-03-01 12:00:00');
        $this->schema();
        $this->seedOrders();
        OrderSummaryService::rebuildAll();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('pos_summary_testing');
        parent::tearDown();
    }

    public function test_pos_and_synced_summary_responses_match_source_across_dates_and_shops(): void
    {
        $cases = [
            ['admin', [], 6, 2700, 360],
            ['admin', ['date_from' => '2026-02-01', 'date_to' => '2026-02-28'], 3, 1000, 350],
            ['manager-sale', ['date_from' => '2026-02-01', 'date_to' => '2026-02-28'], 2, 800, 150],
            ['admin', ['shop_id' => 12, 'date_from' => '2026-02-01', 'date_to' => '2026-02-28'], 1, 200, 200],
            ['admin', ['shop_id' => 11, 'date_from' => '2026-02-02'], 1, 300, 50],
            ['admin', ['date_to' => '2025-12-31'], 1, 700, 0],
            ['admin', ['date_from' => '2027-01-01'], 0, 0, 0],
            ['admin', ['date_from' => '2026-02-28', 'date_to' => '2026-02-01'], 0, 0, 0],
        ];
        foreach ($cases as [$role, $filters, $count, $revenue, $prepaid]) {
            $this->ready(false);
            $source = $this->overview($role, $filters);
            $this->ready(true);
            $summary = $this->overview($role, $filters);
            $this->assertSame($source, $summary, json_encode([$role, $filters]));
            $this->assertSame($count, $summary['total_items']);
            $this->assertEquals($revenue, $summary['total_revenue']);
            $this->assertEquals($prepaid, $summary['total_prepaid_amount']);
        }
        // POS totals do not rewrite COD measures used by existing trend/monthly reports.
        $this->assertEquals(250, DB::table('daily_order_summaries')->where('shop_id', 0)
            ->whereBetween('report_date', ['2026-02-01', '2026-02-28'])->sum('total_revenue'));
        $this->assertEquals(250, DB::table('daily_order_summaries')->where('shop_id', 0)
            ->whereBetween('report_date', ['2026-02-01', '2026-02-28'])->sum('pending_amount'));
    }

    public function test_pos_adjustment_is_cached_without_repeating_source_order_aggregation(): void
    {
        $filters = ['date_from' => '2026-02-01', 'date_to' => '2026-02-28'];
        DB::flushQueryLog();
        DB::enableQueryLog();
        $cold = $this->overview('admin', $filters);
        $coldQueries = DB::getQueryLog();
        DB::flushQueryLog();
        $warm = $this->overview('admin', $filters);
        $warmQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($cold, $warm);
        $sourceCold = $this->sourceQueries($coldQueries);
        $this->assertCount(1, $sourceCold);
        $this->assertStringContainsString('"orders"."origin" = ?', $sourceCold[0]['query']);
        $this->assertContains('local_pos', $sourceCold[0]['bindings']);
        $this->assertSame([], $this->sourceQueries($warmQueries));
    }

    public function test_order_values_are_opt_in_and_match_raw_with_full_pos_amounts_once(): void
    {
        foreach ([
            ['admin', [], 1050, 2710],
            ['admin', ['date_from' => '2026-02-01', 'date_to' => '2026-02-28'], 250, 1000],
            ['manager-sale', ['date_from' => '2026-02-01', 'date_to' => '2026-02-28'], 250, 800],
            ['admin', ['shop_id' => 12, 'date_from' => '2026-02-01', 'date_to' => '2026-02-28'], 0, 200],
            ['admin', ['date_from' => '2026-01-10', 'date_to' => '2026-01-10'], 100, 110],
        ] as [$role, $filters, $cod, $orderValue]) {
            $default = $this->overview($role, $filters);
            $this->assertArrayNotHasKey('total_cod', $default);
            $this->assertArrayNotHasKey('total_order_value', $default);
            $this->assertSame($default, $this->overview($role, $filters + ['include_order_values' => 0]));
            $values = $this->overview($role, $filters + ['include_order_values' => 1]);
            $this->assertSame(['total_cod', 'total_order_value'], array_values(array_diff(array_keys($values), array_keys($default))));
            $this->assertSame($cod, $values['total_cod']);
            $this->assertSame($orderValue, $values['total_order_value']);
            $withoutValues = $values;
            unset($withoutValues['total_cod'], $withoutValues['total_order_value']);
            $this->assertSame($default, $withoutValues);
            $this->ready(false);
            $this->assertSame($values, $this->overview($role, $filters + ['include_order_values' => 1]));
            $this->ready(true);
        }
    }

    public function test_opt_in_values_share_one_cached_pos_aggregate_and_refresh_after_commit(): void
    {
        $filters = ['date_from' => '2026-02-01', 'date_to' => '2026-02-28', 'include_order_values' => 1];
        DB::flushQueryLog();
        DB::enableQueryLog();
        $cold = $this->overview('admin', $filters);
        $coldQueries = DB::getQueryLog();
        DB::flushQueryLog();
        $warm = $this->overview('admin', $filters);
        $warmQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($cold, $warm);
        $this->assertCount(1, $this->sourceQueries($coldQueries));
        $this->assertSame([], $this->sourceQueries($warmQueries));
        $this->assertSame(1000, $warm['total_order_value']);
        $this->assertSame(250, $warm['total_cod']);
        $this->assertNotEquals($warm['total_order_value'], $warm['total_revenue'] + $warm['total_prepaid_amount']);

        $order = Order::findOrFail(2);
        $payload = $order->pancake_full_data;
        $payload['pos_totals']['total_amount'] = '600.00';
        $order->pancake_full_data = $payload;
        $order->save();
        $changed = $this->overview('admin', $filters);
        $this->assertSame(1100, $changed['total_order_value']);
        $this->assertSame(250, $changed['total_cod']);

        $payload['prepaid'] = '150.00';
        $order->forceFill(['prepaid_amount' => 150, 'cash' => 150, 'pancake_full_data' => $payload])->save();
        $paidChange = $this->overview('admin', $filters);
        $this->assertEquals(400, $paidChange['total_prepaid_amount']);
        $this->assertSame(1100, $paidChange['total_order_value']);
        $this->assertSame(250, $paidChange['total_cod']);
        $this->ready(false);
        $this->assertSame($paidChange, $this->overview('admin', $filters));
    }

    public function test_opt_in_values_preserve_zero_indexed_prepaid_when_column_is_missing(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('prepaid_amount'));
        OrderSummaryService::rebuildAll();
        $filters = ['include_order_values' => 1];
        $summary = $this->overview('admin', $filters);
        $this->assertEquals(0, $summary['total_prepaid_amount']);
        $this->assertSame(1050, $summary['total_cod']);
        $this->assertSame(2700, $summary['total_order_value']);
        $this->ready(false);
        $this->assertSame($summary, $this->overview('admin', $filters));
    }

    public function test_committed_pos_change_invalidates_adjustment_and_preserves_scope(): void
    {
        $filters = ['date_from' => '2026-02-01', 'date_to' => '2026-02-28'];
        $this->assertEquals(1000, $this->overview('admin', $filters)['total_revenue']);
        $this->assertEquals(800, $this->overview('manager-sale', $filters)['total_revenue']);
        $version = ReportDataVersion::key('orders');
        DB::beginTransaction();
        $order = Order::findOrFail(2);
        $payload = $order->pancake_full_data;
        $payload['pos_totals']['total_amount'] = '600.00';
        $order->pancake_full_data = $payload;
        $order->save();
        $this->assertSame($version, ReportDataVersion::key('orders'));
        DB::commit();
        $this->assertNotSame($version, ReportDataVersion::key('orders'));
        $this->assertTrue(OrderSummaryService::isReady());
        $summary = $this->overview('admin', $filters);
        $this->assertEquals(1100, $summary['total_revenue']);
        $this->assertEquals(900, $this->overview('manager-sale', $filters)['total_revenue']);
        $this->assertEquals(200, $this->overview('admin', $filters + ['shop_id' => 12])['total_revenue']);
        $this->ready(false);
        $this->assertSame($this->overview('admin', $filters), $summary);
    }

    public function test_rollback_does_not_publish_a_different_pos_adjustment(): void
    {
        $before = $this->overview('admin');
        $version = ReportDataVersion::key('orders');
        DB::beginTransaction();
        $order = Order::findOrFail(2);
        $payload = $order->pancake_full_data;
        $payload['pos_totals']['total_amount'] = '999.00';
        $order->pancake_full_data = $payload;
        $order->save();
        DB::rollBack();
        $this->assertSame($version, ReportDataVersion::key('orders'));
        $this->assertSame($before, $this->overview('admin'));
        $this->assertTrue(OrderSummaryService::isReady());
    }

    public function test_legacy_pos_reference_search_and_pos_creation_fail_cleanly_without_schema(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('origin'));
        $data = $this->overview('admin', ['search' => 'POS-2']);
        $this->assertSame(0, $data['total_items']);
        $this->assertEquals(0, $data['total_revenue']);
        $user = $this->actor('admin');
        $request = Request::create('/api/v1/orders', 'POST');
        $request->setUserResolver(fn () => $user);
        try {
            (new OrderController($this->access()))->store($request, new OrderService);
            $this->fail('POS creation should report that its schema is not ready.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
            $this->assertStringContainsString('migration POS', $error->getMessage());
        }
        $this->assertSame(6, DB::table('orders')->count());
    }

    private function overview(string $role, array $filters = []): array
    {
        $request = Request::create('/api/v1/orders', 'GET', ['view' => 'summary', 'totals_only' => 1] + $filters);
        $request->setUserResolver(fn () => $this->actor($role));
        $response = (new OrderController($this->access()))->index($request);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return $response->getData(true)['data'];
    }

    private function actor(string $role): User
    {
        return (new User(['pancake_user_id' => 'own']))->forceFill(['id' => 100, 'role_id' => 1])
            ->setRelation('role', new Role(['slug' => $role]));
    }

    private function access(): ShopAccessService
    {
        $access = Mockery::mock(ShopAccessService::class)->makePartial();
        $access->shouldReceive('ids')->andReturn(collect([11]));

        return $access;
    }

    private function ready(bool $ready): void
    {
        DB::table('order_summary_state')->where('id', 1)->update(['ready' => $ready]);
    }

    private function sourceQueries(array $queries): array
    {
        return array_values(array_filter($queries, fn ($query) => preg_match('/\bfrom\s+["`]?orders["`]?\b/i', $query['query'])));
    }

    private function seedOrders(): void
    {
        foreach ([
            [1, 11, '2026-01-10 10:00:00', null, 3, 100, 10, null],
            [2, 11, '2026-02-01 10:00:00', 'local_pos', 0, 0, 100, 500],
            [3, 11, '2026-02-02 10:00:00', 'local_pos', 0, 250, 50, 300],
            [4, 12, '2026-02-02 11:00:00', 'local_pos', 0, 0, 200, 200],
            [5, 11, '2025-12-25 10:00:00', 'local_pos', 0, 700, 0, 700],
            [6, 12, '2026-01-15 10:00:00', 'local_pos', 0, 0, 0, 900],
        ] as [$id, $shop, $date, $origin, $status, $cod, $prepaid, $total]) {
            $payload = ['items' => [], 'prepaid' => number_format($prepaid, 2, '.', '')];
            if ($total !== null) {
                $payload['origin'] = 'local_pos';
                $payload['pos_totals'] = ['total_amount' => number_format($total, 2, '.', '')];
            }
            (new Order)->forceFill([
                'id' => $id, 'shop_id' => $shop, 'created_at' => $date, 'updated_at' => $date,
                'origin' => $origin, 'status' => $status, 'cod' => $cod, 'cash' => $prepaid,
                'prepaid_amount' => $prepaid, 'total_quantity' => 1, 'pancake_full_data' => $payload,
                'user_creator_id' => 'own', 'order_number_vtp' => $origin === null ? 'waybill' : null,
            ])->save();
        }
    }

    private function schema(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->integer('shop_id');
            $table->string('origin')->nullable();
            $table->string('user_creator_id')->nullable();
            $table->string('user_care_id')->nullable();
            $table->integer('status')->nullable();
            $table->integer('received_at_shop')->default(0);
            $table->decimal('cod', 20, 2)->default(0);
            $table->decimal('cash', 20, 2)->default(0);
            $table->decimal('prepaid_amount', 20, 2)->default(0);
            $table->decimal('total_quantity', 18, 2)->default(0);
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
        (require database_path('migrations/2026_11_02_000001_create_dashboard_daily_summaries_tables.php'))->up();
        (require database_path('migrations/2026_11_02_000002_make_order_summaries_incremental.php'))->up();
    }
}
