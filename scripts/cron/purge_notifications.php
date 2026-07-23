<?php
/**
 * scripts/cron/purge_notifications.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 5 · nightly notification purge.
 *
 * Moves read notifications older than 90 days into notifications_archive
 * (migration 016) and deletes them from the source table. Idempotent, safe
 * to run daily via cPanel Cron.
 *
 * cPanel Cron entry:
 *     5 0 * * * /usr/local/bin/php /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/purge_notifications.php
 *
 * Exit codes:
 *   0 — success (or no-op).
 *   1 — non-fatal DB error (logged).
 * -----------------------------------------------------------------------------
 */

// Guard against accidental web execution — .htaccess blocks /scripts/* but a
// misconfigured Apache rewrite could reach us; refuse to run over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../../includes/bootstrap.php';

$RETENTION_DAYS = (int) env('NOTIFICATION_RETENTION_DAYS', 90);
if ($RETENTION_DAYS < 7) $RETENTION_DAYS = 90;   // safety floor

try {
    $db = Database::getInstance()->getConnection();

    // Detect which "read" column exists so we work on both the audit-era schema
    // (read_at TIMESTAMP NULL) and the pre-audit schema (is_read TINYINT).
    $hasReadAt = (bool) $db->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'read_at'"
    )->fetchColumn();

    $where = $hasReadAt
        ? "read_at IS NOT NULL AND read_at < (NOW() - INTERVAL {$RETENTION_DAYS} DAY)"
        : "is_read = 1 AND created_at < (NOW() - INTERVAL {$RETENTION_DAYS} DAY)";

    $db->beginTransaction();

    // Insert into the archive first — if this fails, the DELETE never runs.
    // Column list comes from information_schema so a schema drift can't
    // silently drop fields on the way to the archive table.
    $cols = $db->query(
        "SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position)
           FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'notifications'"
    )->fetchColumn();

    $ins = $db->prepare("INSERT INTO notifications_archive ({$cols})
                         SELECT {$cols} FROM notifications WHERE {$where}");
    $ins->execute();
    $moved = $ins->rowCount();

    $del = $db->prepare("DELETE FROM notifications WHERE {$where}");
    $del->execute();
    $deleted = $del->rowCount();

    if ($moved !== $deleted) {
        // Mismatch: someone raced us. Roll back and retry tomorrow — never
        // delete rows we didn't successfully archive.
        $db->rollBack();
        fwrite(STDERR, "purge_notifications: archive/delete mismatch ({$moved} vs {$deleted}); rolled back.\n");
        exit(1);
    }

    $db->commit();

    if ($moved === 0) {
        fwrite(STDOUT, "purge_notifications: no-op (nothing older than {$RETENTION_DAYS} days).\n");
    } else {
        fwrite(STDOUT, "purge_notifications: archived + deleted {$moved} rows (retention: {$RETENTION_DAYS} days).\n");
    }
    exit(0);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('purge_notifications: ' . $e->getMessage());
    fwrite(STDERR, "purge_notifications: ERROR — {$e->getMessage()}\n");
    exit(1);
}
