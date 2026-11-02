<?php

namespace Tests\Feature;

use App\Exceptions\OrderSummaryPendingException;
use App\Models\Order;
use App\Services\OrderSummaryReconciler;
use App\Services\OrderSummaryService;
use App\Services\ReportDataVersion;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncOrderSummariesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'summary_command_testing',
            'database.connections.summary_command_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('summary_command_testing');
        DB::setDefaultConnection('summary_command_testing');
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
        // Both migrations run only on this explicitly asserted in-memory DB.
        (require database_path('migrations/2026_11_02_000001_create_dashboard_daily_summaries_tables.php'))->up();
        (require database_path('migrations/2026_11_02_000002_make_order_summaries_incremental.php'))->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::purge('summary_command_testing');
        parent::tearDown();
    }

    public function test_only_all_initializes_full_history_and_verification_is_read_only(): void
    {
        $this->order();
        $this->order(['shop_id' => 22, 'status' => null, 'cod' => -25.50, 'prepaid_amount' => 2.25,
            'created_at' => '2024-01-01 00:00:01', 'order_number_vtp' => '   ']);
        $this->order(['shop_id' => 22, 'status' => 6, 'cod' => 50]);
        $this->artisan('orders:sync-summaries', ['--days' => 60])->assertExitCode(1);
        $this->assertFalse(OrderSummaryService::isReady());
        $source = DB::table('orders')->orderBy('id')->get()->toJson();

        $this->artisan('orders:sync-summaries', ['--all' => true])->assertExitCode(0);
        $this->assertTrue(OrderSummaryService::isReady());
        $this->assertSame(3, DB::table('order_summary_contributions')->count());
        $this->assertSame(1, DB::table('daily_order_summaries')->where('report_date', '2024-01-01')->where('shop_id', 0)->count());
        $this->assertSame(2, (int) DB::table('daily_order_summaries')->where('report_date', '2026-11-01')->where('shop_id', 0)->value('total_items'));

        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(OrderSummaryReconciler::class)->verify();
        $this->assertTrue($result['ok'], json_encode($result));
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|truncate)\b/i', $query['query']);
        }
        DB::disableQueryLog();
        $this->artisan('orders:sync-summaries', ['--verify' => true])->assertExitCode(0);
        $this->assertSame($source, DB::table('orders')->orderBy('id')->get()->toJson());
    }

    public function test_reconciliation_detects_day_drift_and_disables_only_derived_readiness(): void
    {
        $this->order();
        OrderSummaryService::rebuildAll();
        DB::table('daily_order_summaries')->where('shop_id', 11)->update(['total_revenue' => 999]);
        $version = ReportDataVersion::key('orders');
        $source = DB::table('orders')->get()->toJson();

        $this->artisan('orders:sync-summaries', ['--verify' => true])->assertExitCode(1);
        $this->assertFalse(OrderSummaryService::isReady());
        $this->assertNotSame($version, ReportDataVersion::key('orders'));
        $this->assertSame($source, DB::table('orders')->get()->toJson());
        $this->assertEquals(999, DB::table('daily_order_summaries')->where('shop_id', 11)->value('total_revenue'));
        $this->artisan('orders:sync-summaries', ['--date' => '2026-11-01'])->assertExitCode(1);
        $this->artisan('orders:sync-summaries', ['--all' => true])->assertExitCode(0);
        $this->artisan('orders:sync-summaries', ['--verify' => true])->assertExitCode(0);
    }

    public function test_product_quantity_revenue_count_identity_and_ledger_metadata_are_reconciled(): void
    {
        $this->order(['pancake_full_data' => ['items' => [
            array_replace_recursive($this->item('p1', 1.5), ['variation_info' => ['name' => 'Zulu']]),
            array_replace_recursive($this->item('p1', 0.5), ['variation_info' => ['name' => 'Alpha']]),
            $this->item('p2', 2),
            ['quantity' => 1, 'variation_info' => ['name' => 'Unidentified', 'retail_price' => 3.25]],
        ]]]);
        $this->order(['shop_id' => 22, 'status' => 4, 'cod' => -5]);
        OrderSummaryService::rebuildAll();
        $result = app(OrderSummaryReconciler::class)->verify();
        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame('Zulu', DB::table('daily_product_sales_summaries')->where('shop_id', 11)->where('product_id', 'p1')->value('product_name'));

        DB::table('daily_product_sales_summaries')->where('shop_id', 11)->where('product_id', 'p1')
            ->update(['quantity' => 999, 'revenue' => 888, 'orders_count' => 9, 'variation_id' => 'wrong', 'product_name' => 'Corrupted label']);
        DB::table('order_summary_contributions')->where('order_id', 1)->update(['source_created_at' => '2026-11-01 00:00:00']);
        $result = app(OrderSummaryReconciler::class)->verify();
        $this->assertFalse($result['ok']);
        $this->assertGreaterThanOrEqual(6, $result['differences']);
        $this->assertStringContainsString('product_name', implode(' ', $result['samples']));
        $this->artisan('orders:sync-summaries', ['--verify' => true])->assertExitCode(1);
        $this->assertFalse(OrderSummaryService::isReady());
    }

    public function test_pending_retries_and_old_date_repair_are_idempotent(): void
    {
        $order = $this->order();
        OrderSummaryService::rebuildAll();
        DB::transaction(function () use ($order) {
            OrderSummaryService::trackSourceWrite(fn () => DB::table('orders')->where('id', $order->id)->update(['cod' => 140]));
            DB::table('order_summary_pending')->insert(['order_id' => $order->id, 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->artisan('orders:sync-summaries', ['--verify' => true])->assertExitCode(1);
        $exit = Artisan::call('orders:sync-summaries', ['--pending' => true]);
        $this->assertSame(0, $exit, Artisan::output());
        $this->assertTrue(OrderSummaryService::isReady());
        $this->assertEquals(140, DB::table('daily_order_summaries')->where('shop_id', 11)->value('total_revenue'));

        // Simulate a tracked import needing an explicit date repair. The old
        // ledger date must locate an order that moved to another shop/day.
        OrderSummaryService::trackSourceWrite(fn () => DB::table('orders')->where('id', $order->id)->update(['shop_id' => 22, 'created_at' => '2026-11-02 12:00:00']));
        $this->artisan('orders:sync-summaries', ['--date' => '2026-11-01'])->assertExitCode(0);
        $this->assertSame(0, DB::table('daily_order_summaries')->where('shop_id', 11)->count());
        $this->assertEquals(140, DB::table('daily_order_summaries')->where('shop_id', 22)->value('total_revenue'));
        $this->artisan('orders:sync-summaries', ['--days' => 2])->assertExitCode(0);
        $this->artisan('orders:sync-summaries', ['--pending' => true])->assertExitCode(0);
        $this->artisan('orders:sync-summaries', ['--verify' => true])->assertExitCode(0);
    }

    public function test_invalid_or_ambiguous_repair_options_never_change_source_or_summary(): void
    {
        $this->order();
        OrderSummaryService::rebuildAll();
        $source = DB::table('orders')->get()->toJson();
        $summary = DB::table('daily_order_summaries')->get()->toJson();
        foreach ([['--all' => true, '--days' => 2], ['--date' => '2026-02-30'], ['--date' => 'yesterday'], ['--days' => 0], ['--days' => -1]] as $options) {
            $this->artisan('orders:sync-summaries', $options)->assertExitCode(2);
        }
        $this->assertSame($source, DB::table('orders')->get()->toJson());
        $this->assertSame($summary, DB::table('daily_order_summaries')->get()->toJson());
    }

    public function test_nested_variation_id_keeps_one_identity_across_orders(): void
    {
        $item = ['quantity' => 1, 'variation_info' => ['id' => 'nested-only', 'name' => 'Nested variation', 'retail_price' => 12.50]];
        $this->order(['pancake_full_data' => ['items' => [$item]]]);
        $this->order(['pancake_full_data' => ['items' => [array_replace($item, ['quantity' => 2])]]]);
        OrderSummaryService::rebuildAll();
        $product = DB::table('daily_product_sales_summaries')->sole();
        $this->assertNull($product->product_id);
        $this->assertSame('nested-only', $product->variation_id);
        $this->assertSame(2, (int) $product->orders_count);
        $this->assertEquals(3, $product->quantity);
        $this->assertEquals(37.50, $product->revenue);
        $result = app(OrderSummaryReconciler::class)->verify();
        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame(1, $result['product_groups']);
    }

    public function test_verification_error_disables_readiness_without_repairing_source(): void
    {
        $order = $this->order();
        OrderSummaryService::rebuildAll();
        OrderSummaryService::trackSourceWrite(fn () => DB::table('orders')->where('id', $order->id)->update(['pancake_full_data' => '{broken']));
        $source = DB::table('orders')->get()->toJson();
        $version = ReportDataVersion::key('orders');
        $exit = Artisan::call('orders:sync-summaries', ['--verify' => true]);
        $output = Artisan::output();
        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Summary reads disabled', $output);
        $this->assertFalse(OrderSummaryService::isReady());
        $this->assertNotSame($version, ReportDataVersion::key('orders'));
        $this->assertSame($source, DB::table('orders')->get()->toJson());
    }

    public function test_pending_race_defers_verification_without_disabling_initialized_baseline(): void
    {
        $order = $this->order();
        OrderSummaryService::rebuildAll();
        $version = ReportDataVersion::key('orders');
        $this->mock(OrderSummaryReconciler::class)->shouldReceive('verify')->once()->andReturnUsing(function () use ($order) {
            DB::table('order_summary_pending')->insert(['order_id' => $order->id, 'created_at' => now(), 'updated_at' => now()]);
            throw new OrderSummaryPendingException('Pending source changes exist; run --pending before --verify.');
        });
        $exit = Artisan::call('orders:sync-summaries', ['--verify' => true]);
        $output = Artisan::output();
        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('--pending', $output);
        $this->assertTrue((bool) DB::table('order_summary_state')->where('id', 1)->value('ready'));
        $this->assertSame($version, ReportDataVersion::key('orders'));
        $this->assertSame(1, DB::table('order_summary_pending')->count());
    }

    private function order(array $attributes = []): Order
    {
        $order = new Order;
        $order->forceFill(array_replace([
            'shop_id' => 11, 'status' => 3, 'order_number_vtp' => 'VTP-1', 'cod' => 100.25,
            'prepaid_amount' => 1.75, 'total_quantity' => 2,
            'pancake_full_data' => ['items' => [$this->item('p1', 2)]],
            'created_at' => '2026-11-01 08:12:34', 'updated_at' => '2026-11-01 08:12:34',
        ], $attributes))->save();

        return $order;
    }

    private function item(string $id, float $quantity): array
    {
        return ['product_id' => $id, 'variation_id' => 'v-'.$id, 'quantity' => $quantity,
            'variation_info' => ['name' => 'Same display name', 'retail_price' => 19.95]];
    }
}
