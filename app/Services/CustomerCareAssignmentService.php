<?php

namespace App\Services;

use App\Data\ActivityLogEvent;
use App\Enums\ActivityLogAction;
use App\Enums\ActivityLogSubjectType;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use InvalidArgumentException;

class CustomerCareAssignmentService
{
    public function __construct(
        private readonly ?ActivityLogService $activityLogService = null
    ) {}

    public function markAsCared(
        CustomerCare $customerCare,
        ?CarbonInterface $caredAt = null
    ): ?CustomerCareAssignment {
        if ((int) $customerCare->status !== 1 || $customerCare->time_care === null) {
            return null;
        }

        $assignments = CustomerCareAssignment::query()
            ->where('customer_care_id', $customerCare->getKey())
            ->where('status', CustomerCareAssignment::STATUS_ACTIVE)
            ->lockForUpdate()
            ->get();

        if ($assignments->count() > 1) {
            throw new DomainException('CustomerCare có nhiều phân công đang hoạt động; không thể đánh dấu chăm sóc an toàn.');
        }

        $assignment = $assignments->first();

        if ($assignment === null || $assignment->cared_at !== null) {
            return $assignment;
        }

        $assignment->update([
            'cared_at' => $caredAt ?? now(),
        ]);

        return $assignment;
    }

