<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/notify.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
Rbac::requirePermission('accounting.journal.view');
/**
 * CONTROLLER: Comptabilité (Journals & Chart of Accounts)
 * DESCRIPTION: Handles Chart of Accounts (OHADA/LPC), Journal Entry saving
 *              (double-entry validation), draft queue read + confidence
 *              scoring, single + batch approvals.
 * METHOD: STRICT REST-like JSON API (GET / POST)
 *
 * STATUS VOCABULARY — post migration 039 the enum is
 *     ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft'.
 * Anything speaking 'pending' or 'approved' is stale code from before the
 * migration ran and MUST be treated as a bug. The queue on
 * modules/accounting/journal_entry.php was invisibly returning zero rows
 * because the SELECT still filtered on the retired values.
 *
 * CONFIDENCE — every draft row carries a 0..100 score (see
 * includes/classes/JournalConfidence.php + migration 077). Rules are
 * deterministic SQL over this database; no LLMs, no external calls.
 * The queue read lazily rescores stale rows so the tab is always current
 * without a background job.
 *
 * BATCH APPROVE — accepts a list of ids, requires every one of them to be
 * (a) still in draft, (b) balanced, and (c) at or above the auto-post
 * threshold. Posts them via the same stored proc as single approve, inside
 * one transaction — one row failing rolls back the whole batch. This is the
 * "one click for the obvious ones" workflow.
 */
require_once __DIR__ . '/../../includes/classes/JournalConfidence.php';

$user_id = $_SESSION['user_id'];

// Database Connection (PDO)
try {
    require_once '../../includes/classes/Database.php';
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log('API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur. Veuillez réessayer.']);
    exit;
}

function sendResponse($status, $message, $data = null) {
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response);
    exit;
}

/**
 * A database refusal in words the Comptable can act on. The triggers of
 * migrations 004, 005 and 088 speak English to developers; anything else
 * from the database is logged and never shown (it names tables and SQL).
 */
