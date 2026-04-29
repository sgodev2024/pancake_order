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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')
                    ->constrained()
                    ->onDelete('cascade');
            $table->string("pancake_order_id");
            $table->unique("pancake_order_id");
            $table->string("order_number_vtp")->nullable();
            $table->integer("total_quantity")->default(0);
            $table->integer("cod")->default(0);
            $table->integer("cash")->default(0);
            $table->longText("note")->nullable();
            $table->string('user_creator_id')->nullable();
            $table->string('user_care_id')->nullable();
            $table->string('user_assigning_seller_id')->nullable();
            $table->string('pancake_customer_id')->nullable();
            $table->integer("status")->default(0);
            $table->integer("status_vtp")->nullable();
            $table->json("pancake_full_data")->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
