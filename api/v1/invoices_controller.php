<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';   // Sprint 5
require_once __DIR__ . '/../../includes/functions/signature_side_effects.php';
// ^ pulls in lpc_recompute_sales_order_payment_status(): every payment path
//   below updates invoices.status but must also refresh the linked sales
//   orders — before this, an invoice paid via bank transfer or wallet left
//   the order page reading "Non payé" indefinitely.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('accounting.invoices.view');
/**
 * API CONTROLLER: Facturation & Paiements
 * ENDPOINT: /api/v1/invoices_controller.php
 * DESCRIPTION: Handles AR read, invoice generation, and cash reconciliation logic.
 */
header('Content-Type: application/json; charset=utf-8');

// ==========================================
// 1. STRICT RBAC SECURITY GATE (Finance & Admin Only)
// ==========================================
$user_id = (int)$_SESSION['user_id'];

// ==========================================
// 2. DATABASE CONNECTION
// ==========================================
try {
    require_once '../../includes/config/db.php';
    require_once '../../includes/classes/Database.php';
    
    // Initialize the PDO connection!
    $pdo = Database::getInstance()->getConnection(); 
} catch (Exception $e) {
    error_log('API error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
    exit;
}

// ==========================================
// 3. ACTION ROUTING
// ==========================================
$action  = $_GET['action'] ?? $_POST['action'] ?? '';
$jsonData = [];

if (empty($action)) {
    $rawInput = file_get_contents('php://input');
    if ($rawInput) {
        $jsonData = json_decode($rawInput, true);
        $action   = $jsonData['action'] ?? '';
    }
}

if (empty($action)) {
    echo json_encode(['status' => 'error', 'message' => 'Action non spécifiée']);
    exit;
}

// ==========================================
// HELPER: NOTIFICATION ENGINE
// ==========================================
function notifyAdminFinance($db, $title, $message) {
    $stmt = $db->query("
        SELECT u.id 
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        WHERE r.name IN ('admin', 'finance', 'accountant') AND u.status = 'active'
    ");
    $target_users = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($target_users)) {
        $insertStmt = $db->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
        foreach ($target_users as $uid) {
            $insertStmt->execute([$uid, $title, $message]);
        }
    }
}

/**
 * Record which collection accounts an invoice advertises.
 *
 * Selection order, most specific first:
 *   1. what the user ticked in the generation modal
 *   2. the client's default account (Afriland for the large accounts,
 *      microfinance for the rest — set once on the client, not remembered by
 *      whoever happens to raise the invoice)
 *   3. every account flagged "show on invoice", in display order
 *
 * The first surviving account becomes invoices.settlement_account_id, which is
 * what JournalPoster uses to decide which treasury sub-ledger a payment debits.
 * Before this existed it fell through to "first active account of this type",
 * so with two banks configured every bank payment posted to whichever was
 * created first regardless of where the money actually landed.
 *
 * Degrades silently when migration 044 has not run: the tables and columns are
 * probed, and an invoice still issues without a payment block rather than
 * failing mid-deploy.
 */
function lpc_attach_invoice_payment_accounts(PDO $db, int $invoice_id, $requested, int $client_id): void
{
    $has_table = (int) $db->query("
        SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'invoice_payment_accounts'
    ")->fetchColumn() > 0;
    if (!$has_table) return;

    $ids = [];
    if (is_array($requested)) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $requested))));
    }

    if (!$ids) {
        $stmt = $db->prepare("SELECT default_payment_account_id FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
        $default = (int) $stmt->fetchColumn();
        if ($default > 0) $ids = [$default];
    }

    if (!$ids) {
        $ids = array_map('intval', $db->query("
            SELECT id FROM treasury_accounts
             WHERE status = 'active' AND show_on_invoice = 1
             ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_COLUMN));
    }

    if (!$ids) return;   // nothing configured yet — invoice issues without a payment block

    // Re-read from the database rather than trusting the ids the browser sent:
    // an inactive or non-invoiceable account must not reach a customer document.
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("
        SELECT * FROM treasury_accounts
         WHERE id IN ($ph) AND status = 'active' AND show_on_invoice = 1
         ORDER BY FIELD(id, $ph)
    ");
    $stmt->execute(array_merge($ids, $ids));
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$accounts) return;

    $link = $db->prepare("
        INSERT INTO invoice_payment_accounts (invoice_id, treasury_account_id, sort_order)
        VALUES (?, ?, ?)
    ");
    $snapshot = [];
    foreach ($accounts as $i => $acc) {
        $link->execute([$invoice_id, (int) $acc['id'], $i]);
        $snapshot[] = TreasuryAccount::printBlock($acc);
    }

    $db->prepare("UPDATE invoices SET settlement_account_id = ?, payment_block_snapshot = ? WHERE id = ?")
       ->execute([
           (int) $accounts[0]['id'],
           json_encode($snapshot, JSON_UNESCAPED_UNICODE),
           $invoice_id,
       ]);
}

try {
    $db = Database::getInstance()->getConnection();
    // Force PDO to throw catchable exceptions instead of silent fatal errors
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ==========================================
    // CRASH-PROOF HELPER FUNCTIONS 
    // (Prevents Fatal HTML errors if a table doesn't exist yet)
    // ==========================================
    function safeCount($db, $sql) {
        try { return (int)$db->query($sql)->fetchColumn(); } 
        catch(Exception $e) { return 0; }
    }
    function safeQueryRow($db, $sql) {
        try { return $db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: []; } 
        catch(Exception $e) { return []; }
    }
    function safeQueryAll($db, $sql) {
        try { return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; } 
        catch(Exception $e) { return []; }
    }

    switch ($action) {

        // ==========================================
        // ACTION: PAYMENT ACCOUNTS (invoice modal picker)
        // ==========================================
        // The collection accounts offerable on an invoice, plus this client's
        // default so the modal can pre-tick it. Read-only; safe to call before
        // migration 044 has run, in which case it returns an empty list and the
        // invoice is issued without a payment block.
        case 'payment_accounts':
            $client_id = (int) ($_GET['client_id'] ?? 0);

            $has_col = (int) $db->query("
                SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'treasury_accounts'
                   AND column_name = 'show_on_invoice'
            ")->fetchColumn() > 0;

            if (!$has_col) {
                echo json_encode(['status' => 'success', 'accounts' => [], 'default_account_id' => null]);
                break;
            }

            require_once __DIR__ . '/../../includes/classes/TreasuryAccount.php';
            $accounts = array_map(static function (array $a) {
                // Only what the picker renders. Balances and internal ids for
                // other accounts have no business in this response.
                return [
                    'id'             => (int) $a['id'],
                    'name'           => $a['name'],
                    'type'           => $a['type'],
                    'bank_name'      => $a['bank_name'] ?? null,
                    'account_number' => $a['account_number'] ?? null,
                ];
            }, TreasuryAccount::invoiceable());

            $default_id = null;
            if ($client_id > 0) {
                $stmt = $db->prepare("SELECT default_payment_account_id FROM clients WHERE id = ?");
                $stmt->execute([$client_id]);
                $default_id = (int) $stmt->fetchColumn() ?: null;
            }
            // Only honour the client default if it is still offerable.
            if ($default_id && !in_array($default_id, array_column($accounts, 'id'), true)) {
                $default_id = null;
            }

            echo json_encode([
                'status'             => 'success',
                'accounts'           => $accounts,
                'default_account_id' => $default_id ?: ($accounts[0]['id'] ?? null),
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ==========================================
        // ACTION: ACTIVE TREASURY ACCOUNTS (payment-receipt picker)
        // ==========================================
        // The full list of active wallets the accountant can credit when
        // recording an incoming payment. Distinct from `payment_accounts`,
        // which is scoped to `show_on_invoice = 1` for the customer-facing
        // block. A cash receipt might land in a petty caisse that is not
        // advertised on invoices, so we drop that filter here.
        //
        // Response includes `type` so the frontend can filter by the method
        // dropdown (cash → caisse, bank → banque, momo → momo).
        case 'active_treasury_accounts':
            $has_table = (int) $db->query("
                SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'treasury_accounts'
            ")->fetchColumn() > 0;

            if (!$has_table) {
                echo json_encode(['status' => 'success', 'accounts' => []]);
                break;
            }

            $rows = $db->query("
                SELECT id, name, type, COALESCE(bank_name, '') AS bank_name,
                       COALESCE(account_number, '') AS account_number
                  FROM treasury_accounts
                 WHERE status = 'active'
                 ORDER BY type ASC, COALESCE(sort_order, 0) ASC, name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status'   => 'success',
                'accounts' => array_map(static function ($a) {
                    return [
                        'id'             => (int) $a['id'],
                        'name'           => $a['name'],
                        'type'           => $a['type'],
                        'bank_name'      => $a['bank_name'] ?: null,
                        'account_number' => $a['account_number'] ?: null,
                    ];
                }, $rows),
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ==========================================
        // ACTION: READ TABS (Dynamic AR Dashboard)
        // ==========================================
        case 'read':
            $tab = $_GET['tab'] ?? 'dashboard';
            $responseData = [];

            // 1. Global Badges (Using safe helpers)
            $badges = [];
            $badges['to_invoice'] = safeCount($db, "
                SELECT COUNT(*) FROM deliveries d 
                LEFT JOIN invoice_deliveries id ON d.id = id.delivery_id 
                WHERE d.status = 'completed' AND id.invoice_id IS NULL
            ");
            
            $badges['pending_cash'] = safeCount($db, "
                SELECT COUNT(*) FROM cash_reconciliations WHERE status = 'pending_accountant'
            ");
            
            $responseData['badges'] = $badges;

            if ($tab === 'dashboard') {
                // Same fix as get_unpaid_invoices: SUM(amount) alone under-clears
                // any invoice with an AIR withholding, so the dashboard was
                // over-reporting unpaid AR (and every aging bucket) whenever a
                // withholding-agent client paid. Uniform (amount + AIR)
                // everywhere means the AR total on the dashboard matches the 411
                // balance on the trial balance to the franc.
                $arData = safeQueryRow($db, "
                    SELECT SUM(total_amount) AS total,
                           SUM(total_amount - COALESCE((
                               SELECT SUM(amount + COALESCE(air_withheld_amount, 0))
                                 FROM payments
                                WHERE invoice_id = invoices.id AND status = 'validated'
                           ), 0)) AS unpaid
                      FROM invoices WHERE status != 'paid'
                ");

                // safeCount casts to int, but SUM(open_balance) is a decimal.
                // Kept as-is upstream so the KPI cards stay integer FCFA — the
                // rounding step is safe because FCFA has no subunit.
                $overdue = safeCount($db, "
                    SELECT SUM(total_amount - COALESCE((
                               SELECT SUM(amount + COALESCE(air_withheld_amount, 0))
                                 FROM payments
                                WHERE invoice_id = invoices.id AND status = 'validated'
                           ), 0))
                      FROM invoices
                     WHERE status != 'paid' AND due_date < CURRENT_DATE()
                ");

                $wallets = safeCount($db, "SELECT SUM(balance) FROM client_wallets");

                $pendingCash = safeCount($db, "SELECT SUM(reported_cash) FROM cash_reconciliations WHERE status = 'pending_accountant'");

                $aging = safeQueryRow($db, "
                    SELECT
                        SUM(CASE WHEN due_date >= CURRENT_DATE()
                                 THEN (total_amount - COALESCE((SELECT SUM(amount + COALESCE(air_withheld_amount,0)) FROM payments WHERE invoice_id = invoices.id AND status='validated'), 0))
                                 ELSE 0 END) AS current,
                        SUM(CASE WHEN due_date <  CURRENT_DATE() AND due_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)
                                 THEN (total_amount - COALESCE((SELECT SUM(amount + COALESCE(air_withheld_amount,0)) FROM payments WHERE invoice_id = invoices.id AND status='validated'), 0))
                                 ELSE 0 END) AS d30,
                        SUM(CASE WHEN due_date <  DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY) AND due_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 60 DAY)
                                 THEN (total_amount - COALESCE((SELECT SUM(amount + COALESCE(air_withheld_amount,0)) FROM payments WHERE invoice_id = invoices.id AND status='validated'), 0))
                                 ELSE 0 END) AS d60,
                        SUM(CASE WHEN due_date <  DATE_SUB(CURRENT_DATE(), INTERVAL 60 DAY)
                                 THEN (total_amount - COALESCE((SELECT SUM(amount + COALESCE(air_withheld_amount,0)) FROM payments WHERE invoice_id = invoices.id AND status='validated'), 0))
                                 ELSE 0 END) AS d90
                      FROM invoices WHERE status != 'paid'
                ");

                $topDebtors = safeQueryAll($db, "
                    SELECT c.name AS client_name,
                           SUM(i.total_amount - COALESCE((
                               SELECT SUM(amount + COALESCE(air_withheld_amount, 0))
                                 FROM payments
                                WHERE invoice_id = i.id AND status='validated'
                           ), 0)) AS total_debt
                      FROM invoices i
                      JOIN clients c ON i.client_id = c.id
                     WHERE i.status != 'paid'
                     GROUP BY c.id
                     ORDER BY total_debt DESC
                     LIMIT 5
                ");

                $responseData['kpis'] = [
                    'total_ar'     => (float)($arData['total'] ?? 0),
                    'unpaid_ar'    => (float)($arData['unpaid'] ?? 0),
                    'overdue'      => (float)$overdue,
                    'wallets'      => (float)$wallets,
                    'pending_cash' => (float)$pendingCash
                ];
                $responseData['aging'] = [
                    'current' => (float)($aging['current'] ?? 0),
                    'd30'     => (float)($aging['d30'] ?? 0),
                    'd60'     => (float)($aging['d60'] ?? 0),
                    'd90'     => (float)($aging['d90'] ?? 0)
                ];
                $responseData['top_debtors'] = $topDebtors;

            } elseif ($tab === 'to_invoice') {
                // ---------------------------------------------------------------
                // Sprint 9 — this list was an unbounded fetchAll with a fixed
                // ORDER BY. The DATE and CLIENT column headings carried a
                // cursor-pointer and a fa-sort icon but no click handler at all,
                // so they looked sortable and were not; and the search box only
                // hid rows in the DOM, which silently stopped being the truth
                // once the list outgrew what had been rendered.
                //
                // Sorting, filtering and paging now all resolve here. Sort keys
                // are whitelisted — the column name is never taken from input.
                // ---------------------------------------------------------------
                $SORTABLE = [
                    'date'   => 'd.date',
                    'client' => 'c.name',
                    'bl'     => 'd.reference',
                    'order'  => 'so.reference',
                    'cash'   => 'd.payment_collected',
                ];
                $sort_key = (string) ($_GET['sort'] ?? 'date');
                $sort_col = $SORTABLE[$sort_key] ?? $SORTABLE['date'];
                $dir      = strtoupper((string) ($_GET['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

                $body = "
                    FROM deliveries d
                    JOIN clients c ON d.client_id = c.id
                    JOIN sales_orders so ON d.sales_order_id = so.id
                    LEFT JOIN invoice_deliveries idel ON d.id = idel.delivery_id
                    WHERE d.status = 'completed' AND idel.invoice_id IS NULL
                ";
                $params = [];

                // Picking a client hides every other client's DNs. An invoice
                // can only ever group one client's notes, so this is also what
                // the batching flow downstream actually wants.
                $filter_client = (int) ($_GET['client_id'] ?? 0);
                if ($filter_client > 0) {
                    $body .= " AND d.client_id = :client_id";
                    $params[':client_id'] = $filter_client;
                }

                $lpc_q = trim((string) ($_GET['q'] ?? ''));
                if ($lpc_q !== '') {
                    [$body, $params] = Paginator::addWhere(
                        $body, $params, $lpc_q,
                        ['c.name', 'd.reference', 'so.reference']
                    );
                }

                // d.id as the tiebreaker keeps paging stable when many rows
                // share a date — without it a row can appear on two pages.
                $body .= " ORDER BY {$sort_col} {$dir}, d.id ASC";

                try {
                    $page = Paginator::paginate($db, $body, $params,
                        "d.id, d.reference as bl_ref, d.date, d.client_id, d.payment_collected,
                         c.name as client_name,
                         so.reference as so_ref, so.subtotal as sales_subtotal",
                        null, (int) ($_GET['per_page'] ?? 50), "invoices.read.to_invoice",
                        "COUNT(DISTINCT d.id)");

                    $responseData['deliveries'] = $page['data'];
                    $responseData['pagination'] = [
                        'page'        => $page['page'],
                        'per_page'    => $page['per_page'],
                        'total'       => $page['total'],
                        'total_pages' => $page['total_pages'],
                        'has_prev'    => $page['has_prev'],
                        'has_next'    => $page['has_next'],
                    ];
                    $responseData['sort'] = ['key' => $sort_key, 'dir' => $dir];
                } catch (Throwable $e) {
                    error_log('to_invoice tab: ' . $e->getMessage());
                    $responseData['deliveries'] = [];
                }

                // Clients that actually have something uninvoiced, for the
                // filter dropdown. Cheap, and it keeps the list free of the
                // hundreds of clients with nothing outstanding.
                $responseData['filter_clients'] = safeQueryAll($db, "
                    SELECT c.id, c.name, COUNT(DISTINCT d.id) AS pending
                      FROM deliveries d
                      JOIN clients c ON c.id = d.client_id
                      LEFT JOIN invoice_deliveries idel ON d.id = idel.delivery_id
                     WHERE d.status = 'completed' AND idel.invoice_id IS NULL
                     GROUP BY c.id, c.name
                     ORDER BY c.name ASC
                ");

            } elseif ($tab === 'invoices') {
                // Sprint 5: paginate the invoice list — it grows unbounded.
                $lpc_q = trim((string) ($_GET['q'] ?? ''));
                $body   = "
                    FROM invoices i
                    JOIN clients c ON i.client_id = c.id
                ";
                $params = [];
                if ($lpc_q !== '') {
                    [$body, $params] = Paginator::addWhere(
                        $body, $params, $lpc_q,
                        ['i.reference', 'c.name', 'i.status']
                    );
                }
                $body .= " ORDER BY i.date DESC, i.id DESC";
                try {
                    $page = Paginator::paginate($db, $body, $params,
                        "i.id, i.reference, i.date, i.due_date, i.total_amount, i.status, i.token, i.client_id,
                         c.name as client_name",
                        null, null, "invoices.read.invoices");
                    $responseData['invoices']   = $page['data'];
                    $responseData['pagination'] = [
                        'page'        => $page['page'],
                        'per_page'    => $page['per_page'],
                        'total'       => $page['total'],
                        'total_pages' => $page['total_pages'],
                        'has_prev'    => $page['has_prev'],
                        'has_next'    => $page['has_next'],
                    ];
                } catch (Throwable $e) {
                    error_log('invoices tab: ' . $e->getMessage());
                    $responseData['invoices'] = [];
                }

            } elseif ($tab === 'payments') {
                // Sprint 5: paginate payments. Merged with pending cash
                // reconciliations at the tail so their small count doesn't
                // meaningfully affect the paginator's math.
                $lpc_q = trim((string) ($_GET['q'] ?? ''));
                $body   = "
                    FROM payments p
                    JOIN clients c ON p.client_id = c.id
                    LEFT JOIN invoices i ON p.invoice_id = i.id
                ";
                $params = [];
                if ($lpc_q !== '') {
                    [$body, $params] = Paginator::addWhere(
                        $body, $params, $lpc_q,
                        ['p.reference', 'c.name', 'p.payment_method', 'p.status', 'i.reference']
                    );
                }
                $body .= " ORDER BY p.status ASC, p.payment_date DESC";
                try {
                    $page = Paginator::paginate($db, $body, $params,
                        "p.id, p.reference, p.amount, p.payment_method, p.payment_date, p.status, p.invoice_id,
                         c.name as client_name, i.reference as invoice_ref",
                        null, null, "invoices.read.payments");
                    $payments = $page['data'];
                    $responseData['pagination'] = [
                        'page'        => $page['page'],
                        'per_page'    => $page['per_page'],
                        'total'       => $page['total'],
                        'total_pages' => $page['total_pages'],
                        'has_prev'    => $page['has_prev'],
                        'has_next'    => $page['has_next'],
                    ];
                } catch (Throwable $e) {
                    error_log('payments tab: ' . $e->getMessage());
                    $payments = [];
                }

                // Pending cash reconciliations — small set, kept in front.
                $recons = safeQueryAll($db, "
                    SELECT cr.id, CONCAT('REC-CHAUFFEUR-', cr.id) as reference, cr.reported_cash as amount,
                           'cash' as payment_method, cr.date as payment_date, 'pending' as status,
                           NULL as invoice_id, CONCAT('Chauffeur ID: ', cr.driver_id) as client_name,
                           'Validation de caisse requise' as invoice_ref
                    FROM cash_reconciliations cr
                    WHERE cr.status = 'pending_accountant'
                    LIMIT 200
                ");

                $responseData['payments'] = array_merge($recons, $payments);

            } elseif ($tab === 'wallets') {
                // client_id was missing from the SELECT — the frontend needs it
                // to open the "apply wallet" modal against the right client.
                $responseData['wallets'] = safeQueryAll($db, "
                    SELECT cw.client_id, cw.balance, cw.last_updated, c.name as client_name
                    FROM client_wallets cw
                    JOIN clients c ON cw.client_id = c.id
                    WHERE cw.balance > 0
                    ORDER BY cw.balance DESC
                ");
            }

            echo json_encode(['status' => 'success', 'data' => $responseData]);
            break;

        // ==========================================
        // ACTION: GET CLIENTS & UNPAID INVOICES
        // ==========================================
        case 'get_clients':
            $stmt = $db->query("SELECT id, name FROM clients WHERE deleted_at IS NULL ORDER BY name ASC");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'get_unpaid_invoices':
            // The balance HAS to count air_withheld_amount in the SUM, otherwise
            // an invoice partly settled by a withholding client (whose AIR the
            // ledger has already applied against 411) shows a phantom "Reste Dû"
            // in the payment picker. register_payment sums both fields (line
            // 855) and the ledger clears the receivable at (amount + AIR) — this
            // picker was the only place still summing amount alone.
            // air_amount + net_payable exposed so the payment modal (JS)
            // can pre-fill air_withheld when the invoice was issued to a
            // withholding-agent client. Cf. Batch A of the AIR workflow.
            $client_id = (int)($_GET['client_id'] ?? 0);
            $stmt = $db->prepare("
                SELECT id, reference, total_amount,
                       COALESCE(air_amount, 0)   AS air_amount,
                       COALESCE(net_payable, total_amount) AS net_payable,
                       (total_amount - COALESCE((
                           SELECT SUM(amount + COALESCE(air_withheld_amount, 0))
                             FROM payments
                            WHERE invoice_id = invoices.id AND status = 'validated'
                       ), 0)) AS balance
                  FROM invoices
                 WHERE client_id = ? AND status != 'paid'
                 ORDER BY date ASC, id ASC
            ");
            $stmt->execute([$client_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ==========================================
        // ACTION: GENERATE BATCH INVOICE
        // ==========================================
        case 'generate_invoice':
            // Issuing an invoice recognises revenue, creates a receivable and
            // posts to the general ledger. The gate at the top of this file is
            // 'accounting.invoices.view' — reading the AR dashboard is not
            // authority to do any of that, and every other controller in the
            // app (procurement, inventory) already re-checks per write action.
            // The permission exists and is already granted to the roles that
            // should have it; nothing was calling it.
            Rbac::requirePermission('accounting.invoices.create');

            $db->beginTransaction();

            $client_id    = (int)($jsonData['client_id'] ?? 0);
            $delivery_ids = $jsonData['deliveries'] ?? [];
            $date         = trim((string)($jsonData['date'] ?? ''));
            $due_date     = trim((string)($jsonData['due_date'] ?? ''));
            $tva_rate     = (float)($jsonData['tva_rate'] ?? 0);
            $notes        = trim((string)($jsonData['notes'] ?? ''));

            if (empty($delivery_ids) || empty($client_id)) {
                throw new UserFacingException("Données invalides pour la facturation : client ou livraisons manquants.");
            }
            // Normalise the client-supplied dates. Anything not matching Y-m-d is
            // refused rather than passed to the INSERT, where MySQL would either
            // reject it (strict mode) or coerce it to '0000-00-00' (lax mode);
            // both outcomes leave a broken invoice halfway through the
            // transaction. A missing due_date defaults to +30 days.
            $isDate = static fn($s) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
            if (!$isDate($date)) {
                throw new UserFacingException("Date de facture invalide.");
            }
            if ($due_date === '') {
                $due_date = date('Y-m-d', strtotime($date . ' +30 days'));
            } elseif (!$isDate($due_date)) {
                throw new UserFacingException("Date d'échéance invalide.");
            } elseif ($due_date < $date) {
                throw new UserFacingException("La date d'échéance ne peut pas être antérieure à la date de facture.");
            }
            if ($tva_rate < 0 || $tva_rate > 100) {
                throw new UserFacingException("Taux de TVA hors plage (0–100).");
            }
            // De-duplicate delivery ids so the same BL passed twice cannot lock
            // two rows and double-count the value.
            $delivery_ids = array_values(array_unique(array_map('intval', $delivery_ids)));
            if (in_array(0, $delivery_ids, true)) {
                throw new UserFacingException("Livraisons invalides.");
            }

            // Collapse the LEFT JOIN to one row per delivery. Without GROUP BY
            // a delivery already linked to N invoice_deliveries rows would come
            // back N times, making the count check below fail with the misleading
            // "invalides ou n'appartiennent pas à ce client" — the deliveries
            // are fine, they've simply been double-linked in the past. MAX() is
            // enough to detect "already invoiced" because the check downstream
            // only cares whether ANY invoice_id is present.
            $placeholders = implode(',', array_fill(0, count($delivery_ids), '?'));
            $stmtCheck = $db->prepare("
                SELECT d.id, d.payment_collected, MAX(idel.invoice_id) AS invoice_id
                FROM deliveries d
                LEFT JOIN invoice_deliveries idel ON d.id = idel.delivery_id
                WHERE d.id IN ($placeholders) AND d.client_id = ?
                GROUP BY d.id, d.payment_collected
                FOR UPDATE
            ");
            $params = array_merge($delivery_ids, [$client_id]);
            $stmtCheck->execute($params);
            $valid_deliveries = $stmtCheck->fetchAll(PDO::FETCH_ASSOC);

            // Business-rule failures below use UserFacingException so the
            // operator sees the actual reason instead of the generic
            // "Erreur serveur. Veuillez réessayer." — exactly the case the
            // UserFacingException class docstring points to as the reason
            // for its existence.
            if (count($valid_deliveries) !== count($delivery_ids)) {
                $found_ids   = array_column($valid_deliveries, 'id');
                $missing_ids = array_diff($delivery_ids, $found_ids);
                throw new UserFacingException(
                    "Une ou plusieurs livraisons sont invalides ou n'appartiennent pas à ce client "
                    . '(BL #' . implode(', #', $missing_ids) . ').'
                );
            }
            foreach ($valid_deliveries as $vd) {
                if ($vd['invoice_id'] !== null) {
                    throw new UserFacingException("Le BL #" . $vd['id'] . " est déjà facturé.");
                }
            }

            $stmtItems = $db->prepare("
                SELECT di.product_id, p.name, di.delivered_quantity, di.unit_price 
                FROM delivery_items di
                JOIN products p ON di.product_id = p.id
                WHERE di.delivery_id IN ($placeholders) AND di.delivered_quantity > 0
            ");
            $stmtItems->execute($delivery_ids);
            $raw_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $grouped_items = [];
            $subtotal = 0;
            
            foreach ($raw_items as $item) {
                $pid = $item['product_id'];
                if (!isset($grouped_items[$pid])) {
                    $grouped_items[$pid] = [
                        'name'       => $item['name'],
                        'quantity'   => 0,
                        'unit_price' => (float)$item['unit_price']
                    ];
                }
                $grouped_items[$pid]['quantity'] += (int)$item['delivered_quantity'];
                $subtotal += ((int)$item['delivered_quantity'] * (float)$item['unit_price']);
            }

            // -----------------------------------------------------------------
            // COMMERCIAL REDUCTION — SYSCOHADA audit fix.
            //
            // THE DEFECT. sales_orders carries discount_amount (migration 062)
            // and sales_order_items carries the line-level part of it. But
            // generate_dispatch copies only sales_order_items.unit_price into
            // delivery_items, and this action re-prices the invoice from
            // delivery_items — so the ORDER-HEADER remise never crossed from the
            // order to the invoice. The client was billed more than the order
            // said, and 411, 701 and the TVA base were all overstated by exactly
            // that remise. invoices had no column to put it in either, which is
            // why migration 065 adds one.
            //
            // WHY IT IS NETTED INTO subtotal RATHER THAN GIVEN ITS OWN ENTRY.
            // SYSCOHADA books a reduction granted ON the invoice nowhere at all:
            // revenue is recognised at the net, and the TVA base is the net.
            // Only a reduction granted AFTER the invoice — by avoir — hits 7019.
            // So the correct treatment is to reduce the base here, before any
            // tax is computed, and postInvoiceIssued credits 701 with the net.
            //
            // ALLOCATION. The remise is spread across the deliveries being
            // invoiced in proportion to their value, so invoicing 2 of an
            // order's 3 delivery notes carries 2/3 of the remise rather than
            // all of it or none of it. Each order contributes at most its own
            // remise, and never more than the value actually being billed.
            // -----------------------------------------------------------------
            $gross_subtotal  = $subtotal;
            $discount_amount = 0.0;
            $discount_parts  = [];

            $stmtOrderDisc = $db->prepare("
                SELECT so.id, so.reference, so.discount_amount, so.discount_note,
                       so.subtotal AS order_subtotal,
                       COALESCE(SUM(di.delivered_quantity * di.unit_price), 0) AS billed_value,
                       COALESCE((
                           SELECT SUM(di2.delivered_quantity * di2.unit_price)
                             FROM deliveries d2
                             JOIN delivery_items di2 ON di2.delivery_id = d2.id
                            WHERE d2.sales_order_id = so.id
                       ), 0) AS order_delivered_value
                  FROM deliveries d
                  JOIN sales_orders so ON so.id = d.sales_order_id
                  JOIN delivery_items di ON di.delivery_id = d.id
                 WHERE d.id IN ($placeholders)
                   AND so.discount_amount > 0
                 GROUP BY so.id, so.reference, so.discount_amount, so.discount_note, so.subtotal
            ");
            $stmtOrderDisc->execute($delivery_ids);

            foreach ($stmtOrderDisc->fetchAll(PDO::FETCH_ASSOC) as $od) {
                $order_disc  = (float) $od['discount_amount'];
                $billed      = (float) $od['billed_value'];
                $delivered   = (float) $od['order_delivered_value'];

                // Pro-rata on delivered value. If nothing has been delivered
                // there is nothing to prorate against and nothing to invoice
                // either, so the row simply contributes zero.
                $share = ($delivered > 0) ? round($order_disc * ($billed / $delivered), 0) : 0.0;

                // Never give away more than the lines being billed are worth.
                $share = min($share, $billed, $order_disc);
                if ($share <= 0) continue;

                $discount_amount += $share;
                $discount_parts[] = $od['reference']
                    . ' : ' . number_format($share, 0, ',', ' ') . ' FCFA'
                    . (!empty($od['discount_note']) ? ' (' . $od['discount_note'] . ')' : '');
            }

            $discount_amount = round($discount_amount, 0);
            if ($discount_amount > $gross_subtotal) {
                // Defensive: the per-order clamps above make this unreachable,
                // but a negative taxable base would post a reversed sale and
                // that must never be possible from a rounding path.
                throw new UserFacingException(
                    "Remise reportée (" . number_format($discount_amount, 0, ',', ' ') . " FCFA) "
                    . "supérieure au montant facturable. Vérifiez les remises des commandes liées."
                );
            }

            $subtotal      = $gross_subtotal - $discount_amount;
            $discount_note = $discount_parts ? implode(' · ', $discount_parts) : null;

            // TVA is computed on the NET commercial base — after the reduction,
            // as art. 128 CGI and SYSCOHADA both require. Computing it on the
            // gross would over-collect VAT the client does not owe.
            $tva_amount   = round($subtotal * ($tva_rate / 100), 0);
            $total_amount = $subtotal + $tva_amount;

            // -----------------------------------------------------------------
            // AIR withholding at source — Cameroon (art. 149 CGI).
            // Look up the client: if is_withholding_agent = 1 and has a
            // withholding_air_rate > 0, the client will retain that fraction
            // of the invoice value and pay it to the DGI on our behalf.
            // We split total_amount into (net_payable + air_amount) at issue
            // time so the AR balance clears cleanly on payment.
            // -----------------------------------------------------------------
            $stmtCli = $db->prepare("SELECT COALESCE(is_withholding_agent,0) AS is_wa,
                                            COALESCE(withholding_air_rate,0) AS wa_rate
                                       FROM clients WHERE id = ?");
            $stmtCli->execute([$client_id]);
            $cliRow = $stmtCli->fetch(PDO::FETCH_ASSOC) ?: ['is_wa'=>0,'wa_rate'=>0];
            $air_rate   = ((int)$cliRow['is_wa'] === 1) ? (float)$cliRow['wa_rate'] : 0.0;
            // AIR is computed on the HT (subtotal), not on TVA.
            $air_amount  = round($subtotal * $air_rate, 0);
            $net_payable = $total_amount - $air_amount;

            $hash      = strtoupper(substr(md5(uniqid(rand(), true)), 0, 4));
            // Sprint 8: prefix + format come from app_preferences
            // (Paramètres -> Préférences -> Numérotation). Was 'FAC-' hardcoded.
            $reference = Prefs::docNumber('invoice', $hash);
            $token     = bin2hex(random_bytes(16));

            // gross_subtotal / discount_amount / discount_note arrive with
            // migration 065. Probed rather than assumed, in the same style as
            // lpc_attach_invoice_payment_accounts above: this controller is
            // deployed before the migration is applied as a matter of routine,
            // and naming a missing column would take down invoicing entirely.
            $has_disc_cols = (int) $db->query("
                SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'invoices'
                   AND column_name IN ('gross_subtotal','discount_amount','discount_note')
            ")->fetchColumn() === 3;

            if ($has_disc_cols) {
                $stmtInv = $db->prepare("
                    INSERT INTO invoices (reference, client_id, date, due_date,
                                          gross_subtotal, discount_amount, discount_note,
                                          subtotal, tva_rate, tva_amount,
                                          air_rate, air_amount, net_payable,
                                          total_amount, status, token, created_by, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', ?, ?, ?)
                ");
                $stmtInv->execute([$reference, $client_id, $date, $due_date,
                                   $gross_subtotal, $discount_amount, $discount_note,
                                   $subtotal, $tva_rate, $tva_amount,
                                   $air_rate, $air_amount, $net_payable,
                                   $total_amount, $token, $user_id, $notes]);
            } else {
                if ($discount_amount > 0) {
                    // Refuse rather than silently bill the gross. Dropping the
                    // remise here is the exact defect this fix exists to close,
                    // and doing it quietly is worse than refusing loudly.
                    throw new UserFacingException(
                        "Cette facture porte une remise de " . number_format($discount_amount, 0, ',', ' ')
                        . " FCFA reportée depuis la commande, mais la base de données n'a pas encore "
                        . "les colonnes pour l'enregistrer. Appliquez la migration 065 avant de facturer."
                    );
                }
                $stmtInv = $db->prepare("
                    INSERT INTO invoices (reference, client_id, date, due_date,
                                          subtotal, tva_rate, tva_amount,
                                          air_rate, air_amount, net_payable,
                                          total_amount, status, token, created_by, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', ?, ?, ?)
                ");
                $stmtInv->execute([$reference, $client_id, $date, $due_date,
                                   $subtotal, $tva_rate, $tva_amount,
                                   $air_rate, $air_amount, $net_payable,
                                   $total_amount, $token, $user_id, $notes]);
            }
            $invoice_id = $db->lastInsertId();

            $stmtLink = $db->prepare("INSERT INTO invoice_deliveries (invoice_id, delivery_id) VALUES (?, ?)");
            foreach ($delivery_ids as $did) {
                $stmtLink->execute([$invoice_id, $did]);
            }

            $stmtInvItem = $db->prepare("INSERT INTO invoice_items (invoice_id, product_id, description, quantity, unit_price) VALUES (?, ?, ?, ?, ?)");
            foreach ($grouped_items as $pid => $data) {
                $stmtInvItem->execute([$invoice_id, $pid, $data['name'], $data['quantity'], $data['unit_price']]);
            }

            // ---------------------------------------------------------------
            // Payment accounts the invoice advertises (Sprint 9).
            //
            // The client ticks a set — typically a bank for the transfer plus a
            // MoMo number for a small balance. The FIRST one is the settlement
            // account: the one a payment is assumed to land on, which is what
            // decides the treasury sub-ledger the payment JE debits.
            //
            // A frozen JSON snapshot is written alongside the foreign keys.
            // The keys are the accounting link; the snapshot is the document of
            // record, so a reprint years later shows the account exactly as the
            // client saw it even if the bank has since closed or the IBAN was
            // restructured.
            // ---------------------------------------------------------------
            require_once __DIR__ . '/../../includes/classes/TreasuryAccount.php';
            lpc_attach_invoice_payment_accounts(
                $db,
                (int) $invoice_id,
                $jsonData['payment_account_ids'] ?? null,
                $client_id
            );

            // Auto-post the sale JE: Dr 411/4424 · Cr 701/4432.
            // Any account-mapping error rolls the whole transaction back.
            require_once __DIR__ . '/../../includes/classes/JournalPoster.php';
            JournalPoster::postInvoiceIssued((int)$invoice_id);

            $msg = "Nouvelle Facture ($reference) générée pour " . number_format($total_amount, 0, ',', ' ') . " FCFA"
                 . ($air_amount > 0 ? " (dont AIR retenu à la source " . number_format($air_amount, 0, ',', ' ') . " FCFA)" : '') . '.';
            notifyAdminFinance($db, "Facture Émise", $msg);

            // Batch B — misconfiguration warning: the client was flagged as
            // agent de retenue but no rate is set, so the invoice booked
            // as if the client would pay in full. Very likely a data-entry
            // slip on the client's CRM record (fiche client → cochée mais
            // taux à 0). Notify Finance/Admin so they fix the client's
            // withholding_air_rate and re-issue if needed. Best-effort.
            if ((int) $cliRow['is_wa'] === 1 && (float) $cliRow['wa_rate'] <= 0.0) {
                try {
                    $cname_stmt = $db->prepare("SELECT name FROM clients WHERE id = ?");
                    $cname_stmt->execute([$client_id]);
                    $cname_val = (string) ($cname_stmt->fetchColumn() ?: ('#' . $client_id));
                    notifyAdminFinance(
                        $db,
                        'Configuration retenue AIR incomplète',
                        "Facture {$reference} émise pour {$cname_val} : le client est marqué « retient à la source » mais son taux AIR est à 0. La facture a été émise sans scission AIR. Corrigez la fiche client (CRM → Modifier → cocher taux 2,2 %/5,5 %/10 %/15 %) puis, si nécessaire, ré-émettez la facture."
                    );
                } catch (Throwable $e) {
                    error_log('generate_invoice wa-misconfigured notify: ' . $e->getMessage());
                }
            }

            $db->commit();
            echo json_encode(['status' => 'success', 'token' => $token,
                              'invoice_id' => $invoice_id,
                              'air_amount' => $air_amount,
                              'net_payable' => $net_payable]);
            break;

        // ==========================================
        // ACTION: REGISTER MANUAL PAYMENT
        // Sprint-4-Batch-B: every payment now:
        //   1. Inserts into payments
        //   2. Posts a balanced JE via JournalPoster::postInvoicePayment
        //      (which also inserts into treasury_transactions + updates
        //       treasury_accounts.balance)
        //   3. Updates invoice status atomically
        // ==========================================
        case 'register_payment':
            // Moves money, clears a receivable and posts to the ledger.
            Rbac::requirePermission('accounting.invoices.record_payment');

            require_once __DIR__ . '/../../includes/classes/JournalPoster.php';
            $db->beginTransaction();

            $client_id           = (int)$jsonData['client_id'];
            $invoice_id          = !empty($jsonData['invoice_id']) ? (int)$jsonData['invoice_id'] : null;
            $amount              = (float)$jsonData['amount'];               // cash actually received
            $air_withheld        = (float)($jsonData['air_withheld'] ?? 0);  // portion client kept as AIR
            $cert_id             = !empty($jsonData['withholding_certificate_id'])
                                      ? (int)$jsonData['withholding_certificate_id'] : null;
            $method              = $jsonData['method'];
            $treasury_account_id = !empty($jsonData['treasury_account_id']) ? (int)$jsonData['treasury_account_id'] : null;
            $date                = date('Y-m-d');

            if ($amount < 0 || $air_withheld < 0) throw new UserFacingException("Montants négatifs invalides.");
            if ($amount + $air_withheld <= 0)     throw new UserFacingException("Montant total nul.");

            // If invoice_id is provided and it has an air_rate > 0, validate
            // the split against what we expect at issuance — accountants can
            // override, but they'll see the discrepancy warning.
            if ($invoice_id && $air_withheld > 0) {
                $iw = $db->prepare("SELECT air_amount, net_payable, total_amount FROM invoices WHERE id = ?");
                $iw->execute([$invoice_id]);
                $ir = $iw->fetch(PDO::FETCH_ASSOC);
                if ($ir && (float)$ir['air_amount'] > 0) {
                    $expected_air = (float) $ir['air_amount'];
                    if (abs($air_withheld - $expected_air) > 1) {
                        // Non-fatal; logged for accountant review.
                        error_log("register_payment: AIR mismatch on invoice #{$invoice_id} — "
                                . "expected {$expected_air}, got {$air_withheld}.");
                    }
                }
            }

            $payRef = 'REC-' . strtoupper(substr(md5(uniqid()), 0, 6));

            $stmtPay = $db->prepare("
                INSERT INTO payments (reference, invoice_id, client_id, amount,
                                      air_withheld_amount, withholding_certificate_id,
                                      payment_method, payment_date, status, logged_by, validated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'validated', ?, ?)
            ");
            $stmtPay->execute([$payRef, $invoice_id, $client_id, $amount,
                               $air_withheld, $cert_id, $method, $date, $user_id, $user_id]);
            $payment_id = (int) $db->lastInsertId();

            if ($invoice_id) {
                // AR clearing uses (amount + air_withheld) since the AIR
                // portion ALSO clears our receivable (the client already
                // remitted it to the DGI on our behalf).
                $stmtInv = $db->prepare("
                    SELECT total_amount,
                           COALESCE((SELECT SUM(amount + COALESCE(air_withheld_amount,0))
                                       FROM payments WHERE invoice_id = ? AND status='validated'), 0) AS paid_amount
                      FROM invoices WHERE id = ? FOR UPDATE
                ");
                $stmtInv->execute([$invoice_id, $invoice_id]);
                $inv = $stmtInv->fetch(PDO::FETCH_ASSOC);

                $new_paid = (float) $inv['paid_amount'];
                $total    = (float) $inv['total_amount'];

                $status = 'partial';
                $overpayment = 0;

                if ($new_paid >= $total) {
                    $status = 'paid';
                    $overpayment = $new_paid - $total;
                }

                $db->prepare("UPDATE invoices SET status = ? WHERE id = ?")
                   ->execute([$status, $invoice_id]);

                // Push the new payment total back onto every sales order this
                // invoice touches, so the order-detail page stops reading
                // "Non payé" after the invoice is settled.
                lpc_recompute_sales_order_payment_status($db, (int) $invoice_id);

                if ($overpayment > 0) {
                    $db->prepare("
                        INSERT INTO client_wallets (client_id, balance) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE balance = balance + ?
                    ")->execute([$client_id, $overpayment, $overpayment]);
                }
            } else {
                $db->prepare("
                    INSERT INTO client_wallets (client_id, balance) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE balance = balance + ?
                ")->execute([$client_id, $amount, $amount]);
            }

            // Post JE + treasury_transactions. Rounding drift → SIGNAL 45000 → rollback.
            // JournalPoster throws plain RuntimeException for config gaps (no
            // active treasury account for this method, OHADA account unmapped,
            // etc.) — those are exactly the "operator can fix this" case
            // UserFacingException exists for, so repackage rather than let the
            // outer handler flatten it to "Erreur serveur. Veuillez réessayer."
            try {
                JournalPoster::postInvoicePayment($payment_id, $treasury_account_id);
            } catch (RuntimeException $e) {
                throw new UserFacingException(
                    "Le paiement n'a pas pu être comptabilisé (configuration de trésorerie incomplète). "
                    . "Détail : " . $e->getMessage() . " — vérifiez Paramètres → Trésorerie."
                );
            }

            // Batch B — immediate notification when a payment books AIR
            // retenu but no certificate is attached yet. Best-effort: a
            // notification insert must never break a successful payment.
            if ($air_withheld > 0 && !$cert_id) {
                try {
                    // Fetch client name for the message (best-effort — the
                    // notification is decoration, not a blocker).
                    $cn = $db->prepare("SELECT name FROM clients WHERE id = ?");
                    $cn->execute([$client_id]);
                    $cname = (string) ($cn->fetchColumn() ?: ('#' . $client_id));
                    $amount_fmt = number_format((float) $air_withheld, 0, ',', ' ');
                    notifyAdminFinance(
                        $db,
                        'Retenue AIR sans attestation',
                        "Paiement {$payRef} — {$cname} a retenu {$amount_fmt} FCFA d'AIR. Téléversez l'attestation dans Déclarations Fiscales → Retenues à la source pour créditer ce montant sur la déclaration AIR."
                    );
                } catch (Throwable $e) {
                    error_log('register_payment air-uncertified notify: ' . $e->getMessage());
                }
            }

            $db->commit();
            echo json_encode(['status' => 'success', 'payment_id' => $payment_id]);
            break;

        // ==========================================
        // ACTION: VALIDATE DRIVER CASH (RECONCILIATION)
        // Sprint-4-Batch-B: for every payment created out of this validation
        // we call JournalPoster::postInvoicePayment so the JE + treasury row
        // land atomically with the payments insert.
        // ==========================================
        case 'validate_cash':
            // Turns a driver's declared cash into validated payments and ledger
            // entries. Its own permission already exists and was unused.
            Rbac::requirePermission('accounting.invoices.validate_cash');

            require_once __DIR__ . '/../../includes/classes/JournalPoster.php';
            $db->beginTransaction();

            // The frontend passes the cash_reconciliation.id as 'payment_id'
            $recon_id = (int)$jsonData['payment_id'];

            // 1. Get the Driver Reconciliation Record
            $stmtRecon = $db->prepare("SELECT driver_id, date, status FROM cash_reconciliations WHERE id = ? FOR UPDATE");
            $stmtRecon->execute([$recon_id]);
            $recon = $stmtRecon->fetch(PDO::FETCH_ASSOC);

            if (!$recon || $recon['status'] !== 'pending_accountant') {
                throw new UserFacingException("Réconciliation introuvable ou déjà validée.");
            }

            // 2. Mark Master Reconciliation as Validated
            $db->prepare("UPDATE cash_reconciliations SET status = 'validated', validated_by = ? WHERE id = ?")
               ->execute([$user_id, $recon_id]);

            // 3. Find all deliveries from this driver on this date that collected cash
            $stmtDel = $db->prepare("
                SELECT id, client_id, payment_collected 
                FROM deliveries 
                WHERE driver_id = ? AND date = ? AND payment_collected > 0
            ");
            $stmtDel->execute([$recon['driver_id'], $recon['date']]);
            $deliveries = $stmtDel->fetchAll(PDO::FETCH_ASSOC);

            // 4. Generate Official Client Payments
            $stmtFindInv = $db->prepare("SELECT invoice_id FROM invoice_deliveries WHERE delivery_id = ?");
            $stmtInsertPay = $db->prepare("
                INSERT INTO payments (reference, invoice_id, client_id, amount, payment_method, payment_date, status, logged_by, validated_by) 
                VALUES (?, ?, ?, ?, 'cash', CURRENT_DATE(), 'validated', ?, ?)
            ");

            foreach ($deliveries as $del) {
                $cash        = (float)$del['payment_collected'];
                $client_id   = $del['client_id'];
                $delivery_id = $del['id'];

                // Check if this delivery is already linked to an invoice
                $stmtFindInv->execute([$delivery_id]);
                $invLink = $stmtFindInv->fetch(PDO::FETCH_ASSOC);
                $invoice_id = $invLink ? $invLink['invoice_id'] : null;

                // Log the payment
                $payRef = 'REC-' . strtoupper(substr(md5(uniqid()), 0, 6));
                $stmtInsertPay->execute([$payRef, $invoice_id, $client_id, $cash, $user_id, $user_id]);
                $payment_id = (int) $db->lastInsertId();

                // Post the balanced JE + treasury_transaction (payment_method='cash').
                try {
                    JournalPoster::postInvoicePayment($payment_id, null);
                } catch (RuntimeException $e) {
                    throw new UserFacingException(
                        "La comptabilisation a échoué pour la livraison #{$delivery_id} "
                        . "(configuration de trésorerie incomplète). Détail : " . $e->getMessage()
                        . " — vérifiez Paramètres → Trésorerie."
                    );
                }

                // Financial Imputation Logic
                if ($invoice_id) {
                    // SUM(amount + air_withheld_amount), matching
                    // register_payment above. This path summed `amount` alone
                    // while register_payment summed both, so the two disagreed
                    // about what "paid in full" means: an invoice settled partly
                    // by a withholding client and partly by driver cash could
                    // never reach 'paid' through this branch, because the AIR
                    // the client had already remitted to the DGI on our behalf
                    // was not counted as clearing the receivable — even though
                    // JournalPoster credits 411 with it. The ledger showed the
                    // receivable cleared; the invoice list showed it open.
                    $stmtInvData = $db->prepare("
                        SELECT total_amount,
                               COALESCE((SELECT SUM(amount + COALESCE(air_withheld_amount, 0))
                                           FROM payments WHERE invoice_id = ? AND status = 'validated'), 0) AS paid_amount
                          FROM invoices WHERE id = ? FOR UPDATE
                    ");
                    $stmtInvData->execute([$invoice_id, $invoice_id]);
                    $invData = $stmtInvData->fetch(PDO::FETCH_ASSOC);

                    $new_paid = (float)$invData['paid_amount'];
                    $total    = (float)$invData['total_amount'];

                    $status = 'partial';
                    $overpayment = 0;

                    if ($new_paid >= $total) {
                        $status = 'paid';
                        $overpayment = $new_paid - $total;
                    }

                    $db->prepare("UPDATE invoices SET status = ? WHERE id = ?")
                       ->execute([$status, $invoice_id]);

                    // Mirror the invoice-status change onto the sales orders
                    // this delivery belongs to — same reason as register_payment
                    // above (order page must not linger on "Non payé").
                    lpc_recompute_sales_order_payment_status($db, (int) $invoice_id);

                    if ($overpayment > 0) {
                        $db->prepare("
                            INSERT INTO client_wallets (client_id, balance) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE balance = balance + ?
                        ")->execute([$client_id, $overpayment, $overpayment]);
                    }
                } else {
                    // No invoice generated yet, so the cash drops safely into the client's wallet
                    $db->prepare("
                        INSERT INTO client_wallets (client_id, balance) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE balance = balance + ?
                    ")->execute([$client_id, $cash, $cash]);
                }
            }

            $db->commit();
            echo json_encode(['status' => 'success']);
            break;

        // ==========================================
        // ACTION: WALLET INVOICES (picker feed)
        //
        // Same shape as get_unpaid_invoices, but scoped to a client and always
        // uses the AIR-aware balance formula. Kept separate so the wallet
        // apply modal can be tightened later (e.g. exclude litigated invoices)
        // without disturbing the payment modal's dropdown.
        // ==========================================
        case 'get_wallet_targets':
            $client_id = (int) ($_GET['client_id'] ?? 0);
            if ($client_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Client requis.']);
                break;
            }

            $walStmt = $db->prepare("SELECT COALESCE(balance, 0) AS balance FROM client_wallets WHERE client_id = ?");
            $walStmt->execute([$client_id]);
            $wallet = (float) ($walStmt->fetchColumn() ?: 0);

            $stmt = $db->prepare("
                SELECT id, reference, date, due_date, total_amount,
                       (total_amount - COALESCE((
                           SELECT SUM(amount + COALESCE(air_withheld_amount, 0))
                             FROM payments
                            WHERE invoice_id = invoices.id AND status = 'validated'
                       ), 0)) AS balance
                  FROM invoices
                 WHERE client_id = ? AND status != 'paid'
                 ORDER BY date ASC, id ASC
            ");
            $stmt->execute([$client_id]);
            $rows = array_values(array_filter(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                static fn($r) => (float) $r['balance'] > 0
            ));

            echo json_encode([
                'status'         => 'success',
                'wallet_balance' => $wallet,
                'invoices'       => $rows,
            ]);
            break;

        // ==========================================
        // ACTION: APPLY WALLET
        //
        // Consumes a chunk of client_wallets.balance against an open invoice.
        // Steps (all inside one transaction):
        //   1. Lock the wallet row and the target invoice row.
        //   2. Clamp the requested amount to min(wallet, invoice_open_balance).
        //   3. Insert a 'wallet' payment row (validated, logged_by = operator).
        //   4. Decrement client_wallets.balance.
        //   5. Recompute invoice.status (paid / partial) from the AIR-aware SUM.
        //   6. JournalPoster::postWalletApplication → Dr 419 / Cr 411.
        // ==========================================
        case 'apply_client_wallet':
            Rbac::requirePermission('accounting.invoices.record_payment');
            require_once __DIR__ . '/../../includes/classes/JournalPoster.php';

            $client_id  = (int)   ($jsonData['client_id']  ?? 0);
            $invoice_id = (int)   ($jsonData['invoice_id'] ?? 0);
            $amount_req = (float) ($jsonData['amount']     ?? 0);

            if ($client_id <= 0 || $invoice_id <= 0 || $amount_req <= 0) {
                throw new UserFacingException("Paramètres incomplets pour l'imputation d'avoir.");
            }

            $db->beginTransaction();

            // Lock the wallet
            $stmtW = $db->prepare("SELECT balance FROM client_wallets WHERE client_id = ? FOR UPDATE");
            $stmtW->execute([$client_id]);
            $wallet_bal = (float) ($stmtW->fetchColumn() ?: 0);
            if ($wallet_bal <= 0) {
                throw new UserFacingException("Aucun avoir disponible pour ce client.");
            }

            // Lock the invoice and check it belongs to the same client
            $stmtInv = $db->prepare("
                SELECT id, client_id, total_amount, status,
                       COALESCE((SELECT SUM(amount + COALESCE(air_withheld_amount, 0))
                                   FROM payments WHERE invoice_id = ? AND status = 'validated'), 0) AS paid_amount
                  FROM invoices WHERE id = ? FOR UPDATE
            ");
            $stmtInv->execute([$invoice_id, $invoice_id]);
            $inv = $stmtInv->fetch(PDO::FETCH_ASSOC);
            if (!$inv) {
                throw new UserFacingException("Facture introuvable.");
            }
            if ((int) $inv['client_id'] !== $client_id) {
                throw new UserFacingException("Facture et client ne correspondent pas.");
            }
            if ($inv['status'] === 'paid') {
                throw new UserFacingException("Cette facture est déjà soldée.");
            }

            $open = max(0.0, (float) $inv['total_amount'] - (float) $inv['paid_amount']);
            if ($open <= 0) {
                throw new UserFacingException("Cette facture n'a plus de solde ouvert.");
            }

            // Clamp — the caller can request more than the smaller of the two,
            // but we never consume beyond wallet OR beyond invoice open balance.
            $amount = min($amount_req, $wallet_bal, $open);
            $amount = round($amount, 0);
            if ($amount <= 0) {
                throw new UserFacingException("Montant à imputer nul après ajustement.");
            }

            // 1. Payments row (wallet method)
            $payRef = 'WLT-' . strtoupper(substr(md5(uniqid()), 0, 6));
            $stmtPay = $db->prepare("
                INSERT INTO payments (reference, invoice_id, client_id, amount,
                                      payment_method, payment_date, status,
                                      logged_by, validated_by)
                VALUES (?, ?, ?, ?, 'wallet', CURRENT_DATE(), 'validated', ?, ?)
            ");
            $stmtPay->execute([$payRef, $invoice_id, $client_id, $amount, $user_id, $user_id]);
            $payment_id = (int) $db->lastInsertId();

            // 2. Decrement wallet
            $db->prepare("UPDATE client_wallets SET balance = balance - ? WHERE client_id = ?")
               ->execute([$amount, $client_id]);

            // 3. Recompute invoice status
            $new_paid = (float) $inv['paid_amount'] + $amount;
            $status = ($new_paid >= (float) $inv['total_amount']) ? 'paid' : 'partial';
            $db->prepare("UPDATE invoices SET status = ? WHERE id = ?")
               ->execute([$status, $invoice_id]);

            // Sync the sales-order side too — the wallet-apply path is
            // exactly the case that first surfaced the bug (invoice paid,
            // order still "Non payé"). Same helper as the other payment
            // paths above.
            lpc_recompute_sales_order_payment_status($db, (int) $invoice_id);

            // 4. Journal entry (Dr 419 / Cr 411)
            try {
                JournalPoster::postWalletApplication($payment_id);
            } catch (RuntimeException $e) {
                throw new UserFacingException(
                    "L'imputation n'a pas pu être comptabilisée. Détail : " . $e->getMessage()
                    . " — vérifiez le plan comptable OHADA (411 / 419)."
                );
            }

            $db->commit();
            echo json_encode([
                'status'          => 'success',
                'payment_id'      => $payment_id,
                'amount_applied'  => $amount,
                'invoice_status'  => $status,
                'wallet_balance'  => $wallet_bal - $amount,
            ]);
            break;

        default:
            throw new UserFacingException("Action inconnue : {$action}.");
    }

} catch (UserFacingException $e) {
    // A business rule or config gap said no. The message was written for the
    // operator — surfacing it is what lets an accountant see "aucun compte de
    // trésorerie configuré" instead of a generic error they can't act on.
    // Same pattern as procurement_controller.php.
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code($e->getHttpStatus());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}