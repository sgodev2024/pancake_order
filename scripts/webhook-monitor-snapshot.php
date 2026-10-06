<?php
// Run independently of queue workers, once per minute as root.
chdir('/var/www/html/pancake_order');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$workers = [];
foreach (glob('/proc/[0-9]*/cmdline') as $file) {
    $cmd = str_replace("\0", ' ', @file_get_contents($file) ?: '');
    if (str_contains($cmd, '/var/www/html/pancake_order/artisan queue:work') && str_contains($cmd, '--queue=get-order-webhook')) {
        $workers[] = (int) basename(dirname($file));
    }
}
$queue = Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'get-order-webhook')
    ->selectRaw('count(*) as pending, min(created_at) as oldest_unix, sum(case when reserved_at is not null then 1 else 0 end) as reserved')->first();
$failed = Illuminate\Support\Facades\DB::table('failed_jobs')->where('queue', 'get-order-webhook')
    ->selectRaw('count(*) as total, max(failed_at) as latest')->first();
$data = ['checked_at' => date(DATE_ATOM), 'worker_running' => count($workers) > 0, 'worker_pids' => $workers,
    'queue' => $queue, 'failed' => $failed,
    'retry_after' => config('queue.connections.'.config('queue.default').'.retry_after'), 'worker_timeout' => 180];
$target = storage_path('app/webhook-monitor-status.json');
$tmp = $target.'.tmp';
file_put_contents($tmp, json_encode($data)); chmod($tmp, 0644); rename($tmp, $target);
