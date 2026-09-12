<?php

namespace Tests\Feature;

use App\Models\PancakeOrderSource;
use App\Models\Shop;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PancakeOrderSourceSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'order_source_schema_testing',
            'database.connections.order_source_schema_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('order_source_schema_testing');
        DB::setDefaultConnection('order_source_schema_testing');

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
            $table->timestamps();
        });

        $catalogMigration = require database_path(
            'migrations/2026_09_12_000000_create_pancake_order_sources_table.php'
        );
        $catalogMigration->up();

        $orderMigration = require database_path(
            'migrations/2026_09_12_000001_add_pancake_order_source_to_orders_table.php'
        );
        $orderMigration->up();

        $filterIndexMigration = require database_path(
            'migrations/2026_09_12_000003_add_order_source_filter_index_to_orders_table.php'
        );
        $filterIndexMigration->up();
    }

    public function test_migrations_create_catalog_and_indexed_order_snapshot_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('pancake_order_sources', [
            'id',
            'shop_id',
            'external_source_id',
            'name',
            'parent_external_source_id',
            'link',
            'source_inserted_at',
            'source_updated_at',
            'synced_at',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('orders', [
            'pancake_order_source_id',
            'pancake_order_source_name',
        ]));

        $indexes = collect(DB::select("PRAGMA index_list('orders')"));

        $this->assertTrue(
            $indexes->contains(
                fn ($index): bool => $index->name === 'orders_pancake_order_source_id_index'
            )
        );
        $this->assertTrue(
            $indexes->contains(
                fn ($index): bool => $index->name === 'orders_shop_source_created_at_index'
            )
        );
    }

    public function test_same_shop_and_external_source_id_must_be_unique(): void
    {
        $shop = $this->createShop('PAN-SHOP-1');

        PancakeOrderSource::create([
            'shop_id' => $shop->id,
            'external_source_id' => '-9',
            'name' => 'TikTok',
        ]);

        $this->expectException(QueryException::class);

        PancakeOrderSource::create([
            'shop_id' => $shop->id,
            'external_source_id' => '-9',
            'name' => 'Renamed TikTok',
        ]);
    }

    public function test_same_external_source_id_is_valid_for_different_shops(): void
    {
        $firstShop = $this->createShop('PAN-SHOP-1');
        $secondShop = $this->createShop('PAN-SHOP-2');

        foreach ([$firstShop, $secondShop] as $shop) {
            PancakeOrderSource::create([
                'shop_id' => $shop->id,
                'external_source_id' => '-9',
                'name' => 'TikTok',
            ]);
        }

        $this->assertSame(2, PancakeOrderSource::query()->count());
        $this->assertSame(
            [$firstShop->id, $secondShop->id],
            PancakeOrderSource::query()->orderBy('shop_id')->pluck('shop_id')->all()
        );
    }

    private function createShop(string $pancakeShopId): Shop
    {
        return Shop::create([
            'pancake_shop_id' => $pancakeShopId,
            'name' => $pancakeShopId,
            'api_key' => 'test-key',
        ]);
    }
}
