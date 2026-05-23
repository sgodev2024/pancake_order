<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class MailConfigService
{
    public static function setDynamicConfig()
    {
        try {
            $setting = Setting::where('code', 'smtp')->first();
            if ($setting) {
                $data = $setting->data;
                config([
                    'mail.default' => $data['driver'] ?? 'smtp',
                    'mail.mailers.smtp.transport' => 'smtp',
                    'mail.mailers.smtp.host' => $data['host'],
                    'mail.mailers.smtp.port' => $data['port'],
                    'mail.mailers.smtp.encryption' => $data['encryption'],
                    'mail.mailers.smtp.username' => $data['username'],
                    'mail.mailers.smtp.password' => $data['password'],
                    'mail.from.address' => $data['from_email'],
                    'mail.from.name' => $data['from_name'],
                ]);
                // QUAN TRỌNG: Buộc Laravel xóa instance mailer cũ để nhận cấu hình mới
                app()->forgetInstance('mailer');
                app()->make('mailer');
            }
        } catch(\PDOException $e) {
            Log::error($e->getMessage());
        }
    }
}