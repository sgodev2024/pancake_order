<?php

namespace Tests\Feature;

use App\Services\OrderSummaryService;
use App\Services\ProductSalesQuery;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductSalesQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'product_sales_query_testing',
            'database.connections.product_sales_query_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('product_sales_query_testing');
        DB::setDefaultConnection('product_sales_query_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->integer('shop_id');
            $table->integer('status');
            $table->string('order_number_vtp')->nullable();
            $table->decimal('cod', 20, 2)->default(0);
            $table->decimal('prepaid_amount', 20, 2)->default(0);
            $table->integer('total_quantity')->default(0);
            $table->json('pancake_full_data')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        // These migration objects run exclusively on this asserted in-memory
        // connection, never through the application's configured MySQL store.
        (require database_path('migrations/2026_11_02_000001_create_dashboard_daily_summaries_tables.php'))->up();
        (require database_path('migrations/2026_11_02_000002_make_order_summaries_incremental.php'))->up();
        $this->seedOrders();
    }

    public function test_delivered_totals_use_order_quantity_and_identity_rows_count_each_order_once(): void
    {
        $start = Carbon::parse('2026-01-01')->startOfDay();
        $end = Carbon::parse('2026-01-03')->endOfDay();
        $raw = ProductSalesQuery::rows([11], $start, $end);
        $this->assertCount(2, $raw);
        $this->assertSame(['Same name', 'Same name'], array_column($raw, 'name'));
        $this->assertEquals([8, 3], array_column($raw, 'quantity'));
        $this->assertEquals([84.5, 70], array_column($raw, 'revenue'));
        $this->assertSame([4, 2], array_column($raw, 'orders_count'));
        $this->assertNotSame($raw[0]->product_key, $raw[1]->product_key);
        $this->assertSame(6, ProductSalesQuery::deliveredValue([11], $start, $end));
        $this->assertEquals(81, ProductSalesQuery::deliveredValue([11], $start, $end, 'quantity'));

        OrderSummaryService::rebuildAll();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $summary = ProductSalesQuery::rows([11], $start, $end);
        $count = ProductSalesQuery::deliveredValue([11], $start, $end);
        $quantity = ProductSalesQuery::deliveredValue([11], $start, $end, 'quantity');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertEquals($raw, $summary);
        $this->assertSame(6, $count);
        $this->assertEquals(81, $quantity);
        $this->assertCount(0, $this->sourceQueries($queries));
    }

    public function test_partial_day_edges_match_source_without_including_outside_orders(): void
    {
        $start = Carbon::parse('2026-01-01 12:00:00');
        $end = Carbon::parse('2026-01-03 12:00:00');
        $raw = ProductSalesQuery::rows([11], $start, $end, 'day');
        $rawCounts = ProductSalesQuery::orderCountsByPeriod([11], $start, $end, 'day');
        $this->assertEquals(54.5, array_sum(array_column($raw, 'revenue')));
        $this->assertEquals(5, array_sum(array_column($raw, 'quantity')));
        $this->assertSame(['2026-01-01' => 1, '2026-01-02' => 2, '2026-01-03' => 1], $rawCounts);

        OrderSummaryService::rebuildAll();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $summary = ProductSalesQuery::rows([11], $start, $end, 'day');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertEquals($raw, $summary);
        $this->assertEquals($rawCounts, ProductSalesQuery::orderCountsByPeriod([11], $start, $end, 'day'));
        $this->assertSame(4, ProductSalesQuery::deliveredValue([11], $start, $end));
        $this->assertEquals(22, ProductSalesQuery::deliveredValue([11], $start, $end, 'quantity'));
        $sourceQueries = $this->sourceQueries($queries);
        $this->assertCount(2, $sourceQueries);
        $sourceBindings = array_merge(...array_column($sourceQueries, 'bindings'));
        foreach (['2026-01-01 12:00:00', '2026-01-01 23:59:59', '2026-01-03 00:00:00', '2026-01-03 12:00:00'] as $bound) {
            $this->assertContains($bound, $sourceBindings);
        }

        $singleStart = Carbon::parse('2026-01-01 10:00:00');
        $singleEnd = Carbon::parse('2026-01-01 17:00:00');
        $single = ProductSalesQuery::rows([11], $singleStart, $singleEnd);
        $this->assertCount(1, $single);
        $this->assertEquals(2, $single[0]->quantity);
        $this->assertEquals(14.5, $single[0]->revenue);
        $this->assertSame(1, ProductSalesQuery::deliveredValue([11], $singleStart, $singleEnd));
    }

    public function test_global_scope_does_not_double_count_global_and_shop_daily_rows(): void
    {
        $start = Carbon::parse('2026-01-01')->startOfDay();
        $end = Carbon::parse('2026-01-03')->endOfDay();
        $raw = ProductSalesQuery::rows(null, $start, $end);
        OrderSummaryService::rebuildAll();
        $this->assertEquals($raw, ProductSalesQuery::rows(null, $start, $end));
        $this->assertCount(3, $raw);
        $this->assertSame(7, ProductSalesQuery::deliveredValue(null, $start, $end));
        $this->assertEquals(180, ProductSalesQuery::deliveredValue(null, $start, $end, 'quantity'));
        $this->assertSame([], ProductSalesQuery::rows([], $start, $end));
        $this->assertSame(0, ProductSalesQuery::deliveredValue([], $start, $end));
        $this->assertSame([], ProductSalesQuery::rows([0], $start, $end));
        $this->assertSame(0, ProductSalesQuery::deliveredValue([0], $start, $end));
        $this->assertEquals(0, ProductSalesQuery::deliveredValue([0], $start, $end, 'quantity'));
        $this->assertSame([], ProductSalesQuery::orderCountsByPeriod([0], $start, $end, 'day'));
        $this->assertSame([], ProductSalesQuery::monthlyQuantities([0], 2026));
        $this->assertFalse(ProductSalesQuery::hasDeliveredInYear([0], 2026));
        $this->assertSame(6, ProductSalesQuery::deliveredValue([0, 11], $start, $end));
    }

    public function test_name_labels_match_source_for_a_renamed_identity_on_the_same_day(): void
    {
        $row = DB::table('orders')->where('id', 1)->first();
        $payload = json_decode($row->pancake_full_data, true);
        $payload['items'][0]['variation_info']['name'] = 'ZZ previous label';
        DB::table('orders')->where('id', 1)->update(['pancake_full_data' => json_encode($payload)]);
        $start = Carbon::parse('2026-01-01')->startOfDay();
        $end = $start->copy()->endOfDay();
        $raw = ProductSalesQuery::rows([11], $start, $end);
        OrderSummaryService::rebuildAll();
        $this->assertEquals($raw, ProductSalesQuery::rows([11], $start, $end));

        Order::findOrFail(1)->delete();
        $this->assertTrue(OrderSummaryService::isReady());
        $afterDelete = ProductSalesQuery::rows([11], $start, $end);
        $this->assertSame('Same name', $afterDelete[0]->name);
        DB::table('order_summary_state')->where('id', 1)->update(['ready' => false]);
        $this->assertEquals(ProductSalesQuery::rows([11], $start, $end), $afterDelete);

        OrderSummaryService::rebuildAll();
        $order = Order::findOrFail(2);
        $payload = $order->pancake_full_data;
        foreach ($payload['items'] as &$item) {
            $item['variation_info']['name'] = 'Updated label';
        }
        unset($item);
        $order->updateQuietly(['pancake_full_data' => $payload]);
        $this->assertTrue(OrderSummaryService::isReady());
        $afterUpdate = ProductSalesQuery::rows([11], $start, $end);
        $this->assertSame('Updated label', $afterUpdate[0]->name);
        DB::table('order_summary_state')->where('id', 1)->update(['ready' => false]);
        $this->assertEquals(ProductSalesQuery::rows([11], $start, $end), $afterUpdate);
    }

    public function test_month_quarter_year_and_missing_identity_keep_matching_results(): void
    {
        $start = Carbon::parse('2025-12-01')->startOfDay();
        $end = Carbon::parse('2026-03-31')->endOfDay();
        $raw = [];
        foreach (['day', 'month', 'quarter', 'year'] as $mode) {
            $raw[$mode] = [ProductSalesQuery::rows([11], $start, $end, $mode), ProductSalesQuery::orderCountsByPeriod([11], $start, $end, $mode)];
        }
        $months = ProductSalesQuery::monthlyQuantities([11], 2026);
        $this->assertEquals([(object) ['month' => 1, 'total_qty' => 87], (object) ['month' => 2, 'total_qty' => 2]], $months);
        OrderSummaryService::rebuildAll();
        foreach ($raw as $mode => [$rows, $counts]) {
            $this->assertEquals($rows, ProductSalesQuery::rows([11], $start, $end, $mode));
            $this->assertEquals($counts, ProductSalesQuery::orderCountsByPeriod([11], $start, $end, $mode));
        }
        $this->assertEquals($months, ProductSalesQuery::monthlyQuantities([11], 2026));
        $this->assertTrue(ProductSalesQuery::hasDeliveredInYear([11], 2026));
        $this->assertTrue(ProductSalesQuery::hasDeliveredInYear([11], 2025));
        $this->assertFalse(ProductSalesQuery::hasDeliveredInYear([11], 2024));
        $this->assertFalse(ProductSalesQuery::hasDeliveredInYear([12], 2025));
        $this->assertFalse(ProductSalesQuery::hasDeliveredInYear([], 2026));
        $february = ProductSalesQuery::rows([11], Carbon::parse('2026-02-01')->startOfDay(), Carbon::parse('2026-02-28')->endOfDay());
        $this->assertCount(2, $february);
        $this->assertNotSame($february[0]->product_key, $february[1]->product_key);
    }

    public function test_nested_variation_identity_groups_the_same_product_across_orders(): void
    {
        $payload = json_encode(['items' => [[
            'quantity' => 1, 'variation_info' => ['id' => 'nested-variation', 'name' => 'Nested variation', 'retail_price' => 10],
        ]]]);
        DB::table('orders')->whereIn('id', [1, 2])->update(['pancake_full_data' => $payload]);
        $start = Carbon::parse('2026-01-01')->startOfDay();
        $end = $start->copy()->endOfDay();
        $raw = ProductSalesQuery::rows([11], $start, $end);
        $this->assertCount(1, $raw);
        $this->assertEquals(2, $raw[0]->quantity);
        $this->assertSame(2, $raw[0]->orders_count);
        OrderSummaryService::rebuildAll();
        $this->assertEquals($raw, ProductSalesQuery::rows([11], $start, $end));
    }

    private function sourceQueries(array $queries): array
    {
        return array_values(array_filter($queries, fn ($query) => preg_match('/\bfrom\s+["`]?orders["`]?\b/i', $query['query'])));
    }

    private function seedOrders(): void
    {
        $item = fn ($id, $quantity, $price) => ['product_id' => $id, 'variation_id' => $id === null ? null : $id.'-v', 'quantity' => $quantity,
            'variation_info' => ['name' => 'Same name', 'retail_price' => $price]];
        $fixtures = [
            [1, 11, '2026-01-01 08:00:00', 50, [$item('A', 3, 10), $item('B', 2, 20)], []],
            [2, 11, '2026-01-01 16:00:00', 4, [$item('A', 1.25, 7.25), $item('A', .75, 7.25)], []],
            [3, 11, '2026-01-02 12:00:00', 7, [$item('A', 1, 15)], []],
            [4, 11, '2026-01-03 09:00:00', 8, [$item('A', 2, 12.5)], []],
            [5, 11, '2026-01-03 18:00:00', 9, [$item('B', 1, 30)], []],
            [6, 12, '2026-01-02 12:00:00', 99, [$item('A', 10, 1)], []],
            [7, 11, '2026-01-02 12:00:00', 90, [$item('A', 99, 1)], ['status' => 16]],
            [8, 11, '2026-01-02 12:00:00', 90, [$item('A', 99, 1)], ['order_number_vtp' => '']],
            [9, 11, '2026-01-02 12:00:00', 90, [$item('A', 99, 1)], ['order_number_vtp' => null]],
            [10, 11, '2026-01-02 12:00:00', 90, [$item('A', 99, 1)], ['deleted_at' => '2026-01-03 00:00:00']],
            [11, 11, '2026-02-01 12:00:00', 2, [$item(null, 1, 3), $item(null, 1, 3)], []],
            [12, 11, '2025-12-31 12:00:00', 1, [$item('A', 1, 4)], []],
            [13, 11, '2026-01-04 12:00:00', 6, [], []],
            [14, 11, '2026-01-02 13:00:00', 3, [], ['pancake_full_data' => null]],
            [15, 11, '2026-03-01 13:00:00', 99, [$item('A', 99, 1)], ['status' => 16]],
        ];
        foreach ($fixtures as [$id, $shop, $created, $quantity, $items, $overrides]) {
            DB::table('orders')->insert($overrides + [
                'id' => $id, 'shop_id' => $shop, 'created_at' => $created, 'updated_at' => $created, 'status' => 3,
                'total_quantity' => $quantity, 'order_number_vtp' => 'VTP-'.$id, 'pancake_full_data' => json_encode(['items' => $items]),
            ]);
        }
    }
}
