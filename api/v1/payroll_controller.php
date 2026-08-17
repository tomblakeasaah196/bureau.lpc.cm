<?php
// api/v1/payroll_controller.php
// Sprint-4 (parallel) rewrite. Bootstrap loads env, DB, session, CSRF, Rbac.
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Payroll.php';
require_once __DIR__ . '/../../includes/classes/JournalPoster.php'; // PR-2, migration 095
require_once __DIR__ . '/../../includes/functions/notify.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requireAuth();

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    error_log('payroll_controller: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
    exit;
}

// -----------------------------------------------------------------------------
// Action dispatch
// -----------------------------------------------------------------------------
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action  = $_GET['action'] ?? null;
$payload = [];

if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH' || $method === 'DELETE') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '[]', true) ?: [];
    $action = $action ?? ($payload['action'] ?? null);
}

if (empty($action)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Action non spécifiée.']);
    exit;
}

// -----------------------------------------------------------------------------
// Per-action permission map + CSRF gate.
// -----------------------------------------------------------------------------
$ACTION_PERMS = [
    'list_contracts'    => 'hr.contracts.view',
    'save_contract'     => 'hr.contracts.edit',
    'list_advances'     => 'hr.payroll.view',
    'request_advance'   => 'hr.payroll.view',
    'approve_advance'   => 'hr.payroll.approve_advance',
    'reject_advance'    => 'hr.payroll.approve_advance',
    'get_payroll_grid'  => 'hr.payroll.view',
    'preview'           => 'hr.payroll.view',
    'generate_month'    => 'hr.payroll.generate',
    'list_payslips'     => 'hr.payroll.view',
    'employee_full_detail' => 'hr.payroll.view',
    // PR-2, migration 095 · disbursement half of payroll.
    'list_unsettled_payslips' => 'hr.payroll.generate',
    'settle_payslips'         => 'hr.payroll.generate',
    'list_treasury_accounts'  => 'hr.payroll.generate',
    // PR-2, migration 096 · advance disbursement (Dr 421 / Cr treasury).
    'disburse_advance'        => 'hr.payroll.approve_advance',
];
if (!isset($ACTION_PERMS[$action])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Action inconnue.']);
    exit;
}
Rbac::requirePermission($ACTION_PERMS[$action]);

if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    Csrf::requireValid();
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);

function sendJson($status, $message = '', $data = null): void {
    $out = ['status' => $status];
    if ($message !== '') $out['message'] = $message;
    if ($data !== null)  $out['data']    = $data;
    echo json_encode($out);
    exit;
}

/**
 * Look up (or create) the LPC chart_of_accounts.id whose ohada_account_id
 * maps to the given OHADA account_number. Returns null if unmapped so the
 * caller can surface a clear error.
 */
function lpc_account_for(PDO $db, string $ohada_number): ?int {
    $stmt = $db->prepare(
        "SELECT coa.id
           FROM chart_of_accounts coa
           JOIN ohada_accounts o ON coa.ohada_account_id = o.id
          WHERE o.account_number = ?
          ORDER BY coa.id ASC
          LIMIT 1"
    );
    $stmt->execute([$ohada_number]);
    $r = $stmt->fetchColumn();
    return $r ? (int) $r : null;
}

