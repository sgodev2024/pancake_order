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
            $table->boolean("is_accept")->default(1)->after("status");
            $table->integer("user_accept_id")->nullable()->after("is_accept");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_cares', function (Blueprint $table) {
            $table->dropColumn([
                "is_accept",
                "user_accept_id"
            ]);
        });
    }
};
