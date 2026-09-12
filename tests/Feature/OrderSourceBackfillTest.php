<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PancakeOrderSource;
use App\Models\Shop;
use App\Services\OrderSourceBackfillService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderSourceBackfillTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'order_source_backfill_testing',
            'database.connections.order_source_backfill_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('order_source_backfill_testing');
        DB::setDefaultConnection('order_source_backfill_testing');
        $this->createSchema();
        Http::preventStrayRequests();
    }

    public function test_full_source_backfills_integer_id_trimmed_name_and_preserves_raw_payload(): void
    {
        $shop = $this->createShop();
        $order = $this->createOrder($shop, [
            'order_sources' => -9,
            'order_sources_name' => '  TikTok  ',
            'other_payload_data' => ['must' => 'remain'],
        ]);
        $rawPayload = $order->getRawOriginal('pancake_full_data');

        $result = $this->service()->run(execute: true);
        $order->refresh();

        $this->assertSame(1, $result['summary']['would_backfill']);
        $this->assertSame(1, $result['summary']['backfilled']);
        $this->assertSame('-9', $order->pancake_order_source_id);
        $this->assertSame('TikTok', $order->pancake_order_source_name);
        $this->assertSame($rawPayload, $order->getRawOriginal('pancake_full_data'));
    }

    public function test_null_blank_and_partial_sources_do_not_create_fake_values(): void
    {
        $shop = $this->createShop();
        $noSource = $this->createOrder($shop, [
            'order_sources' => null,
            'order_sources_name' => " \t\r\n ",
            'page' => ['name' => 'Do not use'],
            'ads_source' => 'Do not use',
        ]);
        $idOnly = $this->createOrder($shop, [
            'order_sources' => 15,
            'order_sources_name' => '   ',
        ]);
        $nameOnly = $this->createOrder($shop, [
            'order_sources' => null,
            'order_sources_name' => ' Organic ',
        ]);

        $result = $this->service()->run(execute: true);

        $this->assertSame(1, $result['summary']['no_source']);
        $this->assertSame(2, $result['summary']['partial_source']);
        $this->assertSame(2, $result['summary']['backfilled']);
        $this->assertNull($noSource->fresh()->pancake_order_source_id);
        $this->assertNull($noSource->fresh()->pancake_order_source_name);
        $this->assertSame('15', $idOnly->fresh()->pancake_order_source_id);
        $this->assertNull($idOnly->fresh()->pancake_order_source_name);
        $this->assertNull($nameOnly->fresh()->pancake_order_source_id);
        $this->assertSame('Organic', $nameOnly->fresh()->pancake_order_source_name);
    }

    public function test_populated_orders_are_never_overwritten_and_conflicts_are_reported(): void
    {
        $shop = $this->createShop();
        $same = $this->createOrder(
            $shop,
            ['order_sources' => '-9', 'order_sources_name' => 'TikTok'],
            '-9',
            'TikTok'
        );
        $conflict = $this->createOrder(
            $shop,
            ['order_sources' => '-10', 'order_sources_name' => 'Raw name'],
            '-9',
            'Stored name'
        );
        $partiallyPopulated = $this->createOrder(
            $shop,
            ['order_sources' => '-11', 'order_sources_name' => 'Must not be filled'],
            '-11'
        );

        $result = $this->service()->run(execute: true);

        $this->assertSame(3, $result['summary']['already_populated']);
        $this->assertSame(1, $result['summary']['conflict']);
        $this->assertSame(0, $result['summary']['backfilled']);
        $this->assertSame('TikTok', $same->fresh()->pancake_order_source_name);
        $this->assertSame('-9', $conflict->fresh()->pancake_order_source_id);
        $this->assertSame('Stored name', $conflict->fresh()->pancake_order_source_name);
        $this->assertNull($partiallyPopulated->fresh()->pancake_order_source_name);
    }

    public function test_invalid_payload_is_reported_without_mutation(): void
    {
        $shop = $this->createShop();
        $order = $this->createOrder($shop, 'not-an-object');

        $result = $this->service()->run(execute: true);

        $this->assertSame(1, $result['summary']['invalid_payload']);
        $this->assertSame(0, $result['summary']['backfilled']);
        $this->assertNull($order->fresh()->pancake_order_source_id);
        $this->assertNull($order->fresh()->pancake_order_source_name);
    }

    public function test_preview_command_reports_without_writing(): void
    {
        $shop = $this->createShop();
        $order = $this->createOrder($shop, [
            'order_sources' => 'source-1',
            'order_sources_name' => 'Website',
        ]);

        $this->artisan('order-sources:backfill')
            ->expectsOutputToContain('PREVIEW ONLY')
            ->expectsOutputToContain('Would backfill')
            ->assertSuccessful();

        $this->assertNull($order->fresh()->pancake_order_source_id);
        $this->assertNull($order->fresh()->pancake_order_source_name);
    }

    public function test_execute_writes_once_and_second_execute_is_idempotent(): void
    {
        $shop = $this->createShop();
        $order = $this->createOrder($shop, [
            'order_sources' => 'source-1',
            'order_sources_name' => 'Website',
        ]);

        $first = $this->service()->run(execute: true);
        $second = $this->service()->run(execute: true);

        $this->assertSame(1, $first['summary']['backfilled']);
        $this->assertSame(0, $second['summary']['backfilled']);
        $this->assertSame(0, $second['summary']['would_backfill']);
        $this->assertSame(1, $second['summary']['already_populated']);
        $this->assertSame('source-1', $order->fresh()->pancake_order_source_id);
    }

    public function test_shop_id_restricts_execution_to_the_requested_shop(): void
    {
        $firstShop = $this->createShop('PAN-SHOP-1');
        $secondShop = $this->createShop('PAN-SHOP-2');
        $firstOrder = $this->createOrder($firstShop, $this->sourcePayload('-9'));
        $secondOrder = $this->createOrder($secondShop, $this->sourcePayload('-9'));

        $result = $this->service()->run(shopId: $firstShop->id, execute: true);

        $this->assertSame(1, $result['summary']['scanned']);
        $this->assertSame('-9', $firstOrder->fresh()->pancake_order_source_id);
        $this->assertNull($secondOrder->fresh()->pancake_order_source_id);
    }

    public function test_ids_restrict_execution_to_exact_local_orders(): void
    {
        $shop = $this->createShop();
        $firstOrder = $this->createOrder($shop, $this->sourcePayload('-9'));
        $secondOrder = $this->createOrder($shop, $this->sourcePayload('-10'));

        $this->artisan('order-sources:backfill', [
            '--ids' => (string) $firstOrder->id,
            '--execute' => true,
        ])->assertSuccessful();

        $this->assertSame('-9', $firstOrder->fresh()->pancake_order_source_id);
        $this->assertNull($secondOrder->fresh()->pancake_order_source_id);
    }

    public function test_historical_source_missing_from_catalog_still_backfills(): void
    {
        $shop = $this->createShop();
        $order = $this->createOrder($shop, $this->sourcePayload('historical-source'));

        $result = $this->service()->run(execute: true);

        $this->assertSame(1, $result['summary']['catalog_missing']);
        $this->assertSame(1, $result['summary']['backfilled']);
        $this->assertSame('historical-source', $order->fresh()->pancake_order_source_id);
    }

    public function test_catalog_match_is_audit_only(): void
    {
        $shop = $this->createShop();
        PancakeOrderSource::create([
            'shop_id' => $shop->id,
            'external_source_id' => '-9',
            'name' => 'Current Catalog Name',
        ]);
        $order = $this->createOrder($shop, [
            'order_sources' => '-9',
            'order_sources_name' => 'Historical Snapshot Name',
        ]);

        $result = $this->service()->run(execute: true);

        $this->assertSame(1, $result['summary']['catalog_matched']);
        $this->assertSame('Historical Snapshot Name', $order->fresh()->pancake_order_source_name);
    }

    private function service(): OrderSourceBackfillService
    {
        return app(OrderSourceBackfillService::class);
    }

    private function sourcePayload(string $sourceId): array
    {
        return [
            'order_sources' => $sourceId,
            'order_sources_name' => 'Historical name',
        ];
    }

    private function createShop(string $pancakeShopId = 'PAN-SHOP-1'): Shop
    {
        return Shop::create([
            'pancake_shop_id' => $pancakeShopId,
            'name' => $pancakeShopId,
            'api_key' => 'not-used-by-backfill',
        ]);
    }

    private function createOrder(
        Shop $shop,
        mixed $payload,
        ?string $sourceId = null,
        ?string $sourceName = null
    ): Order {
        return Order::create([
            'shop_id' => $shop->id,
            'pancake_order_id' => 'PAN-ORDER-'.str()->uuid(),
            'pancake_order_source_id' => $sourceId,
            'pancake_order_source_name' => $sourceName,
            'pancake_full_data' => $payload,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_shop_id');
            $table->string('name');
            $table->string('api_key');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_order_id');
            $table->string('pancake_order_source_id')->nullable();
            $table->string('pancake_order_source_name')->nullable();
            $table->json('pancake_full_data')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        $catalogMigration = require database_path(
            'migrations/2026_09_12_000000_create_pancake_order_sources_table.php'
        );
        $catalogMigration->up();

        $activityMigration = require database_path(
            'migrations/2026_09_12_000002_add_activity_state_to_pancake_order_sources_table.php'
        );
        $activityMigration->up();
    }
}
