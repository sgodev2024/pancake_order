<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('aicrm_shipments')) {
            Schema::create('aicrm_shipments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
                $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
                $table->string('mode', 20);
                $table->string('status', 30);
                $table->string('tracking_number')->nullable();
                $table->uuid('reference')->unique();
                $table->string('last_error')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('aicrm_shipment_attempts')) {
            Schema::create('aicrm_shipment_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shipment_id')->constrained('aicrm_shipments')->restrictOnDelete();
                $table->unsignedBigInteger('actor_user_id');
                $table->string('status', 30);
                $table->json('request_payload');
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('error_code')->nullable();
                $table->string('error_message')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('aicrm_shipment_attempts');
        Schema::dropIfExists('aicrm_shipments');
    }
};
