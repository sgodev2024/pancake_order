<?php

namespace App\Services;

use App\Models\ActivityLog;

/**
 * Controlled public representation of a Customer Journey event.
 */
class CustomerJourneyTransformer
{
    /** @var array<string, list<string>> */
    private const METADATA_ALLOWLIST = [
        'customer.entered_system' => [
            'ingestion_path',
            'acquisition_channel',
        ],
        'customer_care.assigned' => [
            'customer_care_id',
            'assignment_id',
            'assignee_user_id',
            'source_type',
            'source_id',
        ],
        'customer_care.reassigned' => [
            'previous_assignment_id',
            'new_assignment_id',
            'previous_assignee_user_id',
            'new_assignee_user_id',
        ],
        'customer_care.reclaimed' => [
            'reclaim_reason',
            'manual_reason',
        ],
        'customer_care.completed' => [
            'customer_care_id',
            'assignment_id',
            'care_sequence_number',
            'sequence_scope',
            'result',
        ],
        'order.created' => [
            'order_id',
            'amount',
            'cash_amount',
            'quantity',
            'items',
            'ingestion_path',
        ],
    ];

    public function transform(ActivityLog $log): array
    {
        $occurredAt = $log->occurred_at ?? $log->created_at;

        return [
            'id' => (int) $log->getKey(),
            'action' => $log->action,
            'occurred_at' => $occurredAt?->toISOString(),
            'source' => $log->source,
            'actor' => $this->userIdentity(
                $log->actor_user_id,
                $log->actor_name ?? $log->actor?->name
            ),
            'target_user' => $this->userIdentity(
                $log->target_user_id,
                $log->target_user_name ?? $log->targetUser?->name
            ),
            'shop' => $log->shop_id === null
                ? null
                : [
                    'id' => (int) $log->shop_id,
                    'name' => $log->shop_name ?? $log->shop?->name,
                ],
            'subject' => [
                'type' => $log->subject_type,
                'id' => $log->subject_id === null ? null : (string) $log->subject_id,
            ],
            'pancake_order_id' => $log->pancake_order_id,
            'metadata' => $this->safeMetadata($log->action, $log->metadata),
        ];
    }

    private function userIdentity(mixed $id, ?string $name): ?array
    {
        if ($id === null) {
            return null;
        }

        return [
            'id' => (int) $id,
            'name' => $name,
        ];
    }

    private function safeMetadata(string $action, mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        $safe = [];
        foreach (self::METADATA_ALLOWLIST[$action] ?? [] as $key) {
            if (! array_key_exists($key, $metadata)) {
                continue;
            }

            if ($key === 'reclaim_reason' && ($metadata['reclaim_source'] ?? null) === 'manual') {
                continue;
            }

            if ($key === 'items') {
                $items = $this->safeItems($metadata[$key]);
                if ($items !== null) {
                    $safe[$key] = $items;
                }

                continue;
            }

            if (is_scalar($metadata[$key]) || $metadata[$key] === null) {
                $safe[$key] = $metadata[$key];
            }
        }

        // Older reclaim writers used "reason" while the public Journey
        // vocabulary is "reclaim_reason". Map it without exposing arbitrary
        // legacy metadata keys.
        if ($action === 'customer_care.reclaimed'
            && ($metadata['reclaim_source'] ?? null) !== 'manual'
            && ! array_key_exists('reclaim_reason', $safe)
            && array_key_exists('reason', $metadata)) {
            $safe['reclaim_reason'] = $metadata['reason'];
        }

        return $safe;
    }

    private function safeItems(mixed $items): ?array
    {
        if (! is_array($items)) {
            return null;
        }

        $safe = [];
        foreach (array_slice($items, 0, 50) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $safeItem = [];
            foreach (['product_id', 'name', 'quantity'] as $key) {
                if (array_key_exists($key, $item)
                    && is_scalar($item[$key])) {
                    $safeItem[$key] = $item[$key];
                }
            }

            if ($safeItem !== []) {
                $safe[] = $safeItem;
            }
        }

        return $safe;
    }
}
