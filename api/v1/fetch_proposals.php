<?php
/**
 * api/v1/fetch_proposals.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the répertoire behind the "Devis" tab on
 * modules/crm/clients.php.
 *
 * Until this endpoint existed there was NO way to see a devis again once the
 * create modal handed you its link: get_proposal.php reads a single token,
 * proposal_studio.php touches the table only to grab ORDER BY id DESC LIMIT 1
 * as a preview stand-in, and crm.proposals.view was a permission no page
 * consumed. This is the list.
 *
 * Actions:
 *   list (default) → one page of devis + the three KPIs, both honouring the
 *                    same filter set so the counters always describe the
 *                    rows underneath them.
 *   reps           → distinct sales_rep_name values, for the filter dropdown.
 *
 * ── Why status is DERIVED and not a column ────────────────────────────────
 * create_proposal.php writes status='sent' and nothing ever updates it, so the
 * column carries no information. The three states the répertoire shows are
 * computed instead:
 *
 *   Signé   — a non-revoked row exists in document_signatures for this devis.
 *   Expiré  — not signed AND date + validity_days < today.
 *   Envoyé  — everything else.
 *
 * Signé deliberately uses "any non-revoked signature" rather than
 * DocumentSignature::getActive(), which additionally requires the stored hash
 * to still match the current figures. getActive() is per-row PHP work (a hash
 * over the loaded document) and cannot be expressed in SQL, so paging and
 * counting on it would mean loading the whole table. The stale case — signed,
 * then edited — is surfaced in the detail modal by proposal_detail.php, which
 * does call getActive(). The list only needs to answer "is this locked?", and
 * for locking purposes ANY live signature locks it. update_proposal.php
 * enforces exactly the same rule, so the padlock the user sees in the list is
 * the padlock the server applies.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('crm.proposals.view');

/** Uniform JSON exit — same shape as proposal_signature_controller.php. */
function fp_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fp_fail(string $msg, int $code = 400): void
{
    fp_out(['status' => 'error', 'message' => $msg], $code);
}

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    error_log('fetch_proposals: DB unavailable: ' . $e->getMessage());
    fp_fail('Service indisponible.', 503);
}

$action = (string) ($_GET['action'] ?? 'list');

// -----------------------------------------------------------------------------
// reps — populates the "Commercial" filter. Cheap enough to be its own action
// rather than riding along on every page of the list.
// -----------------------------------------------------------------------------
if ($action === 'reps') {
    try {
        $rows = $db->query("
            SELECT DISTINCT sales_rep_name
              FROM proposals
             WHERE sales_rep_name IS NOT NULL AND sales_rep_name <> ''
             ORDER BY sales_rep_name ASC
        ")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('fetch_proposals reps: ' . $e->getMessage());
        $rows = [];
    }
    fp_out(['status' => 'success', 'data' => $rows]);
}

// -----------------------------------------------------------------------------
// Filters. Every one of them is optional and every one is bound, never
// interpolated — except the sort column, which is whitelisted below.
// -----------------------------------------------------------------------------
$search  = trim((string) ($_GET['search'] ?? ''));
$status  = (string) ($_GET['status'] ?? 'all');   // all|sent|signed|expired
$from    = trim((string) ($_GET['from'] ?? ''));
$to      = trim((string) ($_GET['to'] ?? ''));
$rep     = trim((string) ($_GET['rep'] ?? ''));
$sort    = (string) ($_GET['sort'] ?? 'date');    // date|amount|client|reference
$dir     = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

// A date that isn't a date is dropped rather than 400'd: the picker can emit
// a partial value mid-typing and the list should keep working.
$isDate = static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$isDate($from)) { $from = ''; }
if (!$isDate($to))   { $to   = ''; }

if (!in_array($status, ['all', 'sent', 'signed', 'expired'], true)) {
    $status = 'all';
}

// Whitelist → never a user string in the SQL. 'date' falls back to id as a
// tiebreak because several devis a day share a date and an unstable ORDER BY
// makes pagination drop and repeat rows.
$sortMap = [
    'date'      => 'p.date',
    'amount'    => 'p.total_amount',
    'client'    => 'p.client_name',
    'reference' => 'p.reference',
];
$sortSql = ($sortMap[$sort] ?? 'p.date') . ' ' . $dir . ', p.id ' . $dir;

// -----------------------------------------------------------------------------
// The signature join.
//
// LEFT JOIN onto a grouped subquery rather than a correlated EXISTS so the
// signer name and date come back in the same pass — the répertoire shows them
// as columns on signed rows (and the validity/expiry columns on unsigned
// ones). MAX(id) picks the most recent live signature; the two snapshot
// columns are read back from it by the outer join.
// -----------------------------------------------------------------------------
$sigJoin = "
    LEFT JOIN (
        SELECT ds.document_id, MAX(ds.id) AS sig_id
          FROM document_signatures ds
         WHERE ds.document_type = 'quote' AND ds.revoked_at IS NULL
         GROUP BY ds.document_id
    ) live ON live.document_id = p.id
    LEFT JOIN document_signatures s ON s.id = live.sig_id
";

