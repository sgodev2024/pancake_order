<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve every upstream ID and existing amount. Local records have no upstream ID.
        Schema::table('orders', function (Blueprint $table) {
            $table->string('pancake_order_id')->nullable()->change();
            $table->decimal('cod', 18, 2)->default(0)->change();
            $table->decimal('cash', 18, 2)->default(0)->change();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('origin', 20)->nullable();
            $table->uuid('idempotency_key')->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->string('warehouse_status', 32)->nullable();
            $table->timestamp('warehouse_queued_at')->nullable();
            $table->unique(['created_by_user_id', 'idempotency_key'], 'orders_pos_actor_request_unique');
            $table->index(['shop_id', 'warehouse_queued_at', 'warehouse_status'], 'orders_warehouse_queue_index');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->string('pancake_customer_id')->nullable()->change();
        });
        Schema::table('products', function (Blueprint $table) {
            // Nullable until first POS sale. Sync only updates the upstream JSON, never this counter.
            $table->unsignedBigInteger('virtual_stock')->nullable();
        });
    }

    public function down(): void
    {
        // Refuse to discard live POS records, counters, or fractional money on rollback.
        throw new RuntimeException('POS migration is forward-only; restore an approved backup instead of discarding sales data.');
    }
};
