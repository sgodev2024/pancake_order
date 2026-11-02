<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['orders', 'permission_groups', 'permissions'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Missing prerequisite table: '.$table);
            }
        }
        if (! Schema::hasColumns('orders', ['shop_id', 'warehouse_status', 'warehouse_queued_at', 'origin'])) {
            throw new RuntimeException('Apply and verify PLAN 2 before the warehouse migration.');
        }
        $types = [
            'source_created_at' => ['timestamp', 'datetime'],
            'first_synced_at' => ['timestamp', 'datetime'],
            'source_creator_name' => ['varchar'],
            'packed_by_user_id' => ['bigint', 'integer'],
            'packed_at' => ['timestamp', 'datetime'],
        ];
        $existing = collect(Schema::getColumns('orders'))->keyBy('name');
        // MySQL DDL commits separately. Resume a partially completed migration without
        // creating duplicate columns or rewriting existing values; refuse schema drift.
        foreach ($types as $name => $allowed) {
            $column = $existing->get($name);
            if ($column && (! $column['nullable'] || ! in_array($column['type_name'], $allowed, true))) {
                throw new RuntimeException('Incompatible existing warehouse column: '.$name);
            }
        }
        $indexColumns = ['shop_id', 'warehouse_status', 'packed_at'];
        $indexes = collect(Schema::getIndexes('orders'));
        $namedIndex = $indexes->firstWhere('name', 'orders_warehouse_packed_index');
        if ($namedIndex && ($namedIndex['columns'] !== $indexColumns || $namedIndex['unique'])) {
            throw new RuntimeException('Incompatible orders_warehouse_packed_index definition.');
        }
        $missing = array_diff(array_keys($types), $existing->keys()->all());
        if ($missing !== []) {
            Schema::table('orders', function (Blueprint $table) use ($missing) {
                foreach ($missing as $name) {
                    match ($name) {
                        'source_creator_name' => $table->string($name)->nullable(),
                        'packed_by_user_id' => $table->unsignedBigInteger($name)->nullable(),
                        default => $table->timestamp($name)->nullable(),
                    };
                }
            });
        }
        if (! $indexes->contains(fn ($index) => $index['columns'] === $indexColumns && ! $index['unique'])) {
            Schema::table('orders', fn (Blueprint $table) => $table->index($indexColumns, 'orders_warehouse_packed_index'));
        }
        // Register catalog entries only. Never attach permissions to any active role/account.
        DB::transaction(function () {
            $group = DB::table('permission_groups')->where('name', 'Kho')->value('id');
            if ($group === null) {
                $group = DB::table('permission_groups')->insertGetId(['name' => 'Kho', 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (['warehouse-view' => 'Xem đơn Kho', 'warehouse-pack' => 'Hoàn tất đóng hàng'] as $slug => $name) {
                if (! DB::table('permissions')->where('slug', $slug)->exists()) {
                    DB::table('permissions')->insert([
                        'permission_group_id' => $group, 'slug' => $slug, 'name' => $name,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Warehouse history is retained. Do not discard packing evidence or permission assignments by rollback.');
    }
};
