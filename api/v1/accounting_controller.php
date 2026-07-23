<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('accounting.journal.view');
/**
 * CONTROLLER: Comptabilité (Journals & Chart of Accounts)
 * DESCRIPTION: Handles Chart of Accounts (OHADA/LPC), Journal Entry saving (Double-Entry validation), and Queue approvals.
 * METHOD: STRICT REST-like JSON API (GET / POST)
 */
header('Content-Type: application/json; charset=utf-8');
$user_id = $_SESSION['user_id'];

// Database Connection (PDO)
try {
    require_once '../../includes/config/db.php';
    require_once '../../includes/classes/Database.php';
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
    $tab = $_GET['tab'] ?? null;
    $response_data = [];

    try {
        switch ($tab) {
            case 'chart':
            case 'wizard':
                // 1. Fetch OHADA Master Accounts
                $stmtM = $pdo->query("SELECT id, account_number, name, type FROM ohada_accounts ORDER BY account_number ASC");
                $response_data['ohada_masters'] = $stmtM->fetchAll();
                
                // 2. Fetch LPC Auxiliary Accounts
                $stmtA = $pdo->query("SELECT id, ohada_account_id, code, name, type FROM chart_of_accounts WHERE is_active = 1 ORDER BY code ASC");
                $response_data['lpc_accounts'] = $stmtA->fetchAll();
                break;

            case 'queue':
                // Fetch Pending Entries (Brouillards)
                $stmtQ = $pdo->query("SELECT id, reference, date, description, status, journal_code FROM journal_entries WHERE status = 'pending' ORDER BY created_at DESC");
                $entries = $stmtQ->fetchAll();
                
                // Fetch lines for each entry
                $queue = [];
                $stmtL = $pdo->prepare("SELECT jl.debit, jl.credit, ca.code as account_code, ca.name as account_name FROM journal_lines jl JOIN chart_of_accounts ca ON jl.account_id = ca.id WHERE jl.journal_entry_id = ?");
                
                foreach ($entries as $e) {
                    $stmtL->execute([$e['id']]);
                    $e['lines'] = $stmtL->fetchAll();
                    $queue[] = $e;
                }
                $response_data['queue'] = $queue;
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

        // 1. CREATE AUXILIARY ACCOUNT (Plan Comptable LPC)
        if ($action === 'create_lpc_account') {
            $ohada_id = (int)$payload['ohada_account_id'];
            $code = trim($payload['code']);
            $name = trim($payload['name']);

            // Validate code length (Strict 6 digits)
            if (strlen($code) !== 6 || !is_numeric($code)) {
                throw new Exception("Le code compte doit être strictement composé de 6 chiffres.");
            }

            // Ensure unique code
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM chart_of_accounts WHERE code = ?");
            $stmt->execute([$code]);
            if ($stmt->fetchColumn() > 0) throw new Exception("Ce code de compte ($code) existe déjà.");

            // Inherit type from parent
            $stmtP = $pdo->prepare("SELECT type FROM ohada_accounts WHERE id = ?");
            $stmtP->execute([$ohada_id]);
            $type = $stmtP->fetchColumn();

            $stmtI = $pdo->prepare("INSERT INTO chart_of_accounts (ohada_account_id, code, name, type) VALUES (?, ?, ?, ?)");
            $stmtI->execute([$ohada_id, $code, $name, $type]);

            $pdo->commit();
            sendResponse('success', 'Compte LPC créé avec succès.');
        }

        // 2. SAVE JOURNAL ENTRY (Draft or Post)
        if ($action === 'save_journal_entry') {
            $status = $payload['status'] === 'post' ? 'approved' : 'pending';
            $journal_code = $payload['journal_code'];
            $date = $payload['date'];
            $ref = trim($payload['reference']);
            $desc = trim($payload['description']);
            $lines = $payload['lines'];

            // Math Validation: Calculate total Debit and Credit
            $total_debit = 0.0;
            $total_credit = 0.0;

            foreach ($lines as $line) {
                $total_debit += (float)$line['debit'];
                $total_credit += (float)$line['credit'];
            }

            // Strict Anti-Fraud Rule: If they want to post to GL, it MUST balance
            if ($status === 'approved') {
                // Using a 0.01 tolerance to avoid PHP floating-point rounding errors
                if (abs($total_debit - $total_credit) > 0.01) {
                    throw new Exception("Écriture déséquilibrée ! Impossible de valider au Grand Livre.");
                }
                if ($total_debit <= 0) {
                    throw new Exception("L'écriture doit avoir un montant supérieur à 0.");
                }
                $approved_by = $user_id;
            } else {
                $approved_by = null; // Stays in draft
            }

            // A. Insert Header
            $stmtE = $pdo->prepare("INSERT INTO journal_entries (reference, journal_code, date, description, status, created_by, approved_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtE->execute([$ref, $journal_code, $date, $desc, $status, $user_id, $approved_by]);
            $entry_id = $pdo->lastInsertId();

            // B. Insert Lines
            $stmtL = $pdo->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
            foreach ($lines as $line) {
                $stmtL->execute([$entry_id, $line['account_id'], (float)$line['debit'], (float)$line['credit']]);
            }

            $pdo->commit();
            $msg = $status === 'approved' ? 'Écriture équilibrée et validée au Grand Livre.' : 'Brouillard sauvegardé.';
            sendResponse('success', $msg);
        }

        // 3. APPROVE PENDING ENTRY (From the Queue)
        if ($action === 'approve_entry') {
            $entry_id = (int)$payload['id'];

            // We must recalculate to ensure no one tampered with the DB manually
            $stmt = $pdo->prepare("SELECT SUM(debit) as d, SUM(credit) as c FROM journal_lines WHERE journal_entry_id = ?");
            $stmt->execute([$entry_id]);
            $totals = $stmt->fetch();

            if (abs((float)$totals['d'] - (float)$totals['c']) > 0.01) {
                throw new Exception("Ce brouillard est déséquilibré. Modifiez-le avant de le valider.");
            }

            $stmtU = $pdo->prepare("UPDATE journal_entries SET status = 'approved', approved_by = ? WHERE id = ?");
            $stmtU->execute([$user_id, $entry_id]);

            $pdo->commit();
            sendResponse('success', 'Brouillard validé et transféré au Grand Livre.');
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