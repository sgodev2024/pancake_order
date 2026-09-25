<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index(['shop_id', 'created_at'], 'products_shop_created_at_index');
            $table->index(['shop_id', 'name'], 'products_shop_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_shop_created_at_index');
            $table->dropIndex('products_shop_name_index');
        });
    }
};
