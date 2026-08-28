<?php

namespace App\Support;

use App\Enums\ActivityLogAction;

/**
 * Documented metadata vocabulary for the MVP Customer Journey actions.
 *
 * This is intentionally a contract description rather than a strict schema:
 * event-specific optional context can evolve without another database column.
 * The service still removes sensitive and raw-payload-shaped keys before
 * persistence.
 */
final class ActivityLogMetadataContract
{
    public const INGESTION_PATH = 'ingestion_path';

    public const ACQUISITION_CHANNEL = 'acquisition_channel';

    /**
     * @return array{
     *     required_local_ids: list<string>,
     *     optional_local_ids: list<string>,
     *     external_ids: list<string>,
     *     context: list<string>
     * }
     */
    public static function for(string|ActivityLogAction $action): ?array
    {
        $action = $action instanceof ActivityLogAction ? $action->value : trim($action);

        return match ($action) {
            ActivityLogAction::CUSTOMER_ENTERED_SYSTEM->value => [
                'required_local_ids' => ['customer_id', 'shop_id'],
                'optional_local_ids' => [],
                'external_ids' => ['pancake_customer_id'],
                'context' => [self::INGESTION_PATH, self::ACQUISITION_CHANNEL],
            ],
            ActivityLogAction::CUSTOMER_CARE_ASSIGNED->value,
            ActivityLogAction::CUSTOMER_CARE_REASSIGNED->value,
            ActivityLogAction::CUSTOMER_CARE_RECLAIMED->value => [
                'required_local_ids' => ['customer_care_id', 'assignment_id'],
                'optional_local_ids' => [
                    'shop_id',
                    'source_id',
                    'assignee_user_id',
                    'previous_assignee_user_id',
                    'new_assignee_user_id',
                    'previous_assignment_id',
                    'new_assignment_id',
                    'previous_customer_care_id',
                ],
                'external_ids' => ['pancake_customer_id', 'pancake_order_id'],
                'context' => [
                    self::INGESTION_PATH,
                    self::ACQUISITION_CHANNEL,
                    'reason',
                    'reclaim_source',
                    'manual_reason',
                ],
            ],
            ActivityLogAction::CUSTOMER_CARE_COMPLETED->value => [
                'required_local_ids' => [
                    'customer_care_id',
                    'assignment_id',
                    'assignee_user_id',
                    'shop_id',
                    'source_id',
                    'care_sequence_number',
                ],
                'optional_local_ids' => ['customer_id', 'order_id'],
                'external_ids' => ['pancake_customer_id', 'pancake_order_id'],
                'context' => [
                    self::INGESTION_PATH,
                    self::ACQUISITION_CHANNEL,
                    'sequence_scope',
                    'sequence_basis',
                    'result',
                ],
            ],
            ActivityLogAction::ORDER_CREATED->value => [
                'required_local_ids' => ['order_id', 'shop_id'],
                'optional_local_ids' => ['customer_id'],
                'external_ids' => ['pancake_order_id', 'pancake_customer_id'],
                'context' => [
                    self::INGESTION_PATH,
                    self::ACQUISITION_CHANNEL,
                    'amount',
                    'amount_source',
                    'cash_amount',
                    'quantity',
                    'items',
                ],
            ],
            default => null,
        };
    }
}
