<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pancake_order_sources', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('synced_at');
            $table->timestamp('last_seen_at')->nullable()->after('is_active');
            $table->index(
                ['shop_id', 'is_active'],
                'pancake_order_sources_shop_active_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('pancake_order_sources', function (Blueprint $table) {
            $table->dropIndex('pancake_order_sources_shop_active_index');
            $table->dropColumn(['is_active', 'last_seen_at']);
        });
    }
};
