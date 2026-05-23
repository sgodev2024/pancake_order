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
        Schema::create('customer_assigneds', function (Blueprint $table) {
            $table->id();
            $table->string("customer_care_id");
            $table->string("pancake_customer_id")->nullable();
            $table->string("pancake_user_id");
            $table->index(["customer_care_id", "pancake_user_id"]);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_assigneds');
    }
};
