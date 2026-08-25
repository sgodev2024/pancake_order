<?php

namespace App\Console\Commands;

use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Services\ActivityLogService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairCustomerCareReclaimDates extends Command
{
    protected $signature = 'customer-care:repair-reclaim-dates
                            {--ids= : Comma-separated CustomerCareAssignment IDs}
                            {--execute : Apply the displayed corrections}';

    protected $description = 'Preview or repair active CustomerCare reclaim dates from persisted care dates';

    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $ids = $this->parseIds($this->option('ids'));

        if ($ids === false) {
            return self::INVALID;
        }

        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $analysis = $this->analyze($ids, $today);

        $this->components->info(
            ($this->option('execute') ? 'EXECUTE' : 'PREVIEW ONLY')
            .' — reference date '.$today->toDateString()
            .' ('.config('app.timezone').')'
        );

        $this->table(
            [
                'CCA',
                'Source',
                'Source ID',
                'Shop',
                'CustomerCare',
                'Assigned at',
                'Care date',
                'Current reclaim',
                'Expected reclaim',
                'Cared at',
                'Care status',
                'Current due',
                'Corrected due',
            ],
            $analysis['rows']
        );

        $updated = 0;

        if ($this->option('execute') && $analysis['candidate_ids'] !== []) {
            $updated = $this->executeRepairs($analysis['candidate_ids']);
        }

        $summary = $analysis['summary'];
        $this->newLine();
        $this->line("Scanned: {$summary['scanned']}");
        $this->line("Candidates: {$summary['candidates']}");
        $this->line("Already aligned: {$summary['already_aligned']}");
        $this->line("Skipped missing relation: {$summary['skipped_missing_relation']}");
        $this->line("Skipped null date: {$summary['skipped_null_date']}");
        $this->line("Uncared candidates: {$summary['uncared_candidates']}");
        $this->line("Cared candidates: {$summary['cared_candidates']}");
        $this->line("Currently prematurely due: {$summary['currently_prematurely_due']}");
        $this->line("Future prematurely due: {$summary['future_prematurely_due']}");
        $this->line("Would update: {$summary['candidates']}");
        $this->line("Updated: {$updated}");

        return self::SUCCESS;
    }

    /**
     * @return list<int>|false|null
     */
    private function parseIds(mixed $value): array|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            $this->components->error('--ids must be a comma-separated list of positive integers.');

            return false;
        }

        $tokens = explode(',', $value);
        $ids = [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token === '' || preg_match('/^[1-9]\d*$/', $token) !== 1) {
                $this->components->error('--ids must be a comma-separated list of positive integers.');

                return false;
            }

            $id = filter_var($token, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);

            if ($id === false) {
                $this->components->error('--ids contains an invalid assignment ID.');

                return false;
            }

            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /**
     * @param  list<int>|null  $ids
     * @return array{
     *     rows: list<array<int, string|int>>,
     *     candidate_ids: list<int>,
     *     summary: array<string, int>
     * }
     */
    private function analyze(?array $ids, CarbonImmutable $today): array
    {
        $assignments = CustomerCareAssignment::query()
            ->where('status', CustomerCareAssignment::STATUS_ACTIVE)
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->with('customerCare:id,date_care,status,time_care')
            ->orderBy('id')
            ->get();

        $rows = [];
        $candidateIds = [];
        $summary = [
            'scanned' => $assignments->count(),
            'candidates' => 0,
            'already_aligned' => 0,
            'skipped_missing_relation' => 0,
            'skipped_null_date' => 0,
            'uncared_candidates' => 0,
            'cared_candidates' => 0,
            'currently_prematurely_due' => 0,
            'future_prematurely_due' => 0,
        ];

        foreach ($assignments as $assignment) {
            if ($assignment->customerCare === null) {
                $summary['skipped_missing_relation']++;

                continue;
            }

            if ($assignment->customerCare->date_care === null) {
                $summary['skipped_null_date']++;

                continue;
            }

            $expected = $this->expectedReclaimDate($assignment->customerCare->date_care);
            $current = $assignment->reclaim_eligible_on;

            if ($current !== null && $current->toDateString() === $expected->toDateString()) {
                $summary['already_aligned']++;

                continue;
            }

            $candidateIds[] = (int) $assignment->getKey();
            $summary['candidates']++;

            if ($assignment->cared_at === null) {
                $summary['uncared_candidates']++;
            } else {
                $summary['cared_candidates']++;
            }

            $currentDue = $current !== null && ! $current->isAfter($today);
            $correctedDue = ! $expected->isAfter($today);

            if ($assignment->cared_at === null && $currentDue && ! $correctedDue) {
                $summary['currently_prematurely_due']++;
            }

            if ($assignment->cared_at === null
                && $current !== null
                && $current->isAfter($today)
                && $current->isBefore($expected)) {
                $summary['future_prematurely_due']++;
            }

            $rows[] = [
                $assignment->id,
                $assignment->source_type,
                $assignment->source_id,
                $assignment->shop_id,
                $assignment->customer_care_id,
                $this->formatDateTime($assignment->assigned_at),
                $this->formatDate($assignment->customerCare->date_care),
                $this->formatDate($current),
                $this->formatDate($expected),
                $this->formatDateTime($assignment->cared_at),
                (string) $assignment->customerCare->status,
                $currentDue ? 'YES' : 'NO',
                $correctedDue ? 'YES' : 'NO',
            ];
        }

        return [
            'rows' => $rows,
            'candidate_ids' => $candidateIds,
            'summary' => $summary,
        ];
    }

    /**
     * @param  list<int>  $candidateIds
     */
    private function executeRepairs(array $candidateIds): int
    {
        return DB::transaction(function () use ($candidateIds) {
            $snapshots = CustomerCareAssignment::query()
                ->whereIn('id', $candidateIds)
                ->where('status', CustomerCareAssignment::STATUS_ACTIVE)
                ->get(['id', 'customer_care_id']);

            $customerCares = CustomerCare::query()
                ->whereIn('id', $snapshots->pluck('customer_care_id')->unique())
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'date_care'])
                ->keyBy('id');

            $assignments = CustomerCareAssignment::query()
                ->whereIn('id', $snapshots->pluck('id'))
                ->where('status', CustomerCareAssignment::STATUS_ACTIVE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            return $assignments->reduce(function (int $updated, CustomerCareAssignment $assignment) use ($customerCares) {
                $customerCare = $customerCares->get($assignment->customer_care_id);

                if ($customerCare === null || $customerCare->date_care === null) {
                    return $updated;
                }

                $expected = $this->expectedReclaimDate($customerCare->date_care);
                $current = $assignment->reclaim_eligible_on;

                if ($current !== null && $current->toDateString() === $expected->toDateString()) {
                    return $updated;
                }

                $oldDate = $current?->toDateString();
                $newDate = $expected->toDateString();

                $affected = CustomerCareAssignment::query()
                    ->whereKey($assignment->getKey())
                    ->where('status', CustomerCareAssignment::STATUS_ACTIVE)
                    ->update(['reclaim_eligible_on' => $newDate]);

                if ($affected !== 1) {
                    return $updated;
                }

                $this->activityLogService->log(
                    'customer_care.reclaim_date_repaired',
                    'system',
                    null,
                    'Hệ thống',
                    $assignment->assignee_user_id,
                    null,
                    $assignment->shop_id,
                    null,
                    'customer_care_assignment',
                    $assignment->id,
                    null,
                    null,
                    ['reclaim_eligible_on' => $oldDate],
                    ['reclaim_eligible_on' => $newDate],
                    [
                        'customer_care_id' => $assignment->customer_care_id,
                        'source_type' => $assignment->source_type,
                        'source_id' => $assignment->source_id,
                    ]
                );

                return $updated + 1;
            }, 0);
        });
    }

    private function expectedReclaimDate(mixed $dateCare): CarbonImmutable
    {
        $scheduledOn = CarbonImmutable::parse((string) $dateCare, config('app.timezone'))
            ->startOfDay();

        return CustomerCareAssignment::calculateReclaimEligibleOn($scheduledOn);
    }

    private function formatDate(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return CarbonImmutable::parse((string) $value, config('app.timezone'))->format('d/m/Y');
    }

    private function formatDateTime(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return CarbonImmutable::parse((string) $value, config('app.timezone'))->format('d/m/Y H:i:s');
    }
}
