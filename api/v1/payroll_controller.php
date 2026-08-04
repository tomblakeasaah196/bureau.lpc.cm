<?php
// api/v1/payroll_controller.php
// Sprint-4 (parallel) rewrite. Bootstrap loads env, DB, session, CSRF, Rbac.
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Payroll.php';

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
        $rows = $db->query("
            SELECT u.id AS user_id,
                   CONCAT(u.first_name,' ',u.last_name) AS employee_name,
                   r.name AS role_name,
                   COALESCE(c.base_salary, 0)         AS base_salary,
                   COALESCE(c.housing_allowance, 0)   AS housing_allowance,
                   COALESCE(c.transport_allowance, 0) AS transport_allowance,
                   c.cnps_number,
                   COALESCE(c.dependents_count, 0)    AS dependents_count,
                   COALESCE(c.marital_status, 'single') AS marital_status,
                   COALESCE(c.tax_regime, 'standard') AS tax_regime,
                   COALESCE(c.seniority_years, 0)     AS seniority_years,
                   COALESCE(c.is_active, 0)           AS is_active
              FROM users u
              JOIN roles r ON u.role_id = r.id
              LEFT JOIN hr_contracts c ON c.user_id = u.id
             WHERE u.status = 'active'
             ORDER BY u.first_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        sendJson('success', '', ['contracts' => $rows]);
    }

    if ($action === 'list_advances') {
        $rows = $db->query("
            SELECT a.*, CONCAT(u.first_name,' ',u.last_name) AS employee_name,
                   DATE_FORMAT(a.request_date, '%d/%m/%Y') AS request_date_fr
              FROM hr_advances a
              JOIN users u ON u.id = a.user_id
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

        $emps = $db->query("
            SELECT c.user_id, c.base_salary, c.housing_allowance, c.transport_allowance,
                   c.dependents_count, c.marital_status, c.tax_regime, c.seniority_years,
                   CONCAT(u.first_name,' ',u.last_name) AS employee_name,
                   r.name AS role_name
              FROM hr_contracts c
              JOIN users u ON u.id = c.user_id
              JOIN roles r ON r.id = u.role_id
             WHERE c.is_active = 1
             ORDER BY u.first_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stmt_paid = $db->prepare("
            SELECT id, token FROM hr_payslips WHERE user_id = ? AND month = ? AND year = ? AND status != 'draft'
        ");
        $stmt_adv  = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM hr_advances
             WHERE user_id = ? AND status = 'approved' AND MONTH(request_date) = ? AND YEAR(request_date) = ?
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
        $stmt = $db->prepare("
            SELECT p.*, CONCAT(u.first_name,' ',u.last_name) AS employee_name
              FROM hr_payslips p JOIN users u ON u.id = p.user_id
             WHERE p.month = ? AND p.year = ?
             ORDER BY u.first_name ASC
        ");
        $stmt->execute([$m, $y]);
        sendJson('success', '', ['payslips' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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

        // 1. Identity + contract
        $stmt = $db->prepare("
            SELECT u.id AS user_id, u.first_name, u.last_name, u.email, u.status AS user_status,
                   r.name AS role_name,
                   c.base_salary, c.housing_allowance, c.transport_allowance,
                   c.cnps_number, c.marital_status, c.dependents_count,
                   c.seniority_years, c.tax_regime, c.hire_date, c.is_active
              FROM users u
              JOIN roles r ON r.id = u.role_id
              LEFT JOIN hr_contracts c ON c.user_id = u.id
             WHERE u.id = ?
             LIMIT 1
        ");
        $stmt->execute([$uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception("Employé introuvable.");

        $identity = [
            'user_id'         => (int) $row['user_id'],
            'employee_name'   => trim($row['first_name'] . ' ' . $row['last_name']),
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
            $st1 = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM hr_advances
                                  WHERE user_id = ? AND status = 'approved'
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
        $contract_id = (int) ($payload['contract_id'] ?? 0);
        $user_id_p   = (int) ($payload['user_id']     ?? 0);
        $period      = (string) ($payload['period']   ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) throw new Exception("Période invalide.");

        if ($contract_id > 0) {
            $stmt = $db->prepare("SELECT * FROM hr_contracts WHERE id = ?");
            $stmt->execute([$contract_id]);
        } else {
            $stmt = $db->prepare("SELECT * FROM hr_contracts WHERE user_id = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$user_id_p]);
        }
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contract) throw new Exception("Contrat introuvable.");

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
    if ($action === 'save_contract') {
        $uid   = (int)   ($payload['user_id']            ?? 0);
        $base  = (float) ($payload['base_salary']        ?? 0);
        $hous  = (float) ($payload['housing_allowance']  ?? 0);
        $trans = (float) ($payload['transport_allowance']?? 0);
        $cnps  = trim((string) ($payload['cnps_number']  ?? ''));
        $mar   = (string)($payload['marital_status']     ?? 'single');
        $dep   = (int)   ($payload['dependents_count']   ?? 0);
        $sen   = (int)   ($payload['seniority_years']    ?? 0);
        $reg   = (string)($payload['tax_regime']         ?? 'standard');
        $active = (int)  ($payload['is_active']          ?? 1);

        if ($uid <= 0) throw new Exception("Utilisateur invalide.");

        $stmt = $db->prepare("
            INSERT INTO hr_contracts
                (user_id, base_salary, housing_allowance, transport_allowance,
                 cnps_number, marital_status, dependents_count, seniority_years,
                 tax_regime, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                base_salary=VALUES(base_salary),
                housing_allowance=VALUES(housing_allowance),
                transport_allowance=VALUES(transport_allowance),
                cnps_number=VALUES(cnps_number),
                marital_status=VALUES(marital_status),
                dependents_count=VALUES(dependents_count),
                seniority_years=VALUES(seniority_years),
                tax_regime=VALUES(tax_regime),
                is_active=VALUES(is_active)
        ");
        $stmt->execute([$uid, $base, $hous, $trans, $cnps, $mar, $dep, $sen, $reg, $active]);
        sendJson('success', 'Contrat enregistré.');
    }

    if ($action === 'request_advance') {
        $uid = (int) ($payload['user_id'] ?? 0);
        $amt = (float)($payload['amount']  ?? 0);
        if ($uid <= 0 || $amt <= 0) throw new Exception("Utilisateur ou montant invalide.");
        $stmt = $db->prepare("INSERT INTO hr_advances (user_id, amount, request_date, status) VALUES (?, ?, CURRENT_DATE(), 'pending')");
        $stmt->execute([$uid, $amt]);
        sendJson('success', 'Acompte demandé.');
    }

    if ($action === 'approve_advance') {
        $id = (int) ($payload['advance_id'] ?? $payload['id'] ?? 0);
        if ($id <= 0) throw new Exception("ID acompte invalide.");
        $stmt = $db->prepare("UPDATE hr_advances SET status = 'approved', approved_by = ? WHERE id = ?");
        $stmt->execute([$user_id, $id]);
        sendJson('success', 'Acompte approuvé.');
    }

    if ($action === 'reject_advance') {
        $id     = (int) ($payload['advance_id'] ?? $payload['id'] ?? 0);
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($id <= 0) throw new Exception("ID acompte invalide.");
        $stmt = $db->prepare("UPDATE hr_advances SET status = 'rejected', approved_by = ? WHERE id = ?");
        $stmt->execute([$user_id, $id]);
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

            $stmt = $db->prepare("SELECT * FROM hr_contracts WHERE user_id = ? AND is_active = 1");
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
            $desc = "Paie {$m}/{$y} - " . $contract['user_id'];
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
            $db->prepare("
                UPDATE hr_advances SET status = 'deducted', payslip_id = ?
                 WHERE user_id = ? AND status = 'approved'
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
