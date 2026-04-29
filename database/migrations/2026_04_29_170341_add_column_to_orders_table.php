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
            $table->boolean("received_at_shop")->default(false)->after("status_vtp");
            $table->string("customer_name")->nullable()->after("received_at_shop");
            $table->string("customer_phone")->nullable()->after("customer_name");
            $table->string("customer_address", 1000)->nullable()->after("customer_phone");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                "received_at_shop",
                "customer_name",
                "customer_phone",
                "customer_address"
            ]);
        });
    }
};
