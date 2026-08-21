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
        Schema::create('customer_care_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id');
            $table->foreignId('customer_care_id');
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('assignee_user_id');
            $table->string('assignee_pancake_user_id');
            $table->timestamp('assigned_at');
            $table->date('reclaim_eligible_on');
            $table->string('status', 20)->default('active');
            $table->timestamp('cared_at')->nullable();
            $table->timestamp('reclaimed_at')->nullable();
            $table->string('reclaim_reason')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')
                ->references('id')
                ->on('shops')
                ->restrictOnDelete();
            $table->foreign('customer_care_id')
                ->references('id')
                ->on('customer_cares')
                ->restrictOnDelete();
            $table->foreign('assignee_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->index(
                ['status', 'reclaim_eligible_on', 'id'],
                'cca_status_eligible_id_idx'
            );
            $table->index(
                ['shop_id', 'status', 'reclaim_eligible_on', 'id'],
                'cca_shop_status_eligible_id_idx'
            );
            $table->index(
                ['source_type', 'source_id', 'status'],
                'cca_source_status_idx'
            );
            $table->index(
                ['customer_care_id', 'status'],
                'cca_care_status_idx'
            );
            $table->index('assignee_user_id', 'cca_assignee_user_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_care_assignments');
    }
};
