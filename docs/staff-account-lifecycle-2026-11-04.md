# Staff account lifecycle — 2026-11-04

Admin can disable/re-enable a non-admin staff account using PUT /api/v1/users/{user}/account-state with disabled:boolean. Disabling revokes access tokens and blocks new login and authenticated API calls. Re-enabling requires a fresh login. Assignments and business history are preserved; transfer/reclaim work separately. Self-lock and locking Admin are rejected.

Deletion returns HTTP 409 when the staff member has active assignments, assignment history or known business history. Admin/self deletion is protected. Foreign-key conflicts return a business error rather than HTTP 500.

Run migration 2026_11_04_000001_create_staff_account_states_table.php before deploying application code. No production account was disabled or deleted as part of deployment. Fourteen isolated SQLite-memory checks passed; frontend production build and login endpoint were verified.

Build frontend with VITE_API_URL=/api/v1. The API client also defaults to /api/v1 to prevent POST /auth/login returning Nginx 405.

Worker recovery on this server: composer.json must be readable by nginx (644); app/Console/Commands must be readable/traversable (755); Laravel daily logs must be writable by nginx. Default ACL user nginx:rwx was added on storage/logs for new files. Permission/ACL adjustments are host configuration, not fully represented by Git. No failed-job retry was issued.
