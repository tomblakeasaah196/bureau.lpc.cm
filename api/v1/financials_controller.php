<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/executive_summary_data.php';   // Sprint 7A D2
require_once __DIR__ . '/../../includes/functions/lpc_csv.php';                  // Sprint 7A D3
require_once __DIR__ . '/../../includes/functions/books_period.php';              // Comptabilité 3 (13.5)
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
/**
 * Bilan and Compte de Résultat from the ledger (VIDEO_QA_FINDINGS 13, QA on
 * copy A, 18/11/2026). What it used to get wrong, and does no more:
 *   · `$b = &$buckets[...]` followed by `foreach ($buckets as … => $b)`: the
 *     reference made every later loop overwrite a bucket, so every account ended
 *     in « Anomalies/Non-mappés » and the Bilan never balanced;
 *   · the first mapping in table order won, not the longest prefix (89 vs 8);
 *   · a client, supplier or State account (class 4) sat on the side its prefix
 *     said, whatever its balance: 4424 « AIR à récupérer » and 4452 counted as
 *     debts. SYSCOHADA classes a third-party account by its balance;
 *   · an unmapped revenue was added as a charge (abs());
 *   · a balance « at the end of N » summed every entry up to N, so once a year
 *     was closed its À-nouveaux counted the history twice (lpc_books_start_year);
 *   · the result of an earlier year never closed was missing from the Bilan.
 * Every account lands in exactly one line, so Actif = Passif + Résultat to the
 * franc. $out['by_line'] lists the accounts behind each line (the drill-down).
 */
