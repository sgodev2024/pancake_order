<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\OrderSummaryService;
use App\Services\ReportDataVersion;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class OrderSummaryProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // This suite owns only an explicitly named in-memory connection.
        config([
            'database.default' => 'order_projection_testing',
            'database.connections.order_projection_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('order_projection_testing');
        DB::setDefaultConnection('order_projection_testing');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Cache::flush();
        Carbon::setTestNow('2026-11-02 15:00:00');

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
        (require database_path('migrations/2026_11_02_000001_create_dashboard_daily_summaries_tables.php'))->up();
        (require database_path('migrations/2026_11_02_000002_make_order_summaries_incremental.php'))->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('order_projection_testing');
        parent::tearDown();
    }

    public function test_rebuild_preserves_source_totals_shop_scope_and_distinct_product_identity(): void
    {
        $this->order([
            'cod' => 120.25, 'prepaid_amount' => 10.50, 'total_quantity' => 5,
            'pancake_full_data' => ['items' => [
                $this->item('p1', 1.5), $this->item('p1', 0.5), $this->item('p2', 3, 7.25),
            ]],
        ]);
        $this->order(['created_at' => '2026-11-01 09:45:01', 'cod' => 80, 'prepaid_amount' => 5, 'total_quantity' => 1]);
        $this->order(['shop_id' => 22, 'created_at' => '2026-11-02 12:34:56', 'status' => 2, 'cod' => 50, 'total_quantity' => 2,
            'pancake_full_data' => ['items' => [$this->item('p1', 2)]]]);
        $this->order(['shop_id' => 22, 'created_at' => '2026-11-02 14:00:00', 'status' => null, 'cod' => 25, 'pancake_full_data' => ['items' => []]]);
        $this->order(['cod' => 999, 'deleted_at' => '2026-11-02 10:00:00']);

        $this->assertFalse(OrderSummaryService::isReady());
        $this->assertSame(5, DB::table('order_summary_pending')->count());
        OrderSummaryService::rebuildAll();

        $this->assertTrue(OrderSummaryService::isReady());
        $this->assertSame(4, DB::table('order_summary_contributions')->count());
        $this->assertSame(0, DB::table('order_summary_pending')->count());
        $this->assertSame(4, (int) DB::table('daily_order_summaries')->where('shop_id', 0)->sum('total_items'));
        $this->assertEqualsWithDelta(275.25, DB::table('daily_order_summaries')->where('shop_id', 0)->sum('total_revenue'), 0.0001);
        $this->assertSame(2, (int) $this->day('2026-11-01', 11)->total_items);
        $this->assertSame(2, (int) $this->day('2026-11-01', 0)->total_items);
        $this->assertDay('2026-11-01', 11, ['total_revenue' => 200.25, 'total_prepaid_amount' => 15.5,
            'success_count' => 2, 'success_amount' => 200.25, 'delivered_vtp_count' => 2, 'delivered_vtp_quantity' => 6]);
        $this->assertDay('2026-11-02', 22, ['shipping_count' => 1, 'shipping_amount' => 50, 'pending_count' => 0, 'total_items' => 2]);

        $products = DB::table('daily_product_sales_summaries')->where('shop_id', 11)->orderBy('product_id')->get();
        $this->assertCount(2, $products);
        $this->assertSame($products[0]->product_name, $products[1]->product_name);
        $this->assertNotSame($products[0]->product_key, $products[1]->product_key);
        $this->assertEquals(3, $products[0]->quantity);
        $this->assertEqualsWithDelta(59.85, (float) $products[0]->revenue, 0.0001);
        $this->assertSame(2, (int) $products[0]->orders_count);
        $this->assertEquals(3, $products[1]->quantity);
        $this->assertEqualsWithDelta(21.75, (float) $products[1]->revenue, 0.0001);
        $this->assertSame(1, (int) $products[1]->orders_count);
        $this->assertSame('2026-11-02 14:00:00', OrderSummaryService::latestOrderAt());
        $this->assertSame('2026-11-01 09:45:01', OrderSummaryService::latestOrderAt([11], true));
        $this->assertNull(OrderSummaryService::latestOrderAt([999]));
    }

    public function test_replayed_webhook_and_rebuild_are_idempotent(): void
    {
        $order = $this->order(['pancake_full_data' => ['items' => [$this->item('p1', 1), $this->item('p1', 2)]]]);
        OrderSummaryService::rebuildAll();
        $before = $this->snapshot();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            OrderSummaryService::queueOrder($order->id);
            $order->save();
            OrderSummaryService::syncOrder($order->id);
        }
        $this->assertEquals($before, $this->snapshot());
        $product = DB::table('daily_product_sales_summaries')->first();
        $this->assertSame(1, (int) $product->orders_count);
        $this->assertEquals(3, $product->quantity);
        $this->assertEqualsWithDelta(59.85, (float) $product->revenue, 0.0001);
        OrderSummaryService::rebuildAll();
        $this->assertEquals($before, $this->snapshot());
    }

    public function test_order_value_items_status_cancellation_and_refund_apply_only_the_latest_contribution(): void
    {
        $order = $this->order(['status' => 1, 'cod' => 100, 'prepaid_amount' => 5]);
        OrderSummaryService::rebuildAll();
        $this->assertDay('2026-11-01', 11, ['total_items' => 1, 'pending_count' => 1, 'pending_amount' => 100]);

        $order->update(['cod' => 150.75, 'prepaid_amount' => 12.25, 'status' => 2,
            'pancake_full_data' => ['items' => [$this->item('p2', 2.5, 20.5)]]]);
        $this->assertDay('2026-11-01', 11, ['total_items' => 1, 'total_revenue' => 150.75, 'total_prepaid_amount' => 12.25,
            'pending_count' => 0, 'pending_amount' => 0, 'shipping_count' => 1, 'shipping_amount' => 150.75]);
        $this->assertSame(0, DB::table('daily_product_sales_summaries')->where('product_id', 'p1')->count());
        $product = DB::table('daily_product_sales_summaries')->sole();
        $this->assertSame('p2', $product->product_id);
        $this->assertEquals(2.5, $product->quantity);
        $this->assertEqualsWithDelta(51.25, (float) $product->revenue, 0.0001);

        $order->update(['status' => 3, 'total_quantity' => 2.5]);
        $this->assertDay('2026-11-01', 11, ['shipping_count' => 0, 'shipping_amount' => 0,
            'success_count' => 1, 'success_amount' => 150.75, 'delivered_vtp_count' => 1, 'delivered_vtp_quantity' => 2.5]);
        $order->update(['status' => 6]);
        $this->assertDay('2026-11-01', 11, ['success_count' => 0, 'success_amount' => 0,
            'delivered_vtp_count' => 0, 'delivered_vtp_quantity' => 0, 'cancel_count' => 1, 'cancel_amount' => 150.75]);
        $order->update(['status' => 4, 'cod' => -25.5, 'prepaid_amount' => 0]);
        $this->assertDay('2026-11-01', 11, ['total_items' => 1, 'total_revenue' => -25.5, 'total_prepaid_amount' => 0,
            'cancel_count' => 0, 'cancel_amount' => 0, 'return_count' => 1, 'return_amount' => -25.5]);
        $this->assertDay('2026-11-01', 0, ['total_revenue' => -25.5, 'return_count' => 1, 'return_amount' => -25.5]);
        $this->assertSame(4, (int) DB::table('daily_product_sales_summaries')->sole()->status);
        $this->assertTrue(OrderSummaryService::isReady());
    }

    public function test_changing_shop_and_day_removes_empty_groups_and_moves_exact_latest_timestamp(): void
    {
        $order = $this->order();
        OrderSummaryService::rebuildAll();
        $order->forceFill(['shop_id' => 22, 'created_at' => '2026-11-02 10:23:45'])->save();
        $this->assertNull($this->day('2026-11-01', 11));
        $this->assertNull($this->day('2026-11-01', 0));
        $this->assertSame(0, DB::table('daily_product_sales_summaries')->where('shop_id', 11)->count());
        $this->assertDay('2026-11-02', 22, ['total_items' => 1, 'total_revenue' => 100]);
        $this->assertDay('2026-11-02', 0, ['total_items' => 1, 'total_revenue' => 100]);
        $this->assertSame('2026-11-02 10:23:45', OrderSummaryService::latestOrderAt([22], true));
        $this->assertNull(OrderSummaryService::latestOrderAt([11]));
        $this->assertSame(1, DB::table('order_summary_contributions')->count());
    }

    public function test_soft_delete_restore_and_last_order_removal_update_all_derived_data(): void
    {
        $older = $this->order();
        $newer = $this->order(['shop_id' => 22, 'created_at' => '2026-11-02 13:57:59']);
        OrderSummaryService::rebuildAll();
        $before = $this->snapshot();
        $newer->delete();
        $this->assertNull($this->day('2026-11-02', 22));
        $this->assertNull($this->day('2026-11-02', 0));
        $this->assertSame(0, DB::table('daily_product_sales_summaries')->where('shop_id', 22)->count());
        $this->assertSame('2026-11-01 08:12:34', OrderSummaryService::latestOrderAt());
        $newer->restore();
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame('2026-11-02 13:57:59', OrderSummaryService::latestOrderAt());
        $newer->forceDelete();
        $older->delete();
        $this->assertSame(0, DB::table('daily_order_summaries')->count());
        $this->assertSame(0, DB::table('daily_product_sales_summaries')->count());
        $this->assertSame(0, DB::table('order_summary_contributions')->count());
        $this->assertNull(OrderSummaryService::latestOrderAt());
        $this->assertTrue(OrderSummaryService::isReady());
    }

    public function test_source_rollback_does_not_publish_projection_pending_work_or_cache_generation(): void
    {
        $order = $this->order();
        OrderSummaryService::rebuildAll();
        $before = $this->snapshot();
        $version = ReportDataVersion::key('orders');
        try {
            DB::transaction(function () use ($order) {
                $order->forceFill(['cod' => 999, 'shop_id' => 22])->save();
                $this->order(['cod' => 777]);
                $this->assertSame(2, DB::table('order_summary_pending')->count());
                $this->assertFalse(OrderSummaryService::isReady());
                throw new RuntimeException('Abort source transaction');
            });
            $this->fail('The transaction must be rolled back.');
        } catch (RuntimeException $error) {
            $this->assertSame('Abort source transaction', $error->getMessage());
        }
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertEquals(100, DB::table('orders')->sole()->cod);
        $this->assertSame(0, DB::table('order_summary_pending')->count());
        $this->assertSame($version, ReportDataVersion::key('orders'));
        $this->assertTrue(OrderSummaryService::isReady());
    }

    public function test_pending_work_disables_summary_reads_until_repaired_and_drained(): void
    {
        $order = $this->order();
        OrderSummaryService::rebuildAll();
        OrderSummaryService::trackSourceWrite(fn () => DB::table('orders')->where('id', $order->id)->update(['cod' => 140]));
        DB::table('order_summary_pending')->insert(['order_id' => $order->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse(OrderSummaryService::isReady());
        $this->assertDay('2026-11-01', 11, ['total_revenue' => 100]);
        $this->assertSame(1, OrderSummaryService::drainPending());
        $this->assertTrue(OrderSummaryService::isReady());
        $this->assertDay('2026-11-01', 11, ['total_revenue' => 140]);
        $this->assertSame(0, OrderSummaryService::drainPending());
        DB::table('order_summary_state')->where('id', 1)->update(['schema_version' => 1]);
        $this->assertFalse(OrderSummaryService::isReady());
    }

    public function test_untracked_bulk_writes_disable_stale_summaries_until_explicit_rebuild(): void
    {
        $order = $this->order();
        OrderSummaryService::rebuildAll();
        $version = ReportDataVersion::key('orders');

        DB::table('orders')->where('id', $order->id)->update(['cod' => 300]);
        $this->assertFalse(OrderSummaryService::isReady());
        $this->assertNotSame($version, ReportDataVersion::key('orders'));
        $this->assertDay('2026-11-01', 11, ['total_revenue' => 100]);
        $this->assertSame(0, OrderSummaryService::drainPending());
        OrderSummaryService::syncOrder($order->id);
        $this->assertFalse(OrderSummaryService::isReady());
        OrderSummaryService::rebuildAll();
        $this->assertTrue(OrderSummaryService::isReady());
        $this->assertDay('2026-11-01', 11, ['total_items' => 1, 'total_revenue' => 300]);

        DB::table('orders')->insert([
            'shop_id' => 22, 'status' => 2, 'cod' => 50,
            'created_at' => '2026-11-02 12:34:56', 'updated_at' => now(),
        ]);
        $this->assertFalse(OrderSummaryService::isReady());
        OrderSummaryService::rebuildAll();
        $this->assertTrue(OrderSummaryService::isReady());
        $this->assertEquals(350, DB::table('daily_order_summaries')->where('shop_id', 0)->sum('total_revenue'));
        $this->assertSame('2026-11-02 12:34:56', OrderSummaryService::latestOrderAt());

        DB::table('orders')->delete();
        $this->assertFalse(OrderSummaryService::isReady());
        OrderSummaryService::rebuildAll();
        $this->assertTrue(OrderSummaryService::isReady());
        $this->assertSame(0, DB::table('daily_order_summaries')->count());
        $this->assertSame(0, DB::table('daily_product_sales_summaries')->count());
        $this->assertSame(0, DB::table('order_summary_contributions')->count());
        $this->assertNull(OrderSummaryService::latestOrderAt());
    }

    public function test_projection_and_cache_generation_are_published_after_source_commit_only(): void
    {
        $order = $this->order();
        OrderSummaryService::rebuildAll();
        $before = $this->snapshot();
        $version = ReportDataVersion::key('orders');
        DB::transaction(function () use ($order, $before, $version) {
            $order->update(['cod' => 130]);
            $order->update(['cod' => 160]);
            $this->assertEquals($before, $this->snapshot());
            $this->assertSame($version, ReportDataVersion::key('orders'));
            $this->assertSame(1, DB::table('order_summary_pending')->count());
            $this->assertFalse(OrderSummaryService::isReady());
        });
        $this->assertDay('2026-11-01', 11, ['total_items' => 1, 'total_revenue' => 160]);
        $this->assertDay('2026-11-01', 0, ['total_items' => 1, 'total_revenue' => 160]);
        $this->assertNotSame($version, ReportDataVersion::key('orders'));
        $this->assertSame(1, DB::table('order_summary_contributions')->count());
        $this->assertSame(1, (int) DB::table('daily_product_sales_summaries')->sole()->orders_count);
        $this->assertTrue(OrderSummaryService::isReady());
    }

    public function test_quiet_model_writes_update_projections_and_cache_versions_without_events(): void
    {
        $order = $this->order();
        OrderSummaryService::rebuildAll();
        $version = ReportDataVersion::key('orders');
        $order->updateQuietly(['cod' => 150, 'status' => 2, 'pancake_full_data' => ['items' => [$this->item('p1', 2, 30)]]]);
        $this->assertTrue(OrderSummaryService::isReady());
        $this->assertDay('2026-11-01', 11, ['total_items' => 1, 'total_revenue' => 150,
            'shipping_count' => 1, 'success_count' => 0, 'delivered_vtp_count' => 0]);
        $this->assertEquals(60, DB::table('daily_product_sales_summaries')->sole()->revenue);
        $this->assertNotSame($version, ReportDataVersion::key('orders'));

        $version = ReportDataVersion::key('orders');
        Order::withoutEvents(fn () => $this->order(['cod' => 75]));
        $this->assertDay('2026-11-01', 11, ['total_items' => 2, 'total_revenue' => 225, 'delivered_vtp_count' => 1]);
        $this->assertSame(2, DB::table('order_summary_contributions')->count());
        $this->assertNotSame($version, ReportDataVersion::key('orders'));

        $version = ReportDataVersion::key('orders');
        Order::withoutEvents(fn () => $order->delete());
        $this->assertDay('2026-11-01', 11, ['total_items' => 1, 'total_revenue' => 75, 'shipping_count' => 0]);
        $this->assertSame(1, DB::table('order_summary_contributions')->count());
        $this->assertNotSame($version, ReportDataVersion::key('orders'));
        $this->assertTrue(OrderSummaryService::isReady());
    }

    public function test_delivered_metadata_requires_status_three_and_nonblank_tracking_number(): void
    {
        $valid = $this->order(['created_at' => '2026-11-01 10:11:12']);
        $this->order(['created_at' => '2026-11-02 11:12:13', 'order_number_vtp' => '   ']);
        $this->order(['created_at' => '2026-11-02 12:13:14', 'status' => 16]);
        OrderSummaryService::rebuildAll();
        $this->assertSame('2026-11-02 12:13:14', OrderSummaryService::latestOrderAt());
        $this->assertSame('2026-11-01 10:11:12', OrderSummaryService::latestOrderAt(null, true));
        $this->assertDay('2026-11-01', 11, ['delivered_vtp_count' => 1]);
        $this->assertDay('2026-11-02', 11, ['success_count' => 2, 'delivered_vtp_count' => 0]);
        $valid->update(['order_number_vtp' => null]);
        $this->assertNull(OrderSummaryService::latestOrderAt(null, true));
        $this->assertDay('2026-11-01', 11, ['success_count' => 1, 'delivered_vtp_count' => 0]);
    }

    private function order(array $attributes = []): Order
    {
        $order = new Order;
        $order->forceFill(array_replace([
            'shop_id' => 11, 'status' => 3, 'order_number_vtp' => 'VTP-1', 'cod' => 100,
            'prepaid_amount' => 0, 'total_quantity' => 1,
            'pancake_full_data' => ['items' => [$this->item('p1', 1)]],
            'created_at' => '2026-11-01 08:12:34', 'updated_at' => '2026-11-01 08:12:34',
        ], $attributes))->save();

        return $order;
    }

    private function item(string $productId, float $quantity, float $price = 19.95): array
    {
        return ['product_id' => $productId, 'variation_id' => 'v-'.$productId, 'quantity' => $quantity,
            'variation_info' => ['name' => 'Same display name', 'retail_price' => $price]];
    }

    private function day(string $date, int $shopId): ?object
    {
        return DB::table('daily_order_summaries')->where('report_date', $date)->where('shop_id', $shopId)->first();
    }

    private function assertDay(string $date, int $shopId, array $expected): void
    {
        $row = $this->day($date, $shopId);
        $this->assertNotNull($row);
        foreach ($expected as $field => $value) {
            $this->assertEqualsWithDelta($value, (float) $row->$field, 0.0001, $date.' shop '.$shopId.' '.$field);
        }
    }

    private function snapshot(): array
    {
        $tables = [];
        foreach (['daily_order_summaries', 'daily_product_sales_summaries', 'order_summary_contributions'] as $table) {
            $rows = DB::table($table)->get()->map(function ($row) {
                $data = (array) $row;
                unset($data['id'], $data['created_at'], $data['updated_at']);

                return $data;
            })->all();
            usort($rows, fn ($left, $right) => strcmp(json_encode($left), json_encode($right)));
            $tables[$table] = $rows;
        }

        return $tables;
    }
}
