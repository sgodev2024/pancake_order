<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up():void {
  if(!Schema::hasTable('care_notification_templates'))Schema::create('care_notification_templates',function(Blueprint $t){$t->string('type',20)->primary();$t->boolean('email_enabled')->default(false);$t->text('subject');$t->text('body');$t->timestamp('email_enabled_at')->nullable();$t->timestamps();});
  Schema::create('care_user_notifications',function(Blueprint $t){$t->bigIncrements('id');$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->unsignedBigInteger('shop_id');$t->string('type',20);$t->string('dedup_key',180)->unique();$t->string('title');$t->text('body');$t->string('path');$t->unsignedInteger('task_count')->default(1);$t->timestamp('read_at')->nullable();$t->string('email_status',20)->default('not_requested');$t->dateTime('created_at');$t->index(['user_id','read_at']);});
  $names=['assigned'=>'Bạn có công việc CSKH mới','today'=>'Lịch CSKH hôm nay','overdue'=>'Lịch CSKH quá hạn'];
  foreach($names as $type=>$title)DB::table('care_notification_templates')->insert(['type'=>$type,'email_enabled'=>false,'subject'=>$title.' - Hương Chất Group','body'=>"Xin chào {{employee_name}},\nBạn có {{task_count}} công việc: ".$title.".\nNgày: {{date}}\nVui lòng đăng nhập hệ thống để xem và xử lý:\n{{login_url}}\nMở lịch: {{work_url}}",'created_at'=>now(),'updated_at'=>now()]);
 }
 public function down():void {Schema::dropIfExists('care_user_notifications');Schema::dropIfExists('care_notification_templates');}
};