// migration 048 may not have run on an older install; the whole page degrades
// to "nothing is signed" rather than 500ing, the same way get_proposal.php
// guards pack_note.
$hasSigTable = (int) $db->query("
    SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'document_signatures'
")->fetchColumn() > 0;

if (!$hasSigTable) {
    $sigJoin = '';
}

$signedExpr = $hasSigTable ? 's.id IS NOT NULL' : '0';
// DATE_ADD on a NULL validity_days yields NULL and NULL < CURDATE() is NULL,
// i.e. not expired — which is the right answer for a devis with no stated
// validity. COALESCE would have forced an arbitrary 0-day default and marked
// every such devis expired on the day it was written.
$expiredExpr = "(DATE_ADD(p.`date`, INTERVAL p.validity_days DAY) < CURDATE())";

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(p.reference LIKE :q OR p.client_name LIKE :q OR p.client_contact LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
if ($from !== '') { $where[] = 'p.`date` >= :from'; $params[':from'] = $from; }
if ($to   !== '') { $where[] = 'p.`date` <= :to';   $params[':to']   = $to; }
if ($rep  !== '') { $where[] = 'p.sales_rep_name = :rep'; $params[':rep'] = $rep; }

// Status filter, expressed against the same three expressions the SELECT uses
// so a row can never be counted in one bucket and displayed in another.
if ($status === 'signed') {
    $where[] = $signedExpr;
} elseif ($status === 'expired') {
    $where[] = 'NOT ' . $signedExpr . ' AND ' . $expiredExpr;
} elseif ($status === 'sent') {
    $where[] = 'NOT ' . $signedExpr . ' AND NOT ' . $expiredExpr;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    // ---- total, for the pager -------------------------------------------
    $countStmt = $db->prepare("SELECT COUNT(*) FROM proposals p {$sigJoin} {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $pages  = max(1, (int) ceil($total / $perPage));
    $page   = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    // ---- the page --------------------------------------------------------
    // LIMIT/OFFSET are cast ints, not bound: PDO in emulated-prepare mode
    // quotes bound integers as strings and MySQL rejects 'LIMIT '25''.
    $sql = "
        SELECT
            p.id,
            p.reference,
            p.client_name,
            p.client_contact,
            p.`date`,
            p.validity_days,
            DATE_ADD(p.`date`, INTERVAL p.validity_days DAY) AS expires_on,
            p.total_amount,
            p.sales_rep_name,
            p.token,
            p.language,
            " . ($hasSigTable ? 's.signer_name, s.signer_role, s.signed_at, s.verify_token' :
                                'NULL AS signer_name, NULL AS signer_role, NULL AS signed_at, NULL AS verify_token') . ",
            CASE
                WHEN {$signedExpr}  THEN 'signed'
                WHEN {$expiredExpr} THEN 'expired'
                ELSE 'sent'
            END AS derived_status
        FROM proposals p
        {$sigJoin}
        {$whereSql}
        ORDER BY {$sortSql}
        LIMIT {$perPage} OFFSET {$offset}
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ---- KPIs ------------------------------------------------------------
    //
    // Computed over the FILTERED set, not the whole table. If the user narrows
    // to one rep and one month, the three cards describe that rep and that
    // month — cards that ignored the filters would contradict the rows on
    // screen and be read as a bug.
    //
    //   devis_sent    — every devis in scope (the "sent" total, since a devis
    //                   exists only once it has been generated).
    //   devis_signed  — of those, how many carry a live signature, plus the
    //                   rate, which is the number a sales review actually asks
    //                   for.
    //   pipeline      — FCFA still winnable: unsigned AND not expired. An
    //                   expired unsigned devis is not pipeline, it is a
    //                   follow-up task, and folding it in inflates the figure.
    $kpiSql = "
        SELECT
            COUNT(*)                                                        AS total_count,
            SUM(CASE WHEN {$signedExpr} THEN 1 ELSE 0 END)                  AS signed_count,
            SUM(CASE WHEN {$signedExpr} THEN p.total_amount ELSE 0 END)     AS signed_value,
            SUM(CASE WHEN NOT {$signedExpr} AND NOT {$expiredExpr}
                     THEN p.total_amount ELSE 0 END)                        AS pipeline_value,
            SUM(CASE WHEN NOT {$signedExpr} AND NOT {$expiredExpr}
                     THEN 1 ELSE 0 END)                                     AS pipeline_count,
            SUM(CASE WHEN NOT {$signedExpr} AND {$expiredExpr}
                     THEN 1 ELSE 0 END)                                     AS expired_count
        FROM proposals p
        {$sigJoin}
        {$whereSql}
    ";
    $kpiStmt = $db->prepare($kpiSql);
    $kpiStmt->execute($params);
    $k = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalCount  = (int) ($k['total_count'] ?? 0);
    $signedCount = (int) ($k['signed_count'] ?? 0);

    fp_out([
        'status' => 'success',
        'data'   => $rows,
        'kpis'   => [
            'total_count'    => $totalCount,
            'signed_count'   => $signedCount,
            // Guarded: 0/0 is a fatal in PHP 8, and an empty répertoire is the
            // normal state of a fresh install.
            'signed_rate'    => $totalCount > 0 ? round($signedCount * 100 / $totalCount) : 0,
            'signed_value'   => (float) ($k['signed_value'] ?? 0),
            'pipeline_value' => (float) ($k['pipeline_value'] ?? 0),
            'pipeline_count' => (int) ($k['pipeline_count'] ?? 0),
            'expired_count'  => (int) ($k['expired_count'] ?? 0),
        ],
        'page'     => $page,
        'pages'    => $pages,
        'per_page' => $perPage,
        'total'    => $total,
    ]);

} catch (Throwable $e) {
    error_log('fetch_proposals: ' . $e->getMessage());
    fp_fail('Erreur serveur. Veuillez réessayer.', 500);
}
