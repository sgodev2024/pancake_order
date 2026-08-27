<?php

namespace App\Data;

use App\Enums\ActivityLogAction;
use App\Enums\ActivityLogSubjectType;
use DateTimeInterface;

/**
 * Input contract for newly written activity-log events.
 *
 * IDs in subjectId and the metadata local-id fields are local database IDs.
 * Pancake identifiers belong in pancakeOrderId, pancakeCustomerId, or the
 * explicitly documented external-id metadata fields.
 */
final readonly class ActivityLogEvent
{
    public function __construct(
        public string|ActivityLogAction $action,
        public string $source,
        public string|ActivityLogSubjectType $subjectType,
        public string|int $subjectId,
        public ?int $shopId = null,
        public ?string $shopName = null,
        public ?int $actorUserId = null,
        public ?string $actorName = null,
        public ?int $targetUserId = null,
        public ?string $targetUserName = null,
        public string|int|null $pancakeOrderId = null,
        public string|int|null $pancakeCustomerId = null,
        public DateTimeInterface|string|null $occurredAt = null,
        public ?string $idempotencyKey = null,
        public ?array $oldValues = null,
        public ?array $newValues = null,
        public ?array $metadata = null,
    ) {}
}
