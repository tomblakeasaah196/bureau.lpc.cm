<?php
/**
 * api/v1/review_controller.php
 * CONTROLLER: Révision Comptable (Trial balance, sub-ledgers, GL).
 * Bootstrap loads env, DB, hardened session, CSRF, Rbac.
 *
 * OHADA compliance notes (post-audit, migration 080):
 *  - Filters journal_entries on status = 'posted' (migration 039 replaced
 *    the legacy 'approved' vocabulary with the immutable 'posted' state).
 *  - Zeroes opening balances for classes 6/7/8 — the compte de résultat
 *    accounts close to Class 12 at exercise end and must restart at zero.
 *  - Emits a Total Général row so Σ Débit = Σ Crédit is visible at all
 *    three horizons (opening, movements, closing).
 *  - Balance des Tiers covers the full third-party family the accountant
 *    reconciles: 401/402/408/409, 411/412/418/419, 421-428, 431-438,
 *    441-449 — not just 401/411.
 *  - Grand Livre reports lettrage integrity per letter (balanced / open).
 *  - `save_lettrage` accepts only [A-Z]{1,3} and refuses updates on lines
 *    that belong to a reversed entry.
 *  - `auto_lettrage` proposes matches on an account using an amount-based
 *    heuristic (equal amounts, opposite sides, unlettered) and returns a
 *    server-side preview the accountant confirms in one call.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('review_controller: ' . $e->getMessage());
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
if (!$action && in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
    $payloadPreview = json_decode(file_get_contents('php://input'), true);
    if (is_array($payloadPreview)) $action = $payloadPreview['action'] ?? null;
}

// -----------------------------------------------------------------------------
// Per-action RBAC (accounting/ledger area only).
// The GET flow uses ?tab= to switch between balance / tiers / gl views, so
// a null-action GET is allowed IF the caller has ledger.view.
// -----------------------------------------------------------------------------
$ACTION_PERMS = [
    null                => 'accounting.ledger.view',   // default GET (?tab=balance|tiers)
    'get_grand_livre'   => 'accounting.ledger.view',
    'get_years'         => 'accounting.ledger.view',
    'save_lettrage'     => 'accounting.ledger.lettrage',
    'clear_lettrage'    => 'accounting.ledger.lettrage',
    'auto_lettrage'     => 'accounting.ledger.lettrage',
];
$needed = $ACTION_PERMS[$action] ?? null;
if ($needed === null && !array_key_exists($action, $ACTION_PERMS)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Action inconnue.']);
    exit;
}
Rbac::requirePermission($needed);
if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) Csrf::requireValid();

// -----------------------------------------------------------------------------
// SYSCOHADA third-party account families visible in "Balance des Tiers".
// The list follows the révisé plan: fournisseurs, clients, personnel,
// organismes sociaux, État. If the seed grows to include 45x (Groupes &
// associés) or 46x (Débiteurs / créditeurs divers), extend here.
// -----------------------------------------------------------------------------
const TIERS_PREFIXES = [
    '401','402','408','409',                 // Fournisseurs
    '411','412','413','414','418','419',     // Clients (+419 acomptes reçus)
    '421','422','423','425','426','427','428', // Personnel
    '431','432','433','437','438',            // Organismes sociaux
    '441','442','443','444','445','446','447','448','449', // État
];

/**
 * Classes 6, 7, 8 close to Class 12 (Résultat) at exercise end via the
 * écritures de clôture. Their prior-year balance is by construction zero
 * on the first day of the new exercise, even if AN entries do not exist
 * yet. Balance-sheet classes (1-5) roll forward.
 */
function is_income_statement_class(string $class): bool {
    return in_array($class, ['6','7','8'], true);
}

