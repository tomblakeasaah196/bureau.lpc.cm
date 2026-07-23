<?php
/**
 * scripts/cron/purge_login_attempts.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 5 · nightly login_attempts cleanup.
 *
 * Delegates to RateLimiter::purge(24) — deletes attempt rows older than 24h.
 * They exist only to compute the sliding-window rate limit; keeping stale
 * rows around bloats the table and slows down the WHERE minute_bucket >= ?
 * range scan the limiter runs on every login attempt.
 *
 * cPanel Cron entry:
 *     20 0 * * * /usr/local/bin/php /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/purge_login_attempts.php
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../../includes/bootstrap.php';

$RETENTION_HOURS = (int) env('LOGIN_ATTEMPTS_RETENTION_HOURS', 24);
if ($RETENTION_HOURS < 1) $RETENTION_HOURS = 24;

try {
    $n = RateLimiter::purge($RETENTION_HOURS);
    if ($n === 0) {
        fwrite(STDOUT, "purge_login_attempts: no-op.\n");
    } else {
        fwrite(STDOUT, "purge_login_attempts: deleted {$n} row(s) older than {$RETENTION_HOURS}h.\n");
    }
    exit(0);
} catch (Throwable $e) {
    error_log('purge_login_attempts: ' . $e->getMessage());
    fwrite(STDERR, "purge_login_attempts: ERROR — {$e->getMessage()}\n");
    exit(1);
}
