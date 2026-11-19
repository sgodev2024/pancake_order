<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Thêm index updated_at cho orders
        Schema::table('orders', function (Blueprint $table) {
            $table->index('updated_at', 'orders_updated_at_index');
        });

        // 2. Bảng tổng hợp đơn hàng theo ngày & shop
        Schema::create('daily_order_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('report_date');
            $table->unsignedBigInteger('shop_id')->default(0); // 0 = toàn hệ thống
            $table->unsignedInteger('total_items')->default(0);
            $table->decimal('total_revenue', 20, 2)->default(0);
            $table->decimal('total_prepaid_amount', 20, 2)->default(0);
            $table->unsignedInteger('shipping_count')->default(0);
            $table->decimal('shipping_amount', 20, 2)->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->decimal('success_amount', 20, 2)->default(0);
            $table->unsignedInteger('cancel_count')->default(0);
            $table->decimal('cancel_amount', 20, 2)->default(0);
            $table->unsignedInteger('return_count')->default(0);
            $table->decimal('return_amount', 20, 2)->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->decimal('pending_amount', 20, 2)->default(0);
            $table->timestamps();

            $table->unique(['report_date', 'shop_id'], 'uniq_report_date_shop');
            $table->index('report_date', 'idx_report_date');
            $table->index('shop_id', 'idx_shop_id');
        });

        // 3. Bảng tổng hợp doanh số sản phẩm theo ngày & shop & trạng thái
        Schema::create('daily_product_sales_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('report_date');
            $table->unsignedBigInteger('shop_id');
            $table->integer('status')->default(0);
            $table->tinyInteger('has_vtp')->default(1);
            $table->string('product_name', 255);
            $table->decimal('quantity', 12, 2)->default(0);
            $table->decimal('revenue', 20, 2)->default(0);
            $table->unsignedInteger('orders_count')->default(0);
            $table->timestamps();

            $table->index(['report_date', 'shop_id', 'status'], 'idx_prod_date_shop_status');
            $table->index(['product_name', 'report_date'], 'idx_prod_name_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_product_sales_summaries');
        Schema::dropIfExists('daily_order_summaries');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_updated_at_index');
        });
    }
};
