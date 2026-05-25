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
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('loyalty_tier_id')->nullable()->after("id");
            $table->double("purchased_amount", 15, 2)->nullable()->after("loyalty_tier_id");
            $table->foreign('loyalty_tier_id')
                  ->references('id')
                  ->on('loyalty_tiers')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn("purchased_amount");
            $table->dropForeign(['loyalty_tier_id']);
            $table->dropColumn("loyalty_tier_id");
        });
    }
};
