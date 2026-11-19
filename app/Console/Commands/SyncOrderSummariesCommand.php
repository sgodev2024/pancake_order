<?php

namespace App\Console\Commands;

use App\Exceptions\OrderSummaryPendingException;
use App\Services\OrderSummaryReconciler;
use App\Services\OrderSummaryService;
use App\Services\ReportDataVersion;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncOrderSummariesCommand extends Command
{
    protected $signature = 'orders:sync-summaries
        {--all : Initialize or rebuild the complete derived history}
        {--pending : Retry up to 1000 durable pending order changes}
        {--verify : Reconcile source data against summaries without rebuilding}
        {--date= : Repair one date (YYYY-MM-DD), after initialization}
        {--days=60 : Repair recent days, after initialization}';

    protected $description = 'Build, incrementally repair, or verify daily order and product summaries';

    public function handle(): int
    {
        $modes = (int) $this->option('all') + (int) $this->option('pending') + (int) $this->option('verify')
            + (int) ($this->option('date') !== null) + (int) $this->input->hasParameterOption('--days');
        if ($modes > 1) {
            $this->error('Choose only one of --all, --pending, --verify, --date, or --days.');

            return self::INVALID;
        }
        if (! OrderSummaryService::installed()) {
            $this->error('Run the dashboard summary migrations first, then orders:sync-summaries --all.');

            return self::FAILURE;
        }

        $verificationStarted = false;
        try {
            if ($this->option('all')) {
                $processed = 0;
                OrderSummaryService::rebuildAll(function (int $count) use (&$processed) {
                    $processed += $count;
                });
                $this->info("Rebuilt the complete history: {$processed} active orders. Run --verify next.");

                return self::SUCCESS;
            }

            // Pending work disables summary reads, but can be drained after
            // a complete baseline has previously been initialized.
            $baseline = DB::table('order_summary_state')->where('id', 1)
                ->where('schema_version', 2)->where('ready', true)->exists();
            if (! $baseline) {
                $this->error('Summary baseline is not ready. Run orders:sync-summaries --all before incremental repair or verification.');

                return self::FAILURE;
            }
            if ($this->option('pending')) {
                $processed = OrderSummaryService::drainPending();
                $remaining = DB::table('order_summary_pending')->count();
                $this->info("Retried {$processed} pending orders; {$remaining} remain.");

                return self::SUCCESS;
            }
            if (! OrderSummaryService::isReady()) {
                $this->error('Pending source changes exist. Run orders:sync-summaries --pending, then retry.');

                return self::FAILURE;
            }
            if ($this->option('verify')) {
                $verificationStarted = true;
                $result = app(OrderSummaryReconciler::class)->verify();
                if (! $result['ok']) {
                    $this->disableSummaries();
                    $this->error("Reconciliation failed: {$result['differences']} differences. Summary reads disabled; source orders were not changed.");
                    foreach ($result['samples'] as $sample) {
                        $this->line($sample);
                    }
                    $this->error('Run orders:sync-summaries --all, then --verify.');

                    return self::FAILURE;
                }
                $this->info("Reconciliation passed: {$result['day_groups']} day/shop groups, {$result['product_groups']} product groups and {$result['orders']} source orders.");

                return self::SUCCESS;
            }

            if ($this->option('date') !== null) {
                $input = (string) $this->option('date');
                $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) ? Carbon::createFromFormat('!Y-m-d', $input) : null;
                if (! $date || $date->format('Y-m-d') !== $input) {
                    $this->error('--date must be a valid YYYY-MM-DD date.');

                    return self::INVALID;
                }
                $dates = [$input];
            } else {
                $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($days === false) {
                    $this->error('--days must be a positive integer.');

                    return self::INVALID;
                }
                $dates = [];
                for ($index = 0; $index < $days; $index++) {
                    $dates[] = now()->subDays($index)->toDateString();
                }
            }
            OrderSummaryService::rebuildDates($dates);
            $this->info('Repaired '.count($dates).' date(s) using idempotent order contributions.');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            if ($verificationStarted && ! ($error instanceof OrderSummaryPendingException)) {
                try {
                    $this->disableSummaries();
                    $this->error('Verification could not complete. Summary reads disabled; fix the error, then run --all and --verify.');
                } catch (\Throwable $invalidationError) {
                    $this->error('Could not disable summary readiness: '.$invalidationError->getMessage());
                }
            }
            $this->error('Summary operation failed: '.$error->getMessage());

            return self::FAILURE;
        }
    }

    private function disableSummaries(): void
    {
        DB::table('order_summary_state')->where('id', 1)->update(['ready' => false, 'updated_at' => now()]);
        ReportDataVersion::bump('orders');
    }
}
