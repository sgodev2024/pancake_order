<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aicrm_shipments', fn (Blueprint $table) => $table->uuid('tenant_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('aicrm_shipments', fn (Blueprint $table) => $table->dropColumn('tenant_id'));
    }
};
