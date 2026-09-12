<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\PancakeOrderSourceSyncException;
use App\Services\PancakeOrderSourceSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncPancakeOrderSources extends Command
{
    protected $signature = 'order-sources:sync
                            {--shop-id= : Local shop ID to synchronize}
                            {--execute : Write the synchronization result to the local catalog}';

    protected $description = 'Preview or synchronize one Pancake shop order-source catalog';

    public function handle(PancakeOrderSourceSyncService $syncService): int
    {
        $shopId = $this->positiveShopId();
        if ($shopId === false) {
            return self::INVALID;
        }

        $shop = Shop::query()->find($shopId);
        if ($shop === null) {
            $this->components->error("Local shop {$shopId} was not found.");

            return self::FAILURE;
        }

        try {
            $summary = $syncService->sync($shop, (bool) $this->option('execute'));
        } catch (PancakeOrderSourceSyncException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->components->error("Unexpected order source sync failure for local shop {$shopId}.");

            return self::FAILURE;
        }

        $this->components->info(
            $summary['executed']
                ? 'EXECUTE MODE — local catalog synchronization completed.'
                : 'PREVIEW ONLY — no database rows were modified.'
        );
        $this->line(
            "Shop: {$summary['shop_id']} — {$summary['shop_name']} "
            ."(Pancake {$summary['pancake_shop_id']})"
        );
        $this->table(
            ['Metric', 'Count'],
            [
                ['Pancake sources', $summary['received']],
                [$summary['executed'] ? 'Created' : 'Would create', $summary['created']],
                [$summary['executed'] ? 'Updated' : 'Would update', $summary['updated']],
                [$summary['executed'] ? 'Deactivated' : 'Would deactivate', $summary['deactivated']],
                ['Unchanged', $summary['unchanged']],
            ]
        );

        return self::SUCCESS;
    }

    private function positiveShopId(): int|false
    {
        $value = $this->option('shop-id');
        if (! is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            $this->components->error('--shop-id must be a positive local shop ID.');

            return false;
        }

        $shopId = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($shopId === false) {
            $this->components->error('--shop-id must be a positive local shop ID.');

            return false;
        }

        return $shopId;
    }
}