function getFinancialAggregates($pdo, $year) {
    $lines = $pdo->query("SELECT * FROM financial_report_lines ORDER BY display_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    $mappings = $pdo->query("SELECT fm.*, frl.code AS line_code FROM financial_mappings fm
                               JOIN financial_report_lines frl ON frl.id = fm.report_line_id
                              WHERE fm.deleted_at IS NULL
                              ORDER BY LENGTH(fm.account_prefix) DESC, fm.account_prefix ASC")->fetchAll(PDO::FETCH_ASSOC);

    [$cumN,  $pN]  = lpc_books_cumulative_where($pdo, (int) $year);
    [$cumN1, $pN1] = lpc_books_cumulative_where($pdo, (int) $year - 1);
    $stmt = $pdo->prepare("
        SELECT ca.code AS account_code, ca.name AS account_name, SUBSTRING(ca.code, 1, 1) AS class,
               SUM(CASE WHEN $cumN  THEN jl.debit - jl.credit ELSE 0 END) AS cum_n,
               SUM(CASE WHEN YEAR(je.date) = ? THEN jl.debit - jl.credit ELSE 0 END) AS per_n,
               SUM(CASE WHEN $cumN1 THEN jl.debit - jl.credit ELSE 0 END) AS cum_n1,
               SUM(CASE WHEN YEAR(je.date) = ? THEN jl.debit - jl.credit ELSE 0 END) AS per_n1,
               SUM(CASE WHEN $cumN  AND YEAR(je.date) < ? THEN jl.debit - jl.credit ELSE 0 END) AS prior_n,
               SUM(CASE WHEN $cumN1 AND YEAR(je.date) < ? THEN jl.debit - jl.credit ELSE 0 END) AS prior_n1
          FROM chart_of_accounts ca
          JOIN journal_lines jl ON ca.id = jl.account_id
          JOIN journal_entries je ON jl.journal_entry_id = je.id AND je.status = 'posted'
         GROUP BY ca.id, ca.code, ca.name
    ");
    $stmt->execute(array_merge($pN, [(int) $year], $pN1, [(int) $year - 1], $pN, [(int) $year], $pN1, [(int) $year - 1]));
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $buckets = [];
    foreach ($lines as $line) {
        $buckets[$line['code']] = [
            'id' => $line['id'], 'code' => $line['code'], 'name' => $line['name'],
            'type' => $line['report_type'], 'sig_category' => $line['sig_category'],
            'brut_N' => 0, 'amort_N' => 0, 'net_N' => 0, 'net_N_1' => 0, 'operator' => '+',
        ];
    }
    // A line's nature for the « (+) / (-) » column: a charge line reads (-).
    foreach ($mappings as $m) {
        if (isset($buckets[$m['line_code']]) && $buckets[$m['line_code']]['type'] === 'resultat' && $m['account_prefix'][0] === '6') {
            $buckets[$m['line_code']]['operator'] = '-';
        }
    }
    $byLine = [];

    // Which line an account goes to for one period, given its (debit − credit).
    $place = function (array $acc, float $net) use ($mappings): array {
        $code = (string) $acc['account_code'];
        $map = null;
        foreach ($mappings as $m) {
            if (strpos($code, (string) $m['account_prefix']) === 0) { $map = $m; break; }
        }
        $cls = $acc['class'];
        if ($cls === '4' && strpos($code, '49') !== 0) {
            return [$net >= 0 ? 'CREANCES' : 'PAS_CIRC', '+'];
        }
        if ($map) return [$map['line_code'], $map['operator']];
        if (in_array($cls, ['1', '2', '3', '4', '5'], true)) return [$net >= 0 ? 'AUT_ACT' : 'AUT_PAS', '+'];
        return ['AUT_CH', '+'];
    };
    $add = function (string $lineCode, string $op, float $net, string $col) use (&$buckets) {
        if (!isset($buckets[$lineCode])) return;
        $b = $buckets[$lineCode];
        if ($b['type'] === 'bilan_actif') {
            if ($col === 'N') {
                if ($op === '+') $b['brut_N'] += $net; else $b['amort_N'] += -$net;
                $b['net_N'] = $b['brut_N'] - $b['amort_N'];
            } else {
                $b['net_N_1'] += $net;
            }
        } else {
            // Passif: credit balances count positive. Résultat: products
            // positive, charges negative, whatever the mapping's operator.
            if ($col === 'N') $b['net_N'] += -$net; else $b['net_N_1'] += -$net;
        }
        $buckets[$lineCode] = $b;
    };

    $priorResult = ['N' => 0.0, 'N_1' => 0.0];
    foreach ($accounts as $acc) {
        $isBilan = in_array($acc['class'], ['1', '2', '3', '4', '5'], true);
        $netN  = round((float) ($isBilan ? $acc['cum_n']  : $acc['per_n']), 2);
        $netN1 = round((float) ($isBilan ? $acc['cum_n1'] : $acc['per_n1']), 2);
        if (!$isBilan) {
            // A result account's movements of earlier years still open (never
            // closed into À-nouveaux) are the « résultats antérieurs » in equity.
            $priorResult['N']   += (float) $acc['prior_n'];
            $priorResult['N_1'] += (float) $acc['prior_n1'];
        }
        if (abs($netN) >= 0.005) {
            [$lc, $op] = $place($acc, $netN);
            $add($lc, $op, $netN, 'N');
            $byLine[$lc][] = ['account_code' => $acc['account_code'], 'account_name' => $acc['account_name'],
                              'debit' => $netN > 0 ? $netN : 0, 'credit' => $netN < 0 ? -$netN : 0,
                              'net' => $buckets[$lc]['type'] === 'bilan_actif' ? $netN : -$netN];
        }
        if (abs($netN1) >= 0.005) {
            [$lc1, $op1] = $place($acc, $netN1);
            $add($lc1, $op1, $netN1, 'N_1');
        }
    }
    foreach (['N' => 'net_N', 'N_1' => 'net_N_1'] as $k => $col) {
        if (abs($priorResult[$k]) >= 0.005 && isset($buckets['CAP_PROP'])) {
            $buckets['CAP_PROP'][$col] += -$priorResult[$k];
        }
    }
    if (abs($priorResult['N']) >= 0.005) {
        $byLine['CAP_PROP'][] = ['account_code' => '13x', 'account_name' => 'Résultats des exercices antérieurs non clôturés',
                                 'debit' => $priorResult['N'] > 0 ? $priorResult['N'] : 0,
                                 'credit' => $priorResult['N'] < 0 ? -$priorResult['N'] : 0, 'net' => -$priorResult['N']];
    }

    $results = ['bilan_actif' => [], 'bilan_passif' => [], 'resultat' => [], 'by_line' => $byLine];
    foreach ($buckets as $b) {
        foreach (['brut_N', 'amort_N', 'net_N', 'net_N_1'] as $k) $b[$k] = round((float) $b[$k], 2);
        if ($b['type'] == 'bilan_actif')  $results['bilan_actif'][]  = $b;
        if ($b['type'] == 'bilan_passif') $results['bilan_passif'][] = $b;
        if ($b['type'] == 'resultat')     $results['resultat'][]     = $b;
    }
    return $results;
}

/** The years the statements can show: every year with entries, plus this one. */
function lpc_report_years(PDO $pdo): array
{
    $years = array_map('intval', $pdo->query("SELECT DISTINCT YEAR(date) FROM journal_entries WHERE status = 'posted'")->fetchAll(PDO::FETCH_COLUMN));
    $years = array_merge($years, array_map('intval', $pdo->query("SELECT year FROM financial_years")->fetchAll(PDO::FETCH_COLUMN)));
    $years[] = (int) date('Y');
    $years = array_values(array_unique(array_filter($years)));
    rsort($years);
    return $years;
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
            unset($data['by_line']);

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
        $code = (string) ($_GET['code'] ?? '');
        $year = (int) ($_GET['year'] ?? date('Y'));
        try {
            // The very accounts the statement put on this line, with the balance
            // it used (cumulative for the Bilan, the year for the Résultat).
            $agg = getFinancialAggregates($pdo, $year);
            $rows = $agg['by_line'][$code] ?? [];
            usort($rows, fn ($a, $b) => strcmp((string) $a['account_code'], (string) $b['account_code']));
            sendResponse('success', 'Drilldown data', $rows);
        } catch (Exception $e) {
            error_log('drilldown: ' . $e->getMessage());
            sendResponse('error', 'Erreur de lecture du Grand Livre.');
        }
    }

    if ($action === 'years') {
        sendResponse('success', 'years', ['years' => lpc_report_years($pdo)]);
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
    // Every closing action changes the books: the page's token first (13.4).
    Csrf::requireValid();
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
                case 'profit_incorporation':
                    $r = $engine->resultSummary($year);
                    sendResponse('success', $r['message'], ['created' => 0, 'phase' => $phase, 'result' => $r]);
                default: sendResponse('error', "Phase inconnue: $phase");
            }
            sendResponse('success', $n ? "$n écriture(s) passée(s) au 31/12/$year." : "Rien à passer : la phase est déjà faite.", ['created' => $n, 'phase' => $phase]);
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
        // « Clôture définitive » (13.5). It used to be a separate path for the
        // role named exactly 'admin': no state check, the À-nouveaux summed every
        // entry ever (counting a closed year's history twice), the result left
        // class 8 out, and it was linked as the closed year's own opening entry.
        if (!Rbac::hasPermission('accounting.reports.close_year')) sendResponse('error', "Permission requise : clôturer l'exercice.");
        require_once __DIR__ . '/../../includes/classes/YearEndClosing.php';
        $year = (int) ($payload['year'] ?? 0);
        try {
            $engine = new YearEndClosing($pdo, (int) $user_id);
            $r = $engine->closeDefinitively($year);
            sendResponse('success', "Exercice $year clôturé. Les À-nouveaux sont passés au 01/01/" . ($year + 1)
                . " (résultat : " . number_format($r['result'], 0, ',', ' ') . " F en " . $r['result_account'] . ").", $r);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            sendResponse('error', $e->getMessage());
        }
    } else {
        sendResponse('error', 'Action inconnue.');
    }
} else {
    sendResponse('error', 'Méthode HTTP non autorisée.');
}