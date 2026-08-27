<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add nullable, additive fields so existing activity logs remain valid.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->timestamp('occurred_at')->nullable()->index();

            // A global nullable unique key identifies one logical event. SQL
            // permits multiple NULLs, which preserves legacy/repair logging.
            $table->string('idempotency_key', 191)->nullable()->unique();
        });
    }

    /**
     * Reverse only the additive schema change.
     */
    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropUnique('activity_logs_idempotency_key_unique');
            $table->dropIndex(['occurred_at']);
            $table->dropColumn(['occurred_at', 'idempotency_key']);
        });
    }
};
