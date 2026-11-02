<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_order_summaries', function (Blueprint $table) {
            $table->unsignedInteger('delivered_vtp_count')->default(0);
            $table->decimal('delivered_vtp_quantity', 18, 2)->default(0);
        });
        Schema::table('daily_product_sales_summaries', function (Blueprint $table) {
            // NULL allows old rows to coexist until the controlled rebuild.
            $table->string('product_key', 64)->nullable();
            $table->string('product_id', 128)->nullable();
            $table->string('variation_id', 128)->nullable();
            $table->decimal('revenue', 24, 4)->default(0)->change();
            $table->unique(['report_date', 'shop_id', 'status', 'has_vtp', 'product_key'], 'product_summary_identity');
        });
        Schema::create('order_summary_state', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('schema_version')->default(2);
            $table->boolean('ready')->default(false);
            $table->timestamps();
        });
        Schema::create('order_summary_contributions', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->primary();
            $table->unsignedBigInteger('shop_id');
            $table->date('report_date');
            $table->timestamp('source_created_at');
            $table->boolean('delivered_vtp');
            $table->string('fingerprint', 64);
            $table->json('contribution');
            $table->timestamps();
            $table->index('source_created_at', 'summary_latest_order');
            $table->index(['delivered_vtp', 'source_created_at'], 'summary_latest_delivered');
            $table->index(['shop_id', 'delivered_vtp', 'source_created_at'], 'summary_shop_latest');
            $table->index('report_date');
        });
        Schema::create('order_summary_pending', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->primary();
            $table->timestamps();
        });
        DB::table('order_summary_state')->insert([
            'id' => 1, 'schema_version' => 2, 'ready' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_summary_pending');
        Schema::dropIfExists('order_summary_contributions');
        Schema::dropIfExists('order_summary_state');
        Schema::table('daily_product_sales_summaries', function (Blueprint $table) {
            $table->dropUnique('product_summary_identity');
            $table->dropColumn(['product_key', 'product_id', 'variation_id']);
        });
        Schema::table('daily_order_summaries', function (Blueprint $table) {
            $table->dropColumn(['delivered_vtp_count', 'delivered_vtp_quantity']);
        });
    }
};
