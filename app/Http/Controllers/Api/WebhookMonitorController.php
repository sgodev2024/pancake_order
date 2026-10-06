<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;

class WebhookMonitorController extends Controller
{
    public function index()
    {
        $read = function ($name) {
            $path = storage_path('app/'.$name);
            if (!is_readable($path)) return null;
            $handle = fopen($path, 'r');
            if (!$handle) return null;
            try { flock($handle, LOCK_SH); return json_decode(stream_get_contents($handle), true); }
            finally { flock($handle, LOCK_UN); fclose($handle); }
        };
        $events = $read('webhook-monitor-events.json');
        $shops = Shop::select('id', 'name', 'pancake_shop_id')->get();
        return response()->json(['success' => true, 'data' => [
            'snapshot' => $read('webhook-monitor-status.json'),
            'events' => $events,
            'shops' => $shops,
            'now' => date(DATE_ATOM),
        ]]);
    }

    public function cleanup(\Illuminate\Http\Request $request)
    {
        $input = $request->validate([
            'action' => 'required|in:preview,delete',
            'before_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'token' => 'required_if:action,delete|string',
            'confirmation' => 'required_if:action,delete|in:XÓA',
        ]);
        $cutoff = $input['before_date'].' 00:00:00';
        $query = fn () => \Illuminate\Support\Facades\DB::table('failed_jobs')
            ->where('queue', 'get-order-webhook')->where('failed_at', '<', $cutoff);
        if ($input['action'] === 'preview') {
            $stats = $query()->selectRaw('count(*) as total, min(failed_at) as oldest, max(failed_at) as newest, max(id) as max_id')->first();
            $eligible = (int) $stats->total;
            $ids = $query()->orderBy('id')->limit(5000)->pluck('id');
            $stats->max_id = $ids->last() ?? 0;
            $stats->total = $ids->count();
            $stats->eligible = $eligible;
            $token = \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
                'user_id' => $request->user()->id, 'cutoff' => $cutoff,
                'max_id' => $stats->max_id ?? 0, 'expires' => time() + 600,
            ]));
            return response()->json(['success' => true, 'data' => ['stats' => $stats, 'token' => $token]]);
        }
        try {
            $scope = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($input['token']), true);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Phiên xem trước không hợp lệ. Hãy xem trước lại.'], 422);
        }
        if (($scope['user_id'] ?? null) !== $request->user()->id || ($scope['cutoff'] ?? null) !== $cutoff || ($scope['expires'] ?? 0) < time()) {
            return response()->json(['success' => false, 'message' => 'Phiên xem trước đã hết hạn hoặc không khớp.'], 422);
        }
        $lock = fopen(storage_path('app/webhook-cleanup.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            return response()->json(['success' => false, 'message' => 'Một lượt dọn đang chạy. Vui lòng chờ.'], 409);
        }
        $backup = null;
        $deleted = 0;
        try {
            $dir = storage_path('app/private/webhook-failed-backups');
            if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new \RuntimeException('Không tạo được thư mục sao lưu');
            $backup = $dir.'/failed-webhook-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.jsonl.gz';
            $stream = gzopen($backup, 'wb');
            if (!$stream) throw new \RuntimeException('Không mở được file sao lưu');
            chmod($backup, 0600);
            $count = 0;
            try {
                $query()->where('id', '<=', $scope['max_id'])->chunkById(500, function ($rows) use ($stream, &$count) {
                    foreach ($rows as $row) {
                        $line = json_encode($row, JSON_THROW_ON_ERROR)."\n";
                        if (gzwrite($stream, $line) !== strlen($line)) throw new \RuntimeException('Ghi sao lưu thất bại');
                        $count++;
                    }
                });
            } finally { gzclose($stream); }
            // Delete only IDs present in the completed backup, in small batches.
            $stream = gzopen($backup, 'rb');
            if (!$stream) throw new \RuntimeException('Không đọc được bản sao lưu');
            try {
                $ids = [];
                while (($line = gzgets($stream)) !== false) {
                    $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $ids[] = $row['id'];
                    if (count($ids) >= 500) {
                        $deleted += $query()->whereIn('id', $ids)->delete();
                        $ids = [];
                    }
                }
                if ($ids) $deleted += $query()->whereIn('id', $ids)->delete();
            } finally { gzclose($stream); }
            \Illuminate\Support\Facades\Log::info('Admin cleaned webhook failed jobs', ['actor_id' => $request->user()->id, 'before_date' => $input['before_date'], 'backed_up' => $count, 'deleted' => $deleted, 'backup' => basename($backup)]);
            return response()->json(['success' => true, 'message' => "Đã sao lưu và xóa {$deleted} job lỗi. Chỉ số cập nhật trong tối đa một phút.", 'data' => ['deleted' => $deleted]]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Webhook failed cleanup interrupted', ['actor_id' => $request->user()->id, 'deleted' => $deleted, 'backup' => $backup ? basename($backup) : null, 'error_type' => get_class($e)]);
            return response()->json(['success' => false, 'message' => "Dọn lịch sử bị gián đoạn; đã xóa {$deleted} bản ghi. Kiểm tra log và bản sao lưu trên server trước khi tiếp tục."], 500);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
