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
        Schema::create('shop_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')
                    ->constrained()
                    ->onDelete('cascade');
            $table->foreignId('user_id')
                    ->constrained()
                    ->onDelete('cascade');
            $table->boolean("is_manager")->default(false);
            $table->unique(['user_id', 'shop_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_users');
    }
};
