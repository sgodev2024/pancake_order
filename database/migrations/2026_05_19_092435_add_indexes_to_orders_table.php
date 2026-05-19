<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['shop_id', 'created_at']);
        
            // Filter theo status (thường query cùng shop_id)
            $table->index(['shop_id', 'status', 'created_at']);
            
            // Non-admin filter
            $table->index('user_creator_id');
            $table->index('user_care_id');
            $table->index('user_assigning_seller_id');
            // Search
            $table->index('customer_phone');
            $table->index('customer_name');
            $table->index('order_number_vtp');
            $table->index('pancake_order_id');
            $table->index("pancake_customer_id");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'created_at']);
            $table->dropIndex(['shop_id', 'status', 'created_at']);
            $table->dropIndex(['user_creator_id']);
            $table->dropIndex(['user_care_id']);
            $table->dropIndex(['customer_phone']);
            $table->dropIndex(['customer_name']);
            $table->dropIndex(['order_number_vtp']);
            $table->dropIndex(['pancake_order_id']);
            $table->dropIndex(['received_at_shop']);
            $table->dropIndex(['pancake_customer_id']);
        });
    }
};
