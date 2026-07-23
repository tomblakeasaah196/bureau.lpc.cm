<?php
/**
 * scripts/cron/purge_audit_logs.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 5 · nightly audit_logs archival.
 *
 * OHADA + CM tax law require accounting records be retained for 10 years.
 * We archive rows OLDER than 7 years into audit_logs_archive (migration 016)
 * so the source table stays lean and indexed for hot lookups. The archive
 * table is still on the same DB — a future policy change can migrate the
 * archive off-box, but the default here is conservative.
 *
 * cPanel Cron entry:
 *     15 0 * * * /usr/local/bin/php /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/purge_audit_logs.php
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../../includes/bootstrap.php';

$RETENTION_YEARS = (int) env('AUDIT_RETENTION_YEARS', 7);
if ($RETENTION_YEARS < 5) $RETENTION_YEARS = 7;   // never archive fresher than 5y

try {
    $db = Database::getInstance()->getConnection();

    $db->beginTransaction();

    $cols = $db->query(
        "SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position)
           FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'audit_logs'"
    )->fetchColumn();

    $where = "created_at < (NOW() - INTERVAL {$RETENTION_YEARS} YEAR)";

    $ins = $db->prepare("INSERT INTO audit_logs_archive ({$cols})
                         SELECT {$cols} FROM audit_logs WHERE {$where}");
    $ins->execute();
    $moved = $ins->rowCount();

    $del = $db->prepare("DELETE FROM audit_logs WHERE {$where}");
    $del->execute();
    $deleted = $del->rowCount();

    if ($moved !== $deleted) {
        $db->rollBack();
        fwrite(STDERR, "purge_audit_logs: archive/delete mismatch ({$moved} vs {$deleted}); rolled back.\n");
        exit(1);
    }

    $db->commit();

    if ($moved === 0) {
        fwrite(STDOUT, "purge_audit_logs: no-op (nothing older than {$RETENTION_YEARS} years).\n");
    } else {
        fwrite(STDOUT, "purge_audit_logs: archived + deleted {$moved} rows (retention: {$RETENTION_YEARS} years).\n");
    }
    exit(0);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('purge_audit_logs: ' . $e->getMessage());
    fwrite(STDERR, "purge_audit_logs: ERROR — {$e->getMessage()}\n");
    exit(1);
}
