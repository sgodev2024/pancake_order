# Website and webhook email monitoring

Install pancake-health-monitor.py as /usr/local/sbin/pancake-health-monitor.py (root-owned, mode 700). Install cron.example as /etc/cron.d/pancake-health-monitor (644). Requires Python 3, Supervisor, SMTP connectivity. Runs independently of Laravel and the business queue.

Create /etc/pancake-monitor (700), smtp.json (600, root-owned) with host, port, username, password, encryption, from and to. Recipient on this installation: sgovietnam.dev@gmail.com. SMTP credentials are intentionally excluded from Git. Port 465 uses implicit TLS; other configured ports use STARTTLS. Refresh the protected SMTP file after changing General Settings; it is a snapshot, not automatically reloaded from the CRM database.

Checks once per minute: HTTP /dang-nhap (200 and root HTML), API /api/v1/me (401 unauthenticated), Supervisor webhook worker RUNNING. Alert after two failures, remind after 30 minutes, notify recovery. Failed mail sends remain retryable. Use --test-email for an explicit test. State /var/lib/pancake-monitor/state.json, log /var/log/pancake-health-monitor.log. Does not restart workers or retry failed business jobs.

This is a same-server monitor: complete host/network failure needs an external monitor. Actual credentials, runtime state and logs must never be committed.
