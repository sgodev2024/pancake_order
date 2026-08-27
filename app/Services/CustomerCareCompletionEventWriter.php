<?php

namespace App\Services;

use App\Data\ActivityLogEvent;
use App\Enums\ActivityLogAction;
use App\Enums\ActivityLogSubjectType;
use App\Models\CustomerCare;
use App\Models\CustomerCareAssignment;
use App\Models\User;
use Carbon\CarbonInterface;

class CustomerCareCompletionEventWriter
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly ?CustomerCareJourneySequenceService $sequenceService = null
    ) {}

    public function write(
        CustomerCare $customerCare,
        CustomerCareAssignment $assignment,
        User $actor,
        CarbonInterface $occurredAt
    ): void {
        $sequence = $this->sequence()->next($customerCare, $assignment);
        $action = ActivityLogAction::CUSTOMER_CARE_COMPLETED;
        $metadata = [
            'customer_care_id' => (int) $customerCare->getKey(),
            'assignment_id' => (int) $assignment->getKey(),
            'assignee_user_id' => (int) $assignment->assignee_user_id,
            'shop_id' => (int) $assignment->shop_id,
            'source_type' => $assignment->source_type,
            'source_id' => (int) $assignment->source_id,
            'care_sequence_number' => $sequence['number'],
            'sequence_scope' => $sequence['scope'],
            'sequence_basis' => CustomerCareJourneySequenceService::BASIS,
            'result' => null,
        ];

        if ($sequence['customer_id'] !== null) {
            $metadata['customer_id'] = $sequence['customer_id'];
        }

        if ($sequence['order_id'] !== null) {
            $metadata['order_id'] = $sequence['order_id'];
        }

        $metadata['pancake_customer_id'] = $customerCare->pancake_customer_id;
        $metadata['pancake_order_id'] = $customerCare->pancake_order_id;

        $this->activityLogService->write(new ActivityLogEvent(
            action: $action,
            source: 'user',
            subjectType: ActivityLogSubjectType::CUSTOMER_CARE,
            subjectId: (string) $customerCare->getKey(),
            shopId: (int) $assignment->shop_id,
            shopName: $assignment->shop?->name,
            actorUserId: (int) $actor->getKey(),
            actorName: $actor->name,
            targetUserId: (int) $assignment->assignee_user_id,
            targetUserName: $assignment->assignee?->name,
            pancakeOrderId: $customerCare->pancake_order_id,
            pancakeCustomerId: $customerCare->pancake_customer_id,
            occurredAt: $occurredAt,
            idempotencyKey: $action->value.':care:'.$customerCare->getKey(),
            oldValues: [
                'status' => 0,
                'time_care' => null,
                'assignment_cared_at' => null,
            ],
            newValues: [
                'status' => 1,
                'time_care' => $occurredAt->format('Y-m-d H:i:s'),
                'assignment_cared_at' => $occurredAt->format('Y-m-d H:i:s'),
            ],
            metadata: $metadata,
        ));
    }

    private function sequence(): CustomerCareJourneySequenceService
    {
        return $this->sequenceService ?? app(CustomerCareJourneySequenceService::class);
    }
}
