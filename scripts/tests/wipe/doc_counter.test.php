<?php
/**
 * scripts/tests/wipe/doc_counter.test.php
 * -----------------------------------------------------------------------------
 * Unbroken document numbers (includes/classes/DocCounter.php, migration 350).
 *
 *   · numbers follow each other: FAC-2611-0001, 0002, …, in the format and with
 *     the prefix set in Préférences, restarting as doc_sequence_reset says;
 *   · two people saving at once never share a number: several PHP processes
 *     save invoices in parallel and every number from 1 to the total appears
 *     exactly once;
 *   · the counter row is locked until the saving transaction ends, and a save
 *     that fails gives its number back (no gap);
 *   · a number an old random code already took is stepped over;
 *   · every controller that numbers a document takes its number from
 *     DocCounter inside its transaction.
 *
 * Run: WIPE_TEST_DB_PORT=3307 WIPE_TEST_DB_NAME=bureau_wipe_test php scripts/tests/wipe/doc_counter.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';

// Child process of the parallel test: save N invoices, print their references
if (preg_match('/^--child=(\d+)$/', $argv[1] ?? '', $m)) {
    $db = wipe_test_db(false);
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');   // only references matter here, not the invoice's client
    $ins = $db->prepare("INSERT INTO invoices (reference) VALUES (?)");
    for ($i = 0; $i < (int) $m[1]; $i++) {
        for ($try = 0; ; $try++) {
            try {
                $db->beginTransaction();
                $ref = DocCounter::next($db, 'invoice', '2026-11-02');
                usleep(random_int(0, 3000));   // others queue on the counter meanwhile
                $ins->execute([$ref]);
                $db->commit();
                echo $ref, "\n";
                break;
            } catch (PDOException $e) {
                $db->rollBack();
                // A deadlock is retried like any InnoDB caller would; a duplicate never is
                if ($try > 5 || !in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
                    throw $e;
                }
            }
        }
    }
    exit(0);
}

$db = wipe_test_db();
$db->exec('SET FOREIGN_KEY_CHECKS = 0');   // documents are saved with their reference only, not their client or order

/** Take a number for $type in its own transaction, saving the document like a controller does */
$save = function (string $type, ?string $date = null) use ($db): string {
    $db->beginTransaction();
    $ref = DocCounter::next($db, $type, $date);
    $db->prepare('INSERT INTO `' . DocCounter::TYPES[$type]['table'] . '` (reference) VALUES (?)')->execute([$ref]);
    $db->commit();

    return $ref;
};

section('numbers follow each other, in the configured format');
check('first invoice of Nov 2026 is FAC-2611-0001', ($a = $save('invoice', '2026-11-02')) === 'FAC-2611-0001', $a);
check('then FAC-2611-0002', ($a = $save('invoice', '2026-11-15')) === 'FAC-2611-0002', $a);
check('a quote has its own counter: DEV-2611-0001', ($a = $save('quote', '2026-11-02')) === 'DEV-2611-0001', $a);
check('order CMD-2611-0001', ($a = $save('order', '2026-11-02')) === 'CMD-2611-0001', $a);
check('delivery note BL-2611-0001', ($a = $save('delivery', '2026-11-02')) === 'BL-2611-0001', $a);
check('purchase order keeps its PO prefix: PO-2611-0001', ($a = $save('purchase', '2026-11-02')) === 'PO-2611-0001', $a);
check('receipt REC-2611-0001', ($a = $save('receipt', '2026-11-02')) === 'REC-2611-0001', $a);
check('wallet payment WLT-2611-0001 (same table, own counter)', ($a = $save('wallet', '2026-11-02')) === 'WLT-2611-0001', $a);
check('expense EX-2611-0001', ($a = $save('expense', '2026-11-02')) === 'EX-2611-0001', $a);
check('recurring expense EXR-2611-0001', ($a = $save('expense_recurring', '2026-11-02')) === 'EXR-2611-0001', $a);
check('CRE keeps its day: CRE-20261102-001', ($a = $save('cre', '2026-11-02')) === 'CRE-20261102-001', $a);
check('next CRE that day: CRE-20261102-002', ($a = $save('cre', '2026-11-02')) === 'CRE-20261102-002', $a);
check('CRE restarts the next day: CRE-20261103-001', ($a = $save('cre', '2026-11-03')) === 'CRE-20261103-001', $a);
check('inventory report INV-20261102-001', ($a = $save('inventory', '2026-11-02')) === 'INV-20261102-001', $a);
check('recycling sale REC-202611-001', ($a = $save('recycling', '2026-11-02')) === 'REC-202611-001', $a);
check('recycling sale the next DAY continues: REC-202611-002 (it used to restart at 001 daily)',
      ($a = $save('recycling', '2026-11-03')) === 'REC-202611-002', $a);
