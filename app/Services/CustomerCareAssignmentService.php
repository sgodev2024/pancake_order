<?php

namespace App\Services;

use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use InvalidArgumentException;

class CustomerCareAssignmentService
{
    public function markAsCared(CustomerCare $customerCare): ?CustomerCareAssignment
    {
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
            'cared_at' => now(),
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
}
