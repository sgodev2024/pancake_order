<?php

namespace App\Services;

use App\Data\ActivityLogEvent;
use App\Enums\ActivityLogAction;
use App\Enums\ActivityLogSubjectType;
use App\Exceptions\ActivityLogIdempotencyConflictException;
use App\Models\ActivityLog;
use App\Support\ActivityLogMetadataContract;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

class ActivityLogService
{
    /**
     * Write a new Customer Journey event using the hardened contract.
     *
     * MVP Journey actions must have a local shop, a local subject ID, a
     * stable subject type, and an idempotency key. Operational/repair actions
     * outside the MVP enum remain available through the legacy log() wrapper.
     */
    public function write(ActivityLogEvent $event): ActivityLog
    {
        return $this->persist($event, true);
    }

    /**
     * Backward-compatible positional API for existing activity-log callers.
     *
     * New writers should use write() with ActivityLogEvent named arguments.
     * The new nullable arguments are appended so existing callers remain
     * source-compatible and legacy schemas can still omit them.
     */
    public function log(
        string|ActivityLogAction $action,
        string $source,
        ?int $actorUserId,
        ?string $actorName,
        ?int $targetUserId,
        ?string $targetUserName,
        ?int $shopId,
        ?string $shopName,
        string|ActivityLogSubjectType $subjectType,
        string|int|null $subjectId = null,
        string|int|null $pancakeOrderId = null,
        string|int|null $pancakeCustomerId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        DateTimeInterface|string|null $occurredAt = null,
        ?string $idempotencyKey = null
    ): ActivityLog {
        return $this->persist(new ActivityLogEvent(
            action: $action,
            source: $source,
            subjectType: $subjectType,
            subjectId: $subjectId ?? '',
            shopId: $shopId,
            shopName: $shopName,
            actorUserId: $actorUserId,
            actorName: $actorName,
            targetUserId: $targetUserId,
            targetUserName: $targetUserName,
            pancakeOrderId: $pancakeOrderId,
            pancakeCustomerId: $pancakeCustomerId,
            occurredAt: $occurredAt,
            idempotencyKey: $idempotencyKey,
            oldValues: $oldValues,
            newValues: $newValues,
            metadata: $metadata,
        ), false);
    }

    private function persist(ActivityLogEvent $event, bool $enforceJourneyContract): ActivityLog
    {
        $action = $this->normalizeAction($event->action);
        $source = $this->normalizeRequiredText($event->source, 'Source');
        $subjectType = $this->normalizeSubjectType($event->subjectType);
        $subjectId = $this->normalizeIdentifier($event->subjectId);
        $occurredAt = $this->normalizeOccurredAt($event->occurredAt);
        $idempotencyKey = $this->normalizeIdempotencyKey($event->idempotencyKey);
        $metadata = $this->removeSensitiveValues($event->metadata);
        $journeyAction = ActivityLogAction::tryFrom($action);

        if ($event->shopId !== null && $event->shopId < 1) {
            throw new InvalidArgumentException('Shop ID must be a positive local ID.');
        }

        if ($enforceJourneyContract && $journeyAction !== null) {
            $this->validateJourneyContract(
                $journeyAction,
                $event->shopId,
                $subjectType,
                $subjectId,
                $idempotencyKey,
                $metadata
            );
        }

        $attributes = [
            'actor_user_id' => $event->actorUserId,
            'actor_name' => $this->normalizeIdentifier($event->actorName),
            'target_user_id' => $event->targetUserId,
            'target_user_name' => $this->normalizeIdentifier($event->targetUserName),
            'source' => $source,
            'action' => $action,
            'shop_id' => $event->shopId,
            'shop_name' => $this->normalizeIdentifier($event->shopName),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'pancake_order_id' => $this->normalizeIdentifier($event->pancakeOrderId),
            'pancake_customer_id' => $this->normalizeIdentifier($event->pancakeCustomerId),
            'old_values' => $this->removeSensitiveValues($event->oldValues),
            'new_values' => $this->removeSensitiveValues($event->newValues),
            'metadata' => $metadata,
        ];

        // Omitting null optional fields preserves compatibility with the
        // lightweight legacy schemas used by existing operational tests.
        if ($occurredAt !== null) {
            $attributes['occurred_at'] = $occurredAt;
        }

        if ($idempotencyKey !== null) {
            $attributes['idempotency_key'] = $idempotencyKey;
        }

        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($idempotencyKey);

            if ($existing !== null) {
                $this->assertSameEvent($existing, $attributes);

                return $existing;
            }
        }

        try {
            return ActivityLog::create($attributes);
        } catch (QueryException $exception) {
            // A concurrent retry can win the unique-key race between the
            // lookup above and INSERT. Only recover when the same key now
            // resolves to an exact event; all other database errors surface.
            if ($idempotencyKey === null) {
                throw $exception;
            }

            $existing = $this->findByIdempotencyKey($idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            $this->assertSameEvent($existing, $attributes);

            return $existing;
        }
    }

