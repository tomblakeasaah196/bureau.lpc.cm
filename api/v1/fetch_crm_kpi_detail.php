<?php
/**
 * api/v1/fetch_crm_kpi_detail.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7F · KPI card drill-down.
 *
 * Backs the detail modal behind each KPI card on modules/crm/clients.php.
 * `?metric=clients|ar|empties`.
 *
 * Every row carries the deep link the modal should navigate to, built here
 * rather than in JavaScript so the destination for a metric is defined once,
 * next to the query that produced the number.
 *
 * The totals returned here are computed with the SAME expressions as
 * fetch_crm_kpis.php. If the modal's total ever disagrees with the card above
 * it, that is a bug worth chasing — not a rounding artefact.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('crm.clients.view');

$metric = (string) ($_GET['metric'] ?? '');

/** Deep link into a destination page, filtered to one client and marked for return. */
function kpi_link(string $page, int $clientId, string $clientName): string
{
    return $page
        . '?client_id=' . $clientId
        . '&client=' . rawurlencode($clientName)
        . '&from=crm_clients';
}

try {
    $db = Database::getInstance()->getConnection();

    switch ($metric) {

        // ---------------------------------------------------------------------
        // Active clients — a straight list would only repeat the table sitting
        // directly beneath the card, so this answers "what is that 9 made of".
        case 'clients':
            $byType = $db->query(
                "SELECT COALESCE(NULLIF(TRIM(type), ''), 'B2B') AS label,
                        COUNT(*) AS n
                   FROM clients
                  WHERE status = 'active'
                  GROUP BY label
                  ORDER BY n DESC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $byStatus = $db->query(
                "SELECT status AS label, COUNT(*) AS n
                   FROM clients
                  GROUP BY status
                  ORDER BY FIELD(status, 'active', 'prospect', 'inactive')"
            )->fetchAll(PDO::FETCH_ASSOC);

            $recent = $db->query(
                "SELECT id, name, lpc_code, type, status, created_at
                   FROM clients
                  WHERE status = 'active'
                  ORDER BY created_at DESC, id DESC
                  LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'metric' => 'clients',
                'title'  => 'Répartition des clients',
                'total'  => array_sum(array_column($byType, 'n')),
                'breakdown' => [
                    ['heading' => 'Par type (clients actifs)',   'rows' => $byType],
                    ['heading' => 'Par statut (tous)',           'rows' => $byStatus],
                ],
                'recent' => array_map(static fn(array $r): array => [
                    'id'      => (int) $r['id'],
                    'name'    => $r['name'],
                    'code'    => $r['lpc_code'],
                    'type'    => $r['type'],
                    'created' => substr((string) $r['created_at'], 0, 10),
                ], $recent),
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ---------------------------------------------------------------------
        // Receivables per client. HAVING rather than WHERE because the balance
        // is an aggregate — a client with one overpaid and one unpaid invoice
        // nets out and should not appear.
        case 'ar':
            $rows = $db->query(
                "SELECT c.id, c.name, c.lpc_code, c.credit_limit,
                        COUNT(i.id) AS invoice_count,
                        SUM(i.total_amount - COALESCE((
                            SELECT SUM(p.amount) FROM payments p
                             WHERE p.invoice_id = i.id AND p.status = 'validated'
                        ), 0)) AS balance,
                        MIN(i.due_date) AS oldest_due
                   FROM clients c
                   JOIN invoices i ON i.client_id = c.id AND i.status <> 'paid'
                  GROUP BY c.id, c.name, c.lpc_code, c.credit_limit
                 HAVING balance > 0
                  ORDER BY balance DESC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'id'            => (int) $r['id'],
                    'name'          => $r['name'],
                    'code'          => $r['lpc_code'],
                    'value'         => (float) $r['balance'],
                    'invoice_count' => (int) $r['invoice_count'],
                    'oldest_due'    => $r['oldest_due'],
                    // Past due matters more than the amount when deciding who to call.
                    'overdue'       => $r['oldest_due'] !== null
                                       && strtotime((string) $r['oldest_due']) < strtotime('today'),
                    'credit_limit'  => (float) $r['credit_limit'],
                    'link'          => kpi_link('/modules/accounting/invoices.php', (int) $r['id'], (string) $r['name']),
                ];
            }

            echo json_encode([
                'status' => 'success',
                'metric' => 'ar',
                'title'  => 'Dette financière par client',
                'unit'   => 'fcfa',
                'total'  => array_sum(array_column($out, 'value')),
                'rows'   => $out,
                'target_label' => 'Facturation & AR',
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ---------------------------------------------------------------------
        // Empties held per client, from the ledger the CRE flow maintains.
        case 'empties':
            $rows = $db->query(
                "SELECT c.id, c.name, c.lpc_code,
                        SUM(cel.quantity_owed) AS owed,
                        COUNT(DISTINCT cel.product_id) AS product_count,
                        MAX(cel.last_updated) AS last_move
                   FROM client_empties_ledger cel
                   JOIN clients c ON c.id = cel.client_id
                  WHERE cel.quantity_owed > 0
                  GROUP BY c.id, c.name, c.lpc_code
                  ORDER BY owed DESC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'id'            => (int) $r['id'],
                    'name'          => $r['name'],
                    'code'          => $r['lpc_code'],
                    'value'         => (int) $r['owed'],
                    'product_count' => (int) $r['product_count'],
                    'last_move'     => substr((string) $r['last_move'], 0, 10),
                    'link'          => kpi_link('/modules/operations/empties_collection.php', (int) $r['id'], (string) $r['name']),
                ];
            }

            echo json_encode([
                'status' => 'success',
                'metric' => 'empties',
                'title'  => 'Emballages détenus par client',
                'unit'   => 'vides',
                'total'  => array_sum(array_column($out, 'value')),
                'rows'   => $out,
                'target_label' => 'Gestion des Vides',
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ---------------------------------------------------------------------
        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Indicateur inconnu.']);
    }

} catch (Throwable $e) {
    error_log('fetch_crm_kpi_detail(' . $metric . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erreur de chargement du détail.']);
}
