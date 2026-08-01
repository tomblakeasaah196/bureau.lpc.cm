<?php
/**
 * api/v1/expenses_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Gestion des Dépenses controller.
 *
 * REPLACES:
 *   - The 'overheads' branch of api/v1/procurement_controller.php (list, save,
 *     delete). That branch survives only long enough for the frontend to be
 *     switched over; the tab it fed is gone in modules/inventory/procurement.php.
 *   - The `action=expense` branch of api/v1/treasury_controller.php now
 *     delegates here via `quick_entry` when the Sortie de Caisse form on
 *     cashflow.php is used — the row lands in `expenses` with the "autre"
 *     category and a paid status, so it shows up in the Dépenses history
 *     and rolls into Budgets like any other expense.
 *
 * ACTIONS (POST unless noted; JSON body except quick_entry which accepts $_POST):
 *   GET  action=dashboard&year=YYYY&month=MM
 *   GET  action=list&start=YYYY-MM-DD&end=YYYY-MM-DD&category_id=&status=&q=&page=&per_page=
 *   GET  action=get&id=N
 *   GET  action=categories
 *   GET  action=recurring
 *   GET  action=form_meta         — categories + treasury accounts + suppliers
 *   GET  action=budget_probe&category_id=N&expense_date=YYYY-MM-DD&exclude_id=N
 *   POST action=save                — create/update
 *   POST action=mark_paid           — flip unpaid → paid, post JE via JournalPoster
 *   POST action=delete
 *   POST action=quick_entry         — the cashflow.php "Sortie de Caisse" backend
 *   POST action=save_category / delete_category
 *   POST action=save_recurring / toggle_recurring / delete_recurring
 *   POST action=upload_attachment   — multipart
 *   POST action=delete_attachment
 *
 * All state-changing actions are wrapped in a single transaction and, where
 * they touch treasury, also update treasury_accounts.balance +
 * treasury_transactions so the Cashflow module stays in sync — same contract
 * treasury_controller uses.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/JournalPoster.php';
require_once __DIR__ . '/../../includes/classes/Paginator.php';
require_once __DIR__ . '/../../includes/classes/Uploads.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('accounting.expenses.view');

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$method  = $_SERVER['REQUEST_METHOD'];
$action  = $_REQUEST['action'] ?? null;

// Every state-changing action goes through the CSRF gate, matching what the
// other write controllers do (fixed_assets, payroll, sales, settings…). This
// one shipped without it: any page the operator visited while logged in could
// silently POST here and book a fake expense, mark an invoice paid — which
// debits a real treasury account and posts to the ledger — or delete a posted
// expense and generate the reversing entry. assets/js/lpc-rbac.js already
// attaches the X-CSRF-Token header to every same-origin non-GET fetch, and
// Csrf::submitted() also reads the token out of $_POST, so both the JSON
// callers and the multipart upload are covered without touching them.
if ($method !== 'GET') {
    Csrf::requireValid();
}

function jr($status, $message = '', $data = null) {
    $out = ['status' => $status, 'message' => $message];
    if ($data !== null) $out['data'] = $data;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

// Every write path shares this: a category resolves to a chart_of_accounts.id
// through expense_categories.coa_account_id. A category with no OHADA mapping
// cannot be posted to the ledger, so mark_paid refuses it — this helper is
// used by both mark_paid and quick_entry.
function coaForCategory(PDO $db, int $category_id): ?int {
    $stmt = $db->prepare("SELECT coa_account_id FROM expense_categories WHERE id = ?");
    $stmt->execute([$category_id]);
    $v = $stmt->fetchColumn();
    return $v ? (int)$v : null;
}

/**
 * EX-YYMM-XXXXXX. Random suffix rather than a counter so parallel writes do not
 * collide on the UNIQUE index — same shape procurement uses.
 *
 * The suffix was 2 bytes (4 hex chars, 65 536 values) and generated blind. That
 * is a birthday problem, not a uniqueness guarantee: at ~300 expenses in the
 * table the odds of *some* pair colliding are already near even, and a
 * collision surfaces as an opaque "Integrity constraint violation" toast on an
 * otherwise valid save. Widened to 3 bytes and, more importantly, checked
 * against the table with a bounded retry so a collision costs one extra SELECT
 * instead of a failed save.
 */
