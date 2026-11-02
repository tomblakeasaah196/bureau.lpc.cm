<?php
/**
 * scripts/tests/wipe/data_wipe.test.php
 * -----------------------------------------------------------------------------
 * The data wipe tool (includes/classes/DataWipe*.php, migration 355) on a small
 * model of bureau (support/fixture_schema.sql) with bureau's real foreign keys
 * and its "no delete of a posted entry" trigger:
 *
 *   · what a pick takes: foreign keys, links without one (journal source,
 *     receipts' entries and treasury, notifications, activity, PDFs), whole
 *     documents, a client's 411 sub-account; optional links cleared; protected
 *     tables never touched, a pick that would need them refused;
 *   · a wipe: rows gone, posted entries too, balances recalculated, counters
 *     and AUTO_INCREMENT restarted, files copied then deleted, the log kept;
 *   · the copy: restore.sql puts every row and figure back exactly;
 *   · copy and wipe are one: a failure half-way changes nothing and leaves no
 *     copy; one wipe at a time;
 *   · access: only the configured account with the key; codes hashed, tied to
 *     the pick, limited tries, single use; the MD by matricule;
 *   · the cron removes copies past 30 days; the tool is in no menu and writes
 *     nothing to the error log.
 *
 * Run: WIPE_TEST_DB_PORT=3307 WIPE_TEST_DB_NAME=bureau_wipe_test php scripts/tests/wipe/data_wipe.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';
$root = $GLOBALS['__wipe_root'];
$copies = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/bureau_wipe_test_copies_' . getmypid();
$GLOBALS['__env'] = ['WIPE_COPY_DIR' => $copies, 'MAIL_FROM' => ''];

$db = wipe_test_db();
require_once $root . '/includes/classes/DataWipe.php';
require_once $root . '/includes/classes/DataWipeAccess.php';

// ---- A small business --------------------------------------------------------------
$db->exec("CREATE TRIGGER bd_journal_entries_no_post_delete BEFORE DELETE ON journal_entries FOR EACH ROW
           BEGIN IF OLD.status = 'posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_entries: cannot delete a posted entry.'; END IF; END");
$db->exec("INSERT INTO roles (id, name) VALUES (1, 'admin'), (2, 'driver')");
$db->exec("INSERT INTO employees (id, employee_code, first_name, last_name) VALUES (1, 'LPC-MD-01', 'Directeur', 'Test'), (2, 'EMP-004', 'Support', 'Test')");
$db->exec("INSERT INTO users (id, employee_id, role_id, employee_code, first_name, last_name, email) VALUES
           (1, 1, 1, 'LPC-MD-01', 'Directeur', 'Test', 'md@test.invalid'), (2, 2, 1, 'EMP-004', 'Support', 'Test', 'wipe@test.invalid')");
$db->exec("INSERT INTO ohada_accounts (id, account_number) VALUES (1, '401'), (2, '411'), (3, '521'), (4, '701')");
$db->exec("INSERT INTO chart_of_accounts (id, ohada_account_id, code, name) VALUES
           (1, 2, '411', 'Clients'), (2, 3, '5211', 'Banque'), (3, 4, '701', 'Ventes'), (4, 2, '411001', 'Client - Alpha'),
           (5, 2, '411002', 'Client - Beta'), (6, 1, '401001', 'Fournisseur - Gamma')");
$db->exec("INSERT INTO treasury_accounts (id, name, balance, coa_account_id) VALUES (1, 'Banque', 1150000, 2), (2, 'Caisse', 30000, NULL)");
$db->exec("INSERT INTO clients (id, name, account_id) VALUES (1, 'Alpha', 4), (2, 'Beta', 5)");
$db->exec("INSERT INTO suppliers (id, name, account_id) VALUES (1, 'Gamma', 6)");
$db->exec("INSERT INTO products (id, code, name, linked_empty_id, cump) VALUES (1, 'VIDE', 'Bonbonne vide', NULL, 800), (2, 'EAU', 'Eau', 1, 1500), (3, 'JUS', 'Jus', NULL, 700)");
$db->exec("INSERT INTO vehicles (id, plate_number) VALUES (1, 'LT-1')");
$db->exec("INSERT INTO proposals (id, reference, client_name) VALUES (1, 'DEV-2611-0001', 'Prospect')");
$db->exec("INSERT INTO proposal_items (id, proposal_id, product_id) VALUES (1, 1, 2)");
$db->exec("INSERT INTO sales_orders (id, reference, client_id, total_amount, payment_status) VALUES (1, 'CMD-2611-0001', 1, 100000, 'paid'), (2, 'CMD-2611-0002', 2, 50000, 'partial')");
$db->exec("INSERT INTO sales_order_items (id, sales_order_id, product_id, quantity) VALUES (1, 1, 2, 40), (2, 2, 3, 10)");
$db->exec("INSERT INTO deliveries (id, reference, sales_order_id, client_id, vehicle_id, status, created_at, signed_at) VALUES
           (1, 'BL-2611-0001', 1, 1, 1, 'completed', '2026-11-02 09:00:00', '2026-11-02 11:00:00'),
           (2, 'BL-2611-0002', 2, 2, 1, 'dispatched', '2026-11-02 10:00:00', NULL)");
$db->exec("INSERT INTO delivery_items (id, delivery_id, product_id, quantity, delivered_quantity, returned_empty_qty) VALUES (1, 1, 2, 40, 40, 15), (2, 2, 3, 10, NULL, 0)");
$db->exec("INSERT INTO invoices (id, reference, client_id, total_amount, status) VALUES (1, 'FAC-2611-0001', 1, 100000, 'paid'), (2, 'FAC-2611-0002', 2, 50000, 'partial')");
$db->exec("INSERT INTO invoice_items (id, invoice_id, product_id) VALUES (1, 1, 2), (2, 2, 3)");
$db->exec("INSERT INTO invoice_deliveries (invoice_id, delivery_id) VALUES (1, 1), (2, 2)");
// Alpha pays 105 000 for a 100 000 invoice (5 000 to its wallet); Beta pays 20 000 on account, then 30 000 on its invoice
$db->exec("INSERT INTO payments (id, reference, invoice_id, client_id, amount, payment_method) VALUES
           (1, 'REC-2611-0001', 1, 1, 105000, 'bank'), (2, 'REC-2611-0002', NULL, 2, 20000, 'cash'), (3, 'REC-2611-0003', 2, 2, 30000, 'bank')");
$db->exec("INSERT INTO client_wallets (client_id, balance, last_updated) VALUES (1, 5000, '2026-11-01 08:00:00'), (2, 20000, '2026-11-01 08:00:00')");
$db->exec("INSERT INTO client_empties_ledger (id, client_id, site_id, product_id, total_out, total_in, quantity_owed, quantity_in_flight) VALUES (1, 1, NULL, 1, 40, 15, 25, 0)");
$db->exec("INSERT INTO journal_entries (id, reference, date, status, source_type, source_id) VALUES
           (1, 'INV-FAC-2611-0001-AA', '2026-11-02', 'posted', 'invoice', 1),
           (2, 'PAY-REC-2611-0001-BB', '2026-11-02', 'posted', NULL, NULL),
           (3, 'INV-FAC-2611-0002-CC', '2026-11-02', 'posted', 'invoice', 2),
           (4, 'PAY-REC-2611-0003-DD', '2026-11-02', 'posted', NULL, NULL),
           (5, 'OD-MANUEL-1', '2026-11-02', 'posted', NULL, NULL),
           (6, 'PAY-REC-2611-0002-EE', '2026-11-02', 'posted', NULL, NULL)");
$db->exec("INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit) VALUES
           (1, 4, 100000, 0), (1, 3, 0, 100000), (2, 2, 105000, 0), (2, 4, 0, 105000), (3, 5, 50000, 0), (3, 3, 0, 50000),
           (4, 2, 30000, 0), (4, 5, 0, 30000), (5, 2, 1000, 0), (5, 3, 0, 1000), (6, 2, 20000, 0), (6, 5, 0, 20000)");
$db->exec("INSERT INTO treasury_transactions (id, account_id, transaction_type, amount, reference) VALUES
           (1, 1, 'in_other', 1000000, 'OUVERTURE'), (2, 1, 'in_client_payment', 105000, 'PAY-REC-2611-0001'),
           (3, 2, 'in_client_payment', 20000, 'PAY-REC-2611-0002'), (4, 1, 'in_client_payment', 30000, 'PAY-REC-2611-0003'),
           (5, 1, 'transfer_out', 10000, 'TRF'), (6, 2, 'transfer_in', 10000, 'TRF')");
$db->exec("UPDATE treasury_transactions SET linked_transfer_id = 6 WHERE id = 5");
$db->exec("UPDATE treasury_transactions SET linked_transfer_id = 5 WHERE id = 6");
$db->exec("INSERT INTO inventory_movements (id, product_id, movement_type, quantity, reference_id, date) VALUES
           (1, 2, 'in_supplier', 100, 1, '2026-11-01 08:00:00'), (2, 2, 'out_delivery', 40, 1, '2026-11-02 09:00:00'),
           (3, 1, 'in_return_emp', 15, 1, '2026-11-02 11:00:00'), (4, 3, 'out_delivery', 10, 2, '2026-11-02 10:00:00')");
$db->exec("INSERT INTO purchase_orders (id, reference, supplier_id) VALUES (1, 'PO-2611-0001', 1)");
$db->exec("INSERT INTO notifications (user_id, title, message, related_url) VALUES
           (1, 'Facture', 'Facture FAC-2611-0001 réglée.', NULL), (1, 'BL', 'BL livré', '/modules/sales/bl_detail.php?id=1'),
           (1, 'Autre', 'Facture FAC-2611-00010 (une autre)', NULL), (1, 'Bienvenue', 'Rien à voir', NULL)");
$db->exec("INSERT INTO audit_logs (user_id, action, table_name, record_id) VALUES (2, 'INSERT', 'invoices', 1), (2, 'INSERT', 'invoices', 99), (2, 'INSERT', 'clients', 1)");
$db->exec("INSERT INTO pdf_documents (type, record_id, path) VALUES ('invoice', 1, '/uploads/documents/invoice/x1.pdf'), ('delivery', 1, '/uploads/documents/delivery/x2.pdf')");
$db->exec("INSERT INTO overheads (id, reference) VALUES (1, 'OVH-1')");
$db->exec("INSERT INTO company_annexes (id, overhead_id) VALUES (1, 1)");
$db->exec("INSERT INTO expenses (id, reference) VALUES (1, 'EX-2611-0001')");
$attachment = 'uploads/expenses/wipe-test-' . getmypid() . '.pdf';
$madeDir = !is_dir($root . '/uploads/expenses');
@mkdir($root . '/uploads/expenses', 0777, true);
// Whatever happens, leave no test file in the site and no copies folder behind
register_shutdown_function(function () use ($root, $attachment, $madeDir, $copies) {
    @unlink($root . '/' . $attachment);
    if ($madeDir) {
        @rmdir($root . '/uploads/expenses');
    }
    if (is_dir($copies)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($copies, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($copies);
    }
});
file_put_contents($root . '/' . $attachment, '%PDF-1.4 test');
$db->prepare("INSERT INTO expense_attachments (expense_id, stored_path) VALUES (1, ?)")->execute(['/' . $attachment]);
foreach (['invoice' => 2, 'receipt' => 3, 'order' => 2, 'delivery' => 2, 'expense' => 1] as $type => $n) {
    $db->prepare("INSERT INTO document_counters (doc_type, period, last_number) VALUES (?, '2026', ?)")->execute([$type, $n]);
}

$wiper = new DataWipe($db);
$snapshot = function (array $skip = []) use ($db): array {
    $out = [];
    foreach ($db->query("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (!in_array($t, $skip, true)) {
            $rows = array_map('json_encode', $db->query("SELECT * FROM `{$t}`")->fetchAll(PDO::FETCH_NUM));
            sort($rows);
            $out[$t] = md5(implode("\n", $rows));
        }
    }
    return $out;
};
$val = fn (string $sql) => $db->query($sql)->fetchColumn();
$before = $snapshot();

section('what can be picked');
$modules = $wiper->modules();
$listed = array_merge(...array_map(fn ($m) => array_keys($m['tables']), array_values($modules)));
foreach (['users', 'roles', 'employees', 'chart_of_accounts', 'treasury_accounts', 'app_preferences', 'document_counters', 'data_wipes', 'audit_logs'] as $t) {
    check("« {$t} » can't be picked", !in_array($t, $listed, true) && !$wiper->wipeable($t));
}
check('invoices, clients, products, suppliers can be picked', !array_diff(['invoices', 'clients', 'products', 'suppliers'], $listed));
check('a table in no module still shows, under « Autres »', isset($modules['autres']) || in_array('overheads', $listed, true));
check('the pick is cleaned and sorted (unknown and protected tables dropped)', $wiper->cleanPick(['users', 'invoices', 'nope', 'clients']) === ['clients', 'invoices']);

section('plan: Factures');
$p = $wiper->plan(['invoices']);
$has = fn (string $t, $k) => isset($p['delete'][$t][$k]);
check('both invoices, their lines and links to BLs', count($p['delete']['invoices']) === 2 && count($p['delete']['invoice_items']) === 2 && count($p['delete']['invoice_deliveries']) === 2);
check('payments of the invoices go; the advance (no invoice) stays', $has('payments', 1) && $has('payments', 3) && !$has('payments', 2));
check('the entries the invoices posted (source_type invoice)', $has('journal_entries', 1) && $has('journal_entries', 3));
check('the entries of their payments (reference PAY-<receipt>-…)', $has('journal_entries', 2) && $has('journal_entries', 4));
check('the advance\'s entry and a manual entry stay', !$has('journal_entries', 6) && !$has('journal_entries', 5));
check('all lines of those entries', count($p['delete']['journal_lines']) === 8);
check('treasury movements of those payments (PAY-<receipt>)', $has('treasury_transactions', 2) && $has('treasury_transactions', 4) && !$has('treasury_transactions', 3));
check('the notification naming FAC-2611-0001 goes; one naming FAC-2611-00010 stays', count($p['delete']['notifications'] ?? []) === 1);
check('activity lines of invoice 1 go; of invoice 99 and of a client stay', count($p['delete']['audit_logs'] ?? []) === 1);
check('the invoice\'s PDF goes, the BL\'s stays', count($p['delete']['pdf_documents'] ?? []) === 1);
check('deliveries and orders stay', empty($p['delete']['deliveries']) && empty($p['delete']['sales_orders']));
check('nothing protected is touched', !array_filter(array_keys($p['delete']), [DataWipeMap::class, 'isProtected']));
check('treasury preview: Banque 1 150 000 → 1 015 000', ($p['balances']['treasury'][0]['before'] ?? null) == 1150000 && ($p['balances']['treasury'][0]['after'] ?? null) == 1015000,
      json_encode($p['balances']['treasury']));

section('plan: Clients');
$p = $wiper->plan(['clients']);
check('their own 411 sub-accounts go (411001, 411002), the 411 parent and others stay',
      array_keys($p['delete']['chart_of_accounts'] ?? []) == [4, 5]);
check('every entry with a line on those sub-accounts goes, even the manual-looking advance entry', isset($p['delete']['journal_entries'][6]));
check('the manual entry on other accounts stays', !isset($p['delete']['journal_entries'][5]));
check('wallets and empties ledger go with the client', count($p['delete']['client_wallets'] ?? []) === 2 && count($p['delete']['client_empties_ledger'] ?? []) === 1);
check('deliveries, orders, invoices and all payments go', count($p['delete']['deliveries']) === 2 && count($p['delete']['sales_orders']) === 2 && count($p['delete']['payments']) === 3);
check('the quote (named in text, not linked) stays', empty($p['delete']['proposals']));
check('the supplier\'s 401 stays', !isset($p['delete']['chart_of_accounts'][6]));
check('stock: the BL\'s out and empties-back movements go, the reception stays',
      isset($p['delete']['inventory_movements'][2], $p['delete']['inventory_movements'][3], $p['delete']['inventory_movements'][4]) && !isset($p['delete']['inventory_movements'][1]));

section('plan: optional links, whole documents, protected tables');
$p = $wiper->plan(['vehicles']);
check('wiping the truck keeps its deliveries and clears their vehicle', empty($p['delete']['deliveries']) && count($p['unlink']['deliveries']['vehicle_id'] ?? []) === 2);
$p = $wiper->plan(['products']);
check('a product\'s order line takes the whole order (no order missing a line)', count($p['delete']['sales_orders'] ?? []) === 2);
check('a quote line only loses its product link (quotes name products in text)', empty($p['delete']['proposals']) && count($p['unlink']['proposal_items']['product_id'] ?? []) === 1);
$p = $wiper->plan(['overheads']);
check('a pick a protected table depends on is refused, with the reason', $p['blocked'] && str_contains($p['blocked'][0], 'jamais effacé'));
$p = $wiper->plan(['treasury_transactions']);
check('the two legs of a transfer go together', isset($p['delete']['treasury_transactions'][5], $p['delete']['treasury_transactions'][6]));

section('a wipe that fails half-way changes nothing');
$db->exec("CREATE TRIGGER wipe_test_fail BEFORE DELETE ON invoices FOR EACH ROW BEGIN IF @wipe_test_fail = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test failure'; END IF; END");
$db->exec('SET @wipe_test_fail = 1');
$e = throws(fn () => $wiper->run(['invoices'], ['id' => 2, 'name' => 'Support Test'], 'Essai qui échoue'));
$db->exec('SET @wipe_test_fail = NULL');
check('the failure is reported', $e instanceof PDOException && str_contains($e->getMessage(), 'test failure'), $e ? $e->getMessage() : 'no error');
check('every table is exactly as before (lines and entries deleted earlier came back)', $snapshot() === $before);
check('no wipe recorded, no copy left', (int) $val("SELECT COUNT(*) FROM data_wipes") === 0 && !glob($copies . '/wipe-*'));
check('the attachment is still on disk', is_file($root . '/' . $attachment));

section('one wipe at a time');
$other = wipe_test_connect();
$other->beginTransaction();
$other->query("SELECT id FROM data_wipe_lock WHERE id = 1 FOR UPDATE")->fetchColumn();
$db->exec('SET SESSION innodb_lock_wait_timeout = 1');
$e = throws(fn () => $wiper->run(['invoices'], ['id' => 2, 'name' => 'Support Test'], 'Pendant un autre effacement'));
$db->exec('SET SESSION innodb_lock_wait_timeout = 50');
$other->rollBack();
check('a second wipe waits for the first (lock wait) and changes nothing', $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1205 && $snapshot() === $before);

section('the wipe: Ventes');
$ventes = ['deliveries', 'delivery_items', 'invoice_deliveries', 'invoice_items', 'invoices', 'payments', 'proposal_items', 'proposals', 'sales_order_items', 'sales_orders'];
$done = $wiper->run($ventes, ['id' => 2, 'name' => 'Support Test'], 'Fin des essais (test)');
check('done, with a count per table', $done['total'] > 0 && $done['counts']['invoices'] === 2 && $done['counts']['payments'] === 3, json_encode($done['counts']));
check('sales tables are empty', (int) $val("SELECT (SELECT COUNT(*) FROM invoices) + (SELECT COUNT(*) FROM payments) + (SELECT COUNT(*) FROM deliveries) + (SELECT COUNT(*) FROM sales_orders)") === 0);
check('posted entries of those documents are gone (despite the trigger), the manual one stays',
      (int) $val("SELECT COUNT(*) FROM journal_entries") === 1 && (int) $val("SELECT COUNT(*) FROM journal_entries WHERE id = 5 AND status = 'posted'") === 1);
check('clients, products, the chart and staff are untouched',
      (int) $val("SELECT COUNT(*) FROM clients") === 2 && (int) $val("SELECT COUNT(*) FROM products") === 3 && (int) $val("SELECT COUNT(*) FROM chart_of_accounts") === 6
      && (int) $val("SELECT COUNT(*) FROM users") === 2);
check('treasury balances: Banque 1 150 000 → 1 015 000, Caisse 30 000 → 10 000',
      (float) $val("SELECT balance FROM treasury_accounts WHERE id = 1") === 1015000.0 && (float) $val("SELECT balance FROM treasury_accounts WHERE id = 2") === 10000.0);
check('wallets: Alpha\'s overpayment (5 000) and Beta\'s advance (20 000) are gone', (float) $val("SELECT balance FROM client_wallets WHERE client_id = 1") === 0.0
      && (float) $val("SELECT balance FROM client_wallets WHERE client_id = 2") === 0.0);
check('empties ledger: what the signed BL counted is taken back (out 40→0, in 15→0, owed 25→0)',
      $db->query("SELECT CONCAT(total_out, '/', total_in, '/', quantity_owed) FROM client_empties_ledger WHERE id = 1")->fetchColumn() === '0/0/0');
check('stock follows the movements left: the reception only', (int) $val("SELECT COUNT(*) FROM inventory_movements") === 1);
check('notifications and activity about the wiped documents are gone, the rest stays',
      (int) $val("SELECT COUNT(*) FROM notifications") === 2 && (int) $val("SELECT COUNT(*) FROM audit_logs") === 2);
check('counters of emptied documents are reset; expense (not wiped) keeps its count',
      (int) $val("SELECT COUNT(*) FROM document_counters WHERE doc_type IN ('invoice', 'receipt', 'order', 'delivery')") === 0
      && (int) $val("SELECT last_number FROM document_counters WHERE doc_type = 'expense'") === 1);
$db->beginTransaction();
$next = DocCounter::next($db, 'invoice', '2026-11-03');
$db->rollBack();
check('so the next invoice is FAC-2611-0001 again', $next === 'FAC-2611-0001', $next);
$db->exec("INSERT INTO sales_orders (reference, client_id) VALUES ('AI-TEST', 1)");
check('AUTO_INCREMENT of an emptied table starts again at 1', (int) $val("SELECT id FROM sales_orders WHERE reference = 'AI-TEST'") === 1);
$db->exec("DELETE FROM sales_orders WHERE reference = 'AI-TEST'");
$db->exec("ALTER TABLE sales_orders AUTO_INCREMENT = 1");
$w = $db->query("SELECT * FROM data_wipes WHERE id = " . (int) $done['id'])->fetch(PDO::FETCH_ASSOC);
check('the wipe is recorded: who, what, counts, reason, copy', $w && (int) $w['user_id'] === 2 && $w['reason'] === 'Fin des essais (test)'
      && json_decode($w['picked'], true) === $ventes && (int) $w['total'] === $done['total'] && $w['copy_file'] !== null && $w['copy_sha256'] === hash_file('sha256', DataWipe::copyPath($w)));
check('the copy is outside the site, named wipe-<id>-<random>.tar.gz', ($path = DataWipe::copyPath($w)) !== null && !str_starts_with(str_replace('\\', '/', realpath($path)), str_replace('\\', '/', realpath($root))));

section('the safety copy puts everything back');
/** Unpack a copy (from a renamed duplicate: Phar caches the archive this same process just wrote) */
$extract = function (string $gz, string $to) use ($copies) {
    $dup = $copies . '/read-' . bin2hex(random_bytes(4)) . '.tar.gz';
    copy($gz, $dup);
    (new PharData($dup))->extractTo($to, null, true);
};
$out = $copies . '/extract';
$extract(DataWipe::copyPath($w), $out);
check('it holds restore.sql and LISEZMOI.txt', is_file($out . '/restore.sql') && is_file($out . '/LISEZMOI.txt'));
$p = $wiper->plan(['expenses']);   // the expense attachment: copied, then deleted from disk
$doneExp = $wiper->run(['expenses'], ['id' => 2, 'name' => 'Support Test'], 'Dépenses de test (test)');
$w2 = $db->query("SELECT * FROM data_wipes WHERE id = " . (int) $doneExp['id'])->fetch(PDO::FETCH_ASSOC);
$extract(DataWipe::copyPath($w2), $out . '2');
check('a wiped row\'s file is in the copy, and gone from the site', is_file($out . '2/files/' . $attachment) && !is_file($root . '/' . $attachment));
foreach ([$out . '2', $out] as $dir) {   // last wipe first
    wipe_test_sql_file($db, $dir . '/restore.sql');
}
$after = $snapshot();
$changed = array_keys(array_diff_assoc($after, $before));
check('after restore.sql every table is exactly as before, except the tool\'s own log', $changed === ['data_wipe_codes', 'data_wipes'] || $changed === ['data_wipes'],
      'changed: ' . implode(', ', $changed));
