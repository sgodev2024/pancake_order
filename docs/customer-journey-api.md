# Customer Journey API

## Endpoint

`GET /api/v1/customers/{customer}/journey`

`{customer}` is the local `customers.id`. It is never interpreted as a
Pancake customer ID, name, or phone number.

The endpoint requires the existing `list-customer` permission. Admins have
global shop scope. Non-admins must belong to the customer's local shop, and
non-manager staff retain the existing customer-record restriction:
`customers.assigned_user_id` must match the actor's Pancake user ID (or local
user ID when no Pancake user ID exists).

## Query parameters

- `page`, default `1`
- `page_size`, default `50`, maximum `100`
- `action`, optional allowlisted filter for one of the six MVP Journey actions

The response keeps the chronological timeline and pagination fields together:

```json
{
  "success": true,
  "data": {
    "customer": { "id": 123, "name": "...", "shop_id": 38, "shop_name": "..." },
    "summary": { "care_count": 2, "order_count": 3 },
    "timeline": [],
    "current_page": 1,
    "per_page": 50,
    "total_items": 0,
    "total_pages": 1
  }
}
```

`care_count` is the number of linked `customer_care.completed` events recorded
by the Journey contract. `order_count` is the number of linked
`order.created` events recorded by the Journey contract. Neither is a lifetime
historical count, and neither implies a successful purchase. The optional
`action` filter affects `timeline` and pagination; summary counts remain the
full linked Journey counts.

## Event linkage and isolation

The query is constrained to the customer's local `shop_id` before event
linkage. Preferred linkage is `metadata.customer_id == customers.id` with a
matching local subject. Order-backed events additionally resolve local Order
IDs. Assignment and completion events resolve local
`customer_care_assignments.source_type = order` and `source_id` to Orders in
the same shop that belong to the requested Customer.

For compatibility with older `order.created` rows that have no local
`metadata.customer_id`, the endpoint may fall back to
`activity_logs.pancake_customer_id` only when the ActivityLog shop and local
Customer shop are identical and the external IDs match. External IDs are
never used across shops or as the route identity. ImportedOpportunity events
without a deterministic local Customer relationship are excluded.

Only these actions are exposed:

- `customer.entered_system`
- `customer_care.assigned`
- `customer_care.reassigned`
- `customer_care.reclaimed`
- `customer_care.completed`
- `order.created`

Operational repair logs remain in the admin ActivityLog API. Timeline metadata
is allowlisted per action; raw values, tokens, idempotency keys, and full
Pancake payloads are not returned. Events are ordered oldest to newest by
`COALESCE(occurred_at, created_at)` and then `activity_logs.id`.
