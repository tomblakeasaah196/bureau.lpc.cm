<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/executive_summary_data.php';   // Sprint 7A D2
require_once __DIR__ . '/../../includes/functions/lpc_csv.php';                  // Sprint 7A D3
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('accounting.reports.view');
/**
 * CONTROLLER: États Financiers & Clôture (Financial Statements API)
 * DESCRIPTION: Generates Bilan, Compte de Résultat, Vue Dirigeant, PDF export
 *              (Sprint 7A), and executes Year-End Close.
 * METHOD: STRICT REST-like JSON API (GET / POST)
 */
$user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['user_role'] ?? null;

// Database Connection
try {
    require_once '../../includes/config/db.php';
    require_once '../../includes/classes/Database.php';
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
    exit;
}

function sendResponse($status, $message, $data = null) {
    // Emit the JSON header lazily so non-JSON responders (PDF stream) can
    // pre-set their own Content-Type without a duplicate header warning.
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_REQUEST['action'] ?? null;

// ==========================================
// HELPER: Core Aggregation Engine
// ==========================================
function getFinancialAggregates($pdo, $year) {
    // 1. Fetch all report lines and mappings
    $lines = $pdo->query("SELECT * FROM financial_report_lines ORDER BY display_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    $mappings = $pdo->query("SELECT * FROM financial_mappings")->fetchAll(PDO::FETCH_ASSOC);

    // 2. We need cumulative balances for the Bilan (Class 1-5) and periodic for Résultat (Class 6-8)
    // To optimize, we pull ALL approved journal lines for the relevant years into PHP memory for rapid sorting.
    // ? positional placeholders — the earlier :yr_n / :yr_n1 named form was
    // rejected by MySQL under real prepared statements (SQLSTATE HY093) because
    // each name appeared four times. Positional placeholders can be bound
    // repeatedly, so we bind the same year value in the order the query needs it.
    $stmt = $pdo->prepare("
        SELECT
            ca.code as account_code,
            SUBSTRING(ca.code, 1, 1) as class,
            SUM(CASE WHEN YEAR(je.date) <= ? THEN jl.debit ELSE 0 END) as cum_debit_n,
            SUM(CASE WHEN YEAR(je.date) <= ? THEN jl.credit ELSE 0 END) as cum_credit_n,
            SUM(CASE WHEN YEAR(je.date) = ?  THEN jl.debit ELSE 0 END) as per_debit_n,
            SUM(CASE WHEN YEAR(je.date) = ?  THEN jl.credit ELSE 0 END) as per_credit_n,
            SUM(CASE WHEN YEAR(je.date) <= ? THEN jl.debit ELSE 0 END) as cum_debit_n1,
            SUM(CASE WHEN YEAR(je.date) <= ? THEN jl.credit ELSE 0 END) as cum_credit_n1,
            SUM(CASE WHEN YEAR(je.date) = ?  THEN jl.debit ELSE 0 END) as per_debit_n1,
            SUM(CASE WHEN YEAR(je.date) = ?  THEN jl.credit ELSE 0 END) as per_credit_n1
        FROM chart_of_accounts ca
        JOIN journal_lines jl ON ca.id = jl.account_id
        JOIN journal_entries je ON jl.journal_entry_id = je.id AND je.status = 'approved'
        GROUP BY ca.id
    ");
    $yn  = $year;
    $yn1 = $year - 1;
    $stmt->execute([$yn, $yn, $yn, $yn, $yn1, $yn1, $yn1, $yn1]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Initialize the Result Structure
    $results = ['bilan_actif' => [], 'bilan_passif' => [], 'resultat' => []];
    $unmapped = [];
    
    // Setup empty line buckets
    $buckets = [];
    foreach ($lines as $line) {
        $buckets[$line['code']] = [
            'id' => $line['id'], 'code' => $line['code'], 'name' => $line['name'], 
            'type' => $line['report_type'], 'sig_category' => $line['sig_category'],
            'brut_N' => 0, 'amort_N' => 0, 'net_N' => 0, 'net_N_1' => 0
        ];
    }

    // 4. The Sorting Hat (Map Accounts to Buckets)
    foreach ($accounts as $acc) {
        $matched = false;
        foreach ($mappings as $map) {
            if (strpos($acc['account_code'], $map['account_prefix']) === 0) {
                // Find the target bucket
                $line_code = null;
                foreach ($buckets as $code => $b) {
                    if ($b['id'] == $map['report_line_id']) { $line_code = $code; break; }
                }
                
                if ($line_code) {
                    $matched = true;
                    $b = &$buckets[$line_code];
                    $is_bilan = in_array($acc['class'], ['1','2','3','4','5']);
                    
                    // Logic: Bilan uses Cumulative (cum_), Resultat uses Periodic (per_)
                    $deb_n = $is_bilan ? $acc['cum_debit_n'] : $acc['per_debit_n'];
                    $cre_n = $is_bilan ? $acc['cum_credit_n'] : $acc['per_credit_n'];
                    $deb_n1 = $is_bilan ? $acc['cum_debit_n1'] : $acc['per_debit_n1'];
                    $cre_n1 = $is_bilan ? $acc['cum_credit_n1'] : $acc['per_credit_n1'];

                    $net_n = $deb_n - $cre_n;
                    $net_n1 = $deb_n1 - $cre_n1;

                    // OHADA Signs adjustment
                    if ($b['type'] == 'bilan_passif' || ($b['type'] == 'resultat' && $acc['class'] == '7')) {
                        $net_n = -$net_n; $net_n1 = -$net_n1;
                    }

                    // For Actif: 3-Column logic (Answer 7A)
                    if ($b['type'] == 'bilan_actif') {
                        if ($map['operator'] == '+') { $b['brut_N'] += $net_n; } 
                        else { $b['amort_N'] += abs($net_n); } // Amortissements are shown positive in the Amort column
                        $b['net_N'] = $b['brut_N'] - $b['amort_N'];
                        
                        // N-1 Net approximation for Actif
                        if ($map['operator'] == '+') { $b['net_N_1'] += $net_n1; } else { $b['net_N_1'] -= abs($net_n1); }
                    } else {
                        // Passif and Resultat
                        if ($map['operator'] == '+') { $b['net_N'] += $net_n; $b['net_N_1'] += $net_n1; } 
                        else { $b['net_N'] -= $net_n; $b['net_N_1'] -= $net_n1; }
                    }
                    break; // Move to next account once mapped
                }
            }
        }
        
        // Unmapped Anomaly Catching (Answer 4B)
        if (!$matched) {
            $is_bilan = in_array($acc['class'], ['1','2','3','4','5']);
            $net_n = $is_bilan ? ($acc['cum_debit_n'] - $acc['cum_credit_n']) : ($acc['per_debit_n'] - $acc['per_credit_n']);
            if (abs($net_n) > 0) {
                if ($acc['class'] == '1' || $acc['class'] == '4' && $net_n < 0) { $buckets['AUT_PAS']['net_N'] += abs($net_n); }
                else if ($is_bilan) { $buckets['AUT_ACT']['brut_N'] += $net_n; $buckets['AUT_ACT']['net_N'] += $net_n; }
                else { $buckets['AUT_CH']['net_N'] += abs($net_n); }
            }
        }
    }

    // 5. Structure Final Arrays
    foreach ($buckets as $code => $b) {
        if ($b['type'] == 'bilan_actif') $results['bilan_actif'][] = $b;
        if ($b['type'] == 'bilan_passif') $results['bilan_passif'][] = $b;
        if ($b['type'] == 'resultat') $results['resultat'][] = $b;
    }

    return $results;
}


// ==========================================
// [GET] READ OPERATIONS
// ==========================================
if ($method === 'GET') {
    if ($action === 'generate_reports') {
        $year = (int)$_GET['year'];
        
        try {
            // 1. Check Year Status
            $stmt = $pdo->prepare("SELECT status FROM financial_years WHERE year = ?");
            $stmt->execute([$year]);
            $year_status = $stmt->fetchColumn() ?: 'open';

            // 2. Get Aggregates
            $data = getFinancialAggregates($pdo, $year);

            // 3. Calculate Net Result dynamically
            $total_produits_N = 0; $total_charges_N = 0;
            $total_produits_N_1 = 0; $total_charges_N_1 = 0;
            
            foreach ($data['resultat'] as $r) {
                // Products are mapped as positive, Charges as negative in our previous step logic for Resultat. 
                // Let's rely on standard Revenue = class 7, Expense = class 6.
                // In our engine, we made Revenue positive and Expenses negative for pure 'Net' calculation.
                $total_produits_N += $r['net_N']; // Actually Net N already considers sign
                $total_produits_N_1 += $r['net_N_1'];
            }
            $data['resultat_net_N'] = $total_produits_N;
            $data['resultat_net_N_1'] = $total_produits_N_1;

            // 4. Tax Simulation — dynamic, driven by company_tax_settings
            //    (see includes/functions/tax_simulation.php). Fully wraps the
            //    old hardcoded (IS 30% vs AIR 5.5%) block so the caller always
            //    gets a well-shaped `tax_simulation` — even if the settings row
            //    is missing (in which case defaults kick in AND we surface it
            //    in the `label` so the accountant knows to configure the entity).
            require_once __DIR__ . '/../../includes/functions/tax_simulation.php';
            try {
                $data['tax_simulation'] = build_tax_simulation($pdo, $year, $data['resultat']);
            } catch (Throwable $tx) {
                // Never let the tax block break generate_reports. Emit a
                // neutral placeholder so the frontend can show "Indisponible".
                error_log('tax_simulation error: ' . $tx->getMessage());
                $data['tax_simulation'] = [
                    'regime'   => 'unknown',
                    'label'    => "Simulation IS indisponible",
                    'cit_rate' => 0, 'air_rate' => 0,
                    'is_on_profit' => 0, 'is_minimum' => 0, 'is_due' => 0,
                    'air_paid' => 0, 'air_withheld' => 0,
                    'error'    => true,
                ];
            }

            $data['year_status'] = $year_status;

            sendResponse('success', 'États financiers générés', $data);

        } catch (Exception $e) {
            sendResponse('error', $e->getMessage());
        }
    }

    if ($action === 'drilldown') {
        $code = $_GET['code'];
        $year = (int)$_GET['year'];

        try {
            // Get mappings for this line code
            $stmtM = $pdo->prepare("SELECT account_prefix FROM financial_mappings fm JOIN financial_report_lines frl ON fm.report_line_id = frl.id WHERE frl.code = ?");
            $stmtM->execute([$code]);
            $prefixes = $stmtM->fetchAll(PDO::FETCH_COLUMN);

            if (empty($prefixes)) sendResponse('success', '', []);

            // Build LIKE query
            $likes = []; $params = [];
            foreach ($prefixes as $p) {
                $likes[] = "ca.code LIKE ?";
                $params[] = $p . "%";
            }
            $wherePrefix = "(" . implode(" OR ", $likes) . ")";
            
            // Determine if Bilan or Resultat to decide Cumulative vs Periodic
            $stmtT = $pdo->prepare("SELECT report_type FROM financial_report_lines WHERE code = ?");
            $stmtT->execute([$code]);
            $type = $stmtT->fetchColumn();
            
            $dateClause = ($type === 'resultat') ? "YEAR(je.date) = ?" : "YEAR(je.date) <= ?";
            $params[] = $year;

            $sql = "
                SELECT ca.code as account_code, ca.name as account_name, 
                       SUM(jl.debit) as debit, SUM(jl.credit) as credit
                FROM chart_of_accounts ca
                JOIN journal_lines jl ON ca.id = jl.account_id
                JOIN journal_entries je ON jl.journal_entry_id = je.id AND je.status = 'approved'
                WHERE $wherePrefix AND $dateClause
                GROUP BY ca.id
                HAVING debit > 0 OR credit > 0
                ORDER BY ca.code ASC
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            sendResponse('success', 'Drilldown data', $stmt->fetchAll(PDO::FETCH_ASSOC));

        } catch (Exception $e) {
            sendResponse('error', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // SPRINT 7A · D2 — Vue Dirigeant executive summary
    // Single-call aggregate returned as a pure numeric envelope so both the
    // browser view and the dompdf template can render from the same shape.
    // ------------------------------------------------------------------
    if ($action === 'vue_dirigeant') {
        $year = (int)($_GET['year'] ?? date('Y'));
        try {
            $data = fetch_executive_summary($pdo, $year);
            sendResponse('success', 'Vue Dirigeant loaded', $data);
        } catch (Throwable $e) {
            error_log('vue_dirigeant: ' . $e->getMessage());
            sendResponse('error', "Erreur d'agrégation Vue Dirigeant.");
        }
    }

    // ------------------------------------------------------------------
    // SPRINT 7A · D3 — Server-side PDF export (bilan / résultat / both)
    // Requires accounting.reports.export in addition to the view perm the
    // top-of-file check already enforces.
    // ------------------------------------------------------------------
    if ($action === 'export_pdf') {
        if (!Rbac::hasPermission('accounting.reports.export')) {
            http_response_code(403);
            sendResponse('error', "Permission requise : accounting.reports.export.");
        }
        $year = (int)($_GET['year'] ?? date('Y'));
        $kind = strtolower((string)($_GET['kind'] ?? 'both'));
        if (!in_array($kind, ['bilan', 'resultat', 'both', 'executive'], true)) $kind = 'both';

        try {
            $sections = [];
            if ($kind === 'executive') {
                // D2 · Vue Dirigeant single-page executive summary.
                $data = fetch_executive_summary($pdo, $year);
                ob_start();
                include __DIR__ . '/../../includes/pdf_templates/executive_summary.php';
                $sections[] = ob_get_clean();
            } else {
                // D3 · Bilan / Résultat exports.
                $data = getFinancialAggregates($pdo, $year);
                $net_N = 0; $net_N_1 = 0;
                foreach ($data['resultat'] as $r) { $net_N += $r['net_N']; $net_N_1 += $r['net_N_1']; }
                $data['resultat_net_N']   = $net_N;
                $data['resultat_net_N_1'] = $net_N_1;
                $data['year']             = $year;
                $data['generated_at']     = date('Y-m-d H:i:s');

                if ($kind === 'bilan' || $kind === 'both') {
                    ob_start();
                    include __DIR__ . '/../../includes/pdf_templates/bilan_export.php';
                    $sections[] = ob_get_clean();
                }
                if ($kind === 'resultat' || $kind === 'both') {
                    ob_start();
                    include __DIR__ . '/../../includes/pdf_templates/resultat_export.php';
                    $sections[] = ob_get_clean();
                }
            }
            // Join with a page break between sections when both are rendered.
            $html = implode('<div style="page-break-before: always;"></div>', $sections);

            require_once __DIR__ . '/../../includes/classes/PdfRenderer.php';
            $bytes = PdfRenderer::fromHtml($html, [
                'paper'       => 'A4',
                'orientation' => 'portrait',
                'footer_html' => '__page_counter__',
            ]);

            $filename = "LPC_{$kind}_{$year}.pdf";
            if (!headers_sent()) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($bytes));
                header('Cache-Control: private, no-store, max-age=0');
                header('X-Content-Type-Options: nosniff');
            }
            echo $bytes;
            exit;
        } catch (Throwable $e) {
            error_log('export_pdf: ' . $e->getMessage());
            sendResponse('error', 'Erreur PDF : ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // SPRINT 7A · D3 — CSV export (bilan / résultat / both) as an "Excel"
    // substitute (XLSX pushed to phase 2 — see note in the JS bookmark).
    // ------------------------------------------------------------------
    if ($action === 'export_csv') {
        if (!Rbac::hasPermission('accounting.reports.export')) {
            http_response_code(403);
            sendResponse('error', "Permission requise : accounting.reports.export.");
        }
        $year = (int)($_GET['year'] ?? date('Y'));
        $kind = strtolower((string)($_GET['kind'] ?? 'both'));
        if (!in_array($kind, ['bilan', 'resultat', 'both'], true)) $kind = 'both';

        try {
            $data = getFinancialAggregates($pdo, $year);

            $rows = [];
            if ($kind === 'bilan' || $kind === 'both') {
                $rows[] = ["--- BILAN {$year} ---", '', '', '', ''];
                $rows[] = ['Section', 'Rubrique', 'Brut N', 'Net N', 'Net N-1'];
                foreach ($data['bilan_actif'] as $l) {
                    $rows[] = ['Actif', $l['name'], (int)round($l['brut_N']), (int)round($l['net_N']), (int)round($l['net_N_1'])];
                }
                foreach ($data['bilan_passif'] as $l) {
                    $rows[] = ['Passif', $l['name'], '', (int)round($l['net_N']), (int)round($l['net_N_1'])];
                }
            }
            if ($kind === 'resultat' || $kind === 'both') {
                $rows[] = ["--- COMPTE DE RÉSULTAT {$year} ---", '', '', '', ''];
                $rows[] = ['Section', 'Rubrique', 'Op.', 'Net N', 'Net N-1'];
                foreach ($data['resultat'] as $l) {
                    $rows[] = [$l['sig_category'] ?? '', $l['name'], $l['operator'] ?? '+', (int)round($l['net_N']), (int)round($l['net_N_1'])];
                }
            }
            lpc_csv_stream(
                ['Section', 'Rubrique', 'Col1', 'Net N', 'Net N-1'],
                $rows,
                "LPC_{$kind}_{$year}.csv"
            );
            exit;
        } catch (Throwable $e) {
            error_log('export_csv: ' . $e->getMessage());
            sendResponse('error', 'Erreur CSV : ' . $e->getMessage());
        }
    }
}

// ==========================================
// SPRINT 8B · GET — TFT, Notes Annexes, Closing state
// ==========================================
if ($method === 'GET' && $action === 'closing_state') {
    require_once __DIR__ . '/../../includes/classes/YearEndClosing.php';
    $year = (int)($_GET['year'] ?? date('Y'));
    $engine = new YearEndClosing($pdo, (int)($user_id ?? 0));
    $state = $engine->getState($year);
    $adj = $pdo->prepare(
        "SELECT id, kind, target_ref, amount, description, journal_entry_id,
                reversal_journal_entry_id, status, created_at
           FROM year_end_adjustments WHERE fiscal_year = ? ORDER BY created_at DESC"
    );
    $adj->execute([$year]);
    $state['adjustments'] = $adj->fetchAll(PDO::FETCH_ASSOC);
    $state['preflight']   = $engine->preflight($year);
    sendResponse('success', 'closing state', $state);
}

if ($method === 'GET' && $action === 'tft') {
    require_once __DIR__ . '/../../includes/functions/tft_engine.php';
    $year = (int)($_GET['year'] ?? date('Y'));
    try {
        $data = build_tft($pdo, $year);
        sendResponse('success', 'TFT', $data);
    } catch (Throwable $e) {
        error_log('tft error: ' . $e->getMessage());
        sendResponse('error', 'Erreur TFT : ' . $e->getMessage());
    }
}

if ($method === 'GET' && $action === 'notes_annexes') {
    require_once __DIR__ . '/../../includes/functions/notes_annexes.php';
    $year = (int)($_GET['year'] ?? date('Y'));
    try {
        $data = build_notes_annexes($pdo, $year);
        sendResponse('success', 'Notes annexes', $data);
    } catch (Throwable $e) {
        error_log('notes error: ' . $e->getMessage());
        sendResponse('error', 'Erreur Notes : ' . $e->getMessage());
    }
}

if ($method === 'GET' && $action === 'export_notes_xlsx') {
    if (!Rbac::hasPermission('accounting.reports.export')) {
        http_response_code(403);
        sendResponse('error', 'Permission requise : accounting.reports.export.');
    }
    require_once __DIR__ . '/../../includes/functions/notes_annexes.php';
    $year = (int)($_GET['year'] ?? date('Y'));
    try {
        $data = build_notes_annexes($pdo, $year);
        $filename = "LPC_Notes_Annexes_{$year}.xlsx";
        // Prefer PhpSpreadsheet when available; otherwise fall back to a
        // native SpreadsheetML 2003 XML workbook (opens in every Excel/LO
        // release since 2003) so a fresh install still produces a real .xlsx
        // download instead of a 500.
        export_notes_xlsx($data, $filename);
        exit;
    } catch (Throwable $e) {
        error_log('notes xlsx error: ' . $e->getMessage());
        sendResponse('error', 'Erreur export Notes : ' . $e->getMessage());
    }
}

// ==========================================
// [POST] WRITE OPERATIONS (CLÔTURE)
// ==========================================
if ($method === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!$payload || !isset($payload['action'])) sendResponse('error', 'Payload invalide.');

    $action = $payload['action'];

    // ------------------------------------------------------------------
    // SPRINT 8B · Clôture wizard — phased actions
    //   - closing_transition:  { year, to, note }
    //   - closing_run_phase:   { year, phase } where phase in
    //       depreciation | is_provision | profit_incorporation
    //   - closing_reverse_adj: { adjustment_id }
    // Backward-compat: 'close_year' is kept and now delegates to the engine's
    // full sequence: transition → depreciation → is_provision →
    // profit_incorporation → provisional_closed → A-Nouveaux → final_closed.
    // ------------------------------------------------------------------
    if ($action === 'closing_transition') {
        if (!Rbac::hasPermission('accounting.closing.run')) sendResponse('error', 'Permission requise.');
        require_once __DIR__ . '/../../includes/classes/YearEndClosing.php';
        try {
            $engine = new YearEndClosing($pdo, (int)$user_id);
            $to = $engine->transition((int)$payload['year'], (string)$payload['to'], $payload['note'] ?? null);
            sendResponse('success', "État: $to", ['state' => $to]);
        } catch (Throwable $e) { sendResponse('error', $e->getMessage()); }
    }
    if ($action === 'closing_run_phase') {
        if (!Rbac::hasPermission('accounting.closing.run')) sendResponse('error', 'Permission requise.');
        require_once __DIR__ . '/../../includes/classes/YearEndClosing.php';
        $engine = new YearEndClosing($pdo, (int)$user_id);
        $year = (int)$payload['year']; $phase = (string)$payload['phase'];
        try {
            $n = 0;
            switch ($phase) {
                case 'depreciation':          $n = $engine->runDepreciation($year); break;
                case 'is_provision':          $n = $engine->runIsProvision($year); break;
                case 'profit_incorporation':  $n = $engine->runProfitIncorporation($year); break;
                default: sendResponse('error', "Phase inconnue: $phase");
            }
            sendResponse('success', "$n écritures générées", ['created' => $n, 'phase' => $phase]);
        } catch (Throwable $e) { sendResponse('error', $e->getMessage()); }
    }
    if ($action === 'closing_reverse_adj') {
        if (!Rbac::hasPermission('accounting.closing.run')) sendResponse('error', 'Permission requise.');
        require_once __DIR__ . '/../../includes/classes/YearEndClosing.php';
        try {
            $engine = new YearEndClosing($pdo, (int)$user_id);
            $revId = $engine->reverseAdjustment((int)$payload['adjustment_id']);
            sendResponse('success', 'Extourne posté', ['reversal_journal_entry_id' => $revId]);
        } catch (Throwable $e) { sendResponse('error', $e->getMessage()); }
    }

    if ($action === 'close_year') {
        if ($user_role !== 'admin') sendResponse('error', 'Seul un Administrateur peut clôturer un exercice.');
        $year = (int)$payload['year'];
        $next_year = $year + 1;

        try {
            $pdo->beginTransaction();

            // 1. Check if already closed
            $stmt = $pdo->prepare("SELECT status FROM financial_years WHERE year = ? FOR UPDATE");
            $stmt->execute([$year]);
            $status = $stmt->fetchColumn();
            if ($status === 'closed') throw new Exception("L'exercice $year est déjà clôturé.");

            // 2. Fetch Aggregates to calculate A-Nouveaux (Opening Balances)
            $data = getFinancialAggregates($pdo, $year);

            // 3. Create A-Nouveaux Journal Entry for Jan 1 of next year
            $ref = "AN-$next_year";
            $desc = "A-Nouveaux (Reports d'ouverture exercice $next_year)";
            
            $stmtH = $pdo->prepare("INSERT INTO journal_entries (reference, journal_code, date, description, status, created_by, approved_by) VALUES (?, 'OD', ?, ?, 'approved', ?, ?)");
            $stmtH->execute([$ref, "$next_year-01-01", $desc, $user_id, $user_id]);
            $an_entry_id = $pdo->lastInsertId();

            $stmtL = $pdo->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");

            // Loop through all Class 1-5 accounts from Year N and move them to Year N+1
            $stmtAcc = $pdo->prepare("
                SELECT ca.id, SUM(jl.debit - jl.credit) as net
                FROM chart_of_accounts ca
                JOIN journal_lines jl ON ca.id = jl.account_id
                JOIN journal_entries je ON jl.journal_entry_id = je.id AND je.status = 'approved'
                WHERE SUBSTRING(ca.code, 1, 1) IN ('1','2','3','4','5') AND YEAR(je.date) <= ?
                GROUP BY ca.id
                HAVING net != 0
            ");
            $stmtAcc->execute([$year]);
            $balances = $stmtAcc->fetchAll();

            foreach ($balances as $b) {
                $deb = $b['net'] > 0 ? $b['net'] : 0;
                $cre = $b['net'] < 0 ? abs($b['net']) : 0;
                $stmtL->execute([$an_entry_id, $b['id'], $deb, $cre]);
            }

            // 4. Incorporate the Net Result (Profit or Loss) into Class 13
            $net_result = 0;
            foreach ($data['resultat'] as $r) { $net_result += $r['net_N']; }

            if (abs($net_result) > 0) {
                // Find account 131 (Profit) or 139 (Loss)
                $target_code = $net_result > 0 ? '131%' : '139%';
                $stmt13 = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE code LIKE ? LIMIT 1");
                $stmt13->execute([$target_code]);
                $acc_13 = $stmt13->fetchColumn();
                
                if ($acc_13) {
                    // Profit is a credit balance. Loss is a debit balance.
                    $deb = $net_result < 0 ? abs($net_result) : 0;
                    $cre = $net_result > 0 ? $net_result : 0;
                    $stmtL->execute([$an_entry_id, $acc_13, $deb, $cre]);
                }
            }

            // 5. Lock the Year
            $stmtClose = $pdo->prepare("
                INSERT INTO financial_years (year, status, opening_balance_journal_id, locked_by, locked_at) 
                VALUES (?, 'closed', ?, ?, CURRENT_TIMESTAMP) 
                ON DUPLICATE KEY UPDATE status = 'closed', locked_by = ?, locked_at = CURRENT_TIMESTAMP
            ");
            $stmtClose->execute([$year, $an_entry_id, $user_id, $user_id]);

            // Open the Next Year
            $pdo->prepare("INSERT IGNORE INTO financial_years (year, status) VALUES (?, 'open')")->execute([$next_year]);

            $pdo->commit();
            sendResponse('success', "EXERCICE $year CLÔTURÉ AVEC SUCCÈS. Les A-Nouveaux ont été transférés sur $next_year.");

        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sendResponse('error', $e->getMessage());
        }
    } else {
        sendResponse('error', 'Action inconnue.');
    }
} else {
    sendResponse('error', 'Méthode HTTP non autorisée.');
}