check('the posted entries are posted again', (int) $val("SELECT COUNT(*) FROM journal_entries WHERE status = 'posted'") === 6);

section('access');
$GLOBALS['__env']['WIPE_ADMIN_EMAIL'] = '';
$GLOBALS['__env']['WIPE_URL_KEY'] = str_repeat('k', 40);
check('off while WIPE_ADMIN_EMAIL is unset', !DataWipeAccess::configured());
$GLOBALS['__env']['WIPE_ADMIN_EMAIL'] = 'Wipe@Test.Invalid';
$GLOBALS['__env']['WIPE_URL_KEY'] = 'short';
check('off while WIPE_URL_KEY is shorter than 32 characters', !DataWipeAccess::configured());
$GLOBALS['__env']['WIPE_URL_KEY'] = str_repeat('k', 40);
check('on with both', DataWipeAccess::configured());
$_GET['k'] = str_repeat('k', 39) . 'x';
check('a wrong key is refused', !DataWipeAccess::keyOk());
$_GET['k'] = str_repeat('k', 40);
check('the right key passes', DataWipeAccess::keyOk());
$_SESSION['user_id'] = 1;
check('an admin who is not the configured account is refused (the MD)', DataWipeAccess::account($db) === null);
$_SESSION['user_id'] = 2;
check('the configured account passes (email compared without case)', (DataWipeAccess::account($db)['id'] ?? 0) == 2);
$db->exec("UPDATE users SET status = 'inactive' WHERE id = 2");
check('not once that account is inactive', DataWipeAccess::account($db) === null);
$db->exec("UPDATE users SET status = 'active' WHERE id = 2");
check('the screen shows only a masked address', DataWipeAccess::maskedEmail() === 'W***@Test.Invalid');
check('the MD is found by matricule LPC-MD-01', (DataWipeAccess::md($db)['id'] ?? 0) == 1);

