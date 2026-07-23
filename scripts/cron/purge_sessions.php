<?php
/**
 * scripts/cron/purge_sessions.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 5 · nightly user_sessions cleanup.
 *
 * Two passes:
 *   1. Idle sessions: rows with logout_time IS NULL but last_activity > 24h
 *      ago get their logout_time stamped to last_activity (so admin dashboards
 *      stop counting them as "online").
 *   2. Old sessions: rows with logout_time IS NOT NULL AND created_at older
 *      than 90 days get hard-deleted (no archive — session rows have no
 *      long-term compliance value; audit_logs holds the actual actions).
 *
 * cPanel Cron entry:
 *     10 0 * * * /usr/local/bin/php /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/purge_sessions.php
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../../includes/bootstrap.php';

$RETENTION_DAYS = (int) env('SESSION_RETENTION_DAYS', 90);
if ($RETENTION_DAYS < 7) $RETENTION_DAYS = 90;

$IDLE_HOURS = (int) env('SESSION_IDLE_HOURS', 24);
if ($IDLE_HOURS < 1) $IDLE_HOURS = 24;

try {
    $db = Database::getInstance()->getConnection();

    // Detect optional columns so we work regardless of migration state.
    $hasLastActivity = (bool) $db->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'user_sessions' AND column_name = 'last_activity'"
    )->fetchColumn();

    $idleClosed = 0;
    if ($hasLastActivity) {
        // Pass 1: close idle sessions (stamp logout_time = last_activity).
        $stmt = $db->prepare(
            "UPDATE user_sessions
                SET logout_time = last_activity
              WHERE logout_time IS NULL
                AND last_activity IS NOT NULL
                AND last_activity < (NOW() - INTERVAL {$IDLE_HOURS} HOUR)"
        );
        $stmt->execute();
        $idleClosed = $stmt->rowCount();
    }

    // Pass 2: hard-delete rows whose session ended a long time ago.
    $stmt = $db->prepare(
        "DELETE FROM user_sessions
          WHERE logout_time IS NOT NULL
            AND created_at < (NOW() - INTERVAL {$RETENTION_DAYS} DAY)"
    );
    $stmt->execute();
    $deleted = $stmt->rowCount();

    if ($idleClosed === 0 && $deleted === 0) {
        fwrite(STDOUT, "purge_sessions: no-op.\n");
    } else {
        fwrite(STDOUT, "purge_sessions: idle-closed={$idleClosed}, hard-deleted={$deleted} (retention {$RETENTION_DAYS}d, idle {$IDLE_HOURS}h).\n");
    }
    exit(0);
} catch (Throwable $e) {
    error_log('purge_sessions: ' . $e->getMessage());
    fwrite(STDERR, "purge_sessions: ERROR — {$e->getMessage()}\n");
    exit(1);
}
