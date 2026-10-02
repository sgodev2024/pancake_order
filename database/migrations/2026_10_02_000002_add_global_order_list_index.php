<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // The default Order screen spans shops and sorts newest first.
            // Shop-prefixed indexes cannot serve that query, causing a full
            // table sort even for the first 30 records.
            $table->index(
                ['deleted_at', 'created_at'],
                'orders_deleted_created_at_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_deleted_created_at_index');
        });
    }
};
