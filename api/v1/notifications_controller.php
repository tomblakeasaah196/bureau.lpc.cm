<?php
/**
 * api/v1/notifications_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — topbar notifications feed.
 *
 * Three real, schema-verified checks (grounded in existing queries elsewhere
 * in this codebase, not invented columns):
 *   1. Overdue invoices          -- invoices_controller.php's own AR-aging query
 *   2. AIR withheld, no attestation -- docs/GUIDE_FISCAL_CAMEROUN.md §7 checklist
 *   3. Low stock                 -- inventory_controller.php's current_qty calc
 *
 * Each item gated behind the same permission that already gates its parent
 * page, so nobody sees an alert for a module they can't open anyway.
 *
 * GET /api/v1/notifications_controller.php  -> { status, data: { items:[], count } }
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

Rbac::requireAuth();
$lang = in_array(($_GET['lang'] ?? 'fr'), ['fr','en'], true) ? $_GET['lang'] : 'fr';

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

    echo json_encode(['status' => 'success', 'data' => ['items' => $items, 'count' => count($items)]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('notifications_controller: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not load notifications.']);
}
