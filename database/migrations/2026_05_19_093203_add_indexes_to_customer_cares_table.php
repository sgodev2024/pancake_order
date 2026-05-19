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
        Schema::table('customer_cares', function (Blueprint $table) {
            $table->string("customer_phones", 1000)->nullable()->change();
            // Sort + filter chính (date_care dùng cho cả ORDER BY lẫn WHERE)
            $table->index(['shop_id', 'date_care']);
            $table->index(['shop_id', 'status', 'date_care']);
            
            // Filter theo user (3 loại user)
            $table->index('user_creator_id');
            $table->index('user_care_id');
            $table->index('user_assigning_seller_id');
            // Filter đơn lẻ
            $table->index('is_accept');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_cares', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'date_care']);
            $table->dropIndex(['shop_id', 'status', 'date_care']);
            $table->dropIndex(['user_creator_id']);
            $table->dropIndex(['user_care_id']);
            $table->dropIndex(['user_assigning_seller_id']);
            $table->dropIndex(['is_accept']);
            $table->dropIndex(['status']);
        });
    }
};
