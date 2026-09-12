<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderPageSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'order_page_schema_testing',
            'database.connections.order_page_schema_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('order_page_schema_testing');
        DB::setDefaultConnection('order_page_schema_testing');

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pancake_order_source_name')->nullable();
            $table->timestamps();
        });

        $migration = require database_path(
            'migrations/2026_09_12_000004_add_pancake_order_page_to_orders_table.php'
        );
        $migration->up();
    }

    public function test_migration_adds_nullable_page_snapshot_and_composite_index_without_foreign_key(): void
    {
        $this->assertTrue(Schema::hasColumns('orders', [
            'pancake_order_page_id',
            'pancake_order_page_name',
        ]));

        $indexes = collect(DB::select("PRAGMA index_list('orders')"));
        $this->assertTrue(
            $indexes->contains(fn ($index): bool => $index->name === 'orders_shop_page_index')
        );

        $indexColumns = collect(DB::select("PRAGMA index_info('orders_shop_page_index')"))
            ->pluck('name')
            ->all();
        $this->assertSame(['shop_id', 'pancake_order_page_id'], $indexColumns);
        $this->assertSame([], DB::select("PRAGMA foreign_key_list('orders')"));
    }
}
