<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('pancake_order_page_id')
                ->nullable()
                ->after('pancake_order_source_name');
            $table->string('pancake_order_page_name')
                ->nullable()
                ->after('pancake_order_page_id');
            $table->index(
                ['shop_id', 'pancake_order_page_id'],
                'orders_shop_page_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_shop_page_index');
            $table->dropColumn([
                'pancake_order_page_id',
                'pancake_order_page_name',
            ]);
        });
    }
};