check('today when no date is given', ($a = $save('quote')) === 'DEV-' . date('ym') . '-' . (date('ym') === '2611' ? '0002' : '0001'), $a);

section('the counter restarts as Préférences say');
check('yearly (default): December continues November', ($a = $save('invoice', '2026-12-31')) === 'FAC-2612-0003', $a);
check('yearly: January starts again', ($a = $save('invoice', '2027-01-01')) === 'FAC-2701-0001', $a);
wipe_test_pref($db, 'doc_sequence_reset', 'monthly');
check('monthly: December 2026 has its own count', ($a = $save('order', '2026-12-05')) === 'CMD-2612-0001', $a);
check('monthly: November 2026 continues where it was', ($a = $save('order', '2026-11-30')) === 'CMD-2611-0002', $a);
wipe_test_pref($db, 'doc_sequence_reset', 'never');
// Switching the rule starts a new count; BL-2611-0001 exists already, so it is stepped over
check('never: one count for ever (a new count steps over numbers already used)',
      ($a = $save('delivery', '2026-11-02')) === 'BL-2611-0002' && ($b = $save('delivery', '2027-03-01')) === 'BL-2703-0003', $a . ' ' . ($b ?? ''));
wipe_test_pref($db, 'doc_sequence_reset', 'yearly');
wipe_test_pref($db, 'doc_prefix_invoice', 'FA');
wipe_test_pref($db, 'doc_number_format', '{PREFIX}{YYYY}{SEQ}');
wipe_test_pref($db, 'doc_sequence_padding', '5');
check('prefix, format and padding come from Préférences', ($a = $save('invoice', '2026-11-20')) === 'FA202600004', $a);
wipe_test_pref($db, 'doc_prefix_invoice', 'FAC');
wipe_test_pref($db, 'doc_number_format', '{PREFIX}-{YYMM}-{SEQ}');
wipe_test_pref($db, 'doc_sequence_padding', '4');

section('no gaps, no shared numbers');
$db->beginTransaction();
$taken = DocCounter::next($db, 'invoice', '2026-11-21');
$db->rollBack();
check('a save that fails gives its number back', ($a = $save('invoice', '2026-11-21')) === $taken && $taken === 'FAC-2611-0005', "{$taken} then {$a}");

$db->exec("INSERT INTO invoices (reference) VALUES ('FAC-2611-0007')");   // an old random code that was all digits
check('then FAC-2611-0006', ($a = $save('invoice', '2026-11-22')) === 'FAC-2611-0006', $a);
check('FAC-2611-0007 was already used by an old code: stepped over to 0008', ($a = $save('invoice', '2026-11-22')) === 'FAC-2611-0008', $a);

$other = wipe_test_connect();
$other->exec('SET SESSION innodb_lock_wait_timeout = 1');
$db->beginTransaction();
$mine = DocCounter::next($db, 'invoice', '2026-11-23');
$other->beginTransaction();
$e = throws(fn () => DocCounter::next($other, 'invoice', '2026-11-23'));
$other->rollBack();
check('while one save is open, a second waits on the counter row (lock wait)', $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1205,
      $e ? get_class($e) . ': ' . $e->getMessage() : 'no wait');