// ==========================================
// [GET] READ OPERATIONS
// ==========================================
if ($method === 'GET') {
    $tab  = $_GET['tab'] ?? null;
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    $response_data = [];

    try {
        // ---------------------------------------------------------------------
        // Standalone: list exercises for the dropdown. Falls back to the
        // last 5 calendar years if financial_years has not been seeded.
        // ---------------------------------------------------------------------
        if ($action === 'get_years') {
            $rows = [];
            try {
                $rows = $pdo->query(
                    "SELECT `year`, `status` FROM financial_years ORDER BY `year` DESC"
                )->fetchAll();
            } catch (PDOException $e) {
                // Table missing — degrade gracefully.
            }
            if (!$rows) {
                $currentYear = (int)date('Y');
                for ($y = $currentYear; $y >= $currentYear - 4; $y--) {
                    $rows[] = ['year' => $y, 'status' => ($y < $currentYear ? 'closed' : 'open')];
                }
            }
            sendResponse('success', 'Exercices chargés', ['years' => $rows]);
        }

        // Shared Helper: Fetch all active LPC accounts for dropdowns
        if ($tab === 'grandlivre' || $tab === 'balance') {
            $stmtAcc = $pdo->query(
                "SELECT id, code, name FROM chart_of_accounts WHERE is_active = 1 ORDER BY code ASC"
            );
            $response_data['accounts'] = $stmtAcc->fetchAll();
        }

        switch ($tab) {
            case 'balance':
                // THE MASTER BALANCE QUERY
                // Dynamically calculates Opening (Years < filter), Movements
                // (Year == filter). Strictly ignores draft/reversed entries.
                $sql = "
                    SELECT
                        ca.id as aux_id, ca.code as aux_code, ca.name as aux_name,
                        oa.account_number as master_code, oa.name as master_name,
                        SUBSTRING(oa.account_number, 1, 1) as class_code,
                        COALESCE(SUM(CASE WHEN YEAR(je.date) < :yr_ouv_d THEN jl.debit  ELSE 0 END), 0) as ouv_d_raw,
                        COALESCE(SUM(CASE WHEN YEAR(je.date) < :yr_ouv_c THEN jl.credit ELSE 0 END), 0) as ouv_c_raw,
                        COALESCE(SUM(CASE WHEN YEAR(je.date) = :yr_mvt_d THEN jl.debit  ELSE 0 END), 0) as mvt_d,
                        COALESCE(SUM(CASE WHEN YEAR(je.date) = :yr_mvt_c THEN jl.credit ELSE 0 END), 0) as mvt_c
                    FROM chart_of_accounts ca
                    JOIN ohada_accounts oa ON ca.ohada_account_id = oa.id
                    LEFT JOIN journal_lines jl ON ca.id = jl.account_id
                    LEFT JOIN journal_entries je ON jl.journal_entry_id = je.id AND je.status = 'posted'
                    WHERE ca.is_active = 1
                    GROUP BY ca.id
                    ORDER BY ca.code ASC
                ";
                $stmt = $pdo->prepare($sql);
                // Real (non-emulated) PDO prepares require one bound value per
                // placeholder occurrence — reusing :yr four times raised
                // SQLSTATE[HY093]. Bind each occurrence separately.
                $stmt->execute([
                    'yr_ouv_d' => $year,
                    'yr_ouv_c' => $year,
                    'yr_mvt_d' => $year,
                    'yr_mvt_c' => $year,
                ]);
                $rows = $stmt->fetchAll();

                // Build the Hierarchical Tree
                $tree = [];

                // Grand-total accumulator — sacred double-entry check.
                $grand = ['ouv_d' => 0, 'ouv_c' => 0, 'mvt_d' => 0, 'mvt_c' => 0, 'clo_d' => 0, 'clo_c' => 0];

                foreach ($rows as $row) {
                    $class  = $row['class_code'];
                    $master = $row['master_code'];

                    // 1. Calculate Net Opening Balance.
                    //    Classes 6/7/8 (compte de résultat) always restart at
                    //    zero — they close to Class 12 at exercise end.
                    if (is_income_statement_class($class)) {
                        $ouv_d = 0;
                        $ouv_c = 0;
                    } else {
                        $ouv_d = 0; $ouv_c = 0;
                        $net_ouv = $row['ouv_d_raw'] - $row['ouv_c_raw'];
                        if ($net_ouv > 0) $ouv_d = $net_ouv;
                        else              $ouv_c = abs($net_ouv);
                    }

                    // 2. Calculate Net Closing Balance (Trial Balance standard)
                    $total_d = $ouv_d + $row['mvt_d'];
                    $total_c = $ouv_c + $row['mvt_c'];
                    $clo_d = 0; $clo_c = 0;
                    if ($total_d > $total_c) {
                        $clo_d = $total_d - $total_c;
                    } else {
                        $clo_c = $total_c - $total_d;
                    }

                    // 3. Initialize Tree Nodes if missing
                    if (!isset($tree[$class])) {
                        $tree[$class] = [
                            'ouv_d' => 0, 'ouv_c' => 0, 'mvt_d' => 0, 'mvt_c' => 0,
                            'clo_d' => 0, 'clo_c' => 0,
                            'masters' => []
                        ];
                    }
                    if (!isset($tree[$class]['masters'][$master])) {
                        $tree[$class]['masters'][$master] = [
                            'name' => $row['master_name'],
                            'type' => null,
                            'ouv_d' => 0, 'ouv_c' => 0, 'mvt_d' => 0, 'mvt_c' => 0,
                            'clo_d' => 0, 'clo_c' => 0,
                            'aux'   => []
                        ];
                    }

                    // 4. Append Aux Account
                    $tree[$class]['masters'][$master]['aux'][] = [
                        'id'    => $row['aux_id'],
                        'code'  => $row['aux_code'],
                        'name'  => $row['aux_name'],
                        'ouv_d' => $ouv_d, 'ouv_c' => $ouv_c,
                        'mvt_d' => (float)$row['mvt_d'], 'mvt_c' => (float)$row['mvt_c'],
                        'clo_d' => $clo_d, 'clo_c' => $clo_c
                    ];

                    // 5. Accumulate Master Totals
                    $tree[$class]['masters'][$master]['ouv_d'] += $ouv_d;
                    $tree[$class]['masters'][$master]['ouv_c'] += $ouv_c;
                    $tree[$class]['masters'][$master]['mvt_d'] += (float)$row['mvt_d'];
                    $tree[$class]['masters'][$master]['mvt_c'] += (float)$row['mvt_c'];
                }

                // Re-net the master closing balances + roll up to class + grand
                foreach ($tree as $classCode => &$classData) {
                    foreach ($classData['masters'] as &$m) {
                        $tot_d = $m['ouv_d'] + $m['mvt_d'];
                        $tot_c = $m['ouv_c'] + $m['mvt_c'];
                        if ($tot_d > $tot_c) { $m['clo_d'] = $tot_d - $tot_c; $m['clo_c'] = 0; }
                        else                 { $m['clo_c'] = $tot_c - $tot_d; $m['clo_d'] = 0; }

                        $classData['ouv_d'] += $m['ouv_d']; $classData['ouv_c'] += $m['ouv_c'];
                        $classData['mvt_d'] += $m['mvt_d']; $classData['mvt_c'] += $m['mvt_c'];
                    }
                    $t_d = $classData['ouv_d'] + $classData['mvt_d'];
                    $t_c = $classData['ouv_c'] + $classData['mvt_c'];
                    if ($t_d > $t_c) { $classData['clo_d'] = $t_d - $t_c; $classData['clo_c'] = 0; }
                    else             { $classData['clo_c'] = $t_c - $t_d; $classData['clo_d'] = 0; }

                    // Grand totals stay in gross terms (Σ Débit vs Σ Crédit),
                    // NOT netted — that is the whole point of the check row.
                    $grand['ouv_d'] += $classData['ouv_d']; $grand['ouv_c'] += $classData['ouv_c'];
                    $grand['mvt_d'] += $classData['mvt_d']; $grand['mvt_c'] += $classData['mvt_c'];
                    $grand['clo_d'] += $classData['clo_d']; $grand['clo_c'] += $classData['clo_c'];
                }
                unset($classData, $m);

                $response_data['balance'] = $tree;
                $response_data['grand']   = $grand;
                $response_data['balanced'] = (
                    round($grand['ouv_d'], 2) === round($grand['ouv_c'], 2) &&
                    round($grand['mvt_d'], 2) === round($grand['mvt_c'], 2) &&
                    round($grand['clo_d'], 2) === round($grand['clo_c'], 2)
                );
                break;

            case 'tiers':
                // Full third-party family (401-449 in the SYSCOHADA révisé
                // families the app seeds), not just 401 + 411.
                $placeholders = implode(',', array_fill(0, count(TIERS_PREFIXES), '?'));
                $params = TIERS_PREFIXES;
                $params[] = $year;
                $params[] = $year;
                $params[] = $year;

                $sql = "
                    SELECT
                        ca.id, ca.code, ca.name,
                        oa.account_number as master_code, oa.name as master_name,
                        COALESCE(SUM(CASE WHEN YEAR(je.date) < ? THEN jl.debit - jl.credit ELSE 0 END), 0) as solde_ouv,
                        COALESCE(SUM(CASE WHEN YEAR(je.date) = ? THEN jl.debit  ELSE 0 END), 0) as mvt_d,
                        COALESCE(SUM(CASE WHEN YEAR(je.date) = ? THEN jl.credit ELSE 0 END), 0) as mvt_c
                    FROM chart_of_accounts ca
                    JOIN ohada_accounts oa ON ca.ohada_account_id = oa.id
                    LEFT JOIN journal_lines jl ON ca.id = jl.account_id
                    LEFT JOIN journal_entries je ON jl.journal_entry_id = je.id AND je.status = 'posted'
                    WHERE ca.is_active = 1 AND oa.account_number IN ($placeholders)
                    GROUP BY ca.id
                    ORDER BY oa.account_number ASC, ca.code ASC
                ";
                // Rebuild params in the order the placeholders appear.
                $params = array_merge([$year, $year, $year], TIERS_PREFIXES);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll();

                $tiers_data = [];
                $totals = ['debiteur' => 0, 'crediteur' => 0];
                foreach ($rows as $r) {
                    $solde_clo = (float)$r['solde_ouv'] + (float)$r['mvt_d'] - (float)$r['mvt_c'];
                    if ($r['solde_ouv'] != 0 || $r['mvt_d'] != 0 || $r['mvt_c'] != 0) {
                        $r['solde_clo'] = $solde_clo;
                        $tiers_data[] = $r;
                        if ($solde_clo > 0) $totals['debiteur']  += $solde_clo;
                        else                $totals['crediteur'] += abs($solde_clo);
                    }
                }
                $response_data['tiers']  = $tiers_data;
                $response_data['totals'] = $totals;
                break;

            case 'grandlivre':
                // The dropdown-population path. Real GL data comes via
                // ?action=get_grand_livre once the operator picks an account.
                break;

            default:
                if (!$action) sendResponse('error', 'Onglet inconnu.');
        }

        // ---------------------------------------------------------------------
        // Grand Livre — populated by explicit action, not by tab.
        // ---------------------------------------------------------------------
        if ($action === 'get_grand_livre') {
            $account_id = (int)($_GET['account_id'] ?? 0);
            $letter_filter = isset($_GET['lettrage_filter']) ? $_GET['lettrage_filter'] : 'all';

            // 1. Opening Balance (Prior Years).
            //    For Class 6/7/8 accounts opening is zero by construction.
            $classRow = $pdo->prepare("
                SELECT SUBSTRING(oa.account_number, 1, 1) AS class_code
                  FROM chart_of_accounts ca
                  JOIN ohada_accounts oa ON ca.ohada_account_id = oa.id
                 WHERE ca.id = ?
            ");
            $classRow->execute([$account_id]);
            $classCode = (string)($classRow->fetchColumn() ?: '');

            $opening_balance = 0.0;
            if (!is_income_statement_class($classCode)) {
                $stmtOuv = $pdo->prepare("
                    SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
                      FROM journal_lines jl
                      JOIN journal_entries je ON jl.journal_entry_id = je.id
                     WHERE jl.account_id = ?
                       AND je.status = 'posted'
                       AND YEAR(je.date) < ?
                ");
                $stmtOuv->execute([$account_id, $year]);
                $opening_balance = (float)$stmtOuv->fetchColumn();
            }

            // 2. Fetch all movements for the selected year, optionally filtered
            //    by lettrage state. The three modes match the drawer UI.
            $filterClause = '';
            if ($letter_filter === 'lettered')   $filterClause = 'AND jl.lettrage IS NOT NULL';
            if ($letter_filter === 'unlettered') $filterClause = 'AND jl.lettrage IS NULL';

            $stmtMvt = $pdo->prepare("
                SELECT jl.id, je.date, je.journal_code, je.reference, je.description,
                       je.source_type, je.source_id,
                       jl.lettrage, jl.debit, jl.credit
                  FROM journal_lines jl
                  JOIN journal_entries je ON jl.journal_entry_id = je.id
                 WHERE jl.account_id = ?
                   AND je.status = 'posted'
                   AND YEAR(je.date) = ?
                   $filterClause
                 ORDER BY je.date ASC, je.id ASC, jl.id ASC
            ");
            $stmtMvt->execute([$account_id, $year]);
            $lines = $stmtMvt->fetchAll();

            // 3. Lettrage integrity per letter — for the small badge next to
            //    each letter in the UI. Balanced letters render green, open
            //    letters render amber (Σ D ≠ Σ C on that letter).
            $stmtLet = $pdo->prepare("
                SELECT jl.lettrage,
                       SUM(jl.debit)  AS sum_d,
                       SUM(jl.credit) AS sum_c
                  FROM journal_lines jl
                  JOIN journal_entries je ON jl.journal_entry_id = je.id
                 WHERE jl.account_id = ?
                   AND je.status = 'posted'
                   AND jl.lettrage IS NOT NULL
                 GROUP BY jl.lettrage
            ");
            $stmtLet->execute([$account_id]);
            $lettersRaw = $stmtLet->fetchAll();
            $letters = [];
            foreach ($lettersRaw as $l) {
                $letters[$l['lettrage']] = [
                    'sum_d'    => (float)$l['sum_d'],
                    'sum_c'    => (float)$l['sum_c'],
                    'balanced' => round((float)$l['sum_d'], 2) === round((float)$l['sum_c'], 2),
                ];
            }

            sendResponse('success', 'Grand Livre chargé', [
                'opening_balance' => $opening_balance,
                'lines'           => $lines,
                'letters'         => $letters,
            ]);
        }

        sendResponse('success', 'Données chargées', $response_data);

    } catch (PDOException $e) {
        error_log('review_controller.get: ' . $e->getMessage());
        sendResponse('error', 'Erreur base de données.');
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
        // ---------------------------------------------------------------------
        // SAVE LETTRAGE (Reconciliation Letter)
        // Accepts 1-3 uppercase Latin letters, or empty to clear.
        // Refuses reversed entries — a reversed entry's lettrage would
        // survive the reversal and confuse the balance.
        // ---------------------------------------------------------------------
        if ($action === 'save_lettrage') {
            $line_id = (int)($payload['line_id'] ?? 0);
            $raw     = trim((string)($payload['lettrage'] ?? ''));
            $letter  = strtoupper($raw);

            if ($line_id <= 0) sendResponse('error', 'Ligne invalide.');
            if ($letter !== '' && !preg_match('/^[A-Z]{1,3}$/', $letter)) {
                sendResponse('error', 'Lettrage: 1 à 3 lettres majuscules.');
            }

            // Guard against reversed / draft parents.
            $chk = $pdo->prepare("
                SELECT je.status
                  FROM journal_lines jl
                  JOIN journal_entries je ON jl.journal_entry_id = je.id
                 WHERE jl.id = ?
            ");
            $chk->execute([$line_id]);
            $status = $chk->fetchColumn();
            if ($status === false) sendResponse('error', 'Ligne introuvable.');
            if ($status !== 'posted') {
                sendResponse('error', 'Le lettrage ne s\'applique qu\'aux écritures postées.');
            }

            $store = $letter === '' ? null : $letter;
            $stmt = $pdo->prepare("UPDATE journal_lines SET lettrage = ? WHERE id = ?");
            $stmt->execute([$store, $line_id]);

            sendResponse('success', 'Lettrage mis à jour.');
        }

        // ---------------------------------------------------------------------
        // CLEAR LETTRAGE — remove all instances of a letter on an account.
        // Used by the drawer "Déletttrer" button, and by auto_lettrage when
        // the accountant undoes a proposed match.
        // ---------------------------------------------------------------------
        if ($action === 'clear_lettrage') {
            $account_id = (int)($payload['account_id'] ?? 0);
            $letter     = strtoupper(trim((string)($payload['lettrage'] ?? '')));
            if ($account_id <= 0 || !preg_match('/^[A-Z]{1,3}$/', $letter)) {
                sendResponse('error', 'Paramètres invalides.');
            }
            $stmt = $pdo->prepare("UPDATE journal_lines SET lettrage = NULL WHERE account_id = ? AND lettrage = ?");
            $stmt->execute([$account_id, $letter]);
            sendResponse('success', 'Lettre effacée.', ['cleared' => $stmt->rowCount()]);
        }

        // ---------------------------------------------------------------------
        // AUTO-LETTRAGE — propose exact-amount matches.
        // Heuristic: for the target account, group unlettered lines by
        // rounded amount. Whenever a group has as many debits as credits
        // (single pair or 2-vs-2), assign the next free letter to all
        // members of the group. Amounts that pair only partially, or
        // that need N-to-M splits, stay unlettered — the accountant
        // finishes those by hand.
        // ---------------------------------------------------------------------
        if ($action === 'auto_lettrage') {
            $account_id = (int)($payload['account_id'] ?? 0);
            $year       = (int)($payload['year'] ?? date('Y'));
            $dry_run    = !empty($payload['dry_run']);
            if ($account_id <= 0) sendResponse('error', 'Compte invalide.');

            // Pull unlettered movements for the year on that account.
            $stmt = $pdo->prepare("
                SELECT jl.id, jl.debit, jl.credit
                  FROM journal_lines jl
                  JOIN journal_entries je ON jl.journal_entry_id = je.id
                 WHERE jl.account_id = ?
                   AND je.status = 'posted'
                   AND YEAR(je.date) = ?
                   AND jl.lettrage IS NULL
            ");
            $stmt->execute([$account_id, $year]);
            $lines = $stmt->fetchAll();

            // Bucket by rounded amount + side.
            $debits  = [];
            $credits = [];
            foreach ($lines as $l) {
                $d = (float)$l['debit'];
                $c = (float)$l['credit'];
                if ($d > 0) $debits[(string)round($d, 2)][]  = (int)$l['id'];
                if ($c > 0) $credits[(string)round($c, 2)][] = (int)$l['id'];
            }

            // Find the next free letter on this account (A, B … Z, AA …).
            $usedStmt = $pdo->prepare("
                SELECT DISTINCT lettrage FROM journal_lines
                 WHERE account_id = ? AND lettrage IS NOT NULL
            ");
            $usedStmt->execute([$account_id]);
            $used = array_flip(array_column($usedStmt->fetchAll(), 'lettrage'));

            $letterGenerator = function() use (&$used) {
                // A..Z, AA..ZZ, AAA..ZZZ — plenty for a single account.
                for ($len = 1; $len <= 3; $len++) {
                    $count = (int)pow(26, $len);
                    for ($n = 0; $n < $count; $n++) {
                        $s = ''; $x = $n;
                        for ($i = 0; $i < $len; $i++) { $s = chr(65 + ($x % 26)) . $s; $x = intdiv($x, 26); }
                        if (!isset($used[$s])) { $used[$s] = true; return $s; }
                    }
                }
                return null;
            };

            $matches = [];
            foreach ($debits as $amt => $dIds) {
                if (empty($credits[$amt])) continue;
                $cIds = $credits[$amt];
                $pairs = min(count($dIds), count($cIds));
                for ($i = 0; $i < $pairs; $i++) {
                    $letter = $letterGenerator();
                    if ($letter === null) break 2;
                    $matches[] = [
                        'letter' => $letter,
                        'amount' => (float)$amt,
                        'line_ids' => [$dIds[$i], $cIds[$i]],
                    ];
                }
            }

            if (!$dry_run && $matches) {
                $pdo->beginTransaction();
                try {
                    $upd = $pdo->prepare("UPDATE journal_lines SET lettrage = ? WHERE id = ?");
                    foreach ($matches as $m) {
                        foreach ($m['line_ids'] as $lid) {
                            $upd->execute([$m['letter'], $lid]);
                        }
                    }
                    $pdo->commit();
                } catch (Throwable $t) {
                    $pdo->rollBack();
                    throw $t;
                }
            }

            sendResponse('success', 'Lettrage automatique préparé.', [
                'matches' => $matches,
                'applied' => !$dry_run,
                'count'   => count($matches),
            ]);
        }

        sendResponse('error', 'Action non reconnue.');

    } catch (Exception $e) {
        error_log('review_controller.post: ' . $e->getMessage());
        sendResponse('error', 'Erreur lors de la mise à jour.');
    }
} else {
    sendResponse('error', 'Méthode HTTP non autorisée.');
}
