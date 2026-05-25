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
            $table->unsignedBigInteger('shop_id')->change();

            $table->foreign('shop_id')
                ->references('id')
                ->on('shops')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_cares', function (Blueprint $table) {
            $table->dropForeign(['shop_id']);
            $table->integer('shop_id')->change();
        });
    }
};