section('codes');
$user = ['id' => 2, 'name' => 'Support Test'];
$e = throws(fn () => DataWipeAccess::sendCode($db, $user, ['invoices'], '…'));
check('no code is sent (or logged) while MAIL_FROM is unset', $e instanceof DomainException && (int) $val("SELECT COUNT(*) FROM data_wipe_codes") === 0);
$db->prepare("INSERT INTO data_wipe_codes (user_id, code_hash, picked_hash, expires_at) VALUES (2, ?, ?, NOW() + INTERVAL 10 MINUTE)")
   ->execute([password_hash('123456', PASSWORD_DEFAULT), DataWipe::pickHash(['invoices'])]);
check('codes are stored hashed', !str_contains((string) $val("SELECT code_hash FROM data_wipe_codes"), '123456'));
$e = throws(fn () => DataWipeAccess::checkCode($db, $user, ['clients'], '123456'));
check('a code only works for the pick it was sent for', $e instanceof DomainException && str_contains($e->getMessage(), 'autre sélection'));
$e = throws(fn () => DataWipeAccess::checkCode($db, $user, ['invoices'], '654321'));
check('a wrong code costs a try', $e instanceof DomainException && (int) $val("SELECT attempts FROM data_wipe_codes") === 1);
$id = DataWipeAccess::checkCode($db, $user, ['invoices'], '123456');
check('the right code passes', $id > 0);
$db->beginTransaction();
DataWipeAccess::consumeCode($db, $id);
$db->commit();
$e = throws(fn () => DataWipeAccess::checkCode($db, $user, ['invoices'], '123456'));
check('and works only once', $e instanceof DomainException);
$db->prepare("INSERT INTO data_wipe_codes (user_id, code_hash, picked_hash, attempts, expires_at) VALUES (2, ?, ?, 5, NOW() + INTERVAL 10 MINUTE)")
   ->execute([password_hash('111111', PASSWORD_DEFAULT), DataWipe::pickHash(['invoices'])]);
