<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('pancake_order_source_id')
                ->nullable()
                ->after('pancake_order_id')
                ->index();
            $table->string('pancake_order_source_name')
                ->nullable()
                ->after('pancake_order_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['pancake_order_source_id']);
            $table->dropColumn([
                'pancake_order_source_id',
                'pancake_order_source_name',
            ]);
        });
    }
};
