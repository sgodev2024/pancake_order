<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PancakeOrderSource;
use App\Models\Shop;
use App\Services\PancakeOrderSourceSyncException;
use App\Services\PancakeOrderSourceSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PancakeOrderSourceSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Ho_Chi_Minh',
            'services.pancake.api_v1_url' => 'https://pos.pages.fm/api/v1/',
            'database.default' => 'order_source_sync_testing',
            'database.connections.order_source_sync_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('order_source_sync_testing');
        DB::setDefaultConnection('order_source_sync_testing');
        $this->createSchema();
        CarbonImmutable::setTestNow('2026-09-12 12:00:00');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_sync_creates_sources_and_maps_normalized_ids_parent_and_fields(): void
    {
        $shop = $this->createShop();
        $this->fakeCatalog([
            $this->source(-9, '  TikTok  ', parentId: -1),
            $this->source('string-source', ' Website ', parentId: 'parent-string'),
        ]);

        $summary = $this->service()->sync($shop, true);

        $this->assertSame(2, $summary['received']);
        $this->assertSame(2, $summary['created']);
        $this->assertSame(0, $summary['updated']);
        $this->assertSame(2, PancakeOrderSource::query()->count());

        $integerSource = PancakeOrderSource::query()
            ->where('external_source_id', '-9')
            ->sole();
        $stringSource = PancakeOrderSource::query()
            ->where('external_source_id', 'string-source')
            ->sole();

        $this->assertSame('-9', $integerSource->external_source_id);
        $this->assertSame('TikTok', $integerSource->name);
        $this->assertSame('-1', $integerSource->parent_external_source_id);
        $this->assertSame('parent-string', $stringSource->parent_external_source_id);
        $this->assertSame('https://example.test/source', $integerSource->link);
        $this->assertSame('2026-09-01 08:00:00', $integerSource->source_inserted_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-02 09:00:00', $integerSource->source_updated_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-12 12:00:00', $integerSource->synced_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-12 12:00:00', $integerSource->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertTrue($integerSource->is_active);

        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://pos.pages.fm/api/v1/shops/PAN-SHOP-1/order_source'
        ) && $request['api_key'] === 'shop-secret');
    }

    public function test_second_sync_is_idempotent_without_duplicate(): void
    {
        $shop = $this->createShop();
        $this->fakeCatalog([$this->source('-9', 'TikTok')]);

        $this->service()->sync($shop, true);
        CarbonImmutable::setTestNow('2026-09-12 13:00:00');
        $summary = $this->service()->sync($shop, true);

        $this->assertSame(1, PancakeOrderSource::query()->count());
        $this->assertSame(0, $summary['created']);
        $this->assertSame(0, $summary['updated']);
        $this->assertSame(1, $summary['unchanged']);
        $this->assertSame(
            '2026-09-12 13:00:00',
            PancakeOrderSource::query()->sole()->last_seen_at->format('Y-m-d H:i:s')
        );
    }

    public function test_rename_updates_catalog_without_rewriting_order_snapshot(): void
    {
        $shop = $this->createShop();
        Order::create([
            'shop_id' => $shop->id,
            'pancake_order_id' => "PAN-ORDER-1_{$shop->id}",
            'pancake_order_source_id' => '-9',
            'pancake_order_source_name' => 'Tiktok',
        ]);
        Http::fakeSequence()
            ->push([
                'success' => true,
                'data' => [$this->source('-9', 'Tiktok')],
            ])
            ->push([
                'success' => true,
                'data' => [$this->source('-9', 'TikTok')],
            ]);
        $this->service()->sync($shop, true);

        $summary = $this->service()->sync($shop, true);

        $this->assertSame(1, $summary['updated']);
        $this->assertSame('TikTok', PancakeOrderSource::query()->sole()->name);
        $this->assertSame('Tiktok', Order::query()->sole()->pancake_order_source_name);
    }

    public function test_same_external_source_id_is_isolated_by_local_shop(): void
    {
        $firstShop = $this->createShop('PAN-SHOP-1', 'first-secret');
        $secondShop = $this->createShop('PAN-SHOP-2', 'second-secret');
        $this->fakeCatalog([$this->source('-9', 'TikTok')]);

        $this->service()->sync($firstShop, true);
        $this->service()->sync($secondShop, true);

        $this->assertSame(2, PancakeOrderSource::query()->count());
        $this->assertSame(
            [$firstShop->id, $secondShop->id],
            PancakeOrderSource::query()->orderBy('shop_id')->pluck('shop_id')->all()
        );
    }

    public function test_missing_remote_source_is_deactivated_without_delete(): void
    {
        $shop = $this->createShop();
        Http::fakeSequence()
            ->push([
                'success' => true,
                'data' => [
                    $this->source('-9', 'TikTok'),
                    $this->source('-10', 'Website'),
                ],
            ])
            ->push([
                'success' => true,
                'data' => [$this->source('-9', 'TikTok')],
            ]);
        $this->service()->sync($shop, true);

        CarbonImmutable::setTestNow('2026-09-13 12:00:00');
        $summary = $this->service()->sync($shop, true);

        $missingSource = PancakeOrderSource::query()
            ->where('external_source_id', '-10')
            ->sole();

        $this->assertSame(1, $summary['deactivated']);
        $this->assertSame(2, PancakeOrderSource::query()->count());
        $this->assertFalse($missingSource->is_active);
        $this->assertSame('2026-09-12 12:00:00', $missingSource->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-13 12:00:00', $missingSource->synced_at->format('Y-m-d H:i:s'));
    }

    public function test_api_failure_does_not_mutate_catalog(): void
    {
        $shop = $this->createShop();
        $source = $this->existingSource($shop, 'Original');
        Http::fake(['*' => Http::response(['message' => 'failure'], 503)]);

        try {
            $this->service()->sync($shop, true);
            $this->fail('Expected a sanitized sync exception.');
        } catch (PancakeOrderSourceSyncException $exception) {
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
        }

        $source->refresh();
        $this->assertSame('Original', $source->name);
        $this->assertTrue($source->is_active);
        $this->assertSame('2026-09-01 12:00:00', $source->synced_at->format('Y-m-d H:i:s'));
    }

    public function test_success_false_and_malformed_response_do_not_mutate_catalog(): void
    {
        $shop = $this->createShop();
        $source = $this->existingSource($shop, 'Original');

        foreach ([
            ['success' => false, 'data' => []],
            ['success' => true, 'data' => [$this->source('-10', 'Valid'), 'invalid-row']],
        ] as $payload) {
            Http::fake(['*' => Http::response($payload)]);

            try {
                $this->service()->sync($shop, true);
                $this->fail('Expected a sanitized sync exception.');
            } catch (PancakeOrderSourceSyncException) {
                $source->refresh();
                $this->assertSame('Original', $source->name);
                $this->assertTrue($source->is_active);
                $this->assertSame(1, PancakeOrderSource::query()->count());
            }
        }
    }

    public function test_credential_is_not_exposed_in_error_command_output_or_logs(): void
    {
        $secret = 'never-expose-this-api-key';
        $shop = $this->createShop('PAN-SHOP-1', $secret);
        Log::spy();
        Http::fake(function () use ($secret) {
            throw new ConnectionException("Connection failed for api_key={$secret}");
        });

        $exitCode = Artisan::call('order-sources:sync', [
            '--shop-id' => (string) $shop->id,
            '--execute' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('request failed', $output);
        $this->assertStringNotContainsString($secret, $output);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('info');
    }

    public function test_command_defaults_to_preview_without_writing(): void
    {
        $shop = $this->createShop();
        $this->fakeCatalog([$this->source('-9', 'TikTok')]);

        $this->artisan('order-sources:sync', ['--shop-id' => (string) $shop->id])
            ->expectsOutputToContain('PREVIEW ONLY')
            ->expectsOutputToContain('Would create')
            ->assertSuccessful();

        $this->assertSame(0, PancakeOrderSource::query()->count());
    }

    public function test_command_execute_is_required_to_write(): void
    {
        $shop = $this->createShop();
        $this->fakeCatalog([$this->source('-9', 'TikTok')]);

        $this->artisan('order-sources:sync', [
            '--shop-id' => (string) $shop->id,
            '--execute' => true,
        ])
            ->expectsOutputToContain('EXECUTE MODE')
            ->expectsOutputToContain('Created')
            ->assertSuccessful();

        $this->assertDatabaseHas('pancake_order_sources', [
            'shop_id' => $shop->id,
            'external_source_id' => '-9',
            'name' => 'TikTok',
            'is_active' => true,
        ]);
    }

    private function service(): PancakeOrderSourceSyncService
    {
        return app(PancakeOrderSourceSyncService::class);
    }

    private function fakeCatalog(array $sources): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => true,
                'data' => $sources,
            ]),
        ]);
    }

    private function source(
        int|string $id,
        string $name,
        int|string|null $parentId = null
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'parent_id' => $parentId,
            'link' => 'https://example.test/source',
            'inserted_at' => '2026-09-01T01:00:00Z',
            'updated_at' => '2026-09-02T02:00:00Z',
        ];
    }

    private function createShop(
        string $pancakeShopId = 'PAN-SHOP-1',
        string $apiKey = 'shop-secret'
    ): Shop {
        return Shop::create([
            'pancake_shop_id' => $pancakeShopId,
            'name' => $pancakeShopId,
            'api_key' => $apiKey,
        ]);
    }

    private function existingSource(Shop $shop, string $name): PancakeOrderSource
    {
        return PancakeOrderSource::create([
            'shop_id' => $shop->id,
            'external_source_id' => '-9',
            'name' => $name,
            'is_active' => true,
            'last_seen_at' => '2026-09-01 12:00:00',
            'synced_at' => '2026-09-01 12:00:00',
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_shop_id')->nullable();
            $table->string('name');
            $table->string('api_key')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_order_id');
            $table->string('pancake_order_source_id')->nullable()->index();
            $table->string('pancake_order_source_name')->nullable();
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
