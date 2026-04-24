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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string("name");
            $table->string("description", 500)->nullable();
            $table->integer("care_cycle_days")->nullable();
            $table->string("pancake_api_key", 500)->nullable();
            $table->string("pancake_shop_id", 500)->nullable();
            $table->string("vpt_username")->nullable();
            $table->string("vpt_password")->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
