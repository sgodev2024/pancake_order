<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('prepaid_amount', 20, 2)->default(0)->after('cod');
        });

        DB::statement(
            "UPDATE orders SET prepaid_amount = COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(pancake_full_data, '$.prepaid')) AS DECIMAL(20, 2)), 0)"
        );
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('prepaid_amount');
        });
    }
};