try {
    // -------------------------------------------------------------------------
    // READ actions
    // -------------------------------------------------------------------------
    if ($action === 'list_contracts') {
        // Sprint 15: `employees` is the SSOT for contract fields. The users
        // join stays LEFT because payroll continues to key by users.id for
        // now (hr_payslips.user_id FK) — an employee with no login therefore
        // appears here as user_id NULL and the payroll grid skips them until
        // a login is provisioned. Migrating hr_payslips / hr_advances to
        // employee_id is scheduled for a follow-up sprint.
        $rows = $db->query("
            SELECT u.id       AS user_id,
                   e.id       AS employee_id,
                   e.employee_code,
                   CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                   r.name     AS role_name,
                   e.base_salary,
                   e.housing_allowance,
                   e.transport_allowance,
                   e.cnps_number,
                   e.dependents_count,
                   e.marital_status,
                   e.tax_regime,
                   e.seniority_years,
                   e.is_active
              FROM employees e
              LEFT JOIN users u ON u.employee_id = e.id
              LEFT JOIN roles r ON r.id = u.role_id
             WHERE e.is_active = 1
             ORDER BY e.first_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        sendJson('success', '', ['contracts' => $rows]);
    }

    if ($action === 'list_advances') {
        // Sprint 15: employee_name comes from `employees` via the users join.
        // hr_advances.user_id still keys by the login row for now.
        $rows = $db->query("
            SELECT a.*,
                   CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                   DATE_FORMAT(a.request_date, '%d/%m/%Y') AS request_date_fr
              FROM hr_advances a
              JOIN users     u ON u.id = a.user_id
              LEFT JOIN employees e ON e.id = u.employee_id
             ORDER BY a.id DESC LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC);
        sendJson('success', '', ['advances' => $rows]);
    }

    if ($action === 'get_payroll_grid') {
        $period = (string) ($_GET['period'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new Exception("Période invalide (attendu YYYY-MM).");
        }
        [$y, $m] = explode('-', $period);
        $y = (int) $y; $m = (int) $m;

        // Sprint 15: read from `employees` (SSOT). Only employees with a
        // linked login appear in the grid, because hr_payslips still keys by
        // user_id. When payslips migrate to employee_id, this JOIN becomes
        // LEFT and unpaid non-login employees start showing up.
        $emps = $db->query("
            SELECT u.id AS user_id,
                   e.base_salary, e.housing_allowance, e.transport_allowance,
                   e.dependents_count, e.marital_status, e.tax_regime, e.seniority_years,
                   CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                   r.name AS role_name
              FROM employees e
              JOIN users u ON u.employee_id = e.id
              JOIN roles r ON r.id = u.role_id
             WHERE e.is_active = 1
             ORDER BY e.first_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stmt_paid = $db->prepare("
            SELECT id, token FROM hr_payslips WHERE user_id = ? AND month = ? AND year = ? AND status != 'draft'
        ");
        $stmt_adv  = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM hr_advances
             WHERE user_id = ? AND status IN ('approved','disbursed') AND MONTH(request_date) = ? AND YEAR(request_date) = ?
        ");
        $stmt_debt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM driver_debts
             WHERE driver_id = ? AND status = 'unpaid' AND MONTH(tour_date) = ? AND YEAR(tour_date) = ?
        ");

        $grid = [];
        foreach ($emps as $e) {
            $stmt_paid->execute([$e['user_id'], $m, $y]);
            $paid = $stmt_paid->fetch(PDO::FETCH_ASSOC);
            $stmt_adv->execute([$e['user_id'], $m, $y]);
            $adv = (float) $stmt_adv->fetchColumn();
            $stmt_debt->execute([$e['user_id'], $m, $y]);
            $debt = (float) $stmt_debt->fetchColumn();

            $grid[] = [
                'user_id'          => (int) $e['user_id'],
                'employee_name'    => $e['employee_name'],
                'role_name'        => $e['role_name'],
                'base_salary'      => (float) $e['base_salary'],
                'housing'          => (float) $e['housing_allowance'],
                'transport'        => (float) $e['transport_allowance'],
                'dependents_count' => (int) $e['dependents_count'],
                'tax_regime'       => $e['tax_regime'],
                'advances'         => $adv,
                'driver_debt'      => $debt,
                'is_paid'          => (bool) $paid,
                'token'            => $paid['token'] ?? null,
                'default_payment'  => $e['role_name'] === 'driver' ? 'caisse' : 'bank',
            ];
        }
        sendJson('success', '', ['grid' => $grid, 'period' => $period]);
    }

    if ($action === 'list_payslips') {
        $period = (string) ($_GET['period'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) throw new Exception("Période invalide.");
        [$y, $m] = array_map('intval', explode('-', $period));
        // Sprint 15: employee_name via employees join.
        $stmt = $db->prepare("
            SELECT p.*, CONCAT(e.first_name, ' ', e.last_name) AS employee_name
              FROM hr_payslips p
              JOIN users     u ON u.id = p.user_id
              LEFT JOIN employees e ON e.id = u.employee_id
             WHERE p.month = ? AND p.year = ?
             ORDER BY e.first_name ASC
        ");
        $stmt->execute([$m, $y]);
        sendJson('success', '', ['payslips' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // -------------------------------------------------------------------------
    // TREASURY ACCOUNTS — feeds the Marquer Payées modal picker.
    // PR-2, migration 095.
    // -------------------------------------------------------------------------
    if ($action === 'list_treasury_accounts') {
        $rows = $db->query("
            SELECT id, name, type, balance
              FROM treasury_accounts
             WHERE status = 'active'
             ORDER BY sort_order ASC, type, name
        ")->fetchAll(PDO::FETCH_ASSOC);
        sendJson('success', '', $rows);
    }

    // -------------------------------------------------------------------------
    // LIST UNSETTLED PAYSLIPS — for a given period (YYYY-MM), grouped by the
    // payment_method column so the UI can render two lists (bank-paid and
    // caisse-paid). Only payslips where status='paid' (accrual posted) AND
    // payment_je_id IS NULL (disbursement NOT posted) are returned — the
    // exact "waiting to be paid" state migration 095 introduced.
    //
    // Historical rows with status='paid' + payment_je_id=NULL that pre-date
    // 095 ALSO show up here. The Backfill Posture rule (leave history
    // alone) is respected because the UI must scope the query to the
    // current pay period, which the user chooses; nothing auto-settles
    // old months.
    // -------------------------------------------------------------------------
    if ($action === 'list_unsettled_payslips') {
        $period = (string)($_GET['period'] ?? $payload['period'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) throw new Exception("Période invalide (YYYY-MM).");
        [$y, $m] = array_map('intval', explode('-', $period));

        // Detect migration 095 — if the column isn't there yet, everything
        // is "unsettled" by definition (no way to mark otherwise), so
        // fall back to a COUNT that returns the raw list.
        $has_col = (int) $db->query("
            SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'hr_payslips'
               AND column_name = 'payment_je_id'
        ")->fetchColumn() > 0;

        $where = "p.month = ? AND p.year = ? AND p.status = 'paid'";
        if ($has_col) $where .= " AND p.payment_je_id IS NULL";

        // Sprint 15: employee_name via employees join.
        $stmt = $db->prepare("
            SELECT p.id, p.user_id, p.net_pay, p.payment_method,
                   CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                   r.name AS role_name
              FROM hr_payslips p
              JOIN users     u ON u.id = p.user_id
              JOIN roles     r ON r.id = u.role_id
              LEFT JOIN employees e ON e.id = u.employee_id
             WHERE {$where}
             ORDER BY p.payment_method, e.last_name, e.first_name
        ");
        $stmt->execute([$m, $y]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by payment_method for the UI. The controller does the
        // grouping (not the client) so the totals server-side match what
        // postPayrollSettlement will see.
        $groups = ['bank' => ['payslips' => [], 'total' => 0.0],
                   'caisse' => ['payslips' => [], 'total' => 0.0]];
        foreach ($rows as $r) {
            $key = ($r['payment_method'] === 'caisse') ? 'caisse' : 'bank';
            $groups[$key]['payslips'][] = $r;
            $groups[$key]['total']     += (float) $r['net_pay'];
        }
        sendJson('success', '', ['period' => $period, 'groups' => $groups]);
    }

    // -------------------------------------------------------------------------
    // SETTLE PAYSLIPS — the "Marquer Payées" bulk action.
    //
    // Given a list of payslip ids all paid FROM THE SAME treasury account,
    // posts ONE JE (Dr 422 sum / Cr treasury COA sum) via
    // JournalPoster::postPayrollSettlement.
    //
    // The client MUST call this once per treasury account: mixing accounts
    // in one call would fold them into one JE with a single treasury
    // credit, which would be wrong (the money left different accounts).
    // Failing that check on the server side would be nice; today the
    // grouping is a controller convention only.
    // -------------------------------------------------------------------------
    if ($action === 'settle_payslips') {
        $treasury_account_id = (int)($payload['treasury_account_id'] ?? 0);
        $payment_date        = trim((string)($payload['payment_date'] ?? date('Y-m-d')));
        $payslip_ids         = $payload['payslip_ids'] ?? [];
        $note                = trim((string)($payload['note'] ?? ''));

        if ($treasury_account_id <= 0) throw new Exception("Compte de trésorerie requis.");
        if (!is_array($payslip_ids) || empty($payslip_ids)) {
            throw new Exception("Aucun bulletin sélectionné.");
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
            throw new Exception("Date de paiement invalide.");
        }

        $chk = $db->prepare("SELECT 1 FROM treasury_accounts WHERE id = ? AND status = 'active'");
        $chk->execute([$treasury_account_id]);
        if (!$chk->fetchColumn()) throw new Exception("Compte de trésorerie invalide ou inactif.");

        $db->beginTransaction();
        try {
            $je_id = JournalPoster::postPayrollSettlement(
                $treasury_account_id,
                array_map('intval', $payslip_ids),
                $payment_date,
                $note
            );
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        // Tell each employee their salary landed — one notification per
        // person, not a broadcast.
        $ids = array_map('intval', $payslip_ids);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $paidSlips = $db->prepare("SELECT user_id, net_pay FROM hr_payslips WHERE id IN ($ph)");
        $paidSlips->execute($ids);
        foreach ($paidSlips->fetchAll(PDO::FETCH_ASSOC) as $slip) {
            lpc_notify_user(
                $db,
                (int) $slip['user_id'],
                'Salaire payé',
                "Votre salaire de " . number_format((float) $slip['net_pay'], 0, ',', ' ') . " FCFA a été réglé le $payment_date.",
                '/modules/hr/payroll_finance.php',
                'info'
            );
        }

        sendJson('success', 'Règlement enregistré.', [
            'journal_entry_id' => $je_id,
            'count'            => count($payslip_ids),
        ]);
    }

    // -------------------------------------------------------------------------
    // EMPLOYEE FULL DETAIL — everything the payroll detail modal needs.
    // Returns identity + contract + this-period breakdown (from a stored
    // payslip if generated, else a live Payroll::compute preview) +
    // employer charges + JE preview lines + this-period advances/debts
    // + last 6 months payslip history.
    // -------------------------------------------------------------------------
    if ($action === 'employee_full_detail') {
        $uid    = (int)   ($_GET['user_id'] ?? $payload['user_id'] ?? 0);
        $period = (string)($_GET['period']  ?? $payload['period']  ?? '');
        if ($uid <= 0) throw new Exception("Utilisateur invalide.");
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) throw new Exception("Période invalide (YYYY-MM).");
        [$y, $m] = array_map('intval', explode('-', $period));
        $month = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m));

        // 1. Identity + contract — Sprint 15: employees is the SSOT.
        // Payroll still keys by users.id, so the query anchors on users and
        // JOINs employees. is_active reads from employees (payroll filter),
        // not from users.status (login lock) — the two are distinct.
        $stmt = $db->prepare("
            SELECT u.id AS user_id, u.email, u.status AS user_status,
                   e.first_name, e.last_name, e.employee_code,
                   e.base_salary, e.housing_allowance, e.transport_allowance,
                   e.cnps_number, e.marital_status, e.dependents_count,
                   e.seniority_years, e.tax_regime, e.hire_date, e.is_active,
                   r.name AS role_name
              FROM users u
              JOIN roles     r ON r.id = u.role_id
              LEFT JOIN employees e ON e.id = u.employee_id
             WHERE u.id = ?
             LIMIT 1
        ");
        $stmt->execute([$uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception("Employé introuvable.");

        $identity = [
            'user_id'         => (int) $row['user_id'],
            'employee_code'   => $row['employee_code'],
            'employee_name'   => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'email'           => $row['email'],
            'user_status'     => $row['user_status'],
            'role_name'       => $row['role_name'],
            'cnps_number'     => $row['cnps_number'],
            'marital_status'  => $row['marital_status'],
            'dependents_count'=> (int) ($row['dependents_count'] ?? 0),
            'seniority_years' => (int) ($row['seniority_years'] ?? 0),
            'tax_regime'      => $row['tax_regime'],
            'hire_date'       => $row['hire_date'],
            'is_active'       => (int) ($row['is_active'] ?? 0),
        ];
        $contract = [
            'base_salary'         => (float) ($row['base_salary'] ?? 0),
            'housing_allowance'   => (float) ($row['housing_allowance'] ?? 0),
            'transport_allowance' => (float) ($row['transport_allowance'] ?? 0),
        ];

        // 2. Current-period slip (if generated) or live compute
        $stmt = $db->prepare("
            SELECT * FROM hr_payslips
             WHERE user_id = ? AND month = ? AND year = ?
             ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$uid, $m, $y]);
        $slip = $stmt->fetch(PDO::FETCH_ASSOC);

        $current = null;
        if ($slip) {
            $bd = null;
            if (!empty($slip['breakdown_json'])) {
                $bd = json_decode($slip['breakdown_json'], true) ?: null;
            }
            $current = [
                'source'         => 'payslip',
                'is_paid'        => in_array($slip['status'], ['paid','validated'], true),
                'payment_method' => $slip['payment_method'],
                'token'          => $slip['token'],
                'status'         => $slip['status'],
                'computation'    => $bd ?: [
                    'gross_salary'   => (float) $slip['gross_salary'],
                    'taxable_base'   => (float) $slip['taxable_base'],
                    'cnps_employee'  => (float) $slip['cnps_employee'],
                    'cnps_employer'  => (float) $slip['cnps_employer'],
                    'irpp'           => (float) $slip['irpp'],
                    'cac'            => (float) $slip['cac'],
                    'cfc_employee'   => (float) $slip['cfc'],
                    'cfc_employer'   => 0.0,
                    'crtv'           => (float) $slip['crtv'],
                    'tdl'            => (float) $slip['tdl'],
                    'advances_deducted'    => (float) $slip['advances_deducted'],
                    'driver_debt_deducted' => (float) $slip['driver_debt_deducted'],
                    'other_deductions'     => 0.0,
                    'absences_deducted'    => (float) $slip['absences_deducted'],
                    'net'                  => (float) $slip['net_pay'],
                ],
                'inputs' => [
                    'bonuses'              => (float) $slip['bonuses'],
                    'absences_deducted'    => (float) $slip['absences_deducted'],
                    'advances_deducted'    => (float) $slip['advances_deducted'],
                    'driver_debt_deducted' => (float) $slip['driver_debt_deducted'],
                ],
            ];
        } else {
            // Live compute — no slip yet for this period.
            $contract_full = array_merge($row, [
                'base_salary'         => $contract['base_salary'],
                'housing_allowance'   => $contract['housing_allowance'],
                'transport_allowance' => $contract['transport_allowance'],
            ]);
            // Pull the same period aggregates the grid uses.
            // Migration 096 · 'disbursed' is the new state after the cash
            // has actually been handed over (JE posted). Both statuses count
            // toward what a payslip should reclaim: 'approved' is the legacy
            // "money out off-books" path, 'disbursed' is the new booked one.
            $st1 = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM hr_advances
                                  WHERE user_id = ? AND status IN ('approved','disbursed')
                                    AND MONTH(request_date) = ? AND YEAR(request_date) = ?");
            $st1->execute([$uid, $m, $y]);
            $adv_pending = (float) $st1->fetchColumn();
            $st2 = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM driver_debts
                                  WHERE driver_id = ? AND status = 'unpaid'
                                    AND MONTH(tour_date) = ? AND YEAR(tour_date) = ?");
            $st2->execute([$uid, $m, $y]);
            $debt_pending = (float) $st2->fetchColumn();

            $inputs = [
                'bonuses'              => 0.0,
                'absences_days'        => 0.0,
                'advances_deducted'    => $adv_pending,
                'driver_debt_deducted' => $debt_pending,
                'other_deductions'     => 0.0,
            ];
            $result = Payroll::compute($contract_full, $inputs, $month);
            $current = [
                'source'         => 'preview',
                'is_paid'        => false,
                'payment_method' => $identity['role_name'] === 'driver' ? 'caisse' : 'bank',
                'token'          => null,
                'status'         => 'preview',
                'computation'    => $result,
                'inputs'         => $inputs,
            ];
        }

        // 3. Pending advances + driver debts detail rows for this period.
        $stmt = $db->prepare("
            SELECT id, amount, request_date, status
              FROM hr_advances
             WHERE user_id = ?
               AND MONTH(request_date) = ? AND YEAR(request_date) = ?
             ORDER BY request_date DESC
        ");
        $stmt->execute([$uid, $m, $y]);
        $advances_period = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $debts_period = [];
        try {
            $stmt = $db->prepare("
                SELECT id, amount, tour_date, status
                  FROM driver_debts
                 WHERE driver_id = ?
                   AND MONTH(tour_date) = ? AND YEAR(tour_date) = ?
                 ORDER BY tour_date DESC
            ");
            $stmt->execute([$uid, $m, $y]);
            $debts_period = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* driver_debts optional */ }

        // 4. Last 6 months history.
        $stmt = $db->prepare("
            SELECT id, month, year, gross_salary, net_pay, status, token, payment_method, created_at
              FROM hr_payslips
             WHERE user_id = ?
             ORDER BY year DESC, month DESC
             LIMIT 6
        ");
        $stmt->execute([$uid]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendJson('success', '', [
            'identity'         => $identity,
            'contract'         => $contract,
            'period'           => $period,
            'current'          => $current,
            'advances_period'  => $advances_period,
            'debts_period'     => $debts_period,
            'history'          => $history,
        ]);
    }

    // -------------------------------------------------------------------------
    // PREVIEW — no writes, live breakdown for the UI
    // -------------------------------------------------------------------------
    if ($action === 'preview') {
        // Sprint 15: `contract_id` no longer exists as a separate row —
        // employee IS the contract. Kept as a POST param name for
        // backward-compat with any cached client bundle: if present, it is
        // treated as a users.id (which was the old semantic in every caller).
        $user_id_p   = (int) ($payload['user_id']     ?? $payload['contract_id'] ?? 0);
        $period      = (string) ($payload['period']   ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) throw new Exception("Période invalide.");
        if ($user_id_p <= 0) throw new Exception("Utilisateur invalide.");

        $stmt = $db->prepare("
            SELECT e.base_salary, e.housing_allowance, e.transport_allowance,
                   e.tax_regime, e.seniority_years, e.marital_status,
                   e.dependents_count, e.cnps_number, e.is_active
              FROM employees e
              JOIN users u ON u.employee_id = e.id
             WHERE u.id = ? AND e.is_active = 1
             LIMIT 1
        ");
        $stmt->execute([$user_id_p]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contract) throw new Exception("Employé actif introuvable.");

        [$y, $m] = array_map('intval', explode('-', $period));
        $month = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m));

        $inputs = [
            'bonuses'              => (float) ($payload['bonuses']              ?? 0),
            'absences_days'        => (float) ($payload['absences_days']        ?? 0),
            'absences_amount'      => (float) ($payload['absences_amount']      ?? 0),
            'advances_deducted'    => (float) ($payload['advances_deducted']    ?? 0),
            'driver_debt_deducted' => (float) ($payload['driver_debt_deducted'] ?? 0),
            'other_deductions'     => (float) ($payload['other_deductions']     ?? 0),
        ];
        $result = Payroll::compute($contract, $inputs, $month);
        sendJson('success', 'Aperçu calculé.', $result);
    }

    // -------------------------------------------------------------------------
    // WRITE actions
    // -------------------------------------------------------------------------
    // Sprint 15 · save_contract removed.
    // Contract fields (base_salary, allowances, CNPS, marital status,
    // dependents, tax regime, seniority) now live on `employees` and are
    // edited only in Données de Base → Employés & RH. This endpoint returns
    // a plain 410 explaining where the write moved to, so any cached client
    // bundle stops trying to write here rather than silently failing.
    if ($action === 'save_contract') {
        http_response_code(410);
        sendJson('error',
            "Cette action a été déplacée. Les champs du contrat (salaire, indemnités, CNPS, ...) se modifient dans Données de Base → Employés & RH.",
            ['moved_to' => '/modules/admin/master_data.php?module=employees']);
        exit;
    }

    if ($action === 'request_advance') {
        $uid = (int) ($payload['user_id'] ?? 0);
        $amt = (float)($payload['amount']  ?? 0);
        if ($uid <= 0 || $amt <= 0) throw new Exception("Utilisateur ou montant invalide.");
        $stmt = $db->prepare("INSERT INTO hr_advances (user_id, amount, request_date, status) VALUES (?, ?, CURRENT_DATE(), 'pending')");
        $stmt->execute([$uid, $amt]);

        // Sprint 15: employee name via employees join.
        $empName = $db->prepare("
            SELECT CONCAT(e.first_name, ' ', e.last_name)
              FROM users u
              LEFT JOIN employees e ON e.id = u.employee_id
             WHERE u.id = ?
        ");
        $empName->execute([$uid]);
        lpc_notify_permission(
            $db,
            'hr.payroll.approve_advance',
            'Demande d\'acompte',
            ($_SESSION['user_name'] ?? 'Un opérateur') . " a soumis une demande d'acompte pour " . ($empName->fetchColumn() ?: "#$uid")
                . " — " . number_format($amt, 0, ',', ' ') . " FCFA.",
            '/modules/hr/payroll_finance.php',
            'warning',
            [$user_id]
        );

        sendJson('success', 'Acompte demandé.');
    }

    if ($action === 'approve_advance') {
        $id = (int) ($payload['advance_id'] ?? $payload['id'] ?? 0);
        if ($id <= 0) throw new Exception("ID acompte invalide.");
        $stmt = $db->prepare("UPDATE hr_advances SET status = 'approved', approved_by = ? WHERE id = ?");
        $stmt->execute([$user_id, $id]);

        $adv = $db->prepare("SELECT user_id, amount FROM hr_advances WHERE id = ?");
        $adv->execute([$id]);
        if ($a = $adv->fetch(PDO::FETCH_ASSOC)) {
            lpc_notify_user(
                $db,
                (int) $a['user_id'],
                'Acompte approuvé',
                "Votre demande d'acompte de " . number_format((float) $a['amount'], 0, ',', ' ') . " FCFA a été approuvée. Décaissement à suivre.",
                '/modules/hr/payroll_finance.php',
                'info'
            );
        }

        sendJson('success', 'Acompte approuvé.');
    }

    // PR-2, migration 096 · disburse an approved advance.
    //   Dr 421 Personnel — avances / Cr treasury COA
    // Requires payload: advance_id, treasury_account_id, payment_date, note?
    if ($action === 'disburse_advance') {
        $advance_id          = (int)($payload['advance_id'] ?? $payload['id'] ?? 0);
        $treasury_account_id = (int)($payload['treasury_account_id'] ?? 0);
        $payment_date        = trim((string)($payload['payment_date'] ?? date('Y-m-d')));
        $note                = trim((string)($payload['note'] ?? ''));

        if ($advance_id <= 0)          throw new Exception("ID acompte invalide.");
        if ($treasury_account_id <= 0) throw new Exception("Compte de trésorerie requis.");
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
            throw new Exception("Date de paiement invalide.");
        }

        // Verify the treasury account is active before opening a transaction.
        $chk = $db->prepare("SELECT 1 FROM treasury_accounts WHERE id = ? AND status = 'active'");
        $chk->execute([$treasury_account_id]);
        if (!$chk->fetchColumn()) throw new Exception("Compte de trésorerie invalide ou inactif.");

        $db->beginTransaction();
        try {
            $je_id = JournalPoster::postAdvanceDisbursement(
                $advance_id,
                $treasury_account_id,
                $payment_date,
                $note
            );
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        $adv2 = $db->prepare("SELECT user_id, amount FROM hr_advances WHERE id = ?");
        $adv2->execute([$advance_id]);
        if ($a2 = $adv2->fetch(PDO::FETCH_ASSOC)) {
            lpc_notify_user(
                $db,
                (int) $a2['user_id'],
                'Acompte décaissé',
                "Votre acompte de " . number_format((float) $a2['amount'], 0, ',', ' ') . " FCFA a été payé le " . $payment_date . '.',
                '/modules/hr/payroll_finance.php',
                'info'
            );
        }

        sendJson('success', 'Acompte décaissé.', ['journal_entry_id' => $je_id]);
    }

    if ($action === 'reject_advance') {
        $id     = (int) ($payload['advance_id'] ?? $payload['id'] ?? 0);
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($id <= 0) throw new Exception("ID acompte invalide.");
        $stmt = $db->prepare("UPDATE hr_advances SET status = 'rejected', approved_by = ? WHERE id = ?");
        $stmt->execute([$user_id, $id]);

        $adv3 = $db->prepare("SELECT user_id, amount FROM hr_advances WHERE id = ?");
        $adv3->execute([$id]);
        if ($a3 = $adv3->fetch(PDO::FETCH_ASSOC)) {
            lpc_notify_user(
                $db,
                (int) $a3['user_id'],
                'Acompte refusé',
                "Votre demande d'acompte de " . number_format((float) $a3['amount'], 0, ',', ' ') . " FCFA a été refusée."
                    . ($reason !== '' ? " Motif : $reason" : ''),
                '/modules/hr/payroll_finance.php',
                'warning'
            );
        }

        sendJson('success', 'Acompte refusé.');
    }

    if ($action === 'generate_month') {
        $period = (string) ($payload['period'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) throw new Exception("Période invalide (attendu YYYY-MM).");
        [$y, $m] = array_map('intval', explode('-', $period));
        $month = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m));

        $employees = $payload['employees'] ?? null;      // array of { user_id, bonuses, absences_days, driver_debt, payment_method }
        if (!is_array($employees) || empty($employees)) throw new Exception("Aucun employé sélectionné.");

        // Cache OHADA-mapped LPC account IDs.
        $acc_map = [];
        foreach (['661', '662', '664', '432', '433', '421', '422', '521', '571'] as $ohada) {
            $acc_map[$ohada] = lpc_account_for($db, $ohada);
        }
        foreach (['661', '432', '433', '422'] as $required) {
            if (!$acc_map[$required]) {
                throw new Exception("Compte OHADA {$required} non mappé — configurez le plan comptable.");
            }
        }

        $db->beginTransaction();

        $insert_slip = $db->prepare("
            INSERT INTO hr_payslips
                (user_id, month, year, base_salary, bonuses, gross_salary, taxable_base,
                 cnps_employee, cnps_employer, irpp, cac, cfc, crtv, rav, tdl,
                 advances_deducted, driver_debt_deducted, absences_deducted,
                 net_pay, payment_method, status, token, token_expires_at,
                 breakdown_json, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, 'validated', ?, DATE_ADD(NOW(), INTERVAL 90 DAY), ?, ?)
        ");

        $created = [];
        foreach ($employees as $emp) {
            $uid = (int) ($emp['user_id'] ?? 0);
            if ($uid <= 0) continue;

            // Sprint 15: contract fields from `employees` via users.employee_id.
            // No hr_contracts row → no compute (employee not on payroll).
            $stmt = $db->prepare("
                SELECT e.base_salary, e.housing_allowance, e.transport_allowance,
                       e.tax_regime, e.seniority_years, e.marital_status,
                       e.dependents_count, e.cnps_number, e.is_active
                  FROM employees e
                  JOIN users u ON u.employee_id = e.id
                 WHERE u.id = ? AND e.is_active = 1
                 LIMIT 1
            ");
            $stmt->execute([$uid]);
            $contract = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$contract) continue;

            $inputs = [
                'bonuses'              => (float) ($emp['bonuses']              ?? 0),
                'absences_days'        => (float) ($emp['absences_days']        ?? 0),
                'advances_deducted'    => (float) ($emp['advances_deducted']    ?? 0),
                'driver_debt_deducted' => (float) ($emp['driver_debt_deducted'] ?? 0),
                'other_deductions'     => (float) ($emp['other_deductions']     ?? 0),
            ];
            $result = Payroll::compute($contract, $inputs, $month);

            // Insert payslip.
            $token = bin2hex(random_bytes(32));
            $payment_method = ($emp['payment_method'] ?? 'bank') === 'caisse' ? 'caisse' : 'bank';
            $insert_slip->execute([
                $uid, $m, $y,
                (float) $contract['base_salary'],
                (float) $inputs['bonuses'],
                $result['gross_salary'],
                $result['taxable_base'],
                $result['cnps_employee'], $result['cnps_employer'],
                $result['irpp'], $result['cac'], $result['cfc_employee'],
                $result['crtv'], $result['tdl'],
                $result['advances_deducted'], $result['driver_debt_deducted'],
                $result['absences_deducted'],
                $result['net'], $payment_method,
                $token,
                json_encode($result, JSON_UNESCAPED_UNICODE),
                $user_id,
            ]);
            $payslip_id = (int) $db->lastInsertId();

            // Post the per-employee balanced JE via post_journal_entry.
            $ref  = "PAIE-{$y}-{$m}-{$uid}";
            $desc = "Paie {$m}/{$y} - #" . $uid;
            $db->prepare("
                INSERT INTO journal_entries (reference, journal_code, date, description, status, created_by)
                VALUES (?, 'OD', ?, ?, 'draft', ?)
            ")->execute([$ref, sprintf('%04d-%02d-01', $y, $m), $desc, $user_id]);
            $entry_id = (int) $db->lastInsertId();

            $insert_line = $db->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");

            // Debit 661 = gross (already absence-adjusted by Payroll::compute).
            $insert_line->execute([$entry_id, $acc_map['661'], $result['gross_salary'], 0.0]);

            // Debit 664 = employer charges (CNPS employer + CFC employer). Not part of gross.
            $employer_charges = $result['cnps_employer'] + $result['cfc_employer'];
            if ($employer_charges > 0 && $acc_map['664']) {
                $insert_line->execute([$entry_id, $acc_map['664'], $employer_charges, 0.0]);
            }

            // Credits (liabilities + net).
            $total_cnps    = $result['cnps_employee'] + $result['cnps_employer'];
            $total_taxes   = $result['irpp'] + $result['cac'] + $result['cfc_employee'] + $result['cfc_employer'] + $result['crtv'] + $result['tdl'];
            $total_advance = $result['advances_deducted'] + $result['driver_debt_deducted'] + $result['other_deductions'];

            if ($total_cnps > 0)                       $insert_line->execute([$entry_id, $acc_map['433'], 0.0, $total_cnps]);
            if ($total_taxes > 0)                      $insert_line->execute([$entry_id, $acc_map['432'], 0.0, $total_taxes]);
            if ($total_advance > 0 && $acc_map['421']) $insert_line->execute([$entry_id, $acc_map['421'], 0.0, $total_advance]);
            if ($result['net'] > 0)                    $insert_line->execute([$entry_id, $acc_map['422'], 0.0, $result['net']]);

            // Post via the stored proc — raises SIGNAL 45000 if unbalanced, which
            // trips the outer transaction and rolls back the whole batch.
            $db->prepare("CALL post_journal_entry(?, ?)")->execute([$entry_id, $user_id]);

            $db->prepare("UPDATE hr_payslips SET journal_entry_id = ?, status = 'paid' WHERE id = ?")
               ->execute([$entry_id, $payslip_id]);

            // Consume advances + driver debts.
            //
            // Migration 096 · both 'approved' (legacy, cash off-books) and
            // 'disbursed' (post-096, JE posted) count as consumable. After
            // consumption both roll into 'deducted', which is the terminal
            // state — the JE line credited to 421 above (line 712) settles
            // BOTH the pre-096 zero balance AND the post-096 debit balance.
            $db->prepare("
                UPDATE hr_advances SET status = 'deducted', payslip_id = ?
                 WHERE user_id = ? AND status IN ('approved','disbursed')
                   AND MONTH(request_date) = ? AND YEAR(request_date) = ?
            ")->execute([$payslip_id, $uid, $m, $y]);

            $db->prepare("
                UPDATE driver_debts SET status = 'deducted_from_salary'
                 WHERE driver_id = ? AND status = 'unpaid'
                   AND MONTH(tour_date) = ? AND YEAR(tour_date) = ?
            ")->execute([$uid, $m, $y]);

            $created[] = ['user_id' => $uid, 'payslip_id' => $payslip_id, 'token' => $token, 'net' => $result['net']];
        }

        $db->commit();

        // One notification per employee — not a broadcast, so it stays
        // proportionate no matter how many payslips a run generates.
        foreach ($created as $c) {
            lpc_notify_user(
                $db,
                (int) $c['user_id'],
                'Bulletin de paie disponible',
                "Votre bulletin de paie de " . sprintf('%02d/%04d', $m, $y) . " est prêt — net à payer : "
                    . number_format((float) $c['net'], 0, ',', ' ') . " FCFA.",
                '/modules/hr/payroll_finance.php',
                'info'
            );
        }
        lpc_notify_permission(
            $db,
            'hr.payroll.generate',
            'Paie générée',
            ($_SESSION['user_name'] ?? 'Un opérateur') . " a généré " . count($created) . " bulletin(s) pour " . sprintf('%02d/%04d', $m, $y) . '.',
            '/modules/hr/payroll_finance.php',
            'info',
            [$user_id]
        );

        sendJson('success', count($created) . ' bulletin(s) générés.', ['created' => $created]);
    }

    // Fallthrough
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Action non gérée.']);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    error_log('payroll_controller: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
}
