<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('zalo_connections', function(Blueprint $t){$t->id();$t->string('base_url');$t->text('api_key');$t->boolean('enabled')->default(false);$t->timestamps();});
  Schema::create('zalo_account_grants', function(Blueprint $t){$t->id();$t->uuid('account_id');$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('access_level',8);$t->unique(['account_id','user_id']);});
  Schema::create('zalo_conversation_links', function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('account_id')->index();});
 }
 public function down(): void {Schema::dropIfExists('zalo_conversation_links');Schema::dropIfExists('zalo_account_grants');Schema::dropIfExists('zalo_connections');}
};
