<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_cares', fn (Blueprint $table) => $table->string('pancake_customer_id')->nullable()->change());
        Schema::create('care_segment_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('actor_user_id');
            $table->uuid('request_id');
            $table->string('request_hash', 64);
            $table->unsignedBigInteger('completed_care_id');
            $table->unsignedBigInteger('next_care_id');
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->text('note');
            $table->text('context')->nullable();
            $table->timestamp('cared_at');
            $table->timestamps();
            $table->unique(['actor_user_id', 'request_id']);
        });
    }
    public function down(): void { throw new RuntimeException('Keep customer care history; review before rollback.'); }
};
