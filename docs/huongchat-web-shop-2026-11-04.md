# Hương Chất Web CRM store

Store identity shops.pancake_shop_id=huongchat_web; source https://huongchat.com/api. Configure HUONG_CHAT_API_URL in the deployment environment and retain the API key privately. Do not commit .env or SMTP credentials.

Command php artisan huongchat:sync --dry-run reads and validates only. php artisan huongchat:sync imports orders, customers and products; scheduled every five minutes with overlap protection. Separate hcweb identifiers prevent collision with Pancake. Local ownership/assignments are preserved. Pending=0, confirmed=1, completed=3, cancelled=6. Only completed eligible orders enter existing opportunity workflow; other statuses are not promoted. Missing staff remain unassigned. No source writes or fake tracking numbers.

Local deletion markers in hidden_huongchat_orders are excluded from synchronization. All nine source orders were marked deleted at deployment, so no historical orders were reimported. No staff memberships were automatically granted. Add the store to each authorized employee's memberships using existing CRM controls. Source Web order API also enforces store access. Never use Pancake synchronization for this store.

A shared-phone identity is scoped to this store; missing phone uses an order-specific customer identity. Unsupported status or invalid detail stops the import before writes. Source is scanned with bounded pages and details; large-volume incremental synchronization is a future improvement. Warehouse state is not automatically fabricated; care appointments follow existing assignment workflow. SQLite-memory fixture tests cover import, replay, update preservation, completed customer totals and deleted exclusion; production scope tests include Admin 200 and unassigned staff 403.

Navigation reordered with monitoring/log group, Warehouse retained under Orders. Browser title changed to Hệ thống quản lý khách hàng - Hương Chất Group. Deleted orders have no restore option in the standard frontend.
