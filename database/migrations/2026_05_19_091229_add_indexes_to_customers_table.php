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
            $table->string("phone_numbers")->nullable()->change();
            $table->index(['shop_id', 'created_at']);
            $table->index(['assigned_user_id', 'created_at']);
            $table->index("name");
            $table->index("phone_numbers");
            $table->index("pancake_customer_id");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'created_at']);
            $table->dropIndex(['assigned_user_id', 'created_at']);
            $table->dropIndex(['name']);
            $table->dropIndex(['phone_numbers']);
            $table->dropIndex(['pancake_customer_id']);
        });
    }
};
