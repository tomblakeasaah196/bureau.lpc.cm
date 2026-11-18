<?php
/**
 * CONTROLLER: Trésorerie & Banque (Treasury API)
 * -----------------------------------------------------------------------------
 * Accounts and their balances, internal transfers, bank reconciliation and the
 * correction requests a locked movement may need. Expenses live in Gestion des
 * Dépenses (expenses_controller.php); the « Sortie de Caisse » card posts there.
 *
 * One gate before anything runs (VIDEO_QA_FINDINGS 9.1, as Flotte 6.7): the
 * action is read where its handler reads it (query string for a read, JSON body
 * for a write), each action needs its own permission, every write is a POST
 * with a valid CSRF token. Every action used to need only
 * accounting.cashflow.view, and a write with a forged token went through.
 *
 * « Retour de Tournée » (driver cash-in) is gone (9.6): LPC has no drivers, and
 * the screen listed users as drivers with an invented « 2 BLs », showed a fake
 * 350 000 F « simulation » when its query failed, and never marked an invoice
 * paid. Client payments are recorded in Facturation & AR.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/JournalPoster.php';
require_once __DIR__ . '/../../includes/classes/TreasuryAccount.php';
require_once __DIR__ . '/../../includes/functions/notify.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('accounting.cashflow.view');
$user_id = (int) ($_SESSION['user_id'] ?? 0);

function sendResponse($status, $message, $data = null) {
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------------------------------------------
// The gate.
// -----------------------------------------------------------------------------
$method  = $_SERVER['REQUEST_METHOD'];
$isWrite = ($method !== 'GET');
$payload = [];
if ($isWrite) {
    $json = json_decode((string) file_get_contents('php://input'), true);
    $payload = is_array($json) ? $json : [];
    $action = $payload['action'] ?? null;
} else {
    $action = $_GET['action'] ?? 'read';
}

const TREASURY_READ_ACTIONS = [
    'read' => 'accounting.cashflow.view',
];
const TREASURY_WRITE_ACTIONS = [
    'create_account'      => 'accounting.cashflow.open_balance',
    'update_account'      => 'accounting.cashflow.open_balance',
    'transfer'            => 'accounting.cashflow.transfer',
    'reconcile'           => 'accounting.cashflow.reconcile',
    'edit_request'        => 'accounting.cashflow.reconcile',
    'decide_edit_request' => 'admin.settings.edit',
];

$allowed = $isWrite ? TREASURY_WRITE_ACTIONS : TREASURY_READ_ACTIONS;
if (!is_string($action) || !isset($allowed[$action])) {
    http_response_code(400);
    sendResponse('error', 'Action inconnue.');
}
Rbac::requirePermission($allowed[$action]);
if ($isWrite) {
    if ($method !== 'POST') {
        http_response_code(405);
        sendResponse('error', 'Méthode HTTP non autorisée.');
    }
    Csrf::requireValid();
}

try {
    $pdo = Database::getInstance()->getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    error_log('treasury_controller: ' . $e->getMessage());
    sendResponse('error', 'Erreur serveur. Veuillez réessayer.');
}

/** Money coming in: every in_* type and the receiving half of a transfer (9.3: recycling sales read as money out). */
const TREASURY_DIRECTION_SQL = "CASE WHEN t.transaction_type LIKE 'in\\_%' OR t.transaction_type = 'transfer_in' THEN 'in' ELSE 'out' END";

