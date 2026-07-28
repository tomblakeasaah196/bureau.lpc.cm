<?php
/**
 * api/v1/fetch_crm_kpis.php
 * -----------------------------------------------------------------------------
 * The three KPI cards on modules/crm/clients.php.
 *
 * ── Sprint 7F fix ────────────────────────────────────────────────────────────
 * Two of these three numbers were structurally incapable of being anything but
 * zero, and had been since the file was written:
 *
 *   · Financial debt read `invoices.amount_paid`. There is no such column —
 *     not in the schema, not added by any migration. Payments live in their own
 *     `payments` table and only count once `status = 'validated'`.
 *   · Bottle debt read `deliveries.expected_empties` / `.returned_empties`.
 *     Neither column exists either; empties balances live in
 *     `client_empties_ledger.quantity_owed`.
 *
 * Both queries therefore threw on every request, and both were wrapped in a
 * `catch (PDOException) { $x = 0; }` that turned the failure into a plausible
 * looking business fact. The cards read "0 FCFA" and "0 Vides", which is what a
 * company with no receivables would also look like.
 *
 * The formulas below are not invented here — they are the ones already used by
 * api/v1/invoices_controller.php (AR ageing, ~line 121) and
 * api/v1/cre_controller.php (empties ledger). Reusing them keeps this card and
 * the pages it deep-links into telling the same story.
 *
 * The catch blocks are gone on purpose. If a query breaks again this endpoint
 * should fail loudly rather than quietly report that the company is owed
 * nothing.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('crm.clients.view');

try {
    $db = Database::getInstance()->getConnection();

    // 1. Active clients.
    $activeClients = (int) $db->query(
        "SELECT COUNT(id) FROM clients WHERE status = 'active'"
    )->fetchColumn();

    // 2. Accounts receivable — invoiced minus validated payments.
    //    Pending driver cash deliberately does NOT reduce the debt: it hasn't
    //    been counted yet, so treating it as received would understate AR.
    $financialDebt = (float) $db->query(
        "SELECT COALESCE(SUM(
                    i.total_amount - COALESCE((
                        SELECT SUM(p.amount) FROM payments p
                         WHERE p.invoice_id = i.id AND p.status = 'validated'
                    ), 0)
                ), 0)
           FROM invoices i
          WHERE i.status <> 'paid'"
    )->fetchColumn();

    // 3. Empties held by clients. quantity_owed is maintained by
    //    sales_controller.php on dispatch and cre_controller.php on collection.
    $bottleDebt = (int) $db->query(
        "SELECT COALESCE(SUM(quantity_owed), 0)
           FROM client_empties_ledger
          WHERE quantity_owed > 0"
    )->fetchColumn();

    echo json_encode([
        'status' => 'success',
        'data'   => [
            'active_clients' => $activeClients,
            'financial_debt' => $financialDebt,
            'bottle_debt'    => $bottleDebt,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('fetch_crm_kpis: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erreur de calcul des indicateurs.']);
}
