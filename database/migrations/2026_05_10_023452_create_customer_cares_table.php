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
        Schema::create('customer_cares', function (Blueprint $table) {
            $table->id();
            $table->integer("shop_id");
            $table->string("pancake_customer_id");
            $table->string("pancake_order_id")->nullable();
            $table->json("customer_phones")->nullable();
            $table->string("customer_name")->nullable();
            $table->string("customer_addresss")->nullable();
            $table->date("date_care");
            $table->text("note")->nullable();
            $table->string("user_creator_id")->nullable();
            $table->string("user_care_id")->nullable();
            $table->string("user_assigning_seller_id")->nullable();
            $table->integer("status")->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_cares');
    }
};
