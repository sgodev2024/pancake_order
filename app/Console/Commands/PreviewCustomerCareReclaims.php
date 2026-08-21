<?php

namespace App\Console\Commands;

use App\Services\CustomerCareReclaimService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PreviewCustomerCareReclaims extends Command
{
    protected $signature = 'customer-care:reclaim-stale
                            {--dry-run : Preview only; Phase D never performs reclaim mutations}
                            {--shop= : Limit the preview to one shop ID}
                            {--limit=100 : Maximum active assignments to scan}';

    protected $description = 'Preview stale CustomerCare assignments without changing any data';

    public function handle(CustomerCareReclaimService $reclaimService): int
    {
        $shopId = $this->positiveIntegerOption('shop', true);
        $limit = $this->positiveIntegerOption('limit');

        if ($shopId === false || $limit === false) {
            return self::INVALID;
        }

        $referenceTime = CarbonImmutable::now(config('app.timezone'));
        $preview = $reclaimService->preview($referenceTime, $shopId, $limit);

        $this->components->info(
            "DRY RUN ONLY — reference date {$preview['reference_date']} "
            .'('.config('app.timezone').'). No data will be changed.'
        );
        $this->table(
            [
                'Assignment ID',
                'Source',
                'Source ID',
                'Shop',
                'CustomerCare ID',
                'Assignee',
                'Assigned At',
                'Eligible On',
                'Care Status',
                'Result',
                'Reason',
            ],
            array_map(
                fn (array $row) => array_values($row),
                $preview['rows']
            )
        );
        $this->table(
            ['Summary', 'Count'],
            [
                ['Scanned', $preview['summary']['scanned']],
                ['Eligible', $preview['summary']['eligible']],
                ['Not yet due', $preview['summary']['not_yet_due']],
                ['Already cared', $preview['summary']['already_cared']],
                ['Anomaly', $preview['summary']['anomaly']],
                ['Invalid/missing relation', $preview['summary']['invalid_relation']],
                ['Skipped', $preview['summary']['skipped']],
            ]
        );

        return self::SUCCESS;
    }

    private function positiveIntegerOption(string $name, bool $nullable = false): int|false|null
    {
        $value = $this->option($name);

        if ($nullable && ($value === null || $value === '')) {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($validated === false) {
            $this->components->error("--{$name} must be a positive integer.");

            return false;
        }

        return $validated;
    }
}
