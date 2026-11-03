<?php
/**
 * api/v1/data_wipe_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — endpoints of the data wipe tool (modules/admin/data_wipe.php,
 * docs/DATA_WIPE.md). Every request first passes DataWipeAccess::enforce():
 * anyone but the WIPE_ADMIN_EMAIL account, on a URL without WIPE_URL_KEY, gets
 * what an unknown URL gets.
 *
 *   POST {action: 'plan', tables}                      what would go, nothing changes
 *   POST {action: 'send_code', tables}                 email a one-time code for this pick
 *   POST {action: 'run', tables, code, reason}         wipe (copy first), tell the MD
 *   GET  ?action=download&id=N                         the safety copy, while kept
 *
 * One by one (the second tab): records = [{table, id, mode?, to?}], mode and to
 * for a staff account ('handover' to an active account, or 'wipe').
 *   POST {action: 'records_search', table, q}          find by number or name
 *   POST {action: 'records_plan', records}             what would go, nothing changes
 *   POST {action: 'records_send_code', records}        a code tied to these records and choices
 *   POST {action: 'records_run', records, code, reason}
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/DataWipe.php';
require_once __DIR__ . '/../../includes/classes/DataWipeAccess.php';

$db = Database::getInstance()->getConnection();
$user = DataWipeAccess::enforce($db);

if (($_GET['action'] ?? '') === 'download') {
    $st = $db->prepare("SELECT * FROM data_wipes WHERE id = ?");
    $st->execute([(int) ($_GET['id'] ?? 0)]);
    $wipe = $st->fetch(PDO::FETCH_ASSOC);
    $path = $wipe ? DataWipe::copyPath($wipe) : null;
    if (!$path) {
        http_response_code(410);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Cette copie n\'est plus conservée.';
        exit;
    }
    header('Content-Type: application/gzip');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="bureau-effacement-' . (int) $wipe['id'] . '-' . date('Y-m-d', strtotime((string) $wipe['created_at'])) . '.tar.gz"');
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$reply = static function (array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $reply(['status' => 'error', 'message' => 'Méthode non autorisée.'], 405);
}
Csrf::requireValid();
// A plan or a wipe of two months of test data can outlast PHP's 30 seconds.
// Killed mid-wipe, the transaction rolls back but `finally` never runs, so its
// work folder (rows already copied) would stay in the copies folder.
@set_time_limit(300);
ignore_user_abort(true);
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$wiper = new DataWipe($db);
$picked = $wiper->cleanPick((array) ($in['tables'] ?? []));

/** The plan as the screen shows it: no row keys, labels and reasons instead. */
$view = static function (array $plan): array {
    $tables = [];
    foreach ($plan['counts'] as $t => $n) {
        $tables[] = ['table' => $t, 'label' => DataWipeMap::label($t), 'rows' => $n, 'picked' => in_array($t, $plan['picked'], true),
                     'why' => in_array($t, $plan['picked'], true) ? null : ($plan['why'][$t] ?? null)];
    }
    $unlinked = [];
    foreach ($plan['unlinked'] as $tc => $n) {
        [$t, $c] = explode('.', $tc, 2);
        $unlinked[] = ['label' => DataWipeMap::label($t), 'column' => $c, 'rows' => $n];
    }
    return ['picked' => $plan['picked'], 'tables' => $tables, 'unlinked' => $unlinked, 'total' => $plan['total'],
            'blocked' => $plan['blocked'], 'balances' => $plan['balances']];
};

/** A few lines for the code email: what would go. */
$what = static function (array $plan): string {
    $lines = [];
    foreach (array_slice($plan['counts'], 0, 25, true) as $t => $n) {
        $lines[] = '  · ' . DataWipeMap::label($t) . ' : ' . $n;
    }
    if (count($plan['counts']) > 25) {
        $lines[] = '  · … et ' . (count($plan['counts']) - 25) . ' autre(s) table(s)';
    }
    return implode("\n", $lines) . "\n  Total : " . $plan['total'] . ' lignes';
};

/** One by one: the plan as the screen shows it, with hand-overs, mailboxes and numbering. */
$viewRecords = static function (array $plan) use ($view): array {
    $v = $view(['picked' => array_values(array_unique(array_column($plan['records'], 'table')))] + $plan);
    $v['records'] = array_map(fn ($r) => array_intersect_key($r, array_flip(['table', 'id', 'label', 'mode', 'to', 'to_name'])), $plan['records']);
    $v['reassigned'] = array_map(fn ($r) => ['label' => DataWipeMap::label($r['table']), 'column' => $r['column'], 'rows' => $r['rows'], 'to' => $r['to']], $plan['reassigned']);
    $v['mailboxes'] = $plan['mailboxes'];
    $v['numbering'] = $plan['numbering'];
    return $v;
};
$records = (array) ($in['records'] ?? []);

