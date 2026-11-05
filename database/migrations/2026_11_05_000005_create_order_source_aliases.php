<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_source_aliases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('source_id');
            $table->string('alias', 150);
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamps();
            $table->unique(['shop_id', 'source_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('order_source_aliases');
    }
};
