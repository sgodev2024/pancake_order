<?php

namespace App\Services;

use App\Models\ActivityLog;
use InvalidArgumentException;

class ActivityLogService
{
    /**
     * Create one immutable business activity log entry.
     *
     * The caller supplies the actor explicitly so this service can also be
     * used from queued jobs and Pancake webhooks, where no authenticated user
     * is available. Do not pass a complete request or external payload here.
     */
    public function log(
        string $action,
        string $source,
        ?int $actorUserId,
        ?string $actorName,
        ?int $targetUserId,
        ?string $targetUserName,
        ?int $shopId,
        ?string $shopName,
        string $subjectType,
        string|int|null $subjectId = null,
        string|int|null $pancakeOrderId = null,
        string|int|null $pancakeCustomerId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null
    ): ActivityLog {
        $action = trim($action);
        $source = trim($source);
        $subjectType = trim($subjectType);

        if ($action === '' || $source === '' || $subjectType === '') {
            throw new InvalidArgumentException('Action, source, and subject type are required.');
        }

        return ActivityLog::create([
            "actor_user_id" => $actorUserId,
            "actor_name" => $this->normalizeIdentifier($actorName),
            "target_user_id" => $targetUserId,
            "target_user_name" => $this->normalizeIdentifier($targetUserName),
            "source" => $source,
            "action" => $action,
            "shop_id" => $shopId,
            "shop_name" => $this->normalizeIdentifier($shopName),
            "subject_type" => $subjectType,
            "subject_id" => $this->normalizeIdentifier($subjectId),
            "pancake_order_id" => $this->normalizeIdentifier($pancakeOrderId),
            "pancake_customer_id" => $this->normalizeIdentifier($pancakeCustomerId),
            "old_values" => $this->removeSensitiveValues($oldValues),
            "new_values" => $this->removeSensitiveValues($newValues),
            "metadata" => $this->removeSensitiveValues($metadata)
        ]);
    }

    private function normalizeIdentifier(string|int|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Remove common credentials recursively before values are persisted.
     * Business callers should still pass only the fields needed for an audit.
     */
    private function removeSensitiveValues(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $sanitized = [];

        foreach ($values as $key => $value) {
            if ($this->isSensitiveKey($key)) {
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

        foreach (["password", "token", "secret", "api_key", "authorization", "cookie"] as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