$e = throws(fn () => DataWipeAccess::checkCode($db, $user, ['invoices'], '111111'));
check('after 5 wrong tries even the right code is refused', $e instanceof DomainException && str_contains($e->getMessage(), 'Trop d'));
$db->exec("UPDATE data_wipe_codes SET attempts = 0, expires_at = NOW() - INTERVAL 1 MINUTE WHERE consumed_at IS NULL");
$e = throws(fn () => DataWipeAccess::checkCode($db, $user, ['invoices'], '111111'));
check('an expired code is refused', $e instanceof DomainException && str_contains($e->getMessage(), 'expiré'));

section('the 30-day cron');
$db->exec("UPDATE data_wipes SET copy_expires_at = NOW() - INTERVAL 1 DAY WHERE id = " . (int) $done['id']);
$file = DataWipe::copyPath($w);
check('one copy past its date is deleted', DataWipe::purgeExpired($db) === 1 && !is_file($file));
$w = $db->query("SELECT * FROM data_wipes WHERE id = " . (int) $done['id'])->fetch(PDO::FETCH_ASSOC);
check('the wipe stays in the log, its copy marked gone', $w['copy_file'] === null && $w['copy_deleted_at'] !== null && (int) $w['total'] === $done['total']);
check('the other copy is kept until its date', DataWipe::copyPath($w2) !== null);

