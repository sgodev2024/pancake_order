<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dateTime('last_order_at')->nullable()->after('order_count');
            $table->index(['shop_id', 'last_order_at'], 'customers_shop_last_order_at_index');
        });

        // Normalize the existing Pancake timestamp. This is derived only from
        // real synchronized customer data and makes recency filters indexable.
        DB::statement(<<<'SQL'
            UPDATE customers
            SET last_order_at = STR_TO_DATE(
                LEFT(
                    NULLIF(
                        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(pancake_full_data, '$.last_order_at')), 'null'),
                        ''
                    ),
                    19
                ),
                '%Y-%m-%dT%H:%i:%s'
            )
            WHERE JSON_EXTRACT(pancake_full_data, '$.last_order_at') IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_shop_last_order_at_index');
            $table->dropColumn('last_order_at');
        });
    }
};
