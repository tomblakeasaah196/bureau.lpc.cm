<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('crm.clients.view');
// api/v1/fetch_clients.php
header('Content-Type: application/json');
require_once '../../includes/config/db.php';
require_once '../../includes/classes/Database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // FETCHING EVERY SINGLE COLUMN NEEDED FOR THE MODAL
    // deleted_at IS NULL: clients in the corbeille have their own listing
    // (fetch_deleted_clients.php) so they don't show up here as pickable for
    // new devis/orders while awaiting auto-purge or restore.
    // is_withholding_agent + withholding_air_rate needed by the edit modal
    // (crm-clients.js openEditModal) so the checkbox + rate dropdown pre-fill
    // correctly. Columns come from migration 020_cameroon_tax_module.sql.
    // « Dette Financière » and « Dette Emballages » per client, from the same
    // tables as the KPI cards above the list (fetch_crm_kpis.php): what is
    // left to pay on its invoices, and the empties it owes. The list printed
    // « 0 FCFA » and « 0 Vides » for everyone (VIDEO_QA_FINDINGS 4.7).
    // The terms come from the client's signed devis (migration 137).
    $stmt = $db->query("
        SELECT c.id, c.lpc_code, c.name, c.type, c.contact_person, c.email, c.phone, c.address, c.niu, c.rc,
               c.tax_id, c.credit_limit, c.status,
               COALESCE(c.is_withholding_agent, 0) AS is_withholding_agent,
               COALESCE(c.withholding_air_rate, 0) AS withholding_air_rate,
               c.payment_terms_days, c.empties_return_min_pct,
               tp.reference AS terms_proposal_ref,
               COALESCE(ar.due, 0)      AS financial_debt,
               COALESCE(em.owed, 0)     AS empties_owed
        FROM clients c
        LEFT JOIN proposals tp ON tp.id = c.terms_proposal_id
        LEFT JOIN (
            SELECT i.client_id,
                   SUM(i.total_amount - COALESCE((
                       SELECT SUM(p.amount) FROM payments p
                        WHERE p.invoice_id = i.id AND p.status = 'validated'
                   ), 0)) AS due
              FROM invoices i
             WHERE i.status <> 'paid'
             GROUP BY i.client_id
        ) ar ON ar.client_id = c.id
        LEFT JOIN (
            SELECT client_id, SUM(quantity_owed) AS owed
              FROM client_empties_ledger
             WHERE quantity_owed > 0
             GROUP BY client_id
        ) em ON em.client_id = c.id
        WHERE c.deleted_at IS NULL
        ORDER BY c.id DESC
    ");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $clients]);

} catch (PDOException $e) {
    error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}