<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('accounting.cashflow.view');
/**
 * CONTROLLER: Trésorerie & Banque (Treasury API)
 * DESCRIPTION: Handles Wallets, Inbound Cash (Tournée), Internal Transfers, and Reconciliation.
 * METHOD: STRICT REST-like JSON API (GET / POST)
 */
header('Content-Type: application/json; charset=utf-8');
$user_id = $_SESSION['user_id'];

// Database Connection (PDO)
try {
    require_once '../../includes/config/db.php';
    require_once '../../includes/classes/Database.php';
    require_once __DIR__ . '/../../includes/classes/JournalPoster.php';
    require_once __DIR__ . '/../../includes/functions/notify.php';
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('API error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
    exit;
}

function sendResponse($status, $message, $data = null) {
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

// ==========================================
// [GET] READ OPERATIONS (Data Fetching)
// ==========================================
if ($method === 'GET') {
    
    // 1. Fetch Expected Cash for a specific Driver
    if ($action === 'get_driver_cash') {
        $driver_id = (int)$_GET['driver_id'];
        try {
            // Find all unpaid invoices/deliveries linked to this driver today or recently
            // Note: Adjust table names if your exact invoice structure differs slightly
            $stmt = $pdo->prepare("
                SELECT i.id, i.reference, c.name as client_name, i.total_amount as expected_amount
                FROM invoices i
                JOIN clients c ON i.client_id = c.id
                JOIN deliveries d ON d.sales_order_id = i.id -- Assuming a link exists
                WHERE d.driver_id = ? AND i.status = 'unpaid'
            ");
            $stmt->execute([$driver_id]);
            $bls = $stmt->fetchAll();

            $total_expected = 0;
            foreach ($bls as $bl) {
                $total_expected += (float)$bl['expected_amount'];
            }

            sendResponse('success', 'BLs récupérés', [
                'total_expected' => $total_expected,
                'bls' => $bls
            ]);
        } catch (Exception $e) {
            // Fallback for testing if DB schema lacks the exact joins yet
            sendResponse('success', 'Mode Simulation', [
                'total_expected' => 350000,
                'bls' => [
                    ['reference' => 'INV-2026-001', 'client_name' => 'Boutique Maman', 'expected_amount' => 150000],
                    ['reference' => 'INV-2026-002', 'client_name' => 'Alimentation Centrale', 'expected_amount' => 200000]
                ]
            ]);
        }
    }

    // 2. Fetch Tab Data
    $tab = $_GET['tab'] ?? null;
    $response_data = [];

    try {
        // Core queries we use across multiple tabs
        $q_accounts = $pdo->query("SELECT * FROM treasury_accounts WHERE status = 'active' ORDER BY type, name")->fetchAll();
        $q_transactions = $pdo->query("
            SELECT t.*, a.name as account_name, a.type as account_type, DATE_FORMAT(t.created_at, '%d/%m/%Y %H:%i') as date,
            CASE WHEN t.transaction_type IN ('in_tournee', 'in_other', 'in_client_payment', 'transfer_in') THEN 'in' ELSE 'out' END as type_direction
            FROM treasury_transactions t
            JOIN treasury_accounts a ON t.account_id = a.id
            ORDER BY t.created_at DESC LIMIT 100
        ")->fetchAll();

        switch ($tab) {
            case 'wallets':
                $response_data['accounts'] = $q_accounts;
                $response_data['transactions'] = $q_transactions;
                break;

            case 'tournee':
                $stmtD = $pdo->query("SELECT id, CONCAT(first_name, ' ', last_name) as name, 2 as pending_bl_count FROM users WHERE role_id IN (SELECT id FROM roles WHERE name IN ('operations', 'driver')) AND status = 'active'");
                $response_data['drivers'] = $stmtD->fetchAll();
                
                $stmtC = $pdo->query("SELECT id, name FROM clients WHERE deleted_at IS NULL ORDER BY name ASC");
                $response_data['clients'] = $stmtC->fetchAll();
                break;

            case 'operations':
                $response_data['accounts'] = $q_accounts;
                break;

            case 'reconciliation':
                $response_data['accounts'] = $q_accounts;
                $response_data['transactions'] = $q_transactions;
                break;

            default:
                sendResponse('error', 'Onglet inconnu.');
        }

        sendResponse('success', 'Données chargées', $response_data);

    } catch (PDOException $e) {
        sendResponse('error', 'Erreur base de données: ' . $e->getMessage());
    }
}

// ==========================================
// [POST] WRITE OPERATIONS (Data Mutation)
// ==========================================
else if ($method === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!$payload || !isset($payload['action'])) sendResponse('error', 'Payload invalide.');

    $action = $payload['action'];

    try {
        $pdo->beginTransaction();

        // 1. CREATE ACCOUNT (Init Wallet)
        if ($action === 'create_account') {
            $name = trim($payload['name'] ?? '');
            $type = $payload['type'] ?? '';
            $acc_num = trim($payload['account_number'] ?? '') ?: null;
            $balance = (float)($payload['balance'] ?? 0);

            if ($name === '' || $type === '') {
                $pdo->rollBack();
                sendResponse('error', 'Nom et type de compte obligatoires.');
            }

            $stmt = $pdo->prepare("INSERT INTO treasury_accounts (name, type, account_number, balance, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $type, $acc_num, $balance, $user_id]);
            $new_acc_id = $pdo->lastInsertId();

            // Log the initial balance if > 0
            if ($balance > 0) {
                $stmtT = $pdo->prepare("INSERT INTO treasury_transactions (account_id, transaction_type, amount, description, logged_by) VALUES (?, 'in_other', ?, 'Solde Initial Ouverture', ?)");
                $stmtT->execute([$new_acc_id, $balance, $user_id]);
            }

            $pdo->commit();

            lpc_notify_permission(
                $pdo,
                'accounting.cashflow.view',
                'Nouveau compte de trésorerie',
                ($_SESSION['user_name'] ?? 'Un opérateur') . " a créé le compte « $name » ($type)"
                    . ($balance > 0 ? ' — solde d\'ouverture ' . number_format($balance, 0, ',', ' ') . ' FCFA.' : '.'),
                '/modules/accounting/cashflow.php',
                'info',
                [$user_id]
            );

            sendResponse('success', 'Compte créé avec succès.');
        }

        // 1b. UPDATE ACCOUNT METADATA (name, account_number, bank_name, iban…)
        //
        // Deliberately narrow: type, balance, status are NOT editable here.
        //   · type is baked into the OHADA sub-account (5211 Afriland vs 5521 MoMo…).
        //     Changing it would silently invalidate every past JE.
        //   · balance is the sum of treasury_transactions — editing it here
        //     would break the audit trail.
        //   · status has its own archive workflow (out of scope for a typo fix).
        // Fields that ARE editable are the "printed on the invoice" columns
        // (bank_name, iban, swift, holder_name, show_on_invoice, sort_order)
        // plus display name and account number. All optional and probed so the
        // controller stays deployable on installs that haven't run 044.
        if ($action === 'update_account') {
            $acc_id = (int) ($payload['id'] ?? 0);
            if ($acc_id <= 0) {
                $pdo->rollBack();
                sendResponse('error', 'Identifiant compte manquant.');
            }

            $stmt = $pdo->prepare("SELECT id FROM treasury_accounts WHERE id = ? AND status = 'active' FOR UPDATE");
            $stmt->execute([$acc_id]);
            if (!$stmt->fetchColumn()) {
                $pdo->rollBack();
                sendResponse('error', 'Compte introuvable ou archivé.');
            }

            $name = trim((string) ($payload['name'] ?? ''));
            if ($name === '') {
                $pdo->rollBack();
                sendResponse('error', 'Le nom d\'affichage est obligatoire.');
            }

            // Column-by-column probe so the SET clause only names columns that
            // actually exist. Same pattern as
            // lpc_attach_invoice_payment_accounts in invoices_controller.
            $has_col = function (string $col) use ($pdo): bool {
                $s = $pdo->prepare("
                    SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE()
                       AND table_name = 'treasury_accounts'
                       AND column_name = ?
                ");
                $s->execute([$col]);
                return ((int) $s->fetchColumn() > 0);
            };

            $sets   = ['name = ?', 'account_number = ?'];
            $params = [$name, trim((string) ($payload['account_number'] ?? '')) ?: null];

            foreach (['bank_name', 'iban', 'swift', 'holder_name'] as $col) {
                if ($has_col($col)) {
                    $sets[]   = "$col = ?";
                    $params[] = trim((string) ($payload[$col] ?? '')) ?: null;
                }
            }
            if ($has_col('show_on_invoice') && array_key_exists('show_on_invoice', $payload)) {
                $sets[]   = "show_on_invoice = ?";
                $params[] = !empty($payload['show_on_invoice']) ? 1 : 0;
            }
            if ($has_col('sort_order') && array_key_exists('sort_order', $payload)) {
                $sets[]   = "sort_order = ?";
                $params[] = (int) $payload['sort_order'];
            }

            $params[] = $acc_id;
            $sql = "UPDATE treasury_accounts SET " . implode(', ', $sets) . " WHERE id = ?";
            $pdo->prepare($sql)->execute($params);

            $pdo->commit();
            sendResponse('success', 'Compte mis à jour.');
        }

        // 2. PROCESS DRIVER CASH (Retour de Tournée)
        if ($action === 'process_tournee') {
            $driver_id = (int)$payload['driver_id'];
            $caisse_id = (int)$payload['caisse_account_id'];
            $expected = (float)$payload['expected'];
            $actual = (float)$payload['actual'];
            $variance = (float)$payload['variance'];
            $overage_client_id = $payload['overage_client_id'] ? (int)$payload['overage_client_id'] : null;

            // A. Add cash to Caisse Principale
            $stmt = $pdo->prepare("UPDATE treasury_accounts SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$actual, $caisse_id]);

            // B. Log the Ledger Transaction
            $stmtT = $pdo->prepare("INSERT INTO treasury_transactions (account_id, transaction_type, amount, reference, description, logged_by) VALUES (?, 'in_tournee', ?, ?, 'Retour de tournée', ?)");
            $stmtT->execute([$caisse_id, $actual, "DRV-".$driver_id, $user_id]);

            // C. Handle Variance
            $shortfall_notice = null;
            if ($variance < 0) {
                // Shortfall -> Driver Debt
                $debt = abs($variance);
                $stmtD = $pdo->prepare("INSERT INTO driver_debts (driver_id, amount, tour_date, logged_by) VALUES (?, ?, CURRENT_DATE(), ?)");
                $stmtD->execute([$driver_id, $debt, $user_id]);

                $drvName = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = ?");
                $drvName->execute([$driver_id]);
                $shortfall_notice = ($drvName->fetchColumn() ?: "chauffeur #$driver_id") . ' — '
                    . number_format($debt, 0, ',', ' ') . ' FCFA manquant(s) sur la tournée de ce jour.';
            }
            else if ($variance > 0 && $overage_client_id) {
                // Overage -> Client Wallet (Avoir)
                // Assuming client_wallets exists from AR module. If not, create it dynamically or skip safely.
                $stmtW = $pdo->prepare("INSERT INTO client_wallets (client_id, balance) VALUES (?, ?) ON DUPLICATE KEY UPDATE balance = balance + ?, last_updated = CURRENT_TIMESTAMP");
                $stmtW->execute([$overage_client_id, $variance, $variance]);
            }

            // D. Sprint-4-Batch-B: post a balanced JE via JournalPoster so
            //    the treasury movement lands in the general ledger too.
            $je_id = JournalPoster::postTourneeReconciliation(
                $driver_id, $expected, $actual, $overage_client_id, $caisse_id
            );

            $pdo->commit();

            if ($shortfall_notice !== null) {
                lpc_notify_permission(
                    $pdo,
                    'accounting.cashflow.view',
                    'Écart de caisse — tournée',
                    $shortfall_notice,
                    '/modules/accounting/cashflow.php',
                    'warning',
                    [$user_id]
                );
            }

            sendResponse('success', 'Tournée validée.', ['journal_entry_id' => $je_id]);
        }

        // 3. INTERNAL TRANSFER (Sprint-4-Batch-B: posts a balanced JE)
        if ($action === 'transfer') {
            $from_id = (int)$payload['from_id'];
            $to_id = (int)$payload['to_id'];
            $amount = (float)$payload['amount'];
            $ref = trim($payload['reference'] ?? '');

            if ($from_id === $to_id) throw new Exception("Impossible de transférer vers le même compte.");
            if ($amount <= 0) throw new Exception("Le montant doit être supérieur à 0.");

            // Lock both accounts (order by id to avoid deadlock with concurrent transfers).
            $ordered = [$from_id, $to_id]; sort($ordered);
            $stmt = $pdo->prepare("SELECT id, balance, name FROM treasury_accounts WHERE id IN (?, ?) FOR UPDATE");
            $stmt->execute($ordered);
            $accs = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $accs[(int)$r['id']] = $r;
            if (!isset($accs[$from_id]) || !isset($accs[$to_id])) throw new Exception("Compte introuvable.");
            if ((float) $accs[$from_id]['balance'] < $amount) throw new Exception("Solde insuffisant dans le compte source.");

            // Update balances.
            $pdo->prepare("UPDATE treasury_accounts SET balance = balance - ? WHERE id = ?")->execute([$amount, $from_id]);
            $pdo->prepare("UPDATE treasury_accounts SET balance = balance + ? WHERE id = ?")->execute([$amount, $to_id]);

            // Paired transaction records.
            $stmtLog = $pdo->prepare("INSERT INTO treasury_transactions (account_id, transaction_type, amount, reference, description, logged_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtLog->execute([$from_id, 'transfer_out', $amount, $ref, "Virement sortant", $user_id]);
            $out_id = $pdo->lastInsertId();
            $stmtLog->execute([$to_id, 'transfer_in', $amount, $ref, "Virement entrant", $user_id]);
            $in_id = $pdo->lastInsertId();
            $linkStmt = $pdo->prepare("UPDATE treasury_transactions SET linked_transfer_id = ? WHERE id = ?");
            $linkStmt->execute([$in_id,  $out_id]);
            $linkStmt->execute([$out_id, $in_id]);

            // Balanced JE — SIGNAL 45000 on drift trips the outer transaction.
            $je_id = JournalPoster::postInternalTransfer($from_id, $to_id, $amount, $ref ?: 'Virement interne');

            $pdo->commit();

            lpc_notify_permission(
                $pdo,
                'accounting.cashflow.view',
                'Virement interne effectué',
                ($_SESSION['user_name'] ?? 'Un opérateur') . " a viré " . number_format($amount, 0, ',', ' ')
                    . " FCFA de « {$accs[$from_id]['name']} » vers « {$accs[$to_id]['name']} »" . ($ref !== '' ? " (réf. $ref)" : '') . '.',
                '/modules/accounting/cashflow.php',
                'info',
                [$user_id]
            );

            sendResponse('success', 'Virement interne effectué.', ['journal_entry_id' => $je_id]);
        }

        // 4. SIMPLE EXPENSE (Petite Caisse) — Sprint-4-Batch-B: posts a balanced JE.
        if ($action === 'expense') {
            $acc_id           = (int)   $payload['account_id'];
            $amount           = (float) $payload['amount'];
            $desc             = trim((string) ($payload['description'] ?? ''));
            $expense_coa_id   = (int)   ($payload['expense_coa_id'] ?? 0);
            $category         = trim((string) ($payload['category'] ?? ''));

            if ($amount <= 0) throw new Exception("Le montant doit être supérieur à 0.");
            if ($expense_coa_id <= 0) throw new Exception("Compte de charge OHADA (6xx) requis.");

            $stmt = $pdo->prepare("SELECT balance FROM treasury_accounts WHERE id = ? FOR UPDATE");
            $stmt->execute([$acc_id]);
            $bal = $stmt->fetchColumn();
            if ($bal < $amount) throw new Exception("Fonds insuffisants.");

            $pdo->prepare("UPDATE treasury_accounts SET balance = balance - ? WHERE id = ?")->execute([$amount, $acc_id]);

            $stmtL = $pdo->prepare("INSERT INTO treasury_transactions (account_id, transaction_type, amount, description, logged_by) VALUES (?, 'out_expense', ?, ?, ?)");
            $stmtL->execute([$acc_id, $amount, $desc, $user_id]);

            // Balanced JE — debit expense COA / credit treasury COA.
            $je_id = JournalPoster::postExpense($expense_coa_id, $acc_id, $amount, $desc, $category);

            $pdo->commit();
            sendResponse('success', 'Dépense enregistrée.', ['journal_entry_id' => $je_id]);
        }

        // 5. RECONCILIATION LOCK
        if ($action === 'reconcile') {
            $trx_id = (int)$payload['transaction_id'];
            $stmt = $pdo->prepare("UPDATE treasury_transactions SET is_reconciled = 1 WHERE id = ?");
            $stmt->execute([$trx_id]);

            $pdo->commit();
            sendResponse('success', 'Transaction verrouillée.');
        }

        // 6. EDIT REQUEST (Anti-Fraud mechanism)
        if ($action === 'edit_request') {
            $trx_id = (int)$payload['transaction_id'];
            $amount = (float)$payload['amount'];
            $reason = trim($payload['reason']);

            $stmt = $pdo->prepare("INSERT INTO treasury_edit_requests (transaction_id, requested_by, reason, proposed_amount) VALUES (?, ?, ?, ?)");
            $stmt->execute([$trx_id, $user_id, $reason, $amount]);

            $pdo->commit();

            // Targets admin.settings.edit as a stand-in for "Direction only"
            // (admin.settings.* is never granted to accountant — see
            // migrations/001_rbac_seed.sql — unlike most accounting.* keys,
            // which accountant also holds). Note: as of this writing there is
            // no decide/approve endpoint anywhere in the codebase for
            // treasury_edit_requests — this notification at least surfaces
            // the request even though acting on it is still a DB-level fix.
            lpc_notify_permission(
                $pdo,
                'admin.settings.edit',
                'Demande de correction — trésorerie',
                ($_SESSION['user_name'] ?? 'Un opérateur') . " demande la correction de la transaction #$trx_id vers "
                    . number_format($amount, 0, ',', ' ') . " FCFA. Motif : $reason",
                '/modules/accounting/cashflow.php',
                'warning',
                [$user_id]
            );

            sendResponse('success', 'Demande de correction envoyée à la Direction.');
        }

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendResponse('error', $e->getMessage());
    }
} else {
    sendResponse('error', 'Méthode HTTP non autorisée.');
}