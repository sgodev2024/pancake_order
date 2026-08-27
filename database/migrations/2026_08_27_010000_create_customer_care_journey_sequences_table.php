<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_care_journey_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('sequence_scope', 50);
            $table->string('scope_key', 191);
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();

            $table->unique(
                ['shop_id', 'sequence_scope', 'scope_key'],
                'cc_journey_sequences_scope_unique'
            );
            $table->index(['shop_id', 'sequence_scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_care_journey_sequences');
    }
};
