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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')
                    ->constrained()
                    ->onDelete('cascade');
            $table->string("pancake_customer_id");
            $table->unique("pancake_customer_id");
            $table->string("fb_id")->nullable();
            $table->string("name")->nullable();
            $table->json("phone_numbers")->nullable();
            $table->json("pancake_full_data")->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