try {
    switch ($in['action'] ?? '') {
        case 'overview':
            $reply(['status' => 'success', 'modules' => $wiper->modules(), 'md' => DataWipeAccess::md($db) ? true : false]);

        case 'plan':
            $reply(['status' => 'success', 'plan' => $view($wiper->plan($picked))]);

        case 'send_code':
            if (!$picked) {
                throw new DomainException('Cochez au moins une table.');
            }
            if (!DataWipeAccess::md($db)) {
                throw new DomainException(DataWipeAccess::mdMissing());
            }
            $plan = $wiper->plan($picked);
            if ($plan['blocked']) {
                throw new DomainException(implode(' ', $plan['blocked']));
            }
            DataWipeAccess::sendCode($db, $user, $picked, $what($plan));
            $reply(['status' => 'success', 'message' => 'Code envoyé à ' . DataWipeAccess::maskedEmail() . '. Il est valable ' . DataWipeAccess::CODE_MINUTES . ' minutes.']);

        case 'run':
            $reason = trim((string) ($in['reason'] ?? ''));
            if (!$picked) {
                throw new DomainException('Cochez au moins une table.');
            }
            if (mb_strlen($reason) < 10) {
                throw new DomainException('Écrivez le motif en quelques mots (10 caractères au moins) : il est envoyé au DG.');
            }
            if (!DataWipeAccess::md($db)) {
                throw new DomainException(DataWipeAccess::mdMissing());
            }
            $codeId = DataWipeAccess::checkCode($db, $user, $picked, trim((string) ($in['code'] ?? '')));
            $done = $wiper->run($picked, $user, $reason, fn (PDO $db) => DataWipeAccess::consumeCode($db, $codeId));
            $told = DataWipeAccess::tellMd($db, $done, $user, $reason);
            $reply(['status' => 'success', 'wipe' => [
                'id' => $done['id'], 'total' => $done['total'], 'expires' => $done['expires'], 'balances' => $done['balances'],
                'tables' => array_map(fn ($t, $n) => ['label' => DataWipeMap::label($t), 'rows' => $n], array_keys($done['counts']), $done['counts']),
                'md' => $told, 'warnings' => $done['warnings'],
            ]]);

        case 'records_search':
            $reply(['status' => 'success', 'results' => $wiper->searchRecords((string) ($in['table'] ?? ''), mb_substr((string) ($in['q'] ?? ''), 0, 100))]);

        case 'records_plan':
            $reply(['status' => 'success', 'plan' => $viewRecords($wiper->planRecords($records))]);

        case 'records_send_code':
            $plan = $wiper->planRecords($records);
            if (!$plan['picked']) {
                throw new DomainException('Choisissez au moins un enregistrement.');
            }
            if (!DataWipeAccess::md($db)) {
                throw new DomainException(DataWipeAccess::mdMissing());
            }
            if ($plan['blocked']) {
                throw new DomainException(implode(' ', $plan['blocked']));
            }
            $chosen = array_map(fn ($r) => '  · ' . $r['label'] . match ($r['mode'] ?? null) {
                'handover' => ' : ses enregistrements passent à ' . $r['to_name'],
                'wipe'     => ' : ses enregistrements sont effacés aussi',
                default    => '',
            }, $plan['records']);
            DataWipeAccess::sendCode($db, $user, $plan['picked'], "Choisis :\n" . implode("\n", $chosen) . "\n\nAvec tout ce qui en dépend :\n" . $what($plan));
            $reply(['status' => 'success', 'message' => 'Code envoyé à ' . DataWipeAccess::maskedEmail() . '. Il est valable ' . DataWipeAccess::CODE_MINUTES . ' minutes.']);

        case 'records_run':
            $reason = trim((string) ($in['reason'] ?? ''));
            $pick = DataWipe::recordPick($wiper->cleanRecords($records)[0]);
            if (!$pick) {
                throw new DomainException('Choisissez au moins un enregistrement.');
            }
            if (mb_strlen($reason) < 10) {
                throw new DomainException('Écrivez le motif en quelques mots (10 caractères au moins) : il est envoyé au DG.');
            }
            if (!DataWipeAccess::md($db)) {
                throw new DomainException(DataWipeAccess::mdMissing());
            }
            $codeId = DataWipeAccess::checkCode($db, $user, $pick, trim((string) ($in['code'] ?? '')));
            $done = $wiper->runRecords($records, $user, $reason, fn (PDO $db) => DataWipeAccess::consumeCode($db, $codeId));
            $told = DataWipeAccess::tellMd($db, $done, $user, $reason);
            $reply(['status' => 'success', 'wipe' => [
                'id' => $done['id'], 'total' => $done['total'], 'expires' => $done['expires'], 'balances' => $done['balances'],
                'tables' => array_map(fn ($t, $n) => ['label' => DataWipeMap::label($t), 'rows' => $n], array_keys($done['counts']), $done['counts']),
                'records' => $done['records'], 'numbering' => $done['numbering'],
                'md' => $told, 'warnings' => $done['warnings'],
            ]]);

        default:
            $reply(['status' => 'error', 'message' => 'Action inconnue.'], 400);
    }
} catch (DomainException $e) {
    $reply(['status' => 'error', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    // Shown to this one account only, never written to the error log (admins read it in Journal d'Erreurs)
    $reply(['status' => 'error', 'message' => $e instanceof RuntimeException ? $e->getMessage()
           : 'Erreur : rien n\'a été effacé. ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'], 500);
}
