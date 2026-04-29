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
        Schema::table('users', function (Blueprint $table) {
            $table->string("pancake_user_id")->nullable()->after("password");
            $table->string("fb_id")->nullable()->after("pancake_user_id");
            $table->string("phone_number")->nullable()->after("fb_id");
            $table->json("pancake_full_data")->nullable()->after("phone_number");
            $table->string("avatar_url", 500)->after("pancake_full_data")->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                "pancake_user_id",
                "fb_id",
                "phone_number",
                "pancake_full_data",
                "avatar_url"
            ]);
        });
    }
};
