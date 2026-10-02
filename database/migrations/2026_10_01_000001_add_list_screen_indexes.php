<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // The single customer ID index exists already; this also serves the
            // history query's newest-first ordering.
            $table->index(['pancake_customer_id', 'created_at'], 'orders_customer_created_at_index');
        });

        Schema::table('customers', function (Blueprint $table): void {
            // Complements the existing (shop_id, created_at) index when a tier
            // filter is applied on the customer list.
            $table->index(['shop_id', 'loyalty_tier_id', 'created_at'], 'customers_shop_tier_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('customers_shop_tier_created_at_index');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_customer_created_at_index');
        });
    }
};
