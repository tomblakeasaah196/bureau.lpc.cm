<?php
/**
 * scripts/cron/purge_deleted_clients.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 11 · nightly corbeille sweep.
 *
 * Permanently erases any client whose deleted_at is older than
 * CLIENTS_TRASH_RETENTION_DAYS (default 30, see .env). Nothing to reassign
 * here — that already happened when the client was moved to the corbeille
 * (api/v1/delete_client.php -> ClientTrash::softDelete), so this is a plain
 * delete per client via ClientTrash::purge().
 *
 * Each client is purged in its own try/catch so one unexpected FK conflict
 * (which would mean some table writes client_id without ClientTrash knowing
 * about it — see the header of includes/classes/ClientTrash.php) logs loudly
 * and is skipped, instead of blocking every other client due for purge that
 * night.
 *
 * cPanel Cron entry:
 *     30 0 * * * /usr/local/bin/php /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/purge_deleted_clients.php
 *
 * Exit codes:
 *   0 — success (or no-op).
 *   1 — one or more clients failed to purge (logged; others still ran).
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/ClientTrash.php';

$RETENTION_DAYS = (int) env('CLIENTS_TRASH_RETENTION_DAYS', 30);
if ($RETENTION_DAYS < 1) $RETENTION_DAYS = 30;   // safety floor — never purge same-day

try {
    $db = Database::getInstance()->getConnection();

    $due = $db->prepare("
        SELECT id, lpc_code, name, deleted_reassigned_to
          FROM clients
         WHERE deleted_at IS NOT NULL
           AND deleted_at < (NOW() - INTERVAL {$RETENTION_DAYS} DAY)
    ");
    $due->execute();
    $clients = $due->fetchAll(PDO::FETCH_ASSOC);

    if (!$clients) {
        fwrite(STDOUT, "purge_deleted_clients: no-op (nothing past the {$RETENTION_DAYS}-day retention window).\n");
        exit(0);
    }

    $purged = 0;
    $failed = 0;

    foreach ($clients as $client) {
        try {
            $db->beginTransaction();
            ClientTrash::purge($db, (int) $client['id']);

            $db->prepare("
                INSERT INTO audit_logs (user_id, action, table_name, record_id, old_json, new_value)
                VALUES (NULL, 'DELETE', 'clients', ?, JSON_OBJECT('lpc_code', ?, 'name', ?, 'reassigned_to', ?), ?)
            ")->execute([
                $client['id'],
                $client['lpc_code'],
                $client['name'],
                $client['deleted_reassigned_to'],
                "Client #{$client['id']} purgé automatiquement après {$RETENTION_DAYS} jours en corbeille",
            ]);

            $db->commit();
            $purged++;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $failed++;
            error_log("purge_deleted_clients: client #{$client['id']} ({$client['lpc_code']}) failed — " . $e->getMessage());
            fwrite(STDERR, "purge_deleted_clients: client #{$client['id']} ({$client['lpc_code']}) FAILED — {$e->getMessage()}\n");
        }
    }

    fwrite(STDOUT, "purge_deleted_clients: purged {$purged}, failed {$failed} (retention: {$RETENTION_DAYS} days).\n");
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    error_log('purge_deleted_clients: ' . $e->getMessage());
    fwrite(STDERR, "purge_deleted_clients: ERROR — {$e->getMessage()}\n");
    exit(1);
}