section('hidden, quiet, and wired as decided');
foreach (['modules/admin/data_wipe.php', 'api/v1/data_wipe_controller.php'] as $f) {
    $code = wipe_test_code($f);
    $gate = strpos($code, 'DataWipeAccess::enforce(');
    $first = min(array_filter([strpos($code, 'header('), strpos($code, 'echo'), strpos($code, '?>'), strpos($code, 'Csrf::')], fn ($x) => $x !== false));
    check("{$f}: the gate runs before any output, header or CSRF check", $gate !== false && $gate < $first);
}
$access = wipe_test_code('includes/classes/DataWipeAccess.php');
check('a refused request gets index.php, as an unknown URL does (.htaccess)', str_contains($access, "require dirname(__DIR__, 2) . '/index.php'"));
$hidden = true;
foreach (array_merge(glob($root . '/includes/components/*.php'), [$root . '/includes/functions/navigation.php', $root . '/includes/classes/Rbac.php']) as $f) {
    $hidden = $hidden && !str_contains((string) file_get_contents($f), 'data_wipe');
}
check('in no menu, sidebar, command palette or permission', $hidden);
$quiet = true;
foreach (['includes/classes/DataWipe.php', 'includes/classes/DataWipeBalances.php', 'includes/classes/DataWipeAccess.php', 'includes/classes/DataWipeMap.php', 'api/v1/data_wipe_controller.php'] as $f) {
    $quiet = $quiet && !preg_match('/\berror_log\s*\(/', wipe_test_code($f));
}
check('nothing written to the error log admins read', $quiet);
$mail = true;
foreach (['includes/classes/DataWipe.php', 'includes/classes/DataWipeAccess.php', 'modules/admin/data_wipe.php', 'api/v1/data_wipe_controller.php', 'migrations/355_data_wipe.sql'] as $f) {
    $mail = $mail && !preg_match('/[A-Za-z0-9._%+-]+@(?!test\.invalid)[A-Za-z0-9.-]+\.[a-z]{2,}/', (string) file_get_contents($root . '/' . $f));
}
check('no email address in the code (it lives in the server\'s .env)', $mail);
check('Courrier (mail_*) and the help centre are never wiped', DataWipeMap::isProtected('mail_messages') && DataWipeMap::isProtected('help_articles'));

wipe_test_done();
