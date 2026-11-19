<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
class SendCareNotifications extends Command {
 protected $signature='care:notify';protected $description='Tạo thông báo chuông và gửi email CSKH theo template bật/tắt';
 public function handle(\App\Services\CareNotificationService $service):int {$this->info(json_encode($service->run()));return 0;}
}
