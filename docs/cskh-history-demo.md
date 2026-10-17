# Local CSKH purchase history demo

Marker: `CSKH_HISTORY_DEMO_20261017`.

Run from `D:\pancake\pancake_order` using PHP 8.2+ (the verified local executable is
`C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe`). The default local account
is `nvcskh@gmail.com`. Its role and password are never modified.

```powershell
& 'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe' artisan customer-care:demo-history
& 'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe' artisan customer-care:demo-history --verify
```

The command requires `APP_ENV=local`, a loopback MySQL connection, database
`pancake_order`, and a MySQL server hostname matching this computer. It refuses
other environments, remote databases and split read/write connections.

It creates two dedicated shops, one demo employee with a random undisclosed
password, two fictional customers, seven orders, one care scheduled today, one
active assignment using `CustomerCareAssignmentService::create()`, and three
memberships. The existing account only gains membership in Shop A. The fake phone
`0000000000`, reserved email domain `example.invalid`, nonnumeric external shop IDs
and disabled API keys are deliberate. No real Pancake credentials are used.

The five main orders have COD values 250000, 450000, 320000, 780000 and 150000.
Only the first matches the existing employee's ownership. The two excluded orders
belong to the other shop or the other customer. Customer identity is matched by
`pancake_customer_id`, never by phone.

Creation runs in a transaction with Eloquent events suppressed. It does not invoke
sync jobs, notification/mail services or the assignment journey-event writer.
Verification uses Laravel's HTTP kernel and API guard with the existing user set
in memory; it creates no OAuth token and prints no secrets. It checks both the
appointment-list endpoint and the purchase-history endpoint against exact IDs.

Repeating creation verifies the same IDs without inserting duplicates or resetting
appointments the user has edited. It reports the care ID/date and API result. If
fixture data has been modified, verification can fail rather than overwrite it.

## UI check

Sign in with the existing account. Open **Lịch hẹn → KH chăm sóc hôm nay**, find
**Nguyễn Văn Demo** in the shop named `CSKH_HISTORY_DEMO_20261017 - Shop A`, and
choose **Lịch sử đơn hàng**. Expect exactly five orders ending in `DEMO-001`
through `DEMO-005`; neither `OUTSIDE-SHOP` nor `OTHER-CUSTOMER` should appear.
On a later date, look under **Khách chăm sóc quá hạn**, or clean up and recreate
the fixture to schedule it for that day. Refresh after changing data.

## Precise cleanup

```powershell
& 'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe' artisan customer-care:demo-history --cleanup
```

The manifest is stored in the main demo shop's `pancake_full_data`. Cleanup validates
the marker and recorded primary keys, then deletes only those rows in a transaction.
It preserves the existing account and removes only the recorded demo memberships.
It refuses cleanup if unrecorded foreign-key or known legacy references exist;
it never disables constraints or cascades through unrelated records. If UI testing
created additional schedules/logs linked to the demo, review those references
before cleanup. Missing fixture means a harmless no-op. No schema changes,
truncate, migrations or blanket seeding are involved.
