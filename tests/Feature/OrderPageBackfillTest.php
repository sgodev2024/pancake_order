<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PancakeOrderSource;
use App\Models\Shop;
use App\Services\OrderPageBackfillService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderPageBackfillTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'order_page_backfill_testing',
            'database.connections.order_page_backfill_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('order_page_backfill_testing');
        DB::setDefaultConnection('order_page_backfill_testing');
        $this->createSchema();
    }

    public function test_preview_reports_all_classifications_and_never_writes(): void
    {
        $shop = $this->createShop();
        PancakeOrderSource::create([
            'shop_id' => $shop->id,
            'external_source_id' => 'page-match',
            'name' => 'Current catalog name',
        ]);
        $full = $this->createOrder($shop, [
            'page_id' => 'page-match',
            'page' => ['name' => 'Historical snapshot name'],
        ]);
        $partial = $this->createOrder($shop, [
            'page_id' => 'page-missing',
            'page' => ['name' => '   '],
        ]);
        $noPage = $this->createOrder($shop, [
            'order_sources_name' => 'Facebook',
            'ads_source' => 'must-not-fallback',
        ]);
        $invalid = $this->createOrder($shop, 'not-an-object');

        $result = $this->service()->run();

        $this->assertSame(4, $result['summary']['scanned']);
        $this->assertSame(2, $result['summary']['would_backfill']);
        $this->assertSame(1, $result['summary']['partial_page']);
        $this->assertSame(1, $result['summary']['no_page']);
        $this->assertSame(1, $result['summary']['invalid_payload']);
        $this->assertSame(1, $result['summary']['catalog_matched']);
        $this->assertSame(1, $result['summary']['catalog_missing']);
        $this->assertSame(0, $result['summary']['backfilled']);

        foreach ([$full, $partial, $noPage, $invalid] as $order) {
            $this->assertNull($order->fresh()->pancake_order_page_id);
            $this->assertNull($order->fresh()->pancake_order_page_name);
        }
    }

    public function test_preview_command_reports_without_writing(): void
    {
        $shop = $this->createShop();
        $order = $this->createOrder($shop, [
            'page_id' => 'page-1',
            'page' => ['name' => 'Page One'],
        ]);

        $this->artisan('order-pages:backfill', ['--shop-id' => (string) $shop->id])
            ->expectsOutputToContain('PREVIEW ONLY')
            ->expectsOutputToContain('Would backfill')
            ->assertSuccessful();

        $this->assertNull($order->fresh()->pancake_order_page_id);
        $this->assertNull($order->fresh()->pancake_order_page_name);
    }

    public function test_execute_backfills_full_and_partial_pages_including_soft_deleted_orders(): void
    {
        $shop = $this->createShop();
        $full = $this->createOrder($shop, [
            'page_id' => 123456789,
            'page' => ['name' => '  Historical Page  '],
            'other_payload_data' => ['must' => 'remain'],
        ], '-1', 'Facebook');
        $idOnly = $this->createOrder($shop, [
            'page' => ['id' => 'page-id-only'],
        ]);
        $nameOnly = $this->createOrder($shop, [
            'account_name' => ' Account only ',
        ]);
        $softDeleted = $this->createOrder($shop, [
            'account' => 'pzl_695112902870160686',
            'account_name' => 'Zalo Account',
        ]);
        $softDeleted->delete();

        $rawPayload = $full->getRawOriginal('pancake_full_data');
        $updatedAt = $full->getRawOriginal('updated_at');
        $result = $this->service()->run(execute: true);

        $this->assertSame(4, $result['summary']['would_backfill']);
        $this->assertSame(2, $result['summary']['partial_page']);
        $this->assertSame(4, $result['summary']['backfilled']);

        $full->refresh();
        $this->assertSame('123456789', $full->pancake_order_page_id);
        $this->assertSame('Historical Page', $full->pancake_order_page_name);
        $this->assertSame('-1', $full->pancake_order_source_id);
        $this->assertSame('Facebook', $full->pancake_order_source_name);
        $this->assertSame($rawPayload, $full->getRawOriginal('pancake_full_data'));
        $this->assertSame($updatedAt, $full->getRawOriginal('updated_at'));

        $this->assertSame('page-id-only', $idOnly->fresh()->pancake_order_page_id);
        $this->assertNull($idOnly->fresh()->pancake_order_page_name);
        $this->assertNull($nameOnly->fresh()->pancake_order_page_id);
        $this->assertSame('Account only', $nameOnly->fresh()->pancake_order_page_name);
        $this->assertSame(
            'pzl_695112902870160686',
            Order::withTrashed()->findOrFail($softDeleted->id)->pancake_order_page_id
        );
    }

    public function test_execute_is_idempotent(): void
    {
        $shop = $this->createShop();
        $order = $this->createOrder($shop, [
            'page_id' => 'page-1',
            'page' => ['name' => 'Page One'],
        ]);

        $first = $this->service()->run(execute: true);
        $second = $this->service()->run(execute: true);

        $this->assertSame(1, $first['summary']['backfilled']);
        $this->assertSame(0, $second['summary']['backfilled']);
        $this->assertSame(0, $second['summary']['would_backfill']);
        $this->assertSame(1, $second['summary']['already_populated']);
        $this->assertSame('page-1', $order->fresh()->pancake_order_page_id);
    }

    public function test_existing_values_are_never_overwritten_and_conflicts_are_separate(): void
    {
        $shop = $this->createShop();
        $same = $this->createOrder(
            $shop,
            ['page_id' => 'page-1', 'page' => ['name' => 'Page One']],
            pageId: 'page-1',
            pageName: 'Page One'
        );
        $conflict = $this->createOrder(
            $shop,
            ['page_id' => 'raw-page', 'page' => ['name' => 'Raw Page']],
            pageId: 'stored-page',
            pageName: 'Stored Page'
        );
        $partiallyPopulated = $this->createOrder(
            $shop,
            ['page_id' => 'page-2', 'page' => ['name' => 'Must not be filled']],
            pageId: 'page-2'
        );

        $result = $this->service()->run(execute: true);

        $this->assertSame(2, $result['summary']['already_populated']);
        $this->assertSame(1, $result['summary']['conflicts']);
        $this->assertSame(0, $result['summary']['backfilled']);
        $this->assertSame('Page One', $same->fresh()->pancake_order_page_name);
        $this->assertSame('stored-page', $conflict->fresh()->pancake_order_page_id);
        $this->assertSame('Stored Page', $conflict->fresh()->pancake_order_page_name);
        $this->assertNull($partiallyPopulated->fresh()->pancake_order_page_name);
    }

    public function test_catalog_missing_does_not_block_backfill_or_rewrite_snapshot_name(): void
    {
        $shop = $this->createShop();
        $order = $this->createOrder($shop, [
            'page_id' => 'historical-page',
            'page' => ['name' => 'Historical Snapshot Name'],
        ]);

        $result = $this->service()->run(execute: true);

        $this->assertSame(1, $result['summary']['catalog_missing']);
        $this->assertSame(1, $result['summary']['backfilled']);
        $this->assertSame('Historical Snapshot Name', $order->fresh()->pancake_order_page_name);
    }

    public function test_shop_and_id_scopes_are_enforced_and_missing_ids_are_reported(): void
    {
        $firstShop = $this->createShop('PAN-SHOP-1');
        $secondShop = $this->createShop('PAN-SHOP-2');
        $first = $this->createOrder($firstShop, ['page_id' => 'first']);
        $second = $this->createOrder($secondShop, ['page_id' => 'second']);

        $result = $this->service()->run(
            shopId: $firstShop->id,
            ids: [$first->id, $second->id, 999999],
            execute: true
        );

        $this->assertSame(1, $result['summary']['scanned']);
        $this->assertSame(2, $result['summary']['not_found']);
        $this->assertSame('first', $first->fresh()->pancake_order_page_id);
        $this->assertNull($second->fresh()->pancake_order_page_id);
    }

    private function service(): OrderPageBackfillService
    {
        return app(OrderPageBackfillService::class);
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
        ?string $sourceName = null,
        ?string $pageId = null,
        ?string $pageName = null
    ): Order {
        return Order::create([
            'shop_id' => $shop->id,
            'pancake_order_id' => 'PAN-ORDER-'.str()->uuid(),
            'pancake_order_source_id' => $sourceId,
            'pancake_order_source_name' => $sourceName,
            'pancake_order_page_id' => $pageId,
            'pancake_order_page_name' => $pageName,
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
            $table->string('pancake_order_page_id')->nullable();
            $table->string('pancake_order_page_name')->nullable();
            $table->json('pancake_full_data')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        $catalogMigration = require database_path(
            'migrations/2026_09_12_000000_create_pancake_order_sources_table.php'
        );
        $catalogMigration->up();
    }
}
