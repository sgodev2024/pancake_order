<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // These mirror the two common optional filters used by the order
            // list and preserve created_at for its newest-first pagination.
            // The existing shorter page index remains useful for lookups that
            // do not sort by time.
            $table->index(
                ['shop_id', 'pancake_order_page_id', 'created_at'],
                'orders_shop_page_created_at_index'
            );
            $table->index(
                ['shop_id', 'received_at_shop', 'created_at'],
                'orders_shop_received_created_at_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_shop_page_created_at_index');
            $table->dropIndex('orders_shop_received_created_at_index');
        });
    }
};
