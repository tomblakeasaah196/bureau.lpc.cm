<?php
/**
 * scripts/cron/notify_pending_attestations.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Batch B · monthly reminder for missing AIR attestations.
 *
 * On the 5th of every month (via cPanel Cron), this script lists every
 * withholding-agent client that had validated payments with
 * air_withheld_amount > 0 for the PREVIOUS month but no matching
 * withholding_certificate_id, and pushes an in-app notification to
 * Finance + Admin/Accountant users.
 *
 * The AIR credit on the monthly AIR declaration only counts CERTIFIED
 * retenues (see includes/classes/TaxEngine.php::computeAir), so any
 * uncertified line is money we booked but cannot yet claim against the
 * DGI declaration. This reminder makes that visible without waiting
 * until the accountant opens Déclarations Fiscales.
 *
 * Idempotent: the same 5-of-month run may fire more than once (cron
 * retry, manual re-run) — we always insert a fresh notification, but
 * the query is grouped per client + period so the message doesn't grow
 * unbounded across runs. purge_notifications.php handles cleanup.
 *
 * cPanel Cron entry (08:00 on the 5th of every month):
 *     0 8 5 * * /usr/local/bin/php /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/notify_pending_attestations.php
 *
 * Exit codes:
 *   0 — success (0..N notifications sent, or no-op)
 *   1 — non-fatal DB error (logged)
 * -----------------------------------------------------------------------------
 */

// Guard against accidental web execution — .htaccess blocks /scripts/* but
// a misconfigured Apache rewrite could reach us. Refuse to run over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../../includes/bootstrap.php';

// -----------------------------------------------------------------------------
// Period under review — always the CALENDAR PREVIOUS month, so this script
// works identically whether cron fires it on the 5th at 08:00 or a re-run
// happens later in the day. Override via env for backfills.
// -----------------------------------------------------------------------------
$now_year  = (int) date('Y');
$now_month = (int) date('n');
$py        = (int) env('LPC_AIR_REMINDER_YEAR',  $now_year);
$pm        = (int) env('LPC_AIR_REMINDER_MONTH', $now_month - 1);
if ($pm < 1)  { $pm = 12; $py -= 1; }
if ($pm > 12) { $pm = 1;  $py += 1; }

$mois_fr = ['', 'janvier','février','mars','avril','mai','juin',
            'juillet','août','septembre','octobre','novembre','décembre'];
$period_label = $mois_fr[$pm] . ' ' . $py;

try {
    $db = Database::getInstance()->getConnection();

    // Pull the list of pending retenues for the target period.
    // Same shape as tax_controller.php ?action=pending_attestations
    // (kept in-sync verbatim so the dashboard and the reminder never
    // disagree about who owes what).
    $q = $db->prepare("
        SELECT p.client_id, c.name AS client_name,
               COUNT(*) AS n_payments,
               COALESCE(SUM(p.air_withheld_amount), 0) AS air_pending
          FROM payments p
          JOIN clients c ON c.id = p.client_id
         WHERE p.withholding_certificate_id IS NULL
           AND p.air_withheld_amount > 0
           AND p.status = 'validated'
           AND YEAR(p.payment_date)  = ?
           AND MONTH(p.payment_date) = ?
         GROUP BY p.client_id, c.name
         ORDER BY air_pending DESC, c.name ASC
    ");
    $q->execute([$py, $pm]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        fwrite(STDOUT, "notify_pending_attestations: no-op — no uncertified retenues for {$period_label}.\n");
        exit(0);
    }

    $total = 0.0;
    foreach ($rows as $r) $total += (float) $r['air_pending'];

    // Recipients — same set as notifyAdminFinance in invoices_controller.php.
    // 'finance' string is defensive: not all installs have that role name.
    $u = $db->query("
        SELECT u.id
          FROM users u
          JOIN roles r ON r.id = u.role_id
         WHERE r.name IN ('admin', 'finance', 'accountant')
           AND u.status = 'active'
    ")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($u)) {
        fwrite(STDERR, "notify_pending_attestations: no active admin/finance/accountant users to notify.\n");
        exit(0);
    }

    // One consolidated message per user — easier to act on than N
    // per-client notifications drowning the topbar bell.
    $client_lines = [];
    foreach ($rows as $r) {
        $client_lines[] = '• ' . $r['client_name']
                        . ' — ' . number_format((float)$r['air_pending'], 0, ',', ' ') . ' FCFA'
                        . ' (' . (int)$r['n_payments'] . ' paiement' . ((int)$r['n_payments'] > 1 ? 's' : '') . ')';
    }
    $total_fr = number_format($total, 0, ',', ' ') . ' FCFA';
    $count_fr = count($rows);

    $title   = "Attestations AIR à réclamer — {$period_label}";
    $message = "Rappel mensuel : {$count_fr} client(s) ayant retenu de l'AIR pour {$period_label} n'ont pas encore fourni d'attestation. "
             . "Total en attente : {$total_fr}.\n\n"
             . implode("\n", $client_lines)
             . "\n\nOuvrez Déclarations Fiscales → Retenues à la source pour téléverser les attestations, "
             . "ou utilisez le bouton « Demander (PDF) » sur le tableau de bord pour envoyer un courrier formel au client.";

    $db->beginTransaction();
    $ins = $db->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
    $sent = 0;
    foreach ($u as $uid) {
        $ins->execute([(int)$uid, $title, $message]);
        $sent++;
    }
    $db->commit();

    fwrite(STDOUT,
        "notify_pending_attestations: sent {$sent} notification(s) — {$count_fr} client(s), {$total_fr} pending for {$period_label}.\n"
    );
    exit(0);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('notify_pending_attestations: ' . $e->getMessage());
    fwrite(STDERR, "notify_pending_attestations: ERROR — {$e->getMessage()}\n");
    exit(1);
}