    public function create(
        CustomerCare $customerCare,
        int $shopId,
        string $sourceType,
        int $sourceId,
        User $assignee,
        CarbonInterface $assignedAt,
        CarbonInterface $scheduledCareDate
    ): CustomerCareAssignment {
        if (! in_array($sourceType, [
            CustomerCareAssignment::SOURCE_ORDER,
            CustomerCareAssignment::SOURCE_IMPORTED_OPPORTUNITY,
        ], true)) {
            throw new InvalidArgumentException('Loại nguồn phân công không hợp lệ.');
        }

        if (! $customerCare->exists || ! $assignee->exists) {
            throw new InvalidArgumentException('Customer care và người được phân công phải tồn tại.');
        }

        if (! $assignee->canReceiveCustomerCareAssignments()) {
            throw new DomainException('Người được phân công phải thuộc bộ phận CSKH.');
        }

        if ((int) $customerCare->shop_id !== $shopId) {
            throw new InvalidArgumentException('Customer care không thuộc cửa hàng phân công.');
        }

        $hasActiveAssignment = CustomerCareAssignment::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', CustomerCareAssignment::STATUS_ACTIVE)
            ->whereNull('cared_at')
            ->exists();

        if ($hasActiveAssignment) {
            throw new DomainException('Nguồn CSKH này đã có phân công đang hoạt động.');
        }

        return CustomerCareAssignment::create([
            'shop_id' => $shopId,
            'customer_care_id' => $customerCare->getKey(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'assignee_user_id' => $assignee->getKey(),
            'assignee_pancake_user_id' => $assignee->pancake_user_id,
            'assigned_at' => $assignedAt,
            'reclaim_eligible_on' => CustomerCareAssignment::calculateReclaimEligibleOn($scheduledCareDate),
            'status' => CustomerCareAssignment::STATUS_ACTIVE,
            'cared_at' => null,
        ]);
    }

    /**
     * Create a new assignment and record its initial/reassignment journey
     * event in the transaction owned by the caller.
     *
     * The source identity is deliberately local: a source type, source row,
     * and shop together define one assignment history. A new CCA row alone
     * is not enough to call an assignment initial.
     */
    public function createWithJourneyEvent(
        CustomerCare $customerCare,
        int $shopId,
        string $sourceType,
        int $sourceId,
        User $assignee,
        CarbonInterface $assignedAt,
        CarbonInterface $scheduledCareDate,
        User $actor,
        ?string $shopName = null
    ): CustomerCareAssignment {
        $previousAssignment = $this->findPreviousAssignment($sourceType, $sourceId, $shopId);
        $assignment = $this->create(
            $customerCare,
            $shopId,
            $sourceType,
            $sourceId,
            $assignee,
            $assignedAt,
            $scheduledCareDate
        );

        $this->writeAssignmentJourneyEvent(
            $assignment,
            $actor,
            $previousAssignment,
            $shopName
        );

        return $assignment;
    }

    /**
     * Return the latest prior assignment for one local source journey.
     *
     * This method is the single first-assignment/reassignment rule used by
     * both Order and ImportedOpportunity assignment flows.
     */
    public function findPreviousAssignment(
        string $sourceType,
        int $sourceId,
        int $shopId
    ): ?CustomerCareAssignment {
        return CustomerCareAssignment::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('shop_id', $shopId)
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    private function writeAssignmentJourneyEvent(
        CustomerCareAssignment $assignment,
        User $actor,
        ?CustomerCareAssignment $previousAssignment,
        ?string $shopName
    ): void {
        $isReassignment = $previousAssignment !== null;
        $action = $isReassignment
            ? ActivityLogAction::CUSTOMER_CARE_REASSIGNED
            : ActivityLogAction::CUSTOMER_CARE_ASSIGNED;
        $metadata = [
            'customer_care_id' => (int) $assignment->customer_care_id,
            'assignment_id' => (int) $assignment->id,
            'assignee_user_id' => (int) $assignment->assignee_user_id,
            'shop_id' => (int) $assignment->shop_id,
            'source_type' => $assignment->source_type,
            'source_id' => (int) $assignment->source_id,
            'assigned_at' => $assignment->assigned_at->toISOString(),
            'assignee_pancake_user_id' => $assignment->assignee_pancake_user_id,
        ];
        $oldValues = null;
        $newValues = [
            'status' => CustomerCareAssignment::STATUS_ACTIVE,
            'assignment_id' => (int) $assignment->id,
            'assignee_user_id' => (int) $assignment->assignee_user_id,
            'assigned_user_id' => (int) $assignment->assignee_user_id,
            'assigned_at' => $assignment->assigned_at->format('Y-m-d H:i:s'),
        ];

        if ($previousAssignment !== null) {
            $metadata['previous_assignment_id'] = (int) $previousAssignment->id;
            $metadata['new_assignment_id'] = (int) $assignment->id;
            $metadata['previous_assignee_user_id'] = (int) $previousAssignment->assignee_user_id;
            $metadata['new_assignee_user_id'] = (int) $assignment->assignee_user_id;
            $metadata['previous_customer_care_id'] = (int) $previousAssignment->customer_care_id;
            $metadata['previous_assigned_at'] = $previousAssignment->assigned_at->toISOString();

            $oldValues = [
                'status' => $previousAssignment->status,
                'assignment_id' => (int) $previousAssignment->id,
                'customer_care_id' => (int) $previousAssignment->customer_care_id,
                'assignee_user_id' => (int) $previousAssignment->assignee_user_id,
                'assigned_at' => $previousAssignment->assigned_at->format('Y-m-d H:i:s'),
                'reclaimed_at' => $previousAssignment->reclaimed_at?->format('Y-m-d H:i:s'),
                'reclaim_reason' => $previousAssignment->reclaim_reason,
            ];
        }

        $customerCare = $assignment->customerCare()->first();

        $this->activityLog()->write(new ActivityLogEvent(
            action: $action,
            source: 'user',
            subjectType: ActivityLogSubjectType::CUSTOMER_CARE_ASSIGNMENT,
            subjectId: (string) $assignment->id,
            shopId: (int) $assignment->shop_id,
            shopName: $shopName,
            actorUserId: (int) $actor->getKey(),
            actorName: $actor->name,
            targetUserId: (int) $assignment->assignee_user_id,
            targetUserName: $assignment->assignee?->name,
            pancakeOrderId: $customerCare?->pancake_order_id,
            pancakeCustomerId: $customerCare?->pancake_customer_id,
            occurredAt: $assignment->assigned_at,
            idempotencyKey: $action->value.':assignment:'.$assignment->id,
            oldValues: $oldValues,
            newValues: $newValues,
            metadata: $metadata,
        ));
    }

    private function activityLog(): ActivityLogService
    {
        return $this->activityLogService ?? app(ActivityLogService::class);
    }
}
