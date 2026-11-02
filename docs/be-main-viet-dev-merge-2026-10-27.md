# BE main / viet_dev integration — 27 October 2026

Integrated `origin/main` at `18d5e326e191daadbc3e3d5773440ee9c296d2a4`
with `viet_dev` at `1ef19c33f60f6fdc0abf44cd56803e28a59d3e39`.
The original branches had four main-only commits and six viet_dev-only commits.

## Resolution

- Retained POS customer lookup alongside main's customer purchase insights.
- Retained POS creation, idempotency, shop/record authorization and product permissions.
- Combined main's order/report aggregates with POS totals. POS creation also writes
  the indexed `prepaid_amount` when that migration has been applied.
- Combined customer recency/payment sync with warehouse enrollment and locking.
- A status-only warehouse webhook preserves both the indexed prepaid amount and
  the existing JSON payment snapshot.
- Kept main's `frontend/` snapshot unchanged. The standalone frontend repository
  and the local frontend server continue to be managed separately.

## Verification

- PHP 8.4 syntax validation for all changed PHP files.
- PHPUnit on isolated SQLite databases: **471 tests, 3,292 assertions,
  zero errors, five failures**. No new failing tests relative to the original
  viet_dev baseline (**462 tests, five failures**).
- POS, warehouse and Zalo subset: **41 tests, 297 assertions, all passing**.
- Zalo bridge tests now create an isolated SQLite schema instead of requiring
  the pre-existing `pancake_local` database. All upstream HTTP calls are faked.
- Added regressions for POS payments/totals in the main overview, and combined
  customer recency/payment/warehouse synchronization with status-only webhooks.
- Cloned only the structure of the current local MySQL database (37 tables) and
  migration history into a temporary database. Added synthetic records, applied
  all three pending migrations, and verified the customer/payment backfills and
  Zalo tables. Removed the temporary database after the checks.
- On that isolated MySQL database, eight authenticated API smoke checks passed:
  order overview, order sales chart, customer order insights, product sales,
  product period report, product monthly quantities, province charts and Zalo
  connection configuration. No external services were called.

The five baseline failures are:

1. `OpportunitySecurityHardeningTest::test_valid_assign_preserves_response_assignment_state_dates_and_activity_log`
2. `OpportunitySecurityHardeningTest::test_staff_and_monitoring_workload_aggregates_count_only_actionable_cares`
3. `OpportunitySecurityHardeningTest::test_import_rejects_incomplete_rows_and_invalid_phone_numbers_without_partial_import`
4. `OpportunitySecurityHardeningTest::test_phase_3_monitoring_returns_only_users_intersecting_the_effective_shop_scope`
5. `OrderSourceApiTest::test_summary_contract_is_compact_while_default_keeps_the_legacy_payload`

This is not a fully passing test suite; these existing failures remain for
separate investigation.

## Database and deployment

The actual application database, environment files, OAuth keys and user accounts
were not changed. The following migrations were verified on the temporary MySQL
database but are still pending on the actual local database:

- `2026_10_07_000001_add_last_order_at_to_customers_table`
- `2026_10_07_000002_add_prepaid_amount_to_orders_table`
- `2026_10_08_000001_create_zalo_chat_bridge_tables`

New purchase-insight and Zalo endpoints require those migrations before use.
Apply them as a separate database change after backup and review. The director
role seeder was not executed. Queue workers, scheduler and upstream Zalo/Pancake
integration were not started by this integration check.