function nextReference(PDO $db): string {
    $check = $db->prepare("SELECT 1 FROM expenses WHERE reference = ? LIMIT 1");
    for ($i = 0; $i < 8; $i++) {
        $ref = 'EX-' . date('ym') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $check->execute([$ref]);
        if (!$check->fetchColumn()) return $ref;
    }
    // Vanishingly unlikely; fall back to something that cannot repeat.
    return 'EX-' . date('ymdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

/** Document root, with a CLI-safe fallback (this file is 2 levels below it). */
function docRoot(): string {
    $r = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    return $r !== '' ? $r : dirname(__DIR__, 2);
}

/** Escape LIKE wildcards so a user searching for "50%" does not match everything. */
function likeTerm(string $q): string {
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
}

/** YYYY-MM-DD or null. Rejects anything else rather than letting MySQL coerce it. */
function cleanDate($v): ?string {
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $y) ? $v : null;
}

// ============================================================================
try {
    $db = Database::getInstance()->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ========================================================================
    // GET — reads
    // ========================================================================
    if ($method === 'GET') {

        // ---- DASHBOARD --------------------------------------------------
        if ($action === 'dashboard') {
            $year  = (int) ($_GET['year']  ?? date('Y'));
            $month = (int) ($_GET['month'] ?? date('n'));
            $ymStart = sprintf('%04d-%02d-01', $year, $month);
            $ymEnd   = date('Y-m-t', strtotime($ymStart));
            $yStart  = sprintf('%04d-01-01', $year);
            $yEnd    = sprintf('%04d-12-31', $year);

            // KPIs. Budget lookup mirrors budget_controller: latest budget row
            // for this fiscal_year, then join budget_lines by ohada_account_id
            // → same account each category maps to.
            $kMonth = (float) $db->query("SELECT COALESCE(SUM(amount),0)
                FROM expenses WHERE expense_date BETWEEN '$ymStart' AND '$ymEnd'")->fetchColumn();
            $kYtd = (float) $db->query("SELECT COALESCE(SUM(amount),0)
                FROM expenses WHERE expense_date BETWEEN '$yStart' AND '$yEnd'")->fetchColumn();
            $kUnpaid = (float) $db->query("SELECT COALESCE(SUM(amount),0)
                FROM expenses WHERE payment_status='unpaid'")->fetchColumn();
            $kUnpaidCount = (int) $db->query("SELECT COUNT(*) FROM expenses WHERE payment_status='unpaid'")->fetchColumn();

            // Category breakdown (this month) with budget consumption. Uses
            // budget_lines.mMM column matching the requested month, like the
            // budgets page does.
            $mCol = 'm' . str_pad((string)$month, 2, '0', STR_PAD_LEFT);
            $stmt = $db->prepare("
                SELECT ec.id, ec.name, ec.icon, ec.color, ec.slug,
                       COALESCE(SUM(e.amount), 0) AS actual,
                       COALESCE(bl.`$mCol`, 0)    AS budget
                  FROM expense_categories ec
             LEFT JOIN expenses e
                    ON e.category_id = ec.id
                   AND e.expense_date BETWEEN ? AND ?
             LEFT JOIN chart_of_accounts coa ON coa.id = ec.coa_account_id
             LEFT JOIN budgets b
                    ON b.fiscal_year = ?
                   AND b.id = (SELECT id FROM budgets WHERE fiscal_year = ? ORDER BY version DESC LIMIT 1)
             LEFT JOIN budget_lines bl
                    ON bl.budget_id = b.id
                   AND bl.ohada_account_id = coa.ohada_account_id
                 WHERE ec.is_active = 1
                 GROUP BY ec.id, ec.name, ec.icon, ec.color, ec.slug, ec.sort_order, bl.`$mCol`
                 ORDER BY actual DESC, ec.sort_order ASC
            ");
            $stmt->execute([$ymStart, $ymEnd, $year, $year]);
            $breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $overBudget = array_values(array_filter($breakdown, function ($r) {
                return (float)$r['budget'] > 0 && (float)$r['actual'] > (float)$r['budget'];
            }));

            // Monthly trend (12 months of this year) for the sparkline.
            $trend = array_fill(0, 12, 0.0);
            $stmt = $db->prepare("
                SELECT MONTH(expense_date) m, SUM(amount) t
                  FROM expenses
                 WHERE YEAR(expense_date) = ?
                 GROUP BY MONTH(expense_date)
            ");
            $stmt->execute([$year]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $trend[(int)$r['m'] - 1] = (float)$r['t'];

            // Recent 5 expenses for the "Dernières activités" tile.
            $recent = $db->query("
                SELECT e.id, e.reference, e.title, e.amount, e.expense_date, e.payment_status,
                       ec.name AS category_name, ec.icon AS category_icon, ec.color AS category_color
                  FROM expenses e
                  JOIN expense_categories ec ON ec.id = e.category_id
                 ORDER BY e.created_at DESC LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);

            jr('success', '', [
                'kpis' => [
                    'month'         => $kMonth,
                    'ytd'           => $kYtd,
                    'unpaid'        => $kUnpaid,
                    'unpaid_count'  => $kUnpaidCount,
                    'over_budget'   => count($overBudget),
                ],
                'breakdown'  => $breakdown,
                'over_budget'=> $overBudget,
                'trend'      => array_values($trend),
                'recent'     => $recent,
                'year'       => $year,
                'month'      => $month,
            ]);
        }

        // ---- LIST -------------------------------------------------------
        if ($action === 'list') {
            $start = $_GET['start']       ?? date('Y-01-01');
            $end   = $_GET['end']         ?? date('Y-12-31');
            $cat   = (int) ($_GET['category_id'] ?? 0);
            $stat  = trim($_GET['status'] ?? '');
            $q     = trim($_GET['q']      ?? '');
            $body  = "
                FROM expenses e
                JOIN expense_categories ec ON ec.id = e.category_id
           LEFT JOIN treasury_accounts ta ON ta.id = e.treasury_account_id
           LEFT JOIN suppliers s          ON s.id = e.supplier_id
           LEFT JOIN (SELECT expense_id, COUNT(*) n FROM expense_attachments GROUP BY expense_id) att
                  ON att.expense_id = e.id
                WHERE e.expense_date BETWEEN ? AND ?
            ";
            $params = [$start, $end];
            if ($cat > 0)              { $body .= " AND e.category_id = ?";   $params[] = $cat; }
            if (in_array($stat, ['paid','unpaid'], true)) { $body .= " AND e.payment_status = ?"; $params[] = $stat; }
            if ($q !== '') {
                $body   .= " AND (e.reference LIKE ? OR e.title LIKE ? OR ec.name LIKE ? OR s.name LIKE ?)";
                // likeTerm() escapes % and _ — searching for "50%" or "loyer_2"
                // used to turn the user's own wildcards into SQL wildcards and
                // return the whole table.
                $like    = likeTerm($q);
                $params  = array_merge($params, [$like, $like, $like, $like]);
            }
            $body .= " ORDER BY e.expense_date DESC, e.id DESC";

            $page = Paginator::paginate($db, $body, $params,
                "e.id, e.reference, e.title, e.amount, e.expense_date, e.payment_status,
                 e.payment_date, e.category_id, e.supplier_id, e.treasury_account_id,
                 e.journal_entry_id,
                 ec.name AS category_name, ec.icon AS category_icon, ec.color AS category_color,
                 ta.name AS treasury_name, ta.type AS treasury_type,
                 s.name  AS supplier_name,
                 COALESCE(att.n, 0) AS attachment_count",
                null, null, "expenses.list:$start:$end:$cat:$stat"
            );
            jr('success', '', [
                'rows'       => $page['data'],
                'pagination' => [
                    'page'        => $page['page'],
                    'per_page'    => $page['per_page'],
                    'total'       => $page['total'],
                    'total_pages' => $page['total_pages'],
                    'has_prev'    => $page['has_prev'],
                    'has_next'    => $page['has_next'],
                ],
            ]);
        }

        // ---- GET ONE (with attachments) ---------------------------------
        if ($action === 'get') {
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) jr('error', 'ID manquant.');
            $stmt = $db->prepare("
                SELECT e.*, ec.name AS category_name, ec.slug AS category_slug,
                       ta.name AS treasury_name, s.name AS supplier_name
                  FROM expenses e
                  JOIN expense_categories ec ON ec.id = e.category_id
             LEFT JOIN treasury_accounts ta ON ta.id = e.treasury_account_id
             LEFT JOIN suppliers s          ON s.id = e.supplier_id
                 WHERE e.id = ?
            ");
            $stmt->execute([$id]);
            $exp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exp) jr('error', 'Dépense introuvable.');

            $ast = $db->prepare("SELECT id, filename, stored_path, mime_type, size_bytes, uploaded_at
                                   FROM expense_attachments WHERE expense_id = ? ORDER BY id");
            $ast->execute([$id]);
            $exp['attachments'] = $ast->fetchAll(PDO::FETCH_ASSOC);
            jr('success', '', $exp);
        }

        // ---- CATEGORIES -------------------------------------------------
        if ($action === 'categories') {
            $stmt = $db->query("
                SELECT ec.id, ec.name, ec.slug, ec.icon, ec.color, ec.is_active, ec.is_system, ec.sort_order,
                       ec.coa_account_id,
                       coa.code AS coa_code, coa.name AS coa_name,
                       oa.account_number AS ohada_account_number,
                       (SELECT COUNT(*) FROM expenses WHERE category_id = ec.id) AS usage_count
                  FROM expense_categories ec
             LEFT JOIN chart_of_accounts coa ON coa.id = ec.coa_account_id
             LEFT JOIN ohada_accounts    oa  ON oa.id  = coa.ohada_account_id
                 ORDER BY ec.sort_order, ec.name
            ");
            jr('success', '', $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        // ---- RECURRING TEMPLATES ---------------------------------------
        if ($action === 'recurring') {
            $stmt = $db->query("
                SELECT r.*, ec.name AS category_name, ec.icon AS category_icon, ec.color AS category_color,
                       ta.name AS treasury_name, s.name AS supplier_name
                  FROM expense_recurring_templates r
                  JOIN expense_categories ec ON ec.id = r.category_id
             LEFT JOIN treasury_accounts ta ON ta.id = r.treasury_account_id
             LEFT JOIN suppliers s          ON s.id  = r.supplier_id
                 ORDER BY r.is_active DESC, r.day_of_month, r.name
            ");
            jr('success', '', $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        // ---- FORM META (categories + accounts + suppliers) --------------
        if ($action === 'form_meta') {
            $cats = $db->query("SELECT id, name, slug, icon, color, coa_account_id
                                  FROM expense_categories WHERE is_active = 1
                                 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
            $acs  = $db->query("SELECT id, name, type, balance FROM treasury_accounts
                                 WHERE status = 'active' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
            $sup  = $db->query("SELECT id, name FROM suppliers
                                 WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            // OHADA options for the category editor: expose all Class 6 accounts.
            $ohada = $db->query("
                SELECT coa.id, coa.code, coa.name
                  FROM chart_of_accounts coa
                  JOIN ohada_accounts oa ON oa.id = coa.ohada_account_id
                 WHERE oa.type = 'expense'
                 ORDER BY coa.code
            ")->fetchAll(PDO::FETCH_ASSOC);
            jr('success', '', ['categories'=>$cats, 'accounts'=>$acs, 'suppliers'=>$sup, 'ohada_expense'=>$ohada]);
        }

        // ---- BUDGET PROBE (live warning while typing) -------------------
        if ($action === 'budget_probe') {
            $cat  = (int) ($_GET['category_id'] ?? 0);
            $date = cleanDate($_GET['expense_date'] ?? null) ?? date('Y-m-d');
            // When the modal is reopened on an existing expense, that row is
            // already in `expenses` and was being counted as "déjà consommé"
            // *and* added again as the projected amount — so editing anything
            // on a saved expense reported a budget overrun twice its real size.
            $exclude = (int) ($_GET['exclude_id'] ?? 0);
            if ($cat <= 0) jr('success', '', ['budget'=>0,'spent'=>0]);
            $year  = (int) substr($date, 0, 4);
            $month = (int) substr($date, 5, 2);
            $mCol  = 'm' . str_pad((string)$month, 2, '0', STR_PAD_LEFT);
            $ymStart = sprintf('%04d-%02d-01', $year, $month);
            $ymEnd   = date('Y-m-t', strtotime($ymStart));

            $stmt = $db->prepare("
                SELECT COALESCE(bl.`$mCol`, 0) AS budget,
                       (SELECT COALESCE(SUM(amount),0) FROM expenses
                         WHERE category_id = ? AND expense_date BETWEEN ? AND ?
                           AND (? = 0 OR id <> ?)) AS spent
                  FROM expense_categories ec
             LEFT JOIN chart_of_accounts coa ON coa.id = ec.coa_account_id
             LEFT JOIN budgets b
                    ON b.id = (SELECT id FROM budgets WHERE fiscal_year = ? ORDER BY version DESC LIMIT 1)
             LEFT JOIN budget_lines bl
                    ON bl.budget_id = b.id AND bl.ohada_account_id = coa.ohada_account_id
                 WHERE ec.id = ?
            ");
            $stmt->execute([$cat, $ymStart, $ymEnd, $exclude, $exclude, $year, $cat]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['budget'=>0,'spent'=>0];
            jr('success', '', [
                'budget' => (float)$r['budget'],
                'spent'  => (float)$r['spent'],
                'month'  => $month,
                'year'   => $year,
            ]);
        }

        jr('error', "Action inconnue: $action");
    }

    // ========================================================================
    // POST — writes
    // ========================================================================
    if ($method === 'POST') {

        // ---- SAVE / UPDATE EXPENSE --------------------------------------
        if ($action === 'save') {
            Rbac::requirePermission('accounting.expenses.create');
            $j = json_body();
            $id            = (int)   ($j['id'] ?? 0);
            $title         = trim((string) ($j['title'] ?? ''));
            $description   = trim((string) ($j['description'] ?? ''));
            $category_id   = (int)   ($j['category_id'] ?? 0);
            $supplier_id   = !empty($j['supplier_id']) ? (int) $j['supplier_id'] : null;
            $amount        = (float) ($j['amount'] ?? 0);
            $expense_date  = cleanDate($j['expense_date'] ?? null);
            $payment_status= in_array($j['payment_status'] ?? 'unpaid', ['paid','unpaid'], true) ? $j['payment_status'] : 'unpaid';
            $treasury_id   = !empty($j['treasury_account_id']) ? (int) $j['treasury_account_id'] : null;
            $payment_date  = cleanDate($j['payment_date'] ?? null);

            if ($title === '')       jr('error', 'Titre requis.');
            if ($category_id <= 0)   jr('error', 'Catégorie requise.');
            if ($amount <= 0)        jr('error', 'Le montant doit être supérieur à 0.');
            // cleanDate() also rejects impossible calendar dates (2026-02-31),
            // which the old /^\d{4}-\d{2}-\d{2}$/ check let through to MySQL.
            if ($expense_date === null) jr('error', 'Date invalide.');

            // Answer #8: payment source is required on every expense — even
            // draft. Enforced here rather than DB so the message is friendly.
            if (!$treasury_id) jr('error', 'Compte de trésorerie requis (même pour une dépense non-payée).');

            // If marked paid, settle it on the day the charge was incurred
            // rather than on "today", so the ledger month matches the budget
            // month that expense_date already drives.
            if ($payment_status === 'paid' && $payment_date === null) $payment_date = $expense_date;

            $db->beginTransaction();

            // Whether this save is the moment the expense *becomes* paid. Only
            // that transition may move money: re-saving a row that is already
            // paid must not debit the treasury or post a second journal entry.
            // Legacy rows migrated from `overheads` are exactly this case —
            // 053 backfilled them with payment_status='paid' and no
            // journal_entry_id, so the "already posted?" check below does not
            // catch them and a re-save would have debited a real account for a
            // payment that happened months ago.
            $wasPaid   = false;
            $reference = null;

            if ($id > 0) {
                Rbac::requirePermission('accounting.expenses.edit');
                // Fetch old row for status-transition handling below.
                $old = $db->prepare("SELECT * FROM expenses WHERE id = ? FOR UPDATE");
                $old->execute([$id]);
                $old = $old->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw new RuntimeException('Dépense introuvable.');
                $wasPaid   = ($old['payment_status'] === 'paid');
                $reference = $old['reference'];
                // A row already posted to the ledger cannot be re-edited here
                // (accounting integrity). Force unpaid → paid transition through
                // mark_paid instead, and refuse edits once posted.
                if (!empty($old['journal_entry_id'])) {
                    throw new RuntimeException('Dépense déjà comptabilisée. Utilisez "Extourner" pour corriger.');
                }
                $upd = $db->prepare("
                    UPDATE expenses SET
                        title = ?, description = ?, category_id = ?, supplier_id = ?,
                        amount = ?, expense_date = ?, payment_status = ?,
                        treasury_account_id = ?, payment_date = ?
                    WHERE id = ?
                ");
                $upd->execute([
                    $title, $description ?: null, $category_id, $supplier_id,
                    $amount, $expense_date, $payment_status,
                    $treasury_id,
                    $payment_status === 'paid' ? $payment_date : null,
                    $id,
                ]);
                $expense_id = $id;
            } else {
                $ref = $reference = nextReference($db);
                $ins = $db->prepare("
                    INSERT INTO expenses
                        (reference, title, description, category_id, supplier_id, amount,
                         expense_date, payment_status, treasury_account_id, payment_date, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $ref, $title, $description ?: null, $category_id, $supplier_id, $amount,
                    $expense_date, $payment_status, $treasury_id,
                    $payment_status === 'paid' ? $payment_date : null,
                    $user_id,
                ]);
                $expense_id = (int) $db->lastInsertId();
            }

            // If saved directly as "paid", post the JE right away (answer #12).
            if ($payment_status === 'paid' && !$wasPaid) {
                $coa = coaForCategory($db, $category_id);
                if (!$coa) throw new RuntimeException("La catégorie n'a pas de compte OHADA associé. Corrigez le mapping.");
                // Deduct from treasury balance + log the transaction, exactly
                // the same way treasury_controller does for its own 'expense' action.
                $bal = $db->prepare("SELECT balance FROM treasury_accounts WHERE id = ? FOR UPDATE");
                $bal->execute([$treasury_id]);
                $curr = (float) $bal->fetchColumn();
                if ($curr < $amount) throw new RuntimeException("Fonds insuffisants sur le compte de trésorerie.");

                $db->prepare("UPDATE treasury_accounts SET balance = balance - ? WHERE id = ?")
                   ->execute([$amount, $treasury_id]);

                // Stamp the expense's own reference, not "EXP-<id>". mark_paid
                // and quick_entry both use the reference, so the treasury
                // ledger was carrying two different citation formats for the
                // same kind of movement and only one of them could be matched
                // back to a row in Dépenses.
                $db->prepare("INSERT INTO treasury_transactions
                              (account_id, transaction_type, amount, reference, description, logged_by)
                              VALUES (?, 'out_expense', ?, ?, ?, ?)")
                   ->execute([$treasury_id, $amount, $reference, $title, $user_id]);

                $je_id = JournalPoster::postExpense($coa, $treasury_id, $amount, $title, '', $payment_date);
                $db->prepare("UPDATE expenses SET journal_entry_id = ? WHERE id = ?")
                   ->execute([$je_id, $expense_id]);
            }

            $db->commit();
            jr('success', 'Dépense enregistrée.', ['id' => $expense_id]);
        }

        // ---- MARK PAID (unpaid → paid, posts to ledger) ------------------
        if ($action === 'mark_paid') {
            Rbac::requirePermission('accounting.expenses.mark_paid');
            $j = json_body();
            $id           = (int) ($j['id'] ?? 0);
            $treasury_id  = (int) ($j['treasury_account_id'] ?? 0);
            $payment_date = cleanDate($j['payment_date'] ?? null) ?? date('Y-m-d');
            if ($id <= 0 || $treasury_id <= 0) jr('error', 'Paramètres incomplets.');

            $db->beginTransaction();
            $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $exp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exp)                              throw new RuntimeException('Dépense introuvable.');
            if ($exp['payment_status'] === 'paid')  throw new RuntimeException('Déjà payée.');

            $coa = coaForCategory($db, (int)$exp['category_id']);
            if (!$coa) throw new RuntimeException("La catégorie n'a pas de compte OHADA.");

            $bal = $db->prepare("SELECT balance FROM treasury_accounts WHERE id = ? FOR UPDATE");
            $bal->execute([$treasury_id]);
            if (((float)$bal->fetchColumn()) < (float)$exp['amount'])
                throw new RuntimeException("Fonds insuffisants.");

            $db->prepare("UPDATE treasury_accounts SET balance = balance - ? WHERE id = ?")
               ->execute([$exp['amount'], $treasury_id]);
            $db->prepare("INSERT INTO treasury_transactions
                          (account_id, transaction_type, amount, reference, description, logged_by)
                          VALUES (?, 'out_expense', ?, ?, ?, ?)")
               ->execute([$treasury_id, $exp['amount'], $exp['reference'], $exp['title'], $user_id]);

            $je_id = JournalPoster::postExpense($coa, $treasury_id, (float)$exp['amount'], $exp['title'], '', $payment_date);

            $db->prepare("UPDATE expenses SET payment_status='paid', payment_date=?,
                          treasury_account_id=?, journal_entry_id=? WHERE id = ?")
               ->execute([$payment_date, $treasury_id, $je_id, $id]);

            $db->commit();
            jr('success', 'Dépense marquée payée et comptabilisée.', ['journal_entry_id' => $je_id]);
        }

        // ---- QUICK ENTRY (fed by the cashflow.php Sortie de Caisse form) -
        if ($action === 'quick_entry') {
            Rbac::requirePermission('accounting.expenses.create');
            // Accepts $_POST rather than JSON — cashflow.js sends it as a form.
            $treasury_id = (int)   ($_POST['account_id']   ?? 0);
            $amount      = (float) ($_POST['amount']       ?? 0);
            $description = trim((string) ($_POST['description'] ?? ''));
            $category_id = (int)   ($_POST['category_id']  ?? 0);
            if ($treasury_id <= 0) jr('error', 'Compte requis.');
            if ($amount     <= 0)  jr('error', 'Montant invalide.');
            if ($description === '') jr('error', 'Description requise.');
            // Default the category to "Autre" if the caller did not pick one,
            // so the row still lands in the module and shows up in reports —
            // the user can re-categorise it later from the Dépenses list.
            if ($category_id <= 0) {
                $category_id = (int) $db->query("SELECT id FROM expense_categories WHERE slug='autre' LIMIT 1")->fetchColumn();
                if ($category_id <= 0) jr('error', "Catégorie 'Autre' introuvable — la migration 053 n'est pas appliquée.");
            }

            $coa = coaForCategory($db, $category_id);
            if (!$coa) jr('error', "La catégorie n'a pas de compte OHADA.");

            $db->beginTransaction();

            $bal = $db->prepare("SELECT balance FROM treasury_accounts WHERE id = ? FOR UPDATE");
            $bal->execute([$treasury_id]);
            if (((float)$bal->fetchColumn()) < $amount) throw new RuntimeException("Fonds insuffisants.");

            $ref = nextReference($db);
            $ins = $db->prepare("
                INSERT INTO expenses
                    (reference, title, description, category_id, amount, expense_date,
                     payment_status, treasury_account_id, payment_date, created_by)
                VALUES (?, ?, NULL, ?, ?, CURDATE(), 'paid', ?, CURDATE(), ?)
            ");
            $ins->execute([$ref, $description, $category_id, $amount, $treasury_id, $user_id]);
            $expense_id = (int) $db->lastInsertId();

            $db->prepare("UPDATE treasury_accounts SET balance = balance - ? WHERE id = ?")
               ->execute([$amount, $treasury_id]);
            $db->prepare("INSERT INTO treasury_transactions
                          (account_id, transaction_type, amount, reference, description, logged_by)
                          VALUES (?, 'out_expense', ?, ?, ?, ?)")
               ->execute([$treasury_id, $amount, $ref, $description, $user_id]);

            // quick_entry always books CURDATE(), so the JE date matches by
            // construction — passed explicitly so the two cannot drift apart.
            $je_id = JournalPoster::postExpense($coa, $treasury_id, $amount, $description, '', date('Y-m-d'));
            $db->prepare("UPDATE expenses SET journal_entry_id = ? WHERE id = ?")
               ->execute([$je_id, $expense_id]);

            $db->commit();
            jr('success', 'Dépense enregistrée.', [
                'id' => $expense_id, 'journal_entry_id' => $je_id, 'reference' => $ref,
            ]);
        }

        // ---- DELETE EXPENSE ---------------------------------------------
        if ($action === 'delete') {
            Rbac::requirePermission('accounting.expenses.delete');
            $j = json_body();
            $id = (int) ($j['id'] ?? ($_POST['id'] ?? 0));
            if ($id <= 0) jr('error', 'ID manquant.');

            $db->beginTransaction();
            $stmt = $db->prepare("SELECT reference, payment_status, journal_entry_id, amount, treasury_account_id
                                    FROM expenses WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $exp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exp) throw new RuntimeException('Dépense introuvable.');

            // Posted entries cannot be silently deleted (migration 004). We
            // reverse the JE, credit the treasury balance back, and only then
            // drop the expense row.
            if (!empty($exp['journal_entry_id'])) {
                // JE reversal — mirror of the source entry.
                $rev = $db->prepare("SELECT account_id, debit, credit FROM journal_lines
                                       WHERE journal_entry_id = ?");
                $rev->execute([$exp['journal_entry_id']]);
                $lines = $rev->fetchAll(PDO::FETCH_ASSOC);

                $ins = $db->prepare("INSERT INTO journal_entries
                    (reference, journal_code, date, description, status, created_by)
                    VALUES (?, 'OD', CURDATE(), ?, 'draft', ?)");
                $ins->execute(['EXT-EX-'.$id.'-'.date('ymdHis'),
                               "Extourne suppression dépense #$id", $user_id]);
                $revId = (int)$db->lastInsertId();
                foreach ($lines as $l) {
                    $db->prepare("INSERT INTO journal_lines
                                  (journal_entry_id, account_id, debit, credit)
                                  VALUES (?, ?, ?, ?)")
                       ->execute([$revId, $l['account_id'], (float)$l['credit'], (float)$l['debit']]);
                }
                $db->prepare("CALL post_journal_entry(?, ?)")->execute([$revId, $user_id]);
                $db->prepare("UPDATE journal_entries SET status='reversed', reversed_by_entry_id=? WHERE id=?")
                   ->execute([$revId, $exp['journal_entry_id']]);

                // Refund treasury.
                //
                // transaction_type MUST be a member of the column's enum:
                //   ('in_tournee','in_other','out_expense','transfer_in','transfer_out')
                // This wrote 'adjustment_in', which is not in it. Under a strict
                // sql_mode that raises a truncation error and rolls the whole
                // delete back; under a lax one MariaDB coerces it to '' and the
                // refund lands in the ledger as an untyped movement. Either way
                // deleting a posted expense was broken. 'in_other' is the enum's
                // catch-all inbound type and is what the rest of the codebase
                // uses for non-tournée money coming back into an account.
                if (!empty($exp['treasury_account_id'])) {
                    $db->prepare("UPDATE treasury_accounts SET balance = balance + ? WHERE id = ?")
                       ->execute([$exp['amount'], $exp['treasury_account_id']]);
                    $db->prepare("INSERT INTO treasury_transactions
                                  (account_id, transaction_type, amount, reference, description, logged_by)
                                  VALUES (?, 'in_other', ?, ?, ?, ?)")
                       ->execute([$exp['treasury_account_id'], $exp['amount'],
                                  'ANN-' . ($exp['reference'] ?? $id),
                                  "Annulation dépense #$id", $user_id]);
                }
            }

            // Attachment rows go with the expense via ON DELETE CASCADE, but the
            // files themselves do not — they would sit under /uploads/expenses
            // forever with nothing pointing at them. Collect the paths before
            // the row disappears and unlink after the commit succeeds.
            $paths = $db->prepare("SELECT stored_path FROM expense_attachments WHERE expense_id = ?");
            $paths->execute([$id]);
            $orphans = $paths->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $db->prepare("DELETE FROM expenses WHERE id = ?")->execute([$id]);
            $db->commit();

            foreach ($orphans as $p) {
                $full = docRoot() . '/' . ltrim((string) $p, '/');
                if (is_file($full)) @unlink($full);
            }
            jr('success', 'Dépense supprimée.');
        }

        // ---- CATEGORIES : save --------------------------------------------
        if ($action === 'save_category') {
            Rbac::requirePermission('accounting.expenses.categories.manage');
            $j    = json_body();
            $id   = (int) ($j['id'] ?? 0);
            $name = trim((string) ($j['name'] ?? ''));
            $coa  = !empty($j['coa_account_id']) ? (int)$j['coa_account_id'] : null;
            $icon = trim((string) ($j['icon']  ?? '')) ?: null;
            $color= trim((string) ($j['color'] ?? '')) ?: null;
            $active = !empty($j['is_active']) ? 1 : 0;
            if ($name === '') jr('error', 'Nom requis.');
            // A category with no OHADA account cannot be posted: mark_paid and
            // quick_entry both refuse it via coaForCategory(). Rejecting it here
            // means the operator finds out while creating the category, not
            // weeks later when the first payment against it will not settle.
            // The modal already marks this field required — the controller did
            // not, so a category saved before the dropdown had loaded stuck.
            if (!$coa) jr('error', "Compte OHADA (Classe 6) requis — sans lui, aucune dépense de cette catégorie ne pourra être comptabilisée.");
            $chk = $db->prepare("SELECT 1 FROM chart_of_accounts WHERE id = ?");
            $chk->execute([$coa]);
            if (!$chk->fetchColumn()) jr('error', 'Compte OHADA introuvable.');
            if ($id > 0) {
                $db->prepare("UPDATE expense_categories SET name=?, coa_account_id=?, icon=?, color=?, is_active=?
                                WHERE id = ?")
                   ->execute([$name, $coa, $icon, $color, $active, $id]);
            } else {
                $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($name));
                $slug = trim($slug, '_') ?: ('cat_' . bin2hex(random_bytes(2)));
                $db->prepare("INSERT INTO expense_categories (name, slug, coa_account_id, icon, color, is_active, is_system, sort_order)
                              VALUES (?, ?, ?, ?, ?, ?, 0, 500)")
                   ->execute([$name, $slug, $coa, $icon, $color, $active]);
                $id = (int)$db->lastInsertId();
            }
            jr('success', 'Catégorie enregistrée.', ['id'=>$id]);
        }

        if ($action === 'delete_category') {
            Rbac::requirePermission('accounting.expenses.categories.manage');
            $j  = json_body();
            $id = (int) ($j['id'] ?? 0);
            if ($id <= 0) jr('error', 'ID manquant.');
            $meta = $db->prepare("SELECT is_system,
                                         (SELECT COUNT(*) FROM expenses WHERE category_id = ?) n
                                    FROM expense_categories WHERE id = ?");
            $meta->execute([$id, $id]);
            $m = $meta->fetch(PDO::FETCH_ASSOC);
            if (!$m)              jr('error', 'Catégorie introuvable.');
            if ($m['is_system'])  jr('error', 'Catégorie système — désactivez-la plutôt.');
            if ((int)$m['n'] > 0) jr('error', "Cette catégorie est utilisée par {$m['n']} dépense(s). Désactivez-la à la place.");
            $db->prepare("DELETE FROM expense_categories WHERE id = ?")->execute([$id]);
            jr('success', 'Catégorie supprimée.');
        }

        // ---- RECURRING TEMPLATES -----------------------------------------
        if ($action === 'save_recurring') {
            Rbac::requirePermission('accounting.expenses.recurring.manage');
            $j = json_body();
            $id           = (int)   ($j['id'] ?? 0);
            $name         = trim((string)($j['name'] ?? ''));
            $category_id  = (int)   ($j['category_id'] ?? 0);
            $supplier_id  = !empty($j['supplier_id']) ? (int)$j['supplier_id'] : null;
            $treasury_id  = !empty($j['treasury_account_id']) ? (int)$j['treasury_account_id'] : null;
            $amount       = (float) ($j['amount'] ?? 0);
            $dom          = max(1, min(28, (int)($j['day_of_month'] ?? 1)));
            $notes        = trim((string)($j['notes'] ?? '')) ?: null;
            if ($name==='' || $category_id<=0 || $amount<=0) jr('error', 'Champs requis manquants.');
            if ($id > 0) {
                $db->prepare("UPDATE expense_recurring_templates
                              SET name=?, category_id=?, supplier_id=?, treasury_account_id=?, amount=?,
                                  day_of_month=?, notes=?
                              WHERE id = ?")
                   ->execute([$name, $category_id, $supplier_id, $treasury_id, $amount, $dom, $notes, $id]);
            } else {
                $db->prepare("INSERT INTO expense_recurring_templates
                              (name, category_id, supplier_id, treasury_account_id, amount,
                               day_of_month, is_active, notes, created_by)
                              VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)")
                   ->execute([$name, $category_id, $supplier_id, $treasury_id, $amount, $dom, $notes, $user_id]);
                $id = (int)$db->lastInsertId();
            }
            jr('success', 'Modèle enregistré.', ['id'=>$id]);
        }

        if ($action === 'toggle_recurring') {
            Rbac::requirePermission('accounting.expenses.recurring.manage');
            $j  = json_body();
            $id = (int) ($j['id'] ?? 0);
            if ($id <= 0) jr('error', 'ID manquant.');
            $st = $db->prepare("UPDATE expense_recurring_templates SET is_active = 1 - is_active WHERE id = ?");
            $st->execute([$id]);
            if ($st->rowCount() === 0) jr('error', 'Modèle introuvable.');
            jr('success', 'Modèle mis à jour.');
        }

        if ($action === 'delete_recurring') {
            Rbac::requirePermission('accounting.expenses.recurring.manage');
            $j  = json_body();
            $id = (int) ($j['id'] ?? 0);
            if ($id <= 0) jr('error', 'ID manquant.');
            // Generated expenses keep pointing at the template through
            // recurring_template_id (ON DELETE SET NULL), so history survives.
            $db->prepare("DELETE FROM expense_recurring_templates WHERE id = ?")->execute([$id]);
            jr('success', 'Modèle supprimé.');
        }

        // ---- ATTACHMENTS -------------------------------------------------
        // Routed through Uploads::saveUploaded rather than hand-rolled, for the
        // same reasons that class exists (see its header). The version this
        // replaces:
        //
        //   · trusted $_FILES['file']['type'], which is whatever the client
        //     claims. Sending Content-Type: image/png with a .phtml payload
        //     passed the whitelist, and the stored name kept the attacker's
        //     extension because it was built from $_FILES[...]['name'].
        //     uploads/.htaccess is the only thing that stopped that being RCE,
        //     and a single misconfigured vhost removes that backstop.
        //   · never chmod'd the file, so under cPanel (PHP writes as the account
        //     user, Apache serves as another) receipts 403'd in the browser.
        //   · did not verify the expense exists — an orphan row with an FK
        //     violation, or a file on disk pointing at nothing.
        //
        // saveUploaded verifies the MIME with finfo, derives the extension from
        // the verified type, randomises the filename, caps the size, re-encodes
        // images through GD to strip polyglot payloads, and sets 0644.
        if ($action === 'upload_attachment') {
            Rbac::requirePermission('accounting.expenses.edit');
            $id = (int) ($_POST['expense_id'] ?? 0);
            if ($id <= 0) jr('error', 'Dépense manquante.');
            if (empty($_FILES['file'])) jr('error', 'Fichier manquant.');

            $exists = $db->prepare("SELECT 1 FROM expenses WHERE id = ?");
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) jr('error', 'Dépense introuvable.');

            try {
                $up = Uploads::saveUploaded('file', 'expenses', [
                    'allowed_mime' => ['application/pdf','image/png','image/jpeg','image/webp','image/gif'],
                    'max_bytes'    => 10 * 1024 * 1024,
                    'sanitize_img' => true,
                ]);
            } catch (Throwable $e) {
                jr('error', 'Fichier refusé : ' . $e->getMessage());
            }

            // Keep the operator's original filename for display only — it is
            // escaped in the UI and never touches the filesystem.
            $display = (string) ($_FILES['file']['name'] ?? 'reçu');
            if (mb_strlen($display) > 255) $display = mb_substr($display, 0, 255);

            $db->prepare("INSERT INTO expense_attachments
                          (expense_id, filename, stored_path, mime_type, size_bytes, uploaded_by)
                          VALUES (?, ?, ?, ?, ?, ?)")
               ->execute([$id, $display, $up['path'], $up['mime'], $up['size'], $user_id]);

            jr('success', 'Fichier ajouté.', [
                'id'   => (int) $db->lastInsertId(),
                'path' => $up['path'],
            ]);
        }

        if ($action === 'delete_attachment') {
            Rbac::requirePermission('accounting.expenses.edit');
            $j   = json_body();
            $id  = (int) ($j['id'] ?? 0);
            if ($id <= 0) jr('error', 'ID manquant.');
            $row = $db->prepare("SELECT stored_path FROM expense_attachments WHERE id = ?");
            $row->execute([$id]);
            $p = $row->fetchColumn();
            if ($p === false) jr('error', 'Pièce jointe introuvable.');

            $db->prepare("DELETE FROM expense_attachments WHERE id = ?")->execute([$id]);

            // Unlink only after the row is gone, and only inside /uploads —
            // stored_path comes from our own writer, but a realpath containment
            // check costs nothing and makes that guarantee local.
            $base = realpath(docRoot() . '/uploads');
            $full = realpath(docRoot() . '/' . ltrim((string) $p, '/'));
            if ($base && $full && strpos($full, $base . DIRECTORY_SEPARATOR) === 0 && is_file($full)) {
                @unlink($full);
            }
            jr('success', 'Pièce jointe supprimée.');
        }

        jr('error', "Action inconnue: $action");
    }

    jr('error', 'Méthode HTTP non supportée.');

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    // The message on Rbac + business exceptions is written for the operator.
    error_log('expenses_controller: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(400);
    jr('error', $e->getMessage());
}
