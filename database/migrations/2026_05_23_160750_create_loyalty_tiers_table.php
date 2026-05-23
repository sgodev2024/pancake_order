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
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique()->comment('Tên hạng khách hàng');
            $table->decimal('discount_percent', 5, 2)->default(0)->comment('Phần trăm giảm giá (0-100)');
            $table->decimal('min_order_value', 15, 2)->default(0)->comment('Giá trị đơn hàng tối thiểu');
            $table->decimal('max_order_value', 15, 2)->nullable()->comment('Giá trị đơn hàng tối đa');
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_tiers');
    }
};