$db->prepare("INSERT INTO invoices (reference) VALUES (?)")->execute([$mine]);
$db->commit();
$other->beginTransaction();
$theirs = DocCounter::next($other, 'invoice', '2026-11-23');
$other->commit();
check('once the first is saved, the second gets the next number', $mine === 'FAC-2611-0009' && $theirs === 'FAC-2611-0010', "{$mine}, {$theirs}");

$e = throws(fn () => DocCounter::next($db, 'invoice'));
check('refused outside a transaction (a number nothing protects)', $e instanceof LogicException);
$e = throws(function () use ($db) { $db->beginTransaction(); try { DocCounter::next($db, 'nonsense'); } finally { $db->rollBack(); } });
check('unknown document type refused', $e instanceof InvalidArgumentException);

section('several people saving invoices at the same moment');
$db->exec("DELETE FROM invoices");
$db->exec("DELETE FROM document_counters WHERE doc_type = 'invoice'");
$workers = 6;
$each = 25;
$procs = [];
for ($w = 0; $w < $workers; $w++) {
    $procs[] = proc_open([PHP_BINARY, __FILE__, "--child={$each}"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$w]);
}
$refs = [];
$errors = '';
foreach ($procs as $w => $p) {
    $refs = array_merge($refs, array_filter(explode("\n", stream_get_contents($pipes[$w][1]))));
    $errors .= stream_get_contents($pipes[$w][2]);
    $code = proc_close($p);
    if ($code !== 0) {
        $errors .= " (worker {$w} exited {$code})";
    }
}
$numbers = array_map(fn ($r) => (int) substr($r, -4), $refs);
sort($numbers);
check("{$workers} processes saved " . ($workers * $each) . ' invoices without an error', count($refs) === $workers * $each && $errors === '', trim($errors));
check('no two invoices share a number', count(array_unique($refs)) === count($refs));
check('the numbers are exactly 1 to ' . ($workers * $each) . ', with no gap', $numbers === range(1, $workers * $each),
      'got ' . implode(',', array_slice($numbers, 0, 12)) . '…');
check('the counter says the same', (int) $db->query("SELECT last_number FROM document_counters WHERE doc_type = 'invoice' AND period = '2026'")->fetchColumn() === $workers * $each);

section('every document takes its number from DocCounter, inside its transaction');
$wiring = [
    'api/v1/invoices_controller.php'         => ['invoice' => 1, 'receipt' => 2, 'wallet' => 1],
    'api/v1/create_proposal.php'             => ['quote' => 1],
    'api/v1/sales_controller.php'            => ['order' => 1, 'delivery' => 1],
    'api/v1/procurement_controller.php'      => ['purchase' => 1],
    'api/v1/expenses_controller.php'         => ['expense' => 2],
    'api/v1/cre_controller.php'              => ['cre' => 1, 'recycling' => 1],
    'api/v1/inventory_controller.php'        => ['inventory' => 1],
    'includes/classes/RecurringExpenses.php' => ['expense_recurring' => 1],
];
foreach ($wiring as $file => $types) {
    $code = wipe_test_code($file);
    foreach ($types as $type => $times) {
        preg_match_all("/DocCounter::next\(\\\$(?:db|pdo), '{$type}'/", $code, $hits, PREG_OFFSET_CAPTURE);
        check("{$file}: {$times}× DocCounter::next(…, '{$type}')", count($hits[0]) === $times, count($hits[0]) . ' found');
        foreach ($hits[0] as [, $at]) {
            $before = substr($code, 0, $at);
            $open = strrpos($before, 'beginTransaction()');
            $closed = strrpos($before, '->commit()');
            check("{$file}: '{$type}' number taken after beginTransaction(), before its commit()",
                  $open !== false && ($closed === false || $closed < $open));
        }
    }
    check("{$file}: no random reference code left", !preg_match('/md5\(uniqid|reference.{0,40}random_bytes|count\(id\) FROM (cre_documents|recycling_sales|inventory_reports)/i', $code));
}
check('every document type is used by some controller',
      array_diff(array_keys(DocCounter::TYPES), array_merge(...array_map('array_keys', array_values($wiring)))) === []);

wipe_test_done();
