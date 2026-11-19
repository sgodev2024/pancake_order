<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('staff_account_states',function(Blueprint $t){$t->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();$t->timestamp('disabled_at');$t->unsignedBigInteger('changed_by')->nullable();});}
 public function down():void {Schema::dropIfExists('staff_account_states');}
};
