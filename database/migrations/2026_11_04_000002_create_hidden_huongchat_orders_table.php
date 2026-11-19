<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('hidden_huongchat_orders',function(Blueprint $t){$t->unsignedBigInteger('order_id')->primary();$t->unsignedBigInteger('hidden_by');$t->timestamp('hidden_at');});}
 public function down():void {Schema::dropIfExists('hidden_huongchat_orders');}
};
