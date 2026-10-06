<?php
namespace App\Services;

class WebhookMonitor
{
    public static function record(string $kind, $shopId = null, ?string $reason = null): void
    {
        // Monitoring must never interrupt order processing. No payload or PII is stored.
        try {
            $path = storage_path('app/webhook-monitor-events.json');
            $handle = fopen($path, 'c+');
            if (!$handle) return;
            try {
                if (!flock($handle, LOCK_EX)) return;
                $state = json_decode(stream_get_contents($handle), true) ?: ['started_at' => date(DATE_ATOM), 'shops' => [], 'recent' => []];
                $key = (string) ($shopId ?? 'unknown');
                $row = $state['shops'][$key] ?? ['shop_id' => $key, 'received' => 0, 'processed' => 0, 'skipped' => 0, 'error' => 0];
                $row[$kind] = ($row[$kind] ?? 0) + 1;
                $row['last_'.$kind] = date(DATE_ATOM);
                $state['shops'][$key] = $row;
                if (in_array($kind, ['error', 'skipped'])) {
                    array_unshift($state['recent'], ['at' => date(DATE_ATOM), 'shop_id' => $key, 'kind' => $kind, 'reason' => $reason]);
                    $state['recent'] = array_slice($state['recent'], 0, 30);
                }
                rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($state)); fflush($handle);
                flock($handle, LOCK_UN);
            } finally { fclose($handle); }
        } catch (\Throwable $e) { /* Best-effort telemetry only. */ }
    }
}
