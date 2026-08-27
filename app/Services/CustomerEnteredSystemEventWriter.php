<?php

namespace App\Services;

use App\Data\ActivityLogEvent;
use App\Enums\ActivityLogAction;
use App\Enums\ActivityLogSubjectType;
use App\Models\ActivityLog;
use App\Models\Customer;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Writes only the customer.entered_system event for an actually-created
 * local Customer. Customer creation boundaries own when this is called.
 */
class CustomerEnteredSystemEventWriter
{
    public function __construct(
        private readonly ActivityLogService $activityLogService
    ) {}

    public function write(
        Customer $customer,
        string $ingestionPath,
        DateTimeInterface|string|null $occurredAt = null,
        ?string $acquisitionChannel = null
    ): ActivityLog {
        $customerId = (int) $customer->getKey();
        $shopId = (int) $customer->shop_id;

        if ($customerId < 1 || $shopId < 1) {
            throw new InvalidArgumentException(
                'A customer.entered_system event requires persisted local customer and shop IDs.'
            );
        }

        $ingestionPath = trim($ingestionPath);
        if ($ingestionPath === '') {
            throw new InvalidArgumentException('Customer entry ingestion path is required.');
        }

        return $this->activityLogService->write(new ActivityLogEvent(
            action: ActivityLogAction::CUSTOMER_ENTERED_SYSTEM,
            source: 'system',
            subjectType: ActivityLogSubjectType::CUSTOMER,
            subjectId: (string) $customerId,
            shopId: $shopId,
            pancakeCustomerId: $customer->pancake_customer_id,
            occurredAt: $occurredAt ?? $customer->created_at,
            idempotencyKey: sprintf(
                'customer.entered_system:shop:%d:customer:%d',
                $shopId,
                $customerId
            ),
            metadata: [
                'customer_id' => $customerId,
                'shop_id' => $shopId,
                'pancake_customer_id' => $customer->pancake_customer_id,
                'ingestion_path' => $ingestionPath,
                'acquisition_channel' => $acquisitionChannel,
            ],
        ));
    }
}
