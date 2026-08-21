<?php

namespace App\Services;

use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\ImportedOpportunity;
use App\Models\Order;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class CustomerCareReclaimService
{
    public const RECLAIM_REASON = 'Quá 3 ngày chưa chăm sóc';

    public const RESULT_ELIGIBLE = 'eligible';

    public const RESULT_RECLAIMED = 'reclaimed';

    public const RESULT_ERROR = 'error';

    public const RESULT_NOT_YET_DUE = 'not_yet_due';

    public const RESULT_ALREADY_CARED = 'already_cared';

    public const RESULT_ANOMALY = 'anomaly';

    public const RESULT_INVALID_RELATION = 'invalid_relation';

    public const RESULT_SKIPPED = 'skipped';

    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {}

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

    /**
     * Execute eligible preview rows with one isolated transaction per assignment.
     *
     * @return array{reference_date: string, rows: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function execute(
        CarbonInterface $referenceTime,
        ?int $shopId = null,
        int $limit = 100,
        int $chunkSize = 200
    ): array {
        $preview = $this->preview($referenceTime, $shopId, $limit, $chunkSize);
        $rows = [];
        $summary = [
            'scanned' => $preview['summary']['scanned'],
            'eligible' => $preview['summary']['eligible'],
            'reclaimed' => 0,
            'skipped' => 0,
            'already_cared' => 0,
            'anomaly' => 0,
            'invalid_relation' => 0,
            'errors' => 0,
        ];

        foreach ($preview['rows'] as $previewRow) {
            $row = $previewRow;

            if ($previewRow['result'] === self::RESULT_ELIGIBLE) {
                try {
                    $row = $this->executeOne(
                        (int) $previewRow['assignment_id'],
                        $referenceTime
                    );
                } catch (Throwable $exception) {
                    $row['result'] = self::RESULT_ERROR;
                    $row['reason'] = $exception->getMessage();
                }
            }

            $this->incrementExecutionSummary($summary, $row['result']);
            $rows[] = $row;
        }

        return [
            'reference_date' => $preview['reference_date'],
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    /**
     * Reclaim exactly one assignment after a persisted, locked eligibility re-check.
     *
     * The initial read only discovers the CustomerCare lock key. The transaction
     * always locks CustomerCare before CustomerCareAssignment to match the care flow.
     *
     * @return array<string, mixed>
     */
    public function executeOne(
        int $assignmentId,
        ?CarbonInterface $referenceTime = null
    ): array {
        $referenceTime ??= CarbonImmutable::now(config('app.timezone'));
        $referenceDate = CarbonImmutable::instance($referenceTime)
            ->setTimezone(config('app.timezone'))
            ->startOfDay();
        $snapshot = CustomerCareAssignment::query()
            ->select(['id', 'customer_care_id'])
            ->find($assignmentId);

        if ($snapshot === null) {
            return $this->missingAssignmentRow($assignmentId);
        }

        return DB::transaction(function () use ($snapshot, $assignmentId, $referenceDate) {
            // Global lock order: CustomerCare -> CustomerCareAssignment -> Shop -> source.
            $customerCare = CustomerCare::query()
                ->whereKey($snapshot->customer_care_id)
                ->lockForUpdate()
                ->first();

            if ($customerCare === null) {
                return $this->missingAssignmentRelationRow(
                    $assignmentId,
                    $snapshot,
                    'CustomerCare relation is missing.'
                );
            }

            $assignment = CustomerCareAssignment::query()
                ->whereKey($assignmentId)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                return $this->missingAssignmentRow($assignmentId);
            }

            $assignment->setRelation('customerCare', $customerCare);

            if ((int) $assignment->customer_care_id !== (int) $customerCare->getKey()) {
                return $this->row(
                    $assignment,
                    self::RESULT_INVALID_RELATION,
                    'CustomerCare relation changed before the locked re-check.'
                );
            }

            $shop = Shop::withTrashed()
                ->whereKey($assignment->shop_id)
                ->lockForUpdate()
                ->first();
            $assignment->setRelation('shop', $shop);
            $assignment->setRelation('assignee', $assignment->assignee()->first());

            $eligibility = $this->evaluateLocked($assignment, $customerCare, $shop, $referenceDate);

            if ($eligibility !== null) {
                return $eligibility;
            }

            $sourceResult = $this->lockAndValidateSource($assignment);

            if (is_array($sourceResult)) {
                return $sourceResult;
            }

            $reclaimedAt = CarbonImmutable::now(config('app.timezone'));
            $assignment->update([
                'status' => CustomerCareAssignment::STATUS_RECLAIMED,
                'reclaimed_at' => $reclaimedAt,
                'reclaim_reason' => self::RECLAIM_REASON,
            ]);

            if ($sourceResult instanceof ImportedOpportunity) {
                $sourceResult->update(['status' => 0]);
            }

            $this->activityLogService->log(
                'customer_care.reclaimed',
                'system',
                null,
                'Hệ thống',
                $assignment->assignee_user_id,
                $assignment->assignee?->name,
                $assignment->shop_id,
                $shop?->name,
                $assignment->source_type,
                $assignment->source_id,
                $customerCare->pancake_order_id,
                $customerCare->pancake_customer_id,
                [
                    'status' => CustomerCareAssignment::STATUS_ACTIVE,
                    'assignee_user_id' => $assignment->assignee_user_id,
                    'assignee_pancake_user_id' => $assignment->assignee_pancake_user_id,
                    'assigned_at' => $assignment->assigned_at->format('Y-m-d H:i:s'),
                    'reclaim_eligible_on' => $assignment->reclaim_eligible_on->toDateString(),
                ],
                [
                    'status' => CustomerCareAssignment::STATUS_RECLAIMED,
                    'reclaimed_at' => $reclaimedAt->format('Y-m-d H:i:s'),
                    'reclaim_reason' => self::RECLAIM_REASON,
                ],
                [
                    'assignment_id' => $assignment->id,
                    'customer_care_id' => $customerCare->id,
                    'source_type' => $assignment->source_type,
                    'source_id' => $assignment->source_id,
                    'reason' => self::RECLAIM_REASON,
                ]
            );

            return $this->row(
                $assignment->refresh(),
                self::RESULT_RECLAIMED,
                self::RECLAIM_REASON
            );
        });
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
     * Return null only when every persisted eligibility condition still passes.
     *
     * @return array<string, mixed>|null
     */
    private function evaluateLocked(
        CustomerCareAssignment $assignment,
        CustomerCare $customerCare,
        ?Shop $shop,
        CarbonInterface $referenceDate
    ): ?array {
        if ($assignment->status !== CustomerCareAssignment::STATUS_ACTIVE) {
            return $this->row($assignment, self::RESULT_SKIPPED, 'Assignment is not active.');
        }

        if ($assignment->cared_at !== null) {
            return $this->row(
                $assignment,
                self::RESULT_ALREADY_CARED,
                'Assignment already has a server-side care marker.'
            );
        }

        if ($shop === null || (int) $customerCare->shop_id !== (int) $assignment->shop_id) {
            return $this->row(
                $assignment,
                self::RESULT_INVALID_RELATION,
                'Shop relation is missing or inconsistent.'
            );
        }

        if ($shop->trashed()) {
            return $this->row(
                $assignment,
                self::RESULT_INVALID_RELATION,
                'Shop is soft deleted.'
            );
        }

        $careStatus = $customerCare->status;
        $hasCareTime = $customerCare->time_care !== null;

        if (! in_array($careStatus, [0, 1, '0', '1'], true)) {
            return $this->row(
                $assignment,
                self::RESULT_ANOMALY,
                'CustomerCare status is outside the supported 0/1 states.'
            );
        }

        if (((int) $careStatus === 1) !== $hasCareTime) {
            return $this->row(
                $assignment,
                self::RESULT_ANOMALY,
                (int) $careStatus === 1
                    ? 'CustomerCare has status=1 but time_care is null.'
                    : 'CustomerCare has status=0 but time_care is set.'
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

        return null;
    }

    /**
     * Lock the source after CustomerCare and assignment locks.
     *
     * @return Order|ImportedOpportunity|array<string, mixed>
     */
    private function lockAndValidateSource(CustomerCareAssignment $assignment): Order|ImportedOpportunity|array
    {
        if ($assignment->source_type === CustomerCareAssignment::SOURCE_ORDER) {
            $order = Order::withTrashed()
                ->whereKey($assignment->source_id)
                ->lockForUpdate()
                ->first();

            if ($order === null || $order->trashed()) {
                return $this->row(
                    $assignment,
                    self::RESULT_INVALID_RELATION,
                    'Order source is missing or soft deleted.'
                );
            }

            if ((int) $order->shop_id !== (int) $assignment->shop_id) {
                return $this->row(
                    $assignment,
                    self::RESULT_INVALID_RELATION,
                    'Order source belongs to a different shop.'
                );
            }

            if ((int) $order->status !== 3) {
                return $this->row(
                    $assignment,
                    self::RESULT_ANOMALY,
                    'Order source is not in the expected status=3 pool state.'
                );
            }

            return $order;
        }

        if ($assignment->source_type === CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY) {
            $opportunity = ImportedOpportunity::query()
                ->whereKey($assignment->source_id)
                ->lockForUpdate()
                ->first();

            if ($opportunity === null) {
                return $this->row(
                    $assignment,
                    self::RESULT_INVALID_RELATION,
                    'ImportedOpportunity source is missing.'
                );
            }

            if ((int) $opportunity->shop_id !== (int) $assignment->shop_id) {
                return $this->row(
                    $assignment,
                    self::RESULT_INVALID_RELATION,
                    'ImportedOpportunity source belongs to a different shop.'
                );
            }

            if ((int) $opportunity->status !== 1) {
                return $this->row(
                    $assignment,
                    self::RESULT_ANOMALY,
                    'ImportedOpportunity source is not in the expected status=1 assigned state.'
                );
            }

            return $opportunity;
        }

        return $this->row(
            $assignment,
            self::RESULT_ANOMALY,
            'Assignment source type is unsupported.'
        );
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function incrementExecutionSummary(array &$summary, string $result): void
    {
        if ($result === self::RESULT_RECLAIMED) {
            $summary['reclaimed']++;

            return;
        }

        $summary['skipped']++;

        if ($result === self::RESULT_ALREADY_CARED) {
            $summary['already_cared']++;
        } elseif ($result === self::RESULT_ANOMALY) {
            $summary['anomaly']++;
        } elseif ($result === self::RESULT_INVALID_RELATION) {
            $summary['invalid_relation']++;
        } elseif ($result === self::RESULT_ERROR) {
            $summary['errors']++;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function missingAssignmentRow(int $assignmentId): array
    {
        return [
            'assignment_id' => $assignmentId,
            'source' => null,
            'source_id' => null,
            'shop' => null,
            'customer_care_id' => null,
            'assignee' => null,
            'assigned_at' => null,
            'eligible_on' => null,
            'care_status' => 'missing',
            'result' => self::RESULT_INVALID_RELATION,
            'reason' => 'Assignment is missing.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function missingAssignmentRelationRow(
        int $assignmentId,
        CustomerCareAssignment $snapshot,
        string $reason
    ): array {
        return [
            'assignment_id' => $assignmentId,
            'source' => null,
            'source_id' => null,
            'shop' => null,
            'customer_care_id' => $snapshot->customer_care_id,
            'assignee' => null,
            'assigned_at' => null,
            'eligible_on' => null,
            'care_status' => 'missing',
            'result' => self::RESULT_INVALID_RELATION,
            'reason' => $reason,
        ];
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