    private function validateJourneyContract(
        ActivityLogAction $action,
        ?int $shopId,
        ?string $subjectType,
        ?string $subjectId,
        ?string $idempotencyKey,
        ?array $metadata
    ): void {
        if ($shopId === null) {
            throw new InvalidArgumentException('A Customer Journey event requires a local shop ID.');
        }

        if ($subjectId === null) {
            throw new InvalidArgumentException('A Customer Journey event requires a local subject ID.');
        }

        if (! ctype_digit($subjectId) || (int) $subjectId < 1) {
            throw new InvalidArgumentException('A Customer Journey subject ID must be a positive local ID.');
        }

        if (! in_array($subjectType, $action->expectedSubjectTypes(), true)) {
            throw new InvalidArgumentException(
                'The subject type does not match the Customer Journey action contract.'
            );
        }

        if ($idempotencyKey === null) {
            throw new InvalidArgumentException('A Customer Journey event requires an idempotency key.');
        }

        $contract = ActivityLogMetadataContract::for($action);

        if ($contract === null || $metadata === null) {
            throw new InvalidArgumentException('A Customer Journey event requires contract metadata.');
        }

        foreach ($contract['required_local_ids'] as $key) {
            if (! array_key_exists($key, $metadata) || ! $this->isPositiveLocalId($metadata[$key])) {
                throw new InvalidArgumentException(
                    'Customer Journey metadata must include positive local IDs for internal relationships.'
                );
            }
        }

        foreach ($contract['optional_local_ids'] as $key) {
            if (array_key_exists($key, $metadata)
                && $metadata[$key] !== null
                && ! $this->isPositiveLocalId($metadata[$key])) {
                throw new InvalidArgumentException(
                    'Optional Customer Journey metadata IDs must be positive local IDs or null.'
                );
            }
        }
    }

    private function isPositiveLocalId(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0;
    }

    private function normalizeAction(string|ActivityLogAction $action): string
    {
        return $action instanceof ActivityLogAction
            ? $action->value
            : $this->normalizeRequiredText($action, 'Action');
    }

    private function normalizeSubjectType(string|ActivityLogSubjectType $subjectType): string
    {
        return $subjectType instanceof ActivityLogSubjectType
            ? $subjectType->value
            : $this->normalizeRequiredText($subjectType, 'Subject type');
    }

    private function normalizeRequiredText(string $value, string $label): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException($label.' is required.');
        }

        return $value;
    }

    private function normalizeIdentifier(string|int|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeOccurredAt(DateTimeInterface|string|null $occurredAt): ?CarbonImmutable
    {
        if ($occurredAt === null) {
            return null;
        }

        $timezone = config('app.timezone');

        return ($occurredAt instanceof DateTimeInterface
            ? CarbonImmutable::instance($occurredAt)
            : CarbonImmutable::parse($occurredAt, $timezone)
        )->setTimezone($timezone);
    }

    private function normalizeIdempotencyKey(?string $idempotencyKey): ?string
    {
        if ($idempotencyKey === null) {
            return null;
        }

        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '') {
            return null;
        }

        if (strlen($idempotencyKey) > 191) {
            throw new InvalidArgumentException('Idempotency key must not exceed 191 characters.');
        }

        return $idempotencyKey;
    }

    private function findByIdempotencyKey(string $idempotencyKey): ?ActivityLog
    {
        return ActivityLog::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertSameEvent(ActivityLog $existing, array $attributes): void
    {
        foreach ([
            'actor_user_id',
            'actor_name',
            'target_user_id',
            'target_user_name',
            'source',
            'action',
            'shop_id',
            'shop_name',
            'subject_type',
            'subject_id',
            'pancake_order_id',
            'pancake_customer_id',
            'occurred_at',
            'old_values',
            'new_values',
            'metadata',
        ] as $field) {
            $expected = $attributes[$field] ?? null;
            $actual = $existing->getAttribute($field);

            if (! $this->valuesEquivalent($field, $actual, $expected)) {
                throw new ActivityLogIdempotencyConflictException(
                    'The idempotency key is already associated with a different activity event.'
                );
            }
        }
    }

    private function valuesEquivalent(string $field, mixed $actual, mixed $expected): bool
    {
        if ($field === 'occurred_at') {
            return $this->formatDateTime($actual) === $this->formatDateTime($expected);
        }

        if (is_array($actual) || is_array($expected)) {
            return $this->canonicalize($actual) === $this->canonicalize($expected);
        }

        if ($actual === $expected) {
            return true;
        }

        return $actual !== null
            && $expected !== null
            && in_array($field, [
                'actor_user_id',
                'target_user_id',
                'shop_id',
                'subject_id',
            ], true)
            && (string) $actual === (string) $expected;
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value)->format('Y-m-d H:i:s');
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
        }

        $canonical = [];

        foreach ($value as $key => $item) {
            $canonical[(string) $key] = $this->canonicalize($item);
        }

        ksort($canonical);

        return $canonical;
    }

    /**
     * Remove credentials and raw-payload-shaped fields recursively before
     * values are persisted. Callers should still pass only audit context.
     */
    private function removeSensitiveValues(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $sanitized = [];

        foreach ($values as $key => $value) {
            if ($this->isSensitiveKey($key) || $this->isRawPayloadKey($key)) {
                continue;
            }

            $sanitized[$key] = is_array($value)
                ? $this->removeSensitiveValues($value)
                : $value;
        }

        return $sanitized;
    }

    private function isSensitiveKey(string|int $key): bool
    {
        $key = strtolower((string) $key);

        foreach (['password', 'token', 'secret', 'api_key', 'authorization', 'cookie'] as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function isRawPayloadKey(string|int $key): bool
    {
        $key = strtolower((string) $key);

        foreach (['payload', 'full_data', 'raw_data'] as $fragment) {
            if ($key === $fragment || str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
