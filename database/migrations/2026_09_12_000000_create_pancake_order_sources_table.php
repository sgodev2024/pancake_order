<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pancake_order_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('external_source_id');
            $table->string('name');
            $table->string('parent_external_source_id')->nullable();
            $table->string('link')->nullable();
            $table->timestamp('source_inserted_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['shop_id', 'external_source_id'],
                'pancake_order_sources_shop_source_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pancake_order_sources');
    }
};
