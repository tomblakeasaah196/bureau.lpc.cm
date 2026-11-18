<?php
/**
 * CONTROLLER: Emprunts & Financements (a tab of Trésorerie & Banque)
 * -----------------------------------------------------------------------------
 * The owner's decision 15 (18/11/2026); the rules are in includes/classes/Loans.php.
 *
 * One gate, as treasury_controller: the action is read where its handler reads
 * it (query string for a read, JSON body for a write); each action needs its
 * own permission; every write is a POST with a valid CSRF token.
 *   read                      accounting.loans.view    (runs the due months and reminders first)
 *   add_counterparty,
 *   record_loan,
 *   record_repayment          accounting.loans.record  (CF, Comptable, DG): nothing posts
 *   decide_loan,
 *   decide_repayment          accounting.loans.approve (DG): the only way anything posts
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Loans.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('accounting.loans.view');
$user_id = (int) ($_SESSION['user_id'] ?? 0);

function loansRespond(string $status, string $message, $data = null): void
{
    $r = ['status' => $status, 'message' => $message];
    if ($data !== null) $r['data'] = $data;
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

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

const LOANS_READ_ACTIONS  = ['read' => 'accounting.loans.view'];
const LOANS_WRITE_ACTIONS = [
    'add_counterparty' => 'accounting.loans.record',
    'record_loan'      => 'accounting.loans.record',
    'record_repayment' => 'accounting.loans.record',
    'decide_loan'      => 'accounting.loans.approve',
    'decide_repayment' => 'accounting.loans.approve',
];

$allowed = $isWrite ? LOANS_WRITE_ACTIONS : LOANS_READ_ACTIONS;
if (!is_string($action) || !isset($allowed[$action])) {
    http_response_code(400);
    loansRespond('error', 'Action inconnue.');
}
Rbac::requirePermission($allowed[$action]);
if ($isWrite) {
    if ($method !== 'POST') {
        http_response_code(405);
        loansRespond('error', 'Méthode HTTP non autorisée.');
    }
    Csrf::requireValid();
}

try {
    $pdo = Database::getInstance()->getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    error_log('loans_controller: ' . $e->getMessage());
    loansRespond('error', 'Erreur serveur. Veuillez réessayer.');
}

if (!$isWrite) {
    try {
        // The months of interest and the reminders due, before showing them.
        try { Loans::runDue($pdo); } catch (Throwable $e) { error_log('Loans::runDue: ' . $e->getMessage()); }
        $data = Loans::overview($pdo);
        $data['kinds']    = Loans::KINDS;
        $data['interest'] = Loans::INTEREST;
        $data['can'] = [
            'record'  => Rbac::hasPermission('accounting.loans.record'),
            'approve' => Rbac::hasPermission('accounting.loans.approve'),
        ];
        loansRespond('success', 'Données chargées', $data);
    } catch (Throwable $e) {
        error_log('loans_controller read: ' . $e->getMessage());
        loansRespond('error', 'Erreur serveur. Veuillez réessayer.');
    }
}

try {
    $pdo->beginTransaction();
    $note = (string) ($payload['note'] ?? '');

    if ($action === 'add_counterparty') {
        $id = Loans::addCounterparty($pdo, (string) ($payload['name'] ?? ''), (string) ($payload['kind'] ?? ''), (string) ($payload['notes'] ?? ''), $user_id);
        $pdo->commit();
        loansRespond('success', 'Contrepartie ajoutée.', ['id' => $id]);
    }

    if ($action === 'record_loan') {
        $r = Loans::record($pdo, $payload, $user_id);
        $pdo->commit();
        loansRespond('success', "{$r['reference']} enregistré. Il attend l'approbation du DG : rien n'est comptabilisé avant.", $r);
    }

    if ($action === 'record_repayment') {
        $id = Loans::recordRepayment($pdo, (int) ($payload['loan_id'] ?? 0), $payload, $user_id);
        $pdo->commit();
        loansRespond('success', "Remboursement enregistré. Il attend l'approbation du DG : rien n'est comptabilisé avant.", ['id' => $id]);
    }

    if ($action === 'decide_loan' || $action === 'decide_repayment') {
        $approve = ($payload['decision'] ?? '') === 'approve';
        if (!$approve && ($payload['decision'] ?? '') !== 'reject') throw new RuntimeException('Décision inconnue.');
        $id = (int) ($payload['id'] ?? 0);
        if ($action === 'decide_loan') {
            $msg = Loans::decideLoan($pdo, $id, $approve, $note, $user_id);
            $who = $pdo->prepare("SELECT created_by, reference FROM loans WHERE id = ?");
        } else {
            $msg = Loans::decideRepayment($pdo, $id, $approve, $note, $user_id);
            $who = $pdo->prepare("SELECT m.created_by, l.reference FROM loan_movements m JOIN loans l ON l.id = m.loan_id WHERE m.id = ?");
        }
        $who->execute([$id]);
        $w = $who->fetch(PDO::FETCH_ASSOC);
        $pdo->commit();
        if ($w && (int) $w['created_by'] !== $user_id) {
            Loans::tellRecorder($pdo, (int) $w['created_by'], $approve ? 'Approuvé par le DG' : 'Refusé par le DG',
                $msg . ($note !== '' ? " Note : {$note}" : ''));
        }
        loansRespond('success', $msg);
    }

    throw new RuntimeException('Action inconnue.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException) {
        error_log('loans_controller ' . $action . ': ' . $e->getMessage());
        loansRespond('error', 'Erreur serveur. Veuillez réessayer.');
    }
    loansRespond('error', $e->getMessage());
}
