<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['aicrm_shipments', 'aicrm_shipment_attempts'] as $name) {
            foreach (['remote_order_id' => 100, 'remote_status' => 100] as $column => $length) {
                if (! Schema::hasColumn($name, $column)) {
                    Schema::table($name, fn (Blueprint $table) => $table->string($column, $length)->nullable());
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['aicrm_shipments', 'aicrm_shipment_attempts'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['remote_order_id', 'remote_status']));
        }
    }
};
