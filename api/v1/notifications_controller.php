<?php
/**
 * api/v1/notifications_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — topbar notifications feed.
 *
 * Merges TWO sources into one feed:
 *
 *   1. Live-computed conditions, schema-verified and grounded in existing
 *      queries elsewhere in this codebase:
 *        - Overdue invoices             -- invoices_controller.php's AR-aging query
 *        - AIR withheld, no attestation -- docs/GUIDE_FISCAL_CAMEROUN.md §7 checklist
 *        - Low stock                    -- inventory_controller.php's current_qty calc
 *        - Fleet doc expiring (Sprint 9) -- fleet_controller.php's insurance/
 *          technical-visit alert query (dashboard tab, Strategy 7B)
 *        - Fleet maintenance due (Sprint 9) -- same file's odometer trigger
 *          (dashboard/maintenance tabs, Strategy 2B)
 *      These stay true until the underlying condition resolves. No id, no
 *      read state — nothing to mark read because there's nothing stored.
 *
 *   2. Stored, per-user rows from the `notifications` table (unread only).
 *      Until this file's Sprint-9 change, NOTHING ever read that table —
 *      every INSERT INTO notifications across the app (invoice issued, goods
 *      received, stock loss, CRE refused, driver delivery confirmed, large
 *      logistics expense, the monthly AIR-attestation cron, and now the
 *      accounting-module events wired via includes/functions/notify.php) was
 *      landing in a table nothing displayed. Only scripts/cron/purge_notifications.php
 *      ever touched it again, archiving rows nobody had seen. This file now
 *      surfaces them, and the POST branch below lets the frontend mark them read.
 *
 * Each item gated behind the same permission that already gates its parent
 * page (live items) or by permission at insert time (stored items — see
 * includes/functions/notify.php's lpc_notify_permission()), so nobody sees
 * an alert for a module they can't open.
 *
 * GET  /api/v1/notifications_controller.php
 *      -> { status, data: { items:[{type,severity,label,href,id?,created_at?}], count } }
 *      Stored items carry an `id` and `created_at` (ISO 8601); live items
 *      carry neither — that's how the frontend knows which ones can be
 *      dismissed and which ones have a real "when" to render.
 *
 * POST /api/v1/notifications_controller.php  (CSRF-gated, JSON or form body)
 *      action=mark_read      &id=N   -> marks one stored notification read
 *      action=mark_all_read          -> marks all of the caller's stored rows read
 *      Both are scoped to $_SESSION['user_id'] — you can only mark your own.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

Rbac::requireAuth();
$lang    = lpc_i18n_current_lang();
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$method  = $_SERVER['REQUEST_METHOD'];

// ==========================================
// POST — mark stored notification(s) read.
// ==========================================
if ($method !== 'GET') {
    Csrf::requireValid();

    $in     = json_decode(file_get_contents('php://input') ?: '', true);
    $action = $in['action'] ?? ($_POST['action'] ?? '');

    try {
        $db = Database::getInstance()->getConnection();

        if ($action === 'mark_read') {
            $id = (int) ($in['id'] ?? ($_POST['id'] ?? 0));
            if ($id > 0 && $user_id > 0) {
                $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
                   ->execute([$id, $user_id]);
            }
            echo json_encode(['status' => 'success']);
        } elseif ($action === 'mark_all_read') {
            if ($user_id > 0) {
                $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")
                   ->execute([$user_id]);
            }
            echo json_encode(['status' => 'success']);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
        }
    } catch (Throwable $e) {
        error_log('notifications_controller POST: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Could not update notification.']);
    }
    exit;
}

// ==========================================
// GET — merged feed.
// ==========================================
try {
    $db = Database::getInstance()->getConnection();
    $items = [];

    if (Rbac::hasPermission('accounting.invoices.view')) {
        $row = $db->query("
            SELECT COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS total
              FROM invoices
             WHERE status != 'paid' AND due_date < CURRENT_DATE()
        ")->fetch(PDO::FETCH_ASSOC);
        if ((int)$row['n'] > 0) {
            $items[] = [
                'type'     => 'overdue_invoices',
                'severity' => 'danger',
                'label'    => $lang === 'en'
                    ? sprintf('%d overdue invoice(s) — %s FCFA', $row['n'], number_format($row['total'], 0, ',', ' '))
                    : sprintf('%d facture(s) en retard — %s FCFA', $row['n'], number_format($row['total'], 0, ',', ' ')),
                'href'     => '/modules/accounting/invoices.php?filter=overdue',
            ];
        }
    }

    if (Rbac::hasPermission('accounting.invoices.view')) {
        $row = $db->query("
            SELECT COUNT(*) AS n, COALESCE(SUM(air_withheld_amount),0) AS total
              FROM payments
             WHERE withholding_certificate_id IS NULL AND air_withheld_amount > 0
        ")->fetch(PDO::FETCH_ASSOC);
        if ((int)$row['n'] > 0) {
            $items[] = [
                'type'     => 'air_uncertified',
                'severity' => 'warning',
                'label'    => $lang === 'en'
                    ? sprintf('%d AIR withholding(s) missing a certificate', $row['n'])
                    : sprintf('%d retenue(s) AIR sans attestation', $row['n']),
                'href'     => '/modules/accounting/tax_declarations.php',
            ];
        }
    }

    if (Rbac::hasPermission('inventory.stock.view')) {
        $row = $db->query("
            SELECT COUNT(*) AS n FROM (
                SELECT p.id,
                       COALESCE((
                           SELECT SUM(CASE WHEN movement_type LIKE 'in_%' THEN quantity ELSE -quantity END)
                             FROM inventory_movements WHERE product_id = p.id
                       ), 0) AS current_qty
                  FROM products p
                 WHERE p.min_stock_level IS NOT NULL
            ) t
            JOIN products p2 ON p2.id = t.id
           WHERE t.current_qty <= p2.min_stock_level
        ")->fetch(PDO::FETCH_ASSOC);
        if ((int)$row['n'] > 0) {
            $items[] = [
                'type'     => 'low_stock',
                'severity' => 'warning',
                'label'    => $lang === 'en'
                    ? sprintf('%d product(s) at or below reorder level', $row['n'])
                    : sprintf('%d produit(s) au seuil de réappro ou en dessous', $row['n']),
                'href'     => '/modules/inventory/stock.php?filter=low_stock',
            ];
        }
    }

    // Sprint 9 · Fleet compliance — same grounded queries as fleet_controller.php's
    // 'dashboard'/'maintenance' tabs (Strategy 7B / 2B alerts), which until now
    // only surfaced inside modules/fleet/vehicles.php itself. An expired
    // insurance or technical-visit document is a legal/safety exposure, not
    // just an inconvenience, so it belongs in the global feed too.
    if (Rbac::hasPermission('fleet.vehicles.view')) {
        $row = $db->query("
            SELECT COUNT(*) AS n FROM vehicles
             WHERE status != 'retired'
               AND (
                    (insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL 15 DAY))
                 OR (technical_visit_expiry IS NOT NULL AND technical_visit_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL 15 DAY))
               )
        ")->fetch(PDO::FETCH_ASSOC);
        if ((int)$row['n'] > 0) {
            $items[] = [
                'type'     => 'fleet_doc_expiring',
                'severity' => 'danger',
                'label'    => $lang === 'en'
                    ? sprintf('%d vehicle(s) with insurance or technical visit expiring within 15 days', $row['n'])
                    : sprintf('%d véhicule(s) avec assurance ou visite technique expirant sous 15 jours', $row['n']),
                'href'     => '/modules/fleet/vehicles.php',
            ];
        }

        $row = $db->query("
            SELECT COUNT(*) AS n
              FROM vehicles v
              JOIN vehicle_maintenance m ON v.id = m.vehicle_id
             WHERE v.status != 'retired' AND m.next_service_odometer IS NOT NULL
               AND (m.next_service_odometer - v.current_odometer) <= 500
               AND m.id = (SELECT MAX(id) FROM vehicle_maintenance WHERE vehicle_id = v.id)
        ")->fetch(PDO::FETCH_ASSOC);
        if ((int)$row['n'] > 0) {
            $items[] = [
                'type'     => 'fleet_maintenance_due',
                'severity' => 'warning',
                'label'    => $lang === 'en'
                    ? sprintf('%d vehicle(s) due for maintenance within 500 km', $row['n'])
                    : sprintf('%d véhicule(s) à entretenir sous 500 km', $row['n']),
                'href'     => '/modules/fleet/vehicles.php',
            ];
        }
    }

    // ---- stored, per-user events (unread only) ----------------------------
    // Gated implicitly: lpc_notify_permission() only ever inserted a row for
    // THIS user if they held a qualifying permission at the time it fired, so
    // there is no separate permission check needed here — same trust model as
    // the live checks above, just enforced at write time instead of read time.
    if ($user_id > 0) {
        $stmt = $db->prepare("
            SELECT id, title, message, type, related_url, created_at
              FROM notifications
             WHERE user_id = ? AND is_read = 0
             ORDER BY created_at DESC
             LIMIT 50
        ");
        $stmt->execute([$user_id]);
        $stored = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sevMap = ['error' => 'danger', 'warning' => 'warning', 'success' => 'info', 'info' => 'info'];
        foreach ($stored as $n) {
            $label = (string) $n['title'];
            $msg   = trim((string) $n['message']);
            if ($msg !== '') {
                $label .= ' — ' . (mb_strlen($msg) > 110 ? mb_substr($msg, 0, 110) . '…' : $msg);
            }
            $items[] = [
                'id'         => (int) $n['id'],
                'type'       => 'event',
                'severity'   => $sevMap[$n['type']] ?? 'info',
                'label'      => $label,
                'href'       => $n['related_url'] ?: '/modules/notifications/index.php',
                // ISO 8601 (date('c')), not the raw MySQL "Y-m-d H:i:s" string —
                // Safari fails to parse the latter with `new Date()`. Only
                // stored items carry this; live-computed conditions have no
                // "when", they're just true right now.
                'created_at' => $n['created_at'] ? date('c', strtotime($n['created_at'])) : null,
            ];
        }
    }

    echo json_encode(['status' => 'success', 'data' => ['items' => $items, 'count' => count($items)]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('notifications_controller: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not load notifications.']);
}
