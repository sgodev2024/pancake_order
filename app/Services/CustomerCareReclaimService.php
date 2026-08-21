<?php

namespace App\Services;

use App\Models\CustomerCareAssignment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class CustomerCareReclaimService
{
    public const RESULT_ELIGIBLE = 'eligible';

    public const RESULT_NOT_YET_DUE = 'not_yet_due';

    public const RESULT_ALREADY_CARED = 'already_cared';

    public const RESULT_ANOMALY = 'anomaly';

    public const RESULT_INVALID_RELATION = 'invalid_relation';

    public const RESULT_SKIPPED = 'skipped';

    /**
     * Build a read-only preview from active assignment records.
     *
     * @return array{reference_date: string, rows: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function preview(
        CarbonInterface $referenceTime,
        ?int $shopId = null,
        int $limit = 100,
        int $chunkSize = 200
    ): array {
        $referenceDate = CarbonImmutable::instance($referenceTime)
            ->setTimezone(config('app.timezone'))
            ->startOfDay();
        $rows = [];
        $summary = [
            'scanned' => 0,
            'eligible' => 0,
            'not_yet_due' => 0,
            'already_cared' => 0,
            'anomaly' => 0,
            'invalid_relation' => 0,
            'skipped' => 0,
        ];

        foreach ([true, false] as $due) {
            if (count($rows) >= $limit) {
                break;
            }

            $batchSize = min($chunkSize, max(1, $limit - count($rows)));

            $this->previewQuery($referenceDate, $shopId, $due)
                ->chunkById($batchSize, function ($assignments) use (
                    &$rows,
                    &$summary,
                    $referenceDate,
                    $limit
                ) {
                    foreach ($assignments as $assignment) {
                        if (count($rows) >= $limit) {
                            return false;
                        }

                        $evaluation = $this->evaluate($assignment, $referenceDate);
                        $rows[] = $evaluation;
                        $summary['scanned']++;
                        $summary[$evaluation['result']]++;

                        if ($evaluation['result'] !== self::RESULT_ELIGIBLE) {
                            $summary['skipped']++;
                        }
                    }

                    return count($rows) < $limit;
                });
        }

        return [
            'reference_date' => $referenceDate->toDateString(),
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    /**
     * Evaluate one persisted assignment without mutating it or its relations.
     *
     * @return array<string, mixed>
     */
    public function evaluate(
        CustomerCareAssignment $assignment,
        CarbonInterface $referenceTime
    ): array {
        $referenceDate = CarbonImmutable::instance($referenceTime)
            ->setTimezone(config('app.timezone'))
            ->startOfDay();

        $assignment->loadMissing([
            'customerCare:id,status,time_care',
            'shop' => fn ($query) => $query->withTrashed()->select('id', 'name', 'deleted_at'),
            'assignee:id,name',
        ]);

        if ($assignment->status !== CustomerCareAssignment::STATUS_ACTIVE) {
            return $this->row(
                $assignment,
                self::RESULT_SKIPPED,
                'Assignment is not active.'
            );
        }

        if ($assignment->cared_at !== null) {
            return $this->row(
                $assignment,
                self::RESULT_ALREADY_CARED,
                'Assignment already has a server-side care marker.'
            );
        }

        if ($assignment->customerCare === null) {
            return $this->row(
                $assignment,
                self::RESULT_INVALID_RELATION,
                'CustomerCare relation is missing.'
            );
        }

        if ($assignment->shop === null) {
            return $this->row(
                $assignment,
                self::RESULT_INVALID_RELATION,
                'Shop relation is missing.'
            );
        }

        if ($assignment->shop->trashed()) {
            return $this->row(
                $assignment,
                self::RESULT_INVALID_RELATION,
                'Shop is soft deleted.'
            );
        }

        $careStatus = $assignment->customerCare->status;
        $hasCareTime = $assignment->customerCare->time_care !== null;

        if (! in_array($careStatus, [0, 1, '0', '1'], true)) {
            return $this->row(
                $assignment,
                self::RESULT_ANOMALY,
                'CustomerCare status is outside the supported 0/1 states.'
            );
        }

        if ((int) $careStatus === 1 && ! $hasCareTime) {
            return $this->row(
                $assignment,
                self::RESULT_ANOMALY,
                'CustomerCare has status=1 but time_care is null.'
            );
        }

        if ((int) $careStatus === 0 && $hasCareTime) {
            return $this->row(
                $assignment,
                self::RESULT_ANOMALY,
                'CustomerCare has status=0 but time_care is set.'
            );
        }

        if ((int) $careStatus === 1) {
            return $this->row(
                $assignment,
                self::RESULT_ALREADY_CARED,
                'Persisted CustomerCare state confirms completed care.'
            );
        }

        if ($assignment->reclaim_eligible_on->isAfter($referenceDate)) {
            return $this->row(
                $assignment,
                self::RESULT_NOT_YET_DUE,
                'Reclaim eligibility date has not been reached.'
            );
        }

        return $this->row(
            $assignment,
            self::RESULT_ELIGIBLE,
            'Active assignment is due and has no completed care.'
        );
    }

    private function previewQuery(
        CarbonInterface $referenceDate,
        ?int $shopId,
        bool $due
    ): Builder {
        return CustomerCareAssignment::query()
            ->where('status', CustomerCareAssignment::STATUS_ACTIVE)
            ->when(
                $due,
                fn (Builder $query) => $query->where(
                    'reclaim_eligible_on',
                    '<=',
                    $referenceDate->toDateString()
                ),
                fn (Builder $query) => $query->where(
                    'reclaim_eligible_on',
                    '>',
                    $referenceDate->toDateString()
                )
            )
            ->when($shopId !== null, fn (Builder $query) => $query->where('shop_id', $shopId))
            ->with([
                'customerCare:id,status,time_care',
                'shop' => fn ($query) => $query->withTrashed()->select('id', 'name', 'deleted_at'),
                'assignee:id,name',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        CustomerCareAssignment $assignment,
        string $result,
        string $reason
    ): array {
        $careStatus = $assignment->customerCare === null
            ? 'missing'
            : sprintf(
                'status=%s, time_care=%s',
                (string) $assignment->customerCare->status,
                $assignment->customerCare->time_care === null ? 'null' : 'set'
            );

        return [
            'assignment_id' => $assignment->id,
            'source' => $assignment->source_type,
            'source_id' => $assignment->source_id,
            'shop' => $assignment->shop === null
                ? (string) $assignment->shop_id
                : "{$assignment->shop_id} - {$assignment->shop->name}",
            'customer_care_id' => $assignment->customer_care_id,
            'assignee' => $assignment->assignee === null
                ? (string) $assignment->assignee_user_id
                : "{$assignment->assignee_user_id} - {$assignment->assignee->name}",
            'assigned_at' => $assignment->assigned_at->format('Y-m-d H:i:s'),
            'eligible_on' => $assignment->reclaim_eligible_on->toDateString(),
            'care_status' => $careStatus,
            'result' => $result,
            'reason' => $reason,
        ];
    }
}
