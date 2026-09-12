<?php

namespace App\Console\Commands;

use App\Services\OrderPageBackfillService;
use Illuminate\Console\Command;
use Throwable;

class BackfillOrderPages extends Command
{
    private const SAMPLE_LIMIT = 25;

    protected $signature = 'order-pages:backfill
                            {--shop-id= : Restrict processing to one local shop ID}
                            {--ids= : Comma-separated local Order IDs}
                            {--execute : Write eligible normalized page values}';

    protected $description = 'Preview or backfill historical Order page snapshots from pancake_full_data';

    public function handle(OrderPageBackfillService $backfillService): int
    {
        $shopId = $this->parsePositiveInteger('shop-id');
        $ids = $this->parseIds($this->option('ids'));
        if ($shopId === false || $ids === false) {
            return self::INVALID;
        }

        $execute = (bool) $this->option('execute');

        $this->components->info(
            $execute
                ? 'EXECUTE MODE — eligible Order page snapshots will be backfilled.'
                : 'PREVIEW ONLY — no database rows will be modified.'
        );
        $this->line('Scope: '.($shopId === null ? 'all shops' : "shop {$shopId}")
            .($ids === null ? ', all Order IDs' : ', '.count($ids).' requested Order IDs'));

        try {
            $result = $backfillService->run(
                $shopId,
                $ids,
                $execute,
                self::SAMPLE_LIMIT
            );
        } catch (Throwable) {
            $this->components->error('Order page backfill failed. No credential or payload data was displayed.');

            return self::FAILURE;
        }

        if ($result['samples'] !== []) {
            $this->table(
                ['Order ID', 'Shop ID', 'Classification', 'Page ID', 'Page Name', 'Catalog'],
                $result['samples']
            );
        }

        $summary = $result['summary'];
        $this->table(
            ['Summary', 'Count'],
            [
                ['Scanned', $summary['scanned']],
                ['Would backfill', $summary['would_backfill']],
                ['Backfilled', $summary['backfilled']],
                ['Partial page', $summary['partial_page']],
                ['No page', $summary['no_page']],
                ['Already populated', $summary['already_populated']],
                ['Conflicts', $summary['conflicts']],
                ['Invalid payload', $summary['invalid_payload']],
                ['Catalog matched', $summary['catalog_matched']],
                ['Not in catalog', $summary['catalog_missing']],
                ['Catalog unavailable', $summary['catalog_unavailable']],
                ['Requested IDs not found in scope', $summary['not_found']],
            ]
        );
        $this->line('Partial page rows with one usable field are included in Would backfill.');
        $this->line('Catalog status is audit-only and never blocks backfill.');
        $this->line('Samples displayed: '.count($result['samples']).' / '.self::SAMPLE_LIMIT.' maximum.');

        return self::SUCCESS;
    }

    private function parsePositiveInteger(string $option): int|false|null
    {
        $value = $this->option($option);
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            $this->components->error("--{$option} must be a positive integer.");

            return false;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($parsed === false) {
            $this->components->error("--{$option} must be a positive integer.");

            return false;
        }

        return $parsed;
    }

    /** @return list<int>|false|null */
    private function parseIds(mixed $value): array|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            $this->components->error('--ids must be a comma-separated list of positive Order IDs.');

            return false;
        }

        $ids = [];
        foreach (explode(',', $value) as $token) {
            $token = trim($token);
            if ($token === '' || preg_match('/^[1-9]\d*$/', $token) !== 1) {
                $this->components->error('--ids must be a comma-separated list of positive Order IDs.');

                return false;
            }

            $id = filter_var($token, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($id === false) {
                $this->components->error('--ids contains an invalid Order ID.');

                return false;
            }

            $ids[$id] = $id;
        }

        return array_values($ids);
    }
}
