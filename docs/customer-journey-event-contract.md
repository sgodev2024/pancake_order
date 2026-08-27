# Customer Journey activity-log contract

Customer Journey events continue to use `activity_logs`. This contract adds
schema, service, and event-writer conventions without rewriting historical
rows.

## MVP actions

The stable action values are:

- `customer.entered_system`
- `customer_care.assigned`
- `customer_care.reassigned`
- `customer_care.reclaimed`
- `customer_care.completed`
- `order.created`

They are represented by `App\Enums\ActivityLogAction`, while the database
continues to store strings in the existing `action` column.

## Identity and scope

- `subject_type` uses `customer`, `order`, `customer_care`, or
  `customer_care_assignment` for new Journey events.
- `subject_id` is always the local database ID serialized as a string.
- Pancake IDs remain in `pancake_order_id`, `pancake_customer_id`, and/or the
  metadata external-ID fields; they are never authorization scope.
- `shop_id` is the local shop ID and is required by the new typed writer for
  MVP Journey actions. `pancake_shop_id` is not an authorization field.
- `source` retains its operational meaning (`user`, `system`, etc.).
  Acquisition/marketing meaning belongs in `metadata.acquisition_channel`.

## Time and idempotency

`occurred_at` records when the business event happened. `created_at` records
when the activity-log row was inserted. `occurred_at` is nullable so old rows
and operational logs remain valid, and values are normalized using the
application timezone (`Asia/Ho_Chi_Minh`).

`idempotency_key` is nullable and globally unique when present. A global key
is the safest default for an event identity: accidental reuse cannot suppress
an event from another shop or action. Existing null keys remain unrestricted.
The service returns the original row for an exact retry and raises an
idempotency conflict for a different event with the same key; it never updates
the original row.

For `customer.entered_system`, `occurred_at` provenance depends on the
ingestion path. `pancake_bulk_sync` uses the trusted Pancake `inserted_at`
timestamp, normalized to the application timezone and used for the local
Customer `created_at`. `pancake_order_webhook` uses the reliable local
Customer `created_at`, because the webhook does not preserve a trusted
external customer-creation timestamp. These events are written only at real
new-Customer creation boundaries; existing-customer updates and historical
Customers are not backfilled.

## Metadata contracts

Metadata uses local IDs for internal relationships and keeps external IDs
separate. The following keys are the documented MVP vocabulary; optional
context may be added without changing the table:

| Action | Required local IDs | External IDs | Context |
| --- | --- | --- | --- |
| `customer.entered_system` | `customer_id`, `shop_id` | `pancake_customer_id` | `ingestion_path`, optional `acquisition_channel` |
| `customer_care.assigned` | `customer_care_id`, `assignment_id` | `pancake_customer_id`, `pancake_order_id` | assignment context, `ingestion_path`, optional `acquisition_channel` |
| `customer_care.reassigned` | `customer_care_id`, `assignment_id` | `pancake_customer_id`, `pancake_order_id` | previous/new assignee IDs, `ingestion_path`, optional `acquisition_channel` |
| `customer_care.reclaimed` | `customer_care_id`, `assignment_id` | `pancake_customer_id`, `pancake_order_id` | `reason`, `ingestion_path`, optional `acquisition_channel` |
| `customer_care.completed` | `customer_care_id`, `assignment_id` | `pancake_customer_id`, `pancake_order_id` | completion context, `ingestion_path`, optional `acquisition_channel` |
| `order.created` | `order_id`, optional `customer_id` | `pancake_order_id`, `pancake_customer_id` | `ingestion_path`, optional `acquisition_channel` |

`source` is not overloaded with `acquisition_channel`. Full raw Pancake
payloads are not part of this contract and are removed when payload-shaped
keys are passed to the service.