function acc_db_message(Throwable $e): string {
    $m = $e->getMessage();
    // Migration 088's triggers already speak French: pass their sentence on.
    if (preg_match('/\b1644\s+((?:Écriture|Exercice|Modification|Redatation)\b.*)$/u', $m, $fr)) return trim($fr[1]);
    if (stripos($m, 'closed financial year') !== false || (stripos($m, 'exercice') !== false && stripos($m, 'clôtur') !== false)) {
        return "L'exercice de cette date est clôturé : choisissez une date de l'exercice ouvert.";
    }
    if (stripos($m, 'debit != credit') !== false || stripos($m, 'balanced') !== false) {
        return "L'écriture n'est pas équilibrée : le total des débits doit égaler le total des crédits.";
    }
    if (stripos($m, 'not in draft') !== false) {
        return "Cette écriture n'est plus un brouillard.";
    }
    error_log('accounting_controller: ' . $m . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return "Erreur serveur : l'opération n'a pas été enregistrée. Réessayez ; si cela se reproduit, signalez-le au support.";
}

/**
 * Which module an entry comes from, in the words of the menu. An entry with
 * no source was typed in « Saisie Manuelle ».
 */
function acc_source_label(?string $type): string {
    $t = (string) $type;
    if ($t === '') return 'Saisie manuelle';
    $map = [
        'purchase_order_payment' => 'Achats · paiement fournisseur',
        'purchase_stock_entry'   => 'Achats · entrée en stock',
        'purchase_order'         => 'Achats · réception',
        'rebate'                 => 'Achats · ristourne',
        'payroll_settlement'     => 'Paie · règlement',
        'advance'                => 'Paie · acompte',
        'payslip_reversal'       => 'Paie · annulation',
        'payslip'                => 'Paie · bulletin',
        'treasury_opening'       => "Trésorerie · solde d'ouverture",
        'transfer'               => 'Trésorerie · virement',
        'loan_movement'          => 'Emprunts · remboursement',
        'loan'                   => 'Emprunts & Financements',
        'fleet'                  => 'Flotte · dépense',
        'fuel'                   => 'Dépenses · carburant',
        'expense'                => 'Dépenses',
        'recycling'              => 'Vides · vente recyclage',
        'invoice_credit_note'    => 'Facturation · avoir',
        'invoice'                => 'Facturation',
        'payment'                => 'Facturation · encaissement',
        'wallet'                 => 'Facturation · avoir client',
        'delivery'               => 'Ventes · livraison',
        'stock'                  => 'Stock',
        'depreciation'           => 'Immobilisations · amortissement',
        'fixed_asset'            => 'Immobilisations',
    ];
    foreach ($map as $prefix => $label) {
        if (strpos($t, $prefix) === 0) return $label . (str_ends_with($t, '_reversal') && $prefix !== 'payslip_reversal' ? ' · extourne' : '');
    }
    return 'Module';
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

            case 'chart_admin':
                // Full chart for the admin editor: every CoA row (active
                // and inactive) with parent info and a usage count that
                // decides whether Deactivate/Delete are safe.
                Rbac::requirePermission('accounting.chart.view');

                $ohada = $pdo->query("
                    SELECT id, account_number, name, type
                      FROM ohada_accounts
                     ORDER BY account_number ASC
                ")->fetchAll();

                // Each row with its posted balance and what uses it: the page
                // offers « Supprimer » only when nothing does (decision 14).
                $coa = $pdo->query("
                    SELECT c.id, c.ohada_account_id, c.code, c.name, c.type,
                           c.is_active,
                           o.account_number AS ohada_number,
                           o.name           AS ohada_name,
                           (SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
                              FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
                             WHERE jl.account_id = c.id AND je.status = 'posted') AS balance,
                           (SELECT COUNT(*) FROM expense_categories ec
                             WHERE ec.coa_account_id = c.id)          AS categories_count
                      FROM chart_of_accounts c
                      JOIN ohada_accounts    o ON o.id = c.ohada_account_id
                     ORDER BY c.code ASC
                ")->fetchAll();
                require_once __DIR__ . '/../../includes/classes/ChartOfAccounts.php';
                $usage = ChartOfAccounts::usage($pdo);
                foreach ($coa as &$row) {
                    $u = $usage[(int) $row['id']] ?? ['lines' => 0, 'links' => []];
                    $row['lines_count'] = $u['lines'];
                    $row['links']       = (object) $u['links'];
                    $row['is_own']      = ($row['code'] === $row['ohada_number']);
                    $row['deletable']   = ($u['lines'] === 0 && !$u['links'] && !$row['is_own']);
                }
                unset($row);

                $response_data['ohada_masters'] = $ohada;
                $response_data['coa']           = $coa;
                $response_data['can_edit']      =
                    Rbac::hasPermission('accounting.chart.edit');
                $response_data['can_create']    =
                    Rbac::hasPermission('accounting.chart.create');
                $response_data['can_delete']    =
                    Rbac::hasPermission('accounting.chart.edit');
                break;

            case 'queue':
                // Lazy rescore first. Cheap: only touches rows with a stale
                // (or never-computed) score. The typical queue read after this
                // is a warm-cache read of pre-scored rows.
                try {
                    JournalConfidence::rescoreStale($pdo, 24);
                } catch (Throwable $e) {
                    // Scoring is best-effort. A rules-engine failure must not
                    // hide the queue from the accountant. Log and carry on
                    // with whatever scores are already on the rows.
                    error_log('JournalConfidence rescore failed: ' . $e->getMessage());
                }

                // Fetch drafts (post-039 vocabulary). Ordered so the highest-
                // confidence entries surface first — matches the UX of
                // "check the top rows, batch-post them, then focus on the
                // suspicious tail."
                $stmtQ = $pdo->query("
                    SELECT id, reference, date, description, status, journal_code, created_by,
                           confidence_score, confidence_reasons, confidence_scored_at
                      FROM journal_entries
                     WHERE status = 'draft'
                     ORDER BY (confidence_score IS NULL) ASC,
                              confidence_score DESC,
                              created_at DESC
                ");
                $entries = $stmtQ->fetchAll();

                // Fetch lines for each entry.
                $stmtL = $pdo->prepare("
                    SELECT jl.debit, jl.credit, ca.code AS account_code, ca.name AS account_name
                      FROM journal_lines jl
                      JOIN chart_of_accounts ca ON jl.account_id = ca.id
                     WHERE jl.journal_entry_id = ?
                ");

                $queue = [];
                foreach ($entries as $e) {
                    $stmtL->execute([$e['id']]);
                    $e['lines']              = $stmtL->fetchAll();
                    $e['confidence_reasons'] = $e['confidence_reasons']
                        ? json_decode($e['confidence_reasons'], true)
                        : [];
                    $e['confidence_score'] = $e['confidence_score'] !== null
                        ? (int) $e['confidence_score']
                        : null;
                    $queue[] = $e;
                }

                $response_data['queue']                = $queue;
                $response_data['auto_post_threshold']  = JournalConfidence::autoPostThreshold();
                $response_data['user_id']              = (int) $user_id;
                $response_data['can_approve']          = Rbac::hasPermission('accounting.journal.approve');
                break;

            case 'journal':
                // Every posted entry of a month, newest first, with the module
                // it came from: what reached the books by itself and what was
                // typed by hand. A reversed entry stays posted (its mirror
                // cancels it, register 11.4) and says so.
                $month = (string) ($_GET['month'] ?? date('Y-m'));
                if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
                $journal = strtoupper(trim((string) ($_GET['journal'] ?? '')));
                $origin  = (string) ($_GET['origin'] ?? '');
                $q       = trim((string) ($_GET['q'] ?? ''));

                $where  = ["je.status = 'posted'", "DATE_FORMAT(je.date, '%Y-%m') = ?"];
                $params = [$month];
                if ($journal !== '' && preg_match('/^[A-Z]{2,4}$/', $journal)) { $where[] = 'je.journal_code = ?'; $params[] = $journal; }
                if ($origin === 'manual') $where[] = "(je.source_type IS NULL OR je.source_type = '')";
                if ($origin === 'auto')   $where[] = "(je.source_type IS NOT NULL AND je.source_type <> '')";
                if ($q !== '') {
                    $where[] = '(je.reference LIKE ? OR je.description LIKE ?)';
                    $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%';
                }
                $stmtJ = $pdo->prepare("
                    SELECT je.id, je.reference, je.date, je.journal_code, je.description,
                           je.source_type, je.reversed_by_entry_id, je.posted_at,
                           TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS author
                      FROM journal_entries je
                      LEFT JOIN users u ON u.id = je.created_by
                     WHERE " . implode(' AND ', $where) . "
                     ORDER BY je.date DESC, je.id DESC
                     LIMIT 300
                ");
                $stmtJ->execute($params);
                $rows = $stmtJ->fetchAll(PDO::FETCH_ASSOC);

                $lines = [];
                if ($rows) {
                    $ids = array_map(fn ($r) => (int) $r['id'], $rows);
                    $ph  = implode(',', array_fill(0, count($ids), '?'));
                    $stL = $pdo->prepare("
                        SELECT jl.journal_entry_id, jl.debit, jl.credit, ca.code AS account_code, ca.name AS account_name
                          FROM journal_lines jl JOIN chart_of_accounts ca ON ca.id = jl.account_id
                         WHERE jl.journal_entry_id IN ($ph)
                         ORDER BY jl.journal_entry_id, (jl.debit > 0) DESC, jl.id
                    ");
                    $stL->execute($ids);
                    foreach ($stL->fetchAll(PDO::FETCH_ASSOC) as $l) $lines[(int) $l['journal_entry_id']][] = $l;
                }
                $reversals = [];
                foreach ($rows as $r) if ($r['reversed_by_entry_id']) $reversals[(int) $r['reversed_by_entry_id']] = true;

                $out = []; $auto = 0; $manual = 0;
                foreach ($rows as $r) {
                    $isManual = ($r['source_type'] ?? '') === '';
                    $isManual ? $manual++ : $auto++;
                    $out[] = [
                        'id'          => (int) $r['id'],
                        'reference'   => $r['reference'],
                        'date'        => $r['date'],
                        'journal_code'=> $r['journal_code'],
                        'description' => $r['description'],
                        'origin'      => acc_source_label($r['source_type']),
                        'manual'      => $isManual,
                        'reversed'    => (bool) $r['reversed_by_entry_id'],
                        'author'      => $isManual ? $r['author'] : '',
                        'lines'       => $lines[(int) $r['id']] ?? [],
                    ];
                }
                $response_data = ['month' => $month, 'entries' => $out, 'count_auto' => $auto, 'count_manual' => $manual];
                break;

            default:
                sendResponse('error', 'Onglet inconnu.');
        }

        sendResponse('success', 'Données chargées', $response_data);

    } catch (PDOException $e) {
        sendResponse('error', acc_db_message($e));
    }
}

// ==========================================
// [POST] WRITE OPERATIONS (Data Mutation)
// ==========================================
else if ($method === 'POST') {
    // Every write carries the page's token (lpc-rbac.js adds it to fetch).
    Csrf::requireValid();
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!$payload || !isset($payload['action'])) sendResponse('error', 'Payload invalide.');

    $action = $payload['action'];

    try {
        $pdo->beginTransaction();

        // 1. CREATE AUXILIARY ACCOUNT (Plan Comptable LPC)
        if ($action === 'create_lpc_account') {
            Rbac::requirePermission('accounting.chart.create');
            $ohada_id = (int)($payload['ohada_account_id'] ?? 0);
            $code = trim((string)($payload['code'] ?? ''));
            $name = trim((string)($payload['name'] ?? ''));
            if ($name === '') throw new Exception("Indiquez le nom du sous-compte.");

            $stmtP = $pdo->prepare("SELECT type, account_number FROM ohada_accounts WHERE id = ?");
            $stmtP->execute([$ohada_id]);
            $parent = $stmtP->fetch(PDO::FETCH_ASSOC);
            if (!$parent) throw new Exception("Compte OHADA parent introuvable.");
            $type = $parent['type'];

            // A sub-account's code starts with its parent's number (5211 under
            // 521, 466001 under 466); left empty, the next free one is taken.
            require_once __DIR__ . '/../../includes/classes/ChartOfAccounts.php';
            if ($code === '') $code = ChartOfAccounts::nextSubCode($pdo, $parent['account_number'], 3);
            if (!preg_match('/^[0-9]{2,10}$/', $code) || strpos($code, $parent['account_number']) !== 0) {
                throw new Exception("Le code doit commencer par {$parent['account_number']} et ne contenir que des chiffres (10 au plus).");
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM chart_of_accounts WHERE code = ?");
            $stmt->execute([$code]);
            if ($stmt->fetchColumn() > 0) throw new Exception("Ce code de compte ($code) existe déjà.");
            // ohada_accounts.type carries an 'other' member that
            // chart_of_accounts.type does not accept — migration 084 maps it
            // to 'asset' when it seeds its own mirrors. Copying the parent
            // type raw would fail on a strict sql_mode, or write an empty
            // type on a lax one. The parent picker on the new admin screen
            // lists every OHADA row, so this is now reachable from the UI.
            if ($type === 'other') $type = 'asset';

            $stmtI = $pdo->prepare("INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active) VALUES (?, ?, ?, ?, 1)");
            $stmtI->execute([$ohada_id, $code, $name, $type]);

            $new_id = (int)$pdo->lastInsertId();
            $pdo->commit();
            sendResponse('success', "Sous-compte $code créé.", ['id' => $new_id, 'code' => $code]);
        }

        // -----------------------------------------------------------------
        // CoA admin — add/rename/deactivate rows in ohada_accounts and
        // chart_of_accounts without a migration.
        //
        // Rename touches display text only; historical journal lines
        // reference chart_of_accounts.id and are unaffected. Deactivating a
        // CoA row flips is_active off — the row still exists so old journal
        // lines stay resolvable, but it disappears from the pickers.
        //
        // Codes (ohada_accounts.account_number and chart_of_accounts.code)
        // are never mutated by the editor. Historical postings and the
        // budget-bucket mappings in migration 091 both look accounts up by
        // code, so a mutation would silently reroute posted history. If a
        // code was wrong, fix it in a migration (like 117 for 6252) and let
        // the reviewer trace it — never through a UI.
        // -----------------------------------------------------------------
        if ($action === 'create_ohada_account') {
            Rbac::requirePermission('accounting.chart.create');
            $number = trim((string)($payload['account_number'] ?? ''));
            $name   = trim((string)($payload['name'] ?? ''));
            $type   = trim((string)($payload['type'] ?? ''));

            if (!preg_match('/^[0-9]{2,6}$/', $number)) {
                throw new Exception("Le numéro OHADA doit être 2 à 6 chiffres.");
            }
            if ($name === '') throw new Exception("Nom obligatoire.");
            $allowed = ['asset','liability','equity','revenue','expense'];
            if (!in_array($type, $allowed, true)) {
                throw new Exception("Type invalide.");
            }

            $chk = $pdo->prepare("SELECT id FROM ohada_accounts WHERE account_number = ?");
            $chk->execute([$number]);
            if ($chk->fetchColumn()) {
                throw new Exception("Le compte OHADA $number existe déjà.");
            }

            // The mirror below writes chart_of_accounts.code = $number, and
            // chart_of_accounts.code does NOT carry a UNIQUE index on every
            // install (migrations 038, 065 and 084 each say so and guard
            // their own seeds with NOT EXISTS). Without this check a second
            // row with the same code could be created, and every lookup that
            // resolves an account by code — lpc_account_for(), the budget
            // bucket map in migration 091 — would silently pick one of two.
            $chkCoa = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE code = ?");
            $chkCoa->execute([$number]);
            if ($chkCoa->fetchColumn()) {
                throw new Exception("Un compte du plan porte déjà le code $number.");
            }

            $ins = $pdo->prepare("INSERT INTO ohada_accounts (account_number, name, type) VALUES (?, ?, ?)");
            $ins->execute([$number, $name, $type]);
            $ohada_id = (int)$pdo->lastInsertId();

            // Auto-mirror a chart_of_accounts front — the same pattern
            // migration 053 uses. Without it the new OHADA parent cannot be
            // referenced by an expense category or journal line.
            $insCoa = $pdo->prepare("
                INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active)
                VALUES (?, ?, ?, ?, 1)
            ");
            $insCoa->execute([$ohada_id, $number, $name, $type]);
            $coa_id = (int)$pdo->lastInsertId();

            $pdo->commit();
            sendResponse('success', 'Compte OHADA créé.',
                ['ohada_id' => $ohada_id, 'coa_id' => $coa_id]);
        }

        if ($action === 'update_ohada_account') {
            Rbac::requirePermission('accounting.chart.edit');
            $id   = (int)($payload['id'] ?? 0);
            $name = trim((string)($payload['name'] ?? ''));
            if (!$id || $name === '') throw new Exception("Paramètres manquants.");

            // Capture the OLD name BEFORE the update. The sync below has to
            // compare each CoA row against what the OHADA row used to be
            // called; joining ohada_accounts after the update would compare
            // it against the NEW name, which only ever matches rows that
            // already carry it — so the sync silently did nothing.
            $prev = $pdo->prepare("SELECT name FROM ohada_accounts WHERE id = ?");
            $prev->execute([$id]);
            $old_name = $prev->fetchColumn();
            if ($old_name === false) throw new Exception("Compte OHADA introuvable.");

            $upd = $pdo->prepare("UPDATE ohada_accounts SET name = ? WHERE id = ?");
            $upd->execute([$name, $id]);

            // Keep the front CoA row in sync IF its name still matches what
            // the OHADA row was called before. A CoA row an operator has
            // customised is left alone.
            $syncCoa = $pdo->prepare("
                UPDATE chart_of_accounts
                   SET name = ?
                 WHERE ohada_account_id = ?
                   AND (name = ? OR name = code)
            ");
            $syncCoa->execute([$name, $id, $old_name]);

            $pdo->commit();
            sendResponse('success', 'Compte OHADA renommé.');
        }

        if ($action === 'update_lpc_account') {
            Rbac::requirePermission('accounting.chart.edit');
            $id   = (int)($payload['id'] ?? 0);
            $name = trim((string)($payload['name'] ?? ''));
            if (!$id || $name === '') throw new Exception("Paramètres manquants.");

            $upd = $pdo->prepare("UPDATE chart_of_accounts SET name = ? WHERE id = ?");
            $upd->execute([$name, $id]);

            $pdo->commit();
            sendResponse('success', 'Compte renommé.');
        }

        if ($action === 'set_lpc_account_active') {
            Rbac::requirePermission('accounting.chart.edit');
            $id     = (int)($payload['id'] ?? 0);
            $active = !empty($payload['is_active']) ? 1 : 0;
            if (!$id) throw new Exception("Paramètres manquants.");

            // Refuse to deactivate a row that any CONFIGURATION still points
            // at. Each of these columns stores a chart_of_accounts.id that a
            // posting routine dereferences at run time; deactivating the
            // target does not clear the pointer, so the breakage only shows
            // up later, at the till, as a failed posting.
            //
            // journal_lines is deliberately NOT in this list: historical
            // postings referencing the account are exactly why we deactivate
            // instead of deleting, so they must never block.
            //
            // Each pair is probed against information_schema first, so an
            // install that predates one of these modules is skipped rather
            // than erroring on an unknown column.
            if (!$active) {
                $refs = [
                    ['expense_categories', 'coa_account_id',             'catégorie(s) de dépense'],
                    ['treasury_accounts',  'coa_account_id',             'compte(s) de trésorerie'],
                    ['products',           'revenue_account_id',         'produit(s) — compte de vente'],
                    ['products',           'stock_account_id',           'produit(s) — compte de stock'],
                    ['products',           'cogs_account_id',            'produit(s) — compte de coût'],
                    ['fixed_assets',       'expense_account_id',         'immobilisation(s) — charge'],
                    ['fixed_assets',       'depr_account_id',            'immobilisation(s) — dotation'],
                    ['fixed_assets',       'accumulated_depr_account_id','immobilisation(s) — amortissements'],
                ];

                $colExists = $pdo->prepare("
                    SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE()
                       AND table_name   = ?
                       AND column_name  = ?
                ");

                $blockers = [];
                foreach ($refs as [$table, $column, $label]) {
                    $colExists->execute([$table, $column]);
                    if ((int)$colExists->fetchColumn() === 0) continue;

                    // Identifiers come from the literal list above, never from
                    // input, so interpolating them is safe; only the id binds.
                    $cnt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
                    $cnt->execute([$id]);
                    $n = (int)$cnt->fetchColumn();
                    if ($n > 0) $blockers[] = "$n $label";
                }

                if ($blockers) {
                    throw new Exception(
                        "Ce compte est encore référencé par : " . implode(' ; ', $blockers)
                        . ". Remappez ces éléments avant de désactiver."
                    );
                }
            }

            $upd = $pdo->prepare("UPDATE chart_of_accounts SET is_active = ? WHERE id = ?");
            $upd->execute([$active, $id]);

            $pdo->commit();
            sendResponse('success', $active ? 'Compte réactivé.' : 'Compte désactivé.');
        }

        // Delete a row nothing uses: no journal line, no client, supplier,
        // treasury account, product, category, fixed asset, loan or setting
        // (the owner's decision 14). A used row is deactivated instead.
        if ($action === 'delete_lpc_account') {
            Rbac::requirePermission('accounting.chart.edit');
            $id = (int)($payload['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT code, name FROM chart_of_accounts WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception("Compte introuvable.");
            require_once __DIR__ . '/../../includes/classes/ChartOfAccounts.php';
            $why = ChartOfAccounts::whyNotDeletable($pdo, $id);
            if ($why !== '') throw new Exception($why);

            $pdo->prepare("DELETE FROM chart_of_accounts WHERE id = ?")->execute([$id]);
            $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, old_json, new_value) VALUES (?, 'DELETE', 'chart_of_accounts', ?, ?, ?)")
                ->execute([$user_id, $id, json_encode(['code' => $row['code'], 'name' => $row['name']], JSON_UNESCAPED_UNICODE),
                           "COA deleted: {$row['code']}"]);
            $pdo->commit();
            sendResponse('success', "Compte {$row['code']} supprimé.");
        }

        if ($action === 'remap_expense_category') {
            Rbac::requirePermission('accounting.chart.edit');
            $cat_id = (int)($payload['category_id'] ?? 0);
            $coa_id = (int)($payload['coa_account_id'] ?? 0);
            if (!$cat_id || !$coa_id) throw new Exception("Paramètres manquants.");

            $chk = $pdo->prepare("SELECT is_active, type, code FROM chart_of_accounts WHERE id = ?");
            $chk->execute([$coa_id]);
            $target = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$target) throw new Exception("Compte cible introuvable.");
            if ((int)$target['is_active'] !== 1) throw new Exception("Compte cible désactivé.");

            // An expense category posts a charge. Pointing one at a revenue,
            // equity or liability account produces a journal entry on the
            // wrong side of the books that nothing downstream would flag.
            // 'asset' stays allowed — a capitalised purchase legitimately
            // lands on a class 2 account.
            if (in_array($target['type'], ['revenue', 'equity', 'liability'], true)) {
                throw new Exception(
                    "Le compte {$target['code']} est de type « {$target['type']} » : "
                    . "une catégorie de dépense doit pointer sur un compte de charge (ou d'immobilisation)."
                );
            }

            $upd = $pdo->prepare("UPDATE expense_categories SET coa_account_id = ? WHERE id = ?");
            $upd->execute([$coa_id, $cat_id]);

            $pdo->commit();
            sendResponse('success', 'Catégorie remappée.');
        }

        // 2. SAVE JOURNAL ENTRY (Draft or Post directly)
        if ($action === 'save_journal_entry') {
            // Vocabulary maps to migration 039 enum: draft = brouillon,
            // posted = validé au grand livre.
            $wants_post   = ($payload['status'] ?? '') === 'post';
            $journal_code = $payload['journal_code'];
            $date         = $payload['date'];
            $ref          = trim($payload['reference']);
            $desc         = trim($payload['description']);
            $lines        = $payload['lines'];

            if (!$journal_code || !$date || !$ref || !$desc || !is_array($lines) || count($lines) < 2) {
                throw new Exception("Champs obligatoires manquants ou moins de 2 lignes.");
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) throw new Exception("Date comptable invalide.");
            if (!in_array($journal_code, ['OD', 'AC', 'VT', 'CA', 'BQ'], true)) throw new Exception("Journal inconnu.");

            // Each line: an active account of the chart, one side only, no
            // negative amount (the triggers of migration 004 would refuse it
            // in English; say it here in words).
            $chkAcc = $pdo->prepare("SELECT code, is_active FROM chart_of_accounts WHERE id = ?");
            $total_debit = 0.0; $total_credit = 0.0;
            foreach ($lines as $line) {
                $d = round((float) ($line['debit'] ?? 0), 2);
                $c = round((float) ($line['credit'] ?? 0), 2);
                if ($d < 0 || $c < 0) throw new Exception("Un montant ne peut pas être négatif.");
                if ($d > 0 && $c > 0) throw new Exception("Une ligne est soit au débit, soit au crédit, pas les deux.");
                if ($d == 0 && $c == 0) throw new Exception("Une ligne a un compte mais aucun montant.");
                $chkAcc->execute([(int) ($line['account_id'] ?? 0)]);
                $acc = $chkAcc->fetch(PDO::FETCH_ASSOC);
                if (!$acc) throw new Exception("Un compte est manquant sur une ligne.");
                if ((int) $acc['is_active'] !== 1) throw new Exception("Le compte {$acc['code']} est désactivé : choisissez un compte actif.");
                $total_debit  += $d;
                $total_credit += $c;
            }

            if ($wants_post) {
                Rbac::requirePermission('accounting.journal.approve');
                if (abs($total_debit - $total_credit) > 0.01) {
                    throw new Exception("Écriture déséquilibrée ! Impossible de valider au Grand Livre.");
                }
                if ($total_debit <= 0) {
                    throw new Exception("L'écriture doit avoir un montant supérieur à 0.");
                }
            } else {
                Rbac::requirePermission('accounting.journal.create');
            }

            // Always insert as 'draft' first — that's what the post_journal_entry
            // stored proc from migration 004 requires. If the user asked to
            // post, we transition after the lines are inserted.
            $stmtE = $pdo->prepare("
                INSERT INTO journal_entries
                    (reference, journal_code, date, description, status, created_by)
                VALUES (?, ?, ?, ?, 'draft', ?)
            ");
            $stmtE->execute([$ref, $journal_code, $date, $desc, $user_id]);
            $entry_id = (int) $pdo->lastInsertId();

            $stmtL = $pdo->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
            foreach ($lines as $line) {
                if (empty($line['account_id'])) throw new Exception("Un compte est manquant sur une ligne.");
                $stmtL->execute([$entry_id, (int)$line['account_id'], (float)$line['debit'], (float)$line['credit']]);
            }

            // Score the fresh entry so the queue widget can decide badge colour
            // immediately, whether it stays as a draft or not.
            JournalConfidence::scoreAndPersist($pdo, $entry_id);

            if ($wants_post) {
                // Stored proc raises SQLSTATE 45000 if the ledger drifted.
                $pdo->prepare("CALL post_journal_entry(?, ?)")->execute([$entry_id, $user_id]);
            }

            $pdo->commit();

            // Notify whoever can approve that a fresh draft is waiting on
            // them — the journal_entry.php queue tab is already the default
            // view on load, so a bare link to the page lands right on it.
            // Only for drafts: a direct post means the actor already held
            // accounting.journal.approve, so nobody else needs to act.
            if (!$wants_post) {
                lpc_notify_permission(
                    $pdo,
                    'accounting.journal.approve',
                    'Écriture en attente d\'approbation',
                    "Nouvelle écriture ($ref, {$journal_code}) déposée par " . ($_SESSION['user_name'] ?? 'un opérateur')
                        . " — " . number_format($total_debit, 0, ',', ' ') . " FCFA. En attente de validation.",
                    '/modules/accounting/journal_entry.php',
                    'warning',
                    [$user_id]
                );
            }

            $msg = $wants_post
                ? 'Écriture équilibrée et validée au Grand Livre.'
                : 'Brouillard sauvegardé dans la file d\'attente.';
            sendResponse('success', $msg, ['entry_id' => $entry_id]);
        }

        // 3. APPROVE ONE ENTRY (From the Queue)
        if ($action === 'approve_entry') {
            Rbac::requirePermission('accounting.journal.approve');
            $entry_id = (int)$payload['id'];

            // Sanity check: the row is actually a draft. Users open two tabs.
            $st = $pdo->prepare("SELECT status, reference, created_by FROM journal_entries WHERE id = ?");
            $st->execute([$entry_id]);
            $entryRow = $st->fetch(PDO::FETCH_ASSOC);
            if ($entryRow === false) throw new Exception("Écriture introuvable.");
            if ($entryRow['status'] !== 'draft') throw new Exception("Cette écriture n'est plus en brouillon (statut actuel : {$entryRow['status']}).");

            // post_journal_entry validates balance + stamps posted_at/posted_by
            // + flips status to 'posted'. SIGNAL SQLSTATE '45000' on drift.
            $pdo->prepare("CALL post_journal_entry(?, ?)")->execute([$entry_id, $user_id]);

            $pdo->commit();

            // Let the person who submitted it know it cleared — skip if they
            // approved their own draft.
            if (!empty($entryRow['created_by']) && (int) $entryRow['created_by'] !== $user_id) {
                lpc_notify_user(
                    $pdo,
                    (int) $entryRow['created_by'],
                    'Écriture approuvée',
                    "Votre écriture {$entryRow['reference']} a été validée et postée au Grand Livre par " . ($_SESSION['user_name'] ?? 'un approbateur') . ".",
                    '/modules/accounting/ledger.php',
                    'info'
                );
            }

            sendResponse('success', 'Brouillard validé et transféré au Grand Livre.');
        }

        // 4. BATCH APPROVE — the "one click for the ≥ 90 %" flow.
        //    Payload: { action: 'batch_approve', ids: [1,2,3,...] }
        //    Every id must be draft, balanced, and confidence >= threshold.
        //    All-or-nothing: any failure rolls back the whole batch, and the
        //    UI re-reads the queue so the user sees why.
        if ($action === 'batch_approve') {
            Rbac::requirePermission('accounting.journal.approve');
            $ids = $payload['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) throw new Exception("Aucune écriture sélectionnée.");
            $ids = array_values(array_unique(array_map('intval', $ids)));

            $threshold = JournalConfidence::autoPostThreshold();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $st = $pdo->prepare("
                SELECT id, status, confidence_score, created_by
                  FROM journal_entries
                 WHERE id IN ($placeholders)
            ");
            $st->execute($ids);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== count($ids)) throw new Exception("Certaines écritures n'existent plus.");

            foreach ($rows as $r) {
                if ($r['status'] !== 'draft') {
                    throw new Exception("L'écriture #{$r['id']} n'est plus en brouillon.");
                }
                if ($r['confidence_score'] === null || (int)$r['confidence_score'] < $threshold) {
                    throw new Exception("L'écriture #{$r['id']} est en dessous du seuil de confiance ({$threshold} %).");
                }
            }

            // Post each. Stored proc rejects unbalanced rows — one bad row
            // rolls the whole transaction back and the user sees the message.
            $call = $pdo->prepare("CALL post_journal_entry(?, ?)");
            $count = 0;
            foreach ($ids as $id) {
                $call->execute([$id, $user_id]);
                $count++;
            }

            $pdo->commit();

            // Group by preparer so someone with 5 entries in the batch gets
            // one message, not five.
            $byPreparer = [];
            foreach ($rows as $r) {
                $cb = (int) ($r['created_by'] ?? 0);
                if ($cb > 0 && $cb !== $user_id) $byPreparer[$cb] = ($byPreparer[$cb] ?? 0) + 1;
            }
            foreach ($byPreparer as $preparerId => $n) {
                lpc_notify_user(
                    $pdo,
                    $preparerId,
                    'Écritures approuvées',
                    "$n de vos écritures ont été validées en lot et postées au Grand Livre.",
                    '/modules/accounting/ledger.php',
                    'info'
                );
            }

            sendResponse('success', "$count écriture(s) postée(s) au Grand Livre.", ['posted' => $count]);
        }

        // 5. DELETE A DRAFT — a brouillard typed by mistake. Only a draft (a
        //    posted entry is corrected by an opposite entry, never deleted);
        //    its author, or whoever may post entries.
        if ($action === 'delete_draft') {
            Rbac::requirePermission('accounting.journal.create');
            $entry_id = (int) ($payload['id'] ?? 0);
            $st = $pdo->prepare("SELECT status, reference, created_by FROM journal_entries WHERE id = ? FOR UPDATE");
            $st->execute([$entry_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception("Écriture introuvable.");
            if ($row['status'] !== 'draft') throw new Exception("Seul un brouillard se supprime : une écriture postée se corrige par une écriture inverse.");
            if ((int) $row['created_by'] !== (int) $user_id && !Rbac::hasPermission('accounting.journal.approve')) {
                throw new Exception("Seul son auteur ou un valideur peut supprimer ce brouillard.");
            }
            $pdo->prepare("DELETE FROM journal_lines WHERE journal_entry_id = ?")->execute([$entry_id]);
            $pdo->prepare("DELETE FROM journal_entries WHERE id = ? AND status = 'draft'")->execute([$entry_id]);
            $pdo->commit();
            sendResponse('success', "Brouillard {$row['reference']} supprimé.");
        }

        // Unknown action falls through — roll back and complain.
        throw new Exception("Action inconnue : " . htmlspecialchars((string)$action));

    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        sendResponse('error', acc_db_message($e));
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendResponse('error', $e->getMessage());
    }
} else {
    sendResponse('error', 'Méthode HTTP non autorisée.');
}
