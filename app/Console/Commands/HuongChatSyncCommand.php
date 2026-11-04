<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Services\HuongChat\HuongChatCrmSync;
class HuongChatSyncCommand extends Command {
 protected $signature='huongchat:sync {--dry-run}';
 protected $description='Đồng bộ Hương Chất Web vào CRM, giữ phân công và loại đơn đã xóa';
 public function handle(HuongChatCrmSync $sync):int {
  try{$result=$sync->run((bool)$this->option('dry-run'));$this->info(json_encode($result));return 0;}
  catch(\Throwable $e){report($e);$this->error('Đồng bộ Hương Chất thất bại. Kiểm tra log; không tự gán nhân viên.');return 1;}
 }
}
