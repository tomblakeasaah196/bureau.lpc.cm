# Nightly cron jobs — Bureau LPC ERP

Sprint 5 · daily housekeeping. Each script is CLI-only, idempotent, and safe to
run daily. Web access to `/scripts/*` is denied by the root `.htaccess`, so a
misconfigured Apache rewrite can't reach these files over HTTP either way.

## What runs, what it does, when

| Script | What it purges | Retention (default) | Env override |
|---|---|---|---|
| `purge_notifications.php` | Read notifications → `notifications_archive` (migration 016) → hard-delete | 90 days | `NOTIFICATION_RETENTION_DAYS` |
| `purge_sessions.php` | 1) idle sessions (`logout_time = last_activity`); 2) hard-delete closed sessions | 24 h idle · 90 d hard | `SESSION_IDLE_HOURS`, `SESSION_RETENTION_DAYS` |
| `purge_audit_logs.php` | audit_logs → `audit_logs_archive` (migration 016) → hard-delete | 7 years | `AUDIT_RETENTION_YEARS` |
| `purge_login_attempts.php` | Old rate-limit rows via `RateLimiter::purge()` | 24 h | `LOGIN_ATTEMPTS_RETENTION_HOURS` |
| `purge_deleted_clients.php` | Clients in the corbeille (`clients.deleted_at`) → hard-delete, no archive table (see `ClientTrash::purge()`, Sprint 11) | 30 days | `CLIENTS_TRASH_RETENTION_DAYS` |

Retention floors are enforced in code — you cannot set audit retention shorter
than 5 years or notifications shorter than 7 days even by editing `.env`.
`purge_deleted_clients.php` floors at 1 day (never same-day) but has no
ceiling — an admin can set a long grace period without a code change.

## cPanel Cron entries

Set these in **cPanel → Advanced → Cron Jobs**. Times are five minutes apart
so they don't all hit the DB at once.

```
5  0 * * * /usr/local/bin/php /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/purge_notifications.php   >> /home/smartqaq/logs/lpc-cron.log 2>&1
10 0 * * * /usr/local/bin/php /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/purge_sessions.php       >> /home/smartqaq/logs/lpc-cron.log 2>&1
15 0 * * * /usr/local/bin/php /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/purge_audit_logs.php     >> /home/smartqaq/logs/lpc-cron.log 2>&1
20 0 * * * /usr/local/bin/php /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/purge_login_attempts.php >> /home/smartqaq/logs/lpc-cron.log 2>&1
25 0 * * * /usr/local/bin/php /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/purge_deleted_clients.php >> /home/smartqaq/logs/lpc-cron.log 2>&1
```

Adjust the `/home/smartqaq/` prefix if the cPanel user changes. The
`>> logs/lpc-cron.log 2>&1` tail keeps a runbook trace we can `tail -f` when
something misbehaves.

## Verify before enabling

Run each script by hand once, from SSH:

```bash
cd ~/public_html/bureau.lpc.cm
php scripts/cron/purge_notifications.php
php scripts/cron/purge_sessions.php
php scripts/cron/purge_audit_logs.php
php scripts/cron/purge_login_attempts.php
php scripts/cron/purge_deleted_clients.php
```

Every one should exit 0 and print either "no-op" (nothing to do) or
"archived + deleted N rows". If a script errors, it exits 1 and writes the
message to STDERR + `error_log()` — never leave the cron enabled on a script
that just failed by hand.

## Web-execution guard

Each script starts with:

```php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}
```

Plus the root `.htaccess` already rewrites `^scripts/` to `[F,L]`. Two layers
of defence. If you're adding a new cron script, keep both.

## Safety notes

- The purge scripts wrap the archive-then-delete pair in a transaction. If
  the row-count of the INSERT doesn't match the DELETE (i.e., someone raced
  the script), the whole thing rolls back and exits 1. Better to run again
  tomorrow than to lose evidence tonight.
- `purge_login_attempts.php` doesn't archive — the table is by design
  ephemeral, and the RateLimiter class exposes `purge()` for exactly this
  use case.
- If you disable the crons, the tables will grow forever. `audit_logs` in
  particular will bloat the daily backup — the disk-full failure mode is a
  quiet, cumulative one.
- `purge_deleted_clients.php` has no archive table by design: its financial
  and transactional rows (orders, invoices, payments, wallet, empties ledger)
  were already reassigned to another client at the moment it was soft-deleted
  (`ClientTrash::softDelete()`), so nothing evidentiary is lost — only the
  client's own name/contact/tax record disappears. Each row deleted is still
  logged to `audit_logs` (old_json snapshot) before it goes, one per client,
  so "who was purged and when" survives even though the client row itself
  does not.