// ==========================================
// [GET] READ
// ==========================================
if (!$isWrite) {
    $tab = $_GET['tab'] ?? 'wallets';
    try {
        // Each account with its own sub-account and that ledger's balance, so the
        // card shows « 5211 » and a difference with the screen would be visible.
        $accounts = $pdo->query("
            SELECT a.*, c.code AS coa_code, c.name AS coa_name,
                   (SELECT COALESCE(SUM(l.debit - l.credit), 0)
                      FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id
                     WHERE l.account_id = a.coa_account_id AND e.status = 'posted') AS ledger_balance
              FROM treasury_accounts a
              LEFT JOIN chart_of_accounts c ON c.id = a.coa_account_id
             WHERE a.status = 'active'
             ORDER BY a.type, a.name
        ")->fetchAll(PDO::FETCH_ASSOC);
        $transactions = $pdo->query("
            SELECT t.id, t.account_id, t.transaction_type, t.amount, t.reference, t.description, t.is_reconciled,
                   t.created_at, a.name AS account_name, a.type AS account_type,
                   DATE_FORMAT(t.created_at, '%d/%m/%Y %H:%i') AS date,
                   " . TREASURY_DIRECTION_SQL . " AS type_direction,
                   (SELECT COUNT(*) FROM treasury_edit_requests r WHERE r.transaction_id = t.id AND r.status = 'pending') AS pending_requests
              FROM treasury_transactions t
              JOIN treasury_accounts a ON t.account_id = a.id
             WHERE t.deleted_at IS NULL
             ORDER BY t.created_at DESC, t.id DESC
             LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC);

        switch ($tab) {
            case 'wallets':
            case 'operations':
                sendResponse('success', 'Données chargées', ['accounts' => $accounts, 'transactions' => $transactions]);
            case 'reconciliation':
                $requests = $pdo->query("
                    SELECT r.id, r.transaction_id, r.reason, r.proposed_amount, r.status, r.resolution_note,
                           DATE_FORMAT(r.created_at, '%d/%m/%Y %H:%i') AS date,
                           DATE_FORMAT(r.resolved_at, '%d/%m/%Y %H:%i') AS resolved_date,
                           t.amount, t.reference, t.description, t.transaction_type, a.name AS account_name,
                           CONCAT_WS(' ', u.first_name, u.last_name) AS requested_by_name,
                           CONCAT_WS(' ', d.first_name, d.last_name) AS resolved_by_name
                      FROM treasury_edit_requests r
                      JOIN treasury_transactions t ON t.id = r.transaction_id
                      JOIN treasury_accounts a     ON a.id = t.account_id
                 LEFT JOIN users u ON u.id = r.requested_by
                 LEFT JOIN users d ON d.id = r.resolved_by
                     ORDER BY (r.status = 'pending') DESC, r.created_at DESC
                     LIMIT 50
                ")->fetchAll(PDO::FETCH_ASSOC);
                sendResponse('success', 'Données chargées', [
                    'accounts' => $accounts, 'transactions' => $transactions, 'requests' => $requests,
                ]);
            default:
                sendResponse('error', 'Onglet inconnu.');
        }
    } catch (PDOException $e) {
        error_log('treasury_controller read: ' . $e->getMessage());
        sendResponse('error', 'Erreur serveur. Veuillez réessayer.');
    }
}

// ==========================================
// [POST] WRITE
// ==========================================
try {
    $pdo->beginTransaction();

    // 1. CREATE ACCOUNT, with its opening balance posted to the ledger (9.2).
    if ($action === 'create_account') {
        $name    = trim((string) ($payload['name'] ?? ''));
        $type    = (string) ($payload['type'] ?? '');
        $acc_num = trim((string) ($payload['account_number'] ?? '')) ?: null;
        $balance = round((float) ($payload['balance'] ?? 0));

        if ($name === '' || !in_array($type, ['bank', 'momo', 'caisse'], true)) {
            throw new RuntimeException('Nom et type de compte obligatoires.');
        }
        if ($balance < 0) throw new RuntimeException("Le solde d'ouverture ne peut pas être négatif.");

        $pdo->prepare("INSERT INTO treasury_accounts (name, type, account_number, balance, created_by) VALUES (?, ?, ?, ?, ?)")
            ->execute([$name, $type, $acc_num, $balance, $user_id]);
        $new_acc_id = (int) $pdo->lastInsertId();
        // Its own sub-account (5211, 5521, 5711…), before anything posts (decision 12).
        TreasuryAccount::ensureLedger($pdo, $new_acc_id);

        $je_id = null;
        if ($balance > 0) {
            $pdo->prepare("INSERT INTO treasury_transactions (account_id, transaction_type, amount, reference, description, logged_by) VALUES (?, 'in_other', ?, 'OUVERTURE', 'Solde Initial Ouverture', ?)")
                ->execute([$new_acc_id, $balance, $user_id]);
            $je_id = JournalPoster::postTreasuryOpening($new_acc_id, $balance, (int) $pdo->lastInsertId());
        }

        $pdo->commit();

        lpc_notify_permission(
            $pdo,
            'accounting.cashflow.view',
            'Nouveau compte de trésorerie',
            ($_SESSION['user_name'] ?? 'Un opérateur') . " a créé le compte « $name »"
                . ($balance > 0 ? ', solde d\'ouverture ' . number_format($balance, 0, ',', ' ') . " FCFA, porté au compte d'attente 471 à régulariser." : '.'),
            '/modules/accounting/cashflow.php',
            'info',
            [$user_id]
        );

        sendResponse('success', $balance > 0
            ? "Compte créé. Le solde d'ouverture est comptabilisé (contrepartie : compte d'attente 471, à régulariser)."
            : 'Compte créé.', ['journal_entry_id' => $je_id]);
    }

    // 1b. UPDATE ACCOUNT DETAILS (name, number, bank, IBAN…). Type and balance are
    //     not editable: the type sets the 5x account, the balance is the sum of
    //     the movements.
    if ($action === 'update_account') {
        $acc_id = (int) ($payload['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id FROM treasury_accounts WHERE id = ? AND status = 'active' FOR UPDATE");
        $stmt->execute([$acc_id]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('Compte introuvable ou archivé.');

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') throw new RuntimeException("Le nom d'affichage est obligatoire.");

        $has_col = function (string $col) use ($pdo): bool {
            $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns
                                 WHERE table_schema = DATABASE() AND table_name = 'treasury_accounts' AND column_name = ?");
            $s->execute([$col]);
            return ((int) $s->fetchColumn() > 0);
        };
        $sets   = ['name = ?', 'account_number = ?'];
        $params = [$name, trim((string) ($payload['account_number'] ?? '')) ?: null];
        foreach (['bank_name', 'iban', 'swift', 'holder_name'] as $col) {
            if ($has_col($col)) { $sets[] = "$col = ?"; $params[] = trim((string) ($payload[$col] ?? '')) ?: null; }
        }
        if ($has_col('show_on_invoice') && array_key_exists('show_on_invoice', $payload)) {
            $sets[] = 'show_on_invoice = ?'; $params[] = !empty($payload['show_on_invoice']) ? 1 : 0;
        }
        if ($has_col('sort_order') && array_key_exists('sort_order', $payload)) {
            $sets[] = 'sort_order = ?'; $params[] = (int) $payload['sort_order'];
        }
        $params[] = $acc_id;
        $pdo->prepare("UPDATE treasury_accounts SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        // The sub-account follows the account's name; one is created if missing.
        $coa_id = TreasuryAccount::ensureLedger($pdo, $acc_id);
        $pdo->prepare("UPDATE chart_of_accounts SET name = ? WHERE id = ?")->execute([mb_substr($name, 0, 100), $coa_id]);

        $pdo->commit();
        sendResponse('success', 'Compte mis à jour.');
    }

    // 2. INTERNAL TRANSFER (posts a balanced JE).
    if ($action === 'transfer') {
        $from_id = (int) ($payload['from_id'] ?? 0);
        $to_id   = (int) ($payload['to_id'] ?? 0);
        $amount  = round((float) ($payload['amount'] ?? 0));
        $ref     = trim((string) ($payload['reference'] ?? ''));

        if ($from_id === $to_id) throw new RuntimeException("Impossible de transférer vers le même compte.");
        if ($amount <= 0) throw new RuntimeException("Le montant doit être supérieur à 0.");
        if ($ref === '') throw new RuntimeException("Indiquez la référence ou le motif du virement.");

        $ordered = [$from_id, $to_id]; sort($ordered);
        $stmt = $pdo->prepare("SELECT id, balance, name FROM treasury_accounts WHERE id IN (?, ?) AND status = 'active' FOR UPDATE");
        $stmt->execute($ordered);
        $accs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $accs[(int) $r['id']] = $r;
        if (!isset($accs[$from_id], $accs[$to_id])) throw new RuntimeException("Compte introuvable.");
        if ((float) $accs[$from_id]['balance'] < $amount) {
            throw new RuntimeException("Solde insuffisant sur « {$accs[$from_id]['name']} » ("
                . number_format((float) $accs[$from_id]['balance'], 0, ',', ' ') . ' FCFA).');
        }

        $pdo->prepare("UPDATE treasury_accounts SET balance = balance - ? WHERE id = ?")->execute([$amount, $from_id]);
        $pdo->prepare("UPDATE treasury_accounts SET balance = balance + ? WHERE id = ?")->execute([$amount, $to_id]);

        $stmtLog = $pdo->prepare("INSERT INTO treasury_transactions (account_id, transaction_type, amount, reference, description, logged_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtLog->execute([$from_id, 'transfer_out', $amount, $ref, "Virement vers « {$accs[$to_id]['name']} »", $user_id]);
        $out_id = (int) $pdo->lastInsertId();
        $stmtLog->execute([$to_id, 'transfer_in', $amount, $ref, "Virement depuis « {$accs[$from_id]['name']} »", $user_id]);
        $in_id = (int) $pdo->lastInsertId();
        $link = $pdo->prepare("UPDATE treasury_transactions SET linked_transfer_id = ? WHERE id = ?");
        $link->execute([$in_id, $out_id]);
        $link->execute([$out_id, $in_id]);

        $je_id = JournalPoster::postInternalTransfer($from_id, $to_id, $amount, $ref);

        $pdo->commit();

        lpc_notify_permission(
            $pdo,
            'accounting.cashflow.view',
            'Virement interne effectué',
            ($_SESSION['user_name'] ?? 'Un opérateur') . " a viré " . number_format($amount, 0, ',', ' ')
                . " FCFA de « {$accs[$from_id]['name']} » vers « {$accs[$to_id]['name']} » (réf. $ref).",
            '/modules/accounting/cashflow.php',
            'info',
            [$user_id]
        );
        sendResponse('success', 'Virement interne effectué.', ['journal_entry_id' => $je_id]);
    }

    // 3. RECONCILIATION LOCK: the movement is on the bank statement.
    if ($action === 'reconcile') {
        $trx_id = (int) ($payload['transaction_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE treasury_transactions SET is_reconciled = 1, updated_by = ? WHERE id = ? AND is_reconciled = 0 AND deleted_at IS NULL");
        $stmt->execute([$user_id, $trx_id]);
        if ($stmt->rowCount() === 0) throw new RuntimeException('Mouvement introuvable ou déjà rapproché.');
        $pdo->commit();
        sendResponse('success', 'Mouvement rapproché et verrouillé.');
    }

    // 4. CORRECTION REQUEST on a movement. It used to notify the Direction and
    //    stop there: nothing could answer it (9.5). The request is now listed
    //    under Rapprochement Bancaire, where the Direction marks it corrected
    //    (the correction itself is made where the movement came from: the
    //    expense, the payment, or an OD in Écritures) or refused, with a note
    //    the requester receives.
    if ($action === 'edit_request') {
        $trx_id = (int) ($payload['transaction_id'] ?? 0);
        $amount = round((float) ($payload['amount'] ?? 0));
        $reason = trim((string) ($payload['reason'] ?? ''));
        if (mb_strlen($reason) < 5) throw new RuntimeException('Expliquez la correction demandée (5 caractères au moins).');

        $stmt = $pdo->prepare("SELECT t.id, t.amount, t.reference, a.name FROM treasury_transactions t JOIN treasury_accounts a ON a.id = t.account_id WHERE t.id = ? AND t.deleted_at IS NULL");
        $stmt->execute([$trx_id]);
        $trx = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$trx) throw new RuntimeException('Mouvement introuvable.');
        $dup = $pdo->prepare("SELECT COUNT(*) FROM treasury_edit_requests WHERE transaction_id = ? AND status = 'pending'");
        $dup->execute([$trx_id]);
        if ((int) $dup->fetchColumn() > 0) throw new RuntimeException('Une demande de correction attend déjà sur ce mouvement.');

        $pdo->prepare("INSERT INTO treasury_edit_requests (transaction_id, requested_by, reason, proposed_amount) VALUES (?, ?, ?, ?)")
            ->execute([$trx_id, $user_id, $reason, $amount]);
        $pdo->commit();

        lpc_notify_permission(
            $pdo,
            'admin.settings.edit',
            'Demande de correction · trésorerie',
            ($_SESSION['user_name'] ?? 'Un opérateur') . " demande de corriger le mouvement "
                . ($trx['reference'] ?: "#$trx_id") . " ({$trx['name']}, " . number_format((float) $trx['amount'], 0, ',', ' ')
                . " FCFA) en " . number_format($amount, 0, ',', ' ') . " FCFA. Motif : $reason",
            '/modules/accounting/cashflow.php#reconciliation',
            'warning',
            [$user_id]
        );
        sendResponse('success', 'Demande de correction envoyée à la Direction.');
    }

    // 5. THE DIRECTION ANSWERS A CORRECTION REQUEST.
    if ($action === 'decide_edit_request') {
        $id       = (int) ($payload['id'] ?? 0);
        $decision = (string) ($payload['decision'] ?? '');
        $note     = trim((string) ($payload['note'] ?? ''));
        if (!in_array($decision, ['approved', 'rejected'], true)) throw new RuntimeException('Décision inconnue.');
        if ($note === '') throw new RuntimeException($decision === 'approved'
            ? 'Indiquez comment la correction a été faite (ex. écriture OD, dépense modifiée).'
            : 'Indiquez la raison du refus.');

        $stmt = $pdo->prepare("SELECT r.*, t.reference FROM treasury_edit_requests r JOIN treasury_transactions t ON t.id = r.transaction_id WHERE r.id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) throw new RuntimeException('Demande introuvable.');
        if ($req['status'] !== 'pending') throw new RuntimeException('Cette demande a déjà reçu une réponse.');

        $pdo->prepare("UPDATE treasury_edit_requests SET status = ?, resolved_by = ?, resolution_note = ?, resolved_at = NOW() WHERE id = ?")
            ->execute([$decision, $user_id, $note, $id]);
        $pdo->commit();

        lpc_notify_user(
            $pdo,
            (int) $req['requested_by'],
            $decision === 'approved' ? 'Correction faite' : 'Correction refusée',
            'Votre demande sur le mouvement ' . ($req['reference'] ?: '#' . $req['transaction_id']) . ' : ' . $note,
            '/modules/accounting/cashflow.php#reconciliation',
            $decision === 'approved' ? 'success' : 'warning'
        );
        sendResponse('success', $decision === 'approved' ? 'Demande marquée corrigée.' : 'Demande refusée.');
    }

    throw new RuntimeException('Action inconnue.');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException) {
        error_log('treasury_controller ' . $action . ': ' . $e->getMessage());
        sendResponse('error', 'Erreur serveur. Veuillez réessayer.');
    }
    sendResponse('error', $e->getMessage());
}
