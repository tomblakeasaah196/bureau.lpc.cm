<?php
/**
 * scripts/cron/mail_send_due.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier: send queued and scheduled mail (mail_outbox rows
 * whose send_at has come). STUB from PR 1: PR 3 (writing) replaces this file
 * with the real sender (docs/MAIL.md §7.3). Until then it does nothing, so the
 * cron entry can be added with the others:
 *
 *   * * * * * /usr/local/bin/php /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/mail_send_due.php >/dev/null 2>&1
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

exit(0);
