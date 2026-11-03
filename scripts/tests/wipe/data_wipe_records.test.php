<?php
/**
 * scripts/tests/wipe/data_wipe_records.test.php
 * -----------------------------------------------------------------------------
 * The data wipe tool's "one by one" tab (DataWipe::planRecords / runRecords,
 * DataWipeStaff; owner's decisions of 3 Nov 2026) on the small model of bureau
 * (support/fixture_schema.sql + support/fixture_staff.sql):
 *
 *   · documents, clients and products found by number or name, each with what
 *     depends on it, as the whole-table tab;
 *   · numbering: a counter whose last number goes steps back, one from the
 *     middle leaves a gap, and the plan says which;
 *   · staff accounts: inactive only, never the wipe account nor the MD's; the
 *     login and employee record go together; every column naming the account,
 *     with or without a foreign key, is handed over to the account picked, or
 *     with "wipe them too", the rows it made go and the rest lose its name;
 *   · Courrier: its own mailbox goes (bureau's copy, never in the safety copy),
 *     its access, signature, notes and assignments follow the choice;
 *   · the run: one transaction, copy first, no activity line left by the tool,
 *     and restore.sql puts everything back;
 *   · codes tied to the records and the choices made for accounts.
 *
 * Run: WIPE_TEST_DB_PORT=3307 WIPE_TEST_DB_NAME=bureau_wipe_test php scripts/tests/wipe/data_wipe_records.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';
$root = $GLOBALS['__wipe_root'];
$copies = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/bureau_wipe_test_copies_r' . getmypid();
$GLOBALS['__env'] = ['WIPE_COPY_DIR' => $copies, 'MAIL_FROM' => '', 'WIPE_ADMIN_EMAIL' => 'wipe@test.invalid'];

$db = wipe_test_db(true, ['fixture_staff.sql']);
require_once $root . '/includes/classes/DataWipe.php';
require_once $root . '/includes/classes/DataWipeAccess.php';

// ---- People: the MD, the wipe account, two inactive accounts to delete, an active colleague ------
$db->exec("CREATE TRIGGER bd_journal_entries_no_post_delete BEFORE DELETE ON journal_entries FOR EACH ROW
           BEGIN IF OLD.status = 'posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_entries: cannot delete a posted entry.'; END IF; END");
// As migration 007 on the server: deleting a login writes an activity line
$db->exec("CREATE TRIGGER ad_users_audit AFTER DELETE ON users FOR EACH ROW
           BEGIN INSERT INTO audit_logs (user_id, action, table_name, record_id) VALUES (@lpc_current_user_id, 'DELETE', 'users', OLD.id); END");
$db->exec("INSERT INTO roles (id, name) VALUES (1, 'admin'), (2, 'driver')");
$db->exec("INSERT INTO employees (id, employee_code, first_name, last_name) VALUES
           (1, 'LPC-MD-01', 'Directeur', 'Test'), (2, 'EMP-002', 'Support', 'Test'), (3, 'EMP-003', 'Paul', 'Essai'),
           (4, 'EMP-004', 'Rita', 'Essai'), (5, 'EMP-005', 'Anne', 'Active')");
$db->exec("INSERT INTO users (id, employee_id, role_id, employee_code, first_name, last_name, email, status) VALUES
           (1, 1, 1, 'LPC-MD-01', 'Directeur', 'Test', 'md@test.invalid', 'active'),
           (2, 2, 1, 'EMP-002', 'Support', 'Test', 'wipe@test.invalid', 'active'),
           (3, 3, 2, 'EMP-003', 'Paul', 'Essai', 'paul@test.invalid', 'inactive'),
           (4, 4, 2, 'EMP-004', 'Rita', 'Essai', 'rita@test.invalid', 'inactive'),
           (5, 5, 1, 'EMP-005', 'Anne', 'Active', 'anne@test.invalid', 'active')");
$db->exec("UPDATE users SET updated_by = 3 WHERE id = 5");
$db->exec("UPDATE employees SET manager_id = 3, created_by = 3 WHERE id = 5");
$avatar = 'uploads/avatars/wipe-test-' . getmypid() . '.png';
$madeDir = !is_dir($root . '/uploads/avatars');
@mkdir($root . '/uploads/avatars', 0777, true);
register_shutdown_function(function () use ($root, $avatar, $madeDir, $copies) {
    @unlink($root . '/' . $avatar);
    if ($madeDir) {
        @rmdir($root . '/uploads/avatars');
    }
    if (is_dir($copies)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($copies, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($copies);
    }
});
file_put_contents($root . '/' . $avatar, 'PNG test');
$db->prepare("UPDATE employees SET avatar_path = ? WHERE id = 3")->execute(['/' . $avatar]);
$db->exec("INSERT INTO employee_documents (employee_id, file_path, uploaded_by) VALUES (3, 'uploads/employees/none.pdf', 5)");
$db->exec("INSERT INTO hr_contracts (user_id, base_salary) VALUES (3, 150000), (4, 120000)");
$db->exec("INSERT INTO hr_payslips (id, user_id, month, year, net, created_by) VALUES (1, 3, 10, 2026, 140000, 5), (2, 4, 10, 2026, 110000, 3), (3, 5, 10, 2026, 200000, 3)");
$db->exec("INSERT INTO user_prefs (user_id, pref_key, pref_value) VALUES (3, 'theme', 'dark'), (4, 'theme', 'light'), (5, 'theme', 'light')");
$db->exec("INSERT INTO user_sessions (user_id) VALUES (3), (5)");
$db->exec("INSERT INTO help_chat_log (user_id, question) VALUES (3, 'Comment facturer ?')");

// ---- A small business --------------------------------------------------------------------
$db->exec("INSERT INTO ohada_accounts (id, account_number) VALUES (1, '401'), (2, '411'), (3, '521'), (4, '701')");
$db->exec("INSERT INTO chart_of_accounts (id, ohada_account_id, code, name) VALUES
           (1, 2, '411', 'Clients'), (2, 3, '5211', 'Banque'), (3, 4, '701', 'Ventes'), (4, 2, '411001', 'Client - Alpha'), (5, 2, '411002', 'Client - Beta')");
$db->exec("INSERT INTO treasury_accounts (id, name, balance, coa_account_id, created_by) VALUES (1, 'Banque', 1135000, 2, 5), (2, 'Caisse', 0, NULL, 3)");
$db->exec("INSERT INTO clients (id, name, account_id) VALUES (1, 'Alpha', 4), (2, 'Beta', 5)");
$db->exec("INSERT INTO products (id, code, name, cump) VALUES (1, 'EAU', 'Eau', 1500), (2, 'JUS', 'Jus', 700)");
$db->exec("INSERT INTO vehicles (id, plate_number, default_driver_id) VALUES (1, 'LT-1', 4)");
$db->exec("INSERT INTO sales_orders (id, reference, client_id, total_amount, created_by) VALUES (1, 'CMD-2611-0001', 1, 100000, 5)");
$db->exec("INSERT INTO sales_order_items (sales_order_id, product_id, quantity) VALUES (1, 1, 10)");
$db->exec("INSERT INTO deliveries (id, reference, sales_order_id, client_id, vehicle_id, status, created_at, driver_id, created_by) VALUES
           (1, 'BL-2611-0001', 1, 1, 1, 'dispatched', '2026-11-02 09:00:00', 4, 5)");
$db->exec("INSERT INTO delivery_items (delivery_id, product_id, quantity) VALUES (1, 1, 10)");
$db->exec("INSERT INTO invoices (id, reference, client_id, total_amount, status, created_at, created_by) VALUES
           (1, 'FAC-2611-0001', 1, 100000, 'paid', '2026-11-02 10:00:00', 3),
           (2, 'FAC-2611-0002', 2, 50000, 'unpaid', '2026-11-02 11:00:00', 4),
           (3, 'FAC-2611-0003', 1, 35000, 'paid', '2026-11-02 12:00:00', 5)");
$db->exec("INSERT INTO invoice_items (invoice_id, product_id) VALUES (1, 1), (2, 2), (3, 2)");
$db->exec("INSERT INTO payments (id, reference, invoice_id, client_id, amount, payment_method, logged_by, validated_by, created_at) VALUES
           (1, 'REC-2611-0001', 1, 1, 100000, 'bank', 3, 4, '2026-11-02 10:30:00'), (2, 'REC-2611-0002', 3, 1, 35000, 'bank', 5, 4, '2026-11-02 12:30:00')");
$db->exec("INSERT INTO journal_entries (id, reference, date, status, source_type, source_id, created_by, approved_by) VALUES
           (1, 'INV-FAC-2611-0001-AA', '2026-11-02', 'posted', 'invoice', 1, 3, NULL),
           (2, 'INV-FAC-2611-0002-BB', '2026-11-02', 'posted', 'invoice', 2, 4, NULL),
           (3, 'INV-FAC-2611-0003-CC', '2026-11-02', 'posted', 'invoice', 3, 5, 4),
           (4, 'PAY-REC-2611-0001-DD', '2026-11-02', 'posted', NULL, NULL, 3, NULL),
           (5, 'PAY-REC-2611-0002-EE', '2026-11-02', 'posted', NULL, NULL, 5, NULL)");
$db->exec("INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit) VALUES
           (1, 4, 100000, 0), (1, 3, 0, 100000), (2, 5, 50000, 0), (2, 3, 0, 50000), (3, 4, 35000, 0), (3, 3, 0, 35000),
           (4, 2, 100000, 0), (4, 4, 0, 100000), (5, 2, 35000, 0), (5, 4, 0, 35000)");
$db->exec("INSERT INTO treasury_transactions (id, account_id, transaction_type, amount, reference) VALUES
           (1, 1, 'in_other', 1000000, 'OUVERTURE'), (2, 1, 'in_client_payment', 100000, 'PAY-REC-2611-0001'), (3, 1, 'in_client_payment', 35000, 'PAY-REC-2611-0002')");
$db->exec("INSERT INTO expenses (id, reference, created_by, created_at) VALUES (1, 'EX-2611-0001', 4, '2026-11-02 08:00:00')");
$db->exec("INSERT INTO notifications (user_id, title, message) VALUES (3, 'Pour Paul', 'Bienvenue'), (5, 'Facture', 'Facture FAC-2611-0002 émise.'), (5, 'Autre', 'Rien à voir')");
$db->exec("INSERT INTO audit_logs (user_id, action, table_name, record_id) VALUES (3, 'INSERT', 'invoices', 1), (4, 'INSERT', 'invoices', 2), (5, 'INSERT', 'clients', 1)");
foreach (['invoice' => 3, 'receipt' => 2, 'order' => 1, 'delivery' => 1, 'expense' => 1] as $type => $n) {
    $db->prepare("INSERT INTO document_counters (doc_type, period, last_number) VALUES (?, '2026', ?)")->execute([$type, $n]);
}

// ---- Courrier: Paul's own mailbox, and a shared one they both work in ----------------------------
$db->exec("INSERT INTO mail_accounts (id, kind, email, display_name, owner_user_id, secret) VALUES
           (1, 'personal', 'paul.box@test.invalid', 'Paul', 3, 'chiffré'), (2, 'shared', 'contact@test.invalid', 'Contact', 5, 'chiffré')");
$db->exec("INSERT INTO mail_account_members (account_id, user_id, added_by) VALUES (2, 3, 5), (2, 4, 3), (2, 5, NULL)");
$db->exec("INSERT INTO mail_threads (id, account_id, subject, assigned_to) VALUES (1, 1, 'Privé', NULL), (2, 2, 'Commande client', 4)");
$db->exec("INSERT INTO mail_messages (id, account_id, thread_id, subject, body_text, sent_by, seen_by) VALUES
           (1, 1, 1, 'Privé', 'Courrier privé de Paul', 3, NULL), (2, 1, 1, 'Re: Privé', 'Réponse privée', NULL, 3),
           (3, 2, 2, 'Commande client', 'Bonjour', NULL, 4), (4, 2, 2, 'Re: Commande client', 'Merci', 3, NULL)");
$db->exec("INSERT INTO mail_thread_notes (thread_id, user_id, body) VALUES (2, 3, 'Note de Paul'), (2, 4, 'Note de Rita')");
$db->exec("INSERT INTO mail_signatures (user_id, phone) VALUES (3, '600'), (4, '601')");

$wiper = new DataWipe($db);
$snapshot = function () use ($db): array {
    $out = [];
    foreach ($db->query("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $rows = array_map('json_encode', $db->query("SELECT * FROM `{$t}`")->fetchAll(PDO::FETCH_NUM));
        sort($rows);
        $out[$t] = md5(implode("\n", $rows));
    }
    return $out;
};
$val = fn (string $sql) => $db->query($sql)->fetchColumn();
$wipeUser = ['id' => 2, 'name' => 'Support Test'];
$paul = ['table' => 'users', 'id' => 3, 'mode' => 'handover', 'to' => 5];
$rita = ['table' => 'users', 'id' => 4, 'mode' => 'wipe'];
$before = $snapshot();

section('finding records');
check('every kind this database has can be searched', array_keys($wiper->recordKinds()) == array_keys(array_filter(DataWipeMap::RECORDS, fn ($k) => true, ARRAY_FILTER_USE_KEY)));
$found = $wiper->searchRecords('invoices', '2611-0002');
check('an invoice by part of its number', count($found) === 1 && $found[0]['id'] === 2 && str_contains($found[0]['sub'], 'Beta'), json_encode($found));
check('invoices by client name', count($wiper->searchRecords('invoices', 'alpha')) === 2);
check('a client by name', ($wiper->searchRecords('clients', 'Bet')[0]['id'] ?? 0) === 2);
check('% and _ are searched as text', $wiper->searchRecords('invoices', '%') === [] && $wiper->searchRecords('invoices', '_') === []);
check('an unknown kind finds nothing', $wiper->searchRecords('chart_of_accounts', '411') === [] && $wiper->searchRecords('nope', '') === []);
$staff = [];
foreach ($wiper->searchRecords('users', 'test.invalid') as $r) {
    $staff[$r['id']] = $r['refused'];
}
check('staff: inactive accounts can be picked', $staff[3] === null && $staff[4] === null);
check('staff: an active account can\'t, until it is deactivated', str_contains((string) $staff[5], 'désactivez-le'));
check('staff: never the wipe account itself', str_contains((string) $staff[2], 'qui efface'));
$db->exec("UPDATE users SET status = 'inactive' WHERE id = 1");
check('staff: never the MD, even inactive', str_contains((string) ($wiper->searchRecords('users', 'LPC-MD')[0]['refused'] ?? ''), 'DG'));
$db->exec("UPDATE users SET status = 'active' WHERE id = 1");
check('a record is named for the screen and the log', $wiper->recordLabel('invoices', 2) === 'Facture FAC-2611-0002 · Beta' && $wiper->recordLabel('users', 3) === 'Compte Paul Essai (EMP-003)');

section('plan: one document');
$p = $wiper->planRecords([['table' => 'invoices', 'id' => 2]]);
check('the invoice, its line, its posted entry and the notification naming it',
      array_keys($p['delete']['invoices']) == [2] && count($p['delete']['invoice_items']) === 1 && array_keys($p['delete']['journal_entries']) == [2]
      && count($p['delete']['notifications'] ?? []) === 1, json_encode($p['counts']));
check('the other invoices and the client stay', empty($p['delete']['clients']) && count($p['delete']['invoices']) === 1);
check('FAC-2611-0002 is in the middle: the plan says it leaves a gap', count($p['numbering']) === 1 && $p['numbering'][0]['kind'] === 'gap'
      && str_contains($p['numbering'][0]['text'], 'FAC-2611-0002') && str_contains($p['numbering'][0]['text'], 'trou'), json_encode($p['numbering']));
$p = $wiper->planRecords([['table' => 'invoices', 'id' => 3]]);
check('FAC-2611-0003 is the last: the counter steps back', ($p['numbering'][0]['kind'] ?? '') === 'back' && str_contains($p['numbering'][0]['text'], 'reprendra FAC-2611-0003'));
check('its payment and the payment\'s entry and treasury movement go too', isset($p['delete']['payments'][2], $p['delete']['journal_entries'][5], $p['delete']['treasury_transactions'][3]));
check('the receipt REC-2611-0002 was the last too', count(array_filter($p['numbering'], fn ($n) => str_contains($n['text'], 'REC-2611-0002 était le dernier'))) === 1);
$p = $wiper->planRecords([['table' => 'invoices', 'id' => 3], ['table' => 'invoices', 'id' => 2]]);
check('the two last invoices: back two numbers, no gap', count(array_filter($p['numbering'], fn ($n) => $n['kind'] === 'gap')) === 0
      && str_contains(implode(' ', array_column($p['numbering'], 'text')), 'FAC-2611-0002, FAC-2611-0003 étaient les derniers numéros'));
check('the pick is sorted and named, whatever order it came in', $p['picked'] === ['invoices#2', 'invoices#3'] && $p['records'][0]['label'] === 'Facture FAC-2611-0002 · Beta');
$p = $wiper->planRecords([['table' => 'clients', 'id' => 2]]);
check('a client takes its invoices, entries and own 411 sub-account', isset($p['delete']['invoices'][2], $p['delete']['chart_of_accounts'][5]) && !isset($p['delete']['invoices'][1]));
$p = $wiper->planRecords([['table' => 'products', 'id' => 2]]);
check('a product takes the whole invoices it is on', isset($p['delete']['invoices'][2], $p['delete']['invoices'][3]) && !isset($p['delete']['invoices'][1]));
$p = $wiper->planRecords([['table' => 'invoices', 'id' => 99], ['table' => 'chart_of_accounts', 'id' => 1]]);
check('a record that no longer exists blocks, one that can\'t be picked is dropped', $p['picked'] === [] && count($p['blocked']) === 1);

section('staff columns');
$cols = (new DataWipeStaff($db, $wiper))->columns();
check('declared (invoices.created_by) and undeclared (deliveries.created_by, journal_entries.created_by) are found',
      isset($cols['invoices.created_by'], $cols['deliveries.created_by'], $cols['journal_entries.created_by'], $cols['mail_messages.sent_by'], $cols['mail_threads.assigned_to']));
check('a link to another table is not taken for one (journal_entries.reversed_by_entry_id)', !isset($cols['journal_entries.reversed_by_entry_id']));
check('the tool\'s own log is left alone', !array_filter(array_keys($cols), fn ($c) => str_starts_with($c, 'data_wipe')));

section('plan: an account handed over');
$p = $wiper->planRecords([['table' => 'users', 'id' => 3, 'mode' => 'handover']]);
check('without the account who takes over, it is refused', (bool) array_filter($p['blocked'], fn ($b) => str_contains($b, 'choisissez le compte actif')));
$p = $wiper->planRecords([['table' => 'users', 'id' => 3, 'mode' => 'handover', 'to' => 4]]);
check('an inactive account can\'t take over', (bool) array_filter($p['blocked'], fn ($b) => str_contains($b, 'choisissez le compte actif')));
$p = $wiper->planRecords([$paul]);
check('allowed with an active account', $p['blocked'] === [], json_encode($p['blocked']));
check('the login and the employee record go together', array_keys($p['delete']['users']) == [3] && array_keys($p['delete']['employees']) == [3]);
check('so do the contract, payslip, preferences, session, notifications, documents', count($p['delete']['hr_contracts']) === 1
      && array_keys($p['delete']['hr_payslips']) == [1] && count($p['delete']['user_prefs']) === 1 && count($p['delete']['user_sessions']) === 1
      && count($p['delete']['notifications']) === 1 && count($p['delete']['employee_documents']) === 1);
check('their invoice, payment, entries and the treasury account they created stay, handed to Anne',
      isset($p['reassign']['invoices']['created_by'][1], $p['reassign']['payments']['logged_by'][1], $p['reassign']['journal_entries']['created_by'][1],
            $p['reassign']['journal_entries']['created_by'][4], $p['reassign']['treasury_accounts']['created_by'][2])
      && $p['reassign']['invoices']['created_by'][1] === 5 && empty($p['delete']['invoices']));
check('Rita\'s payslip they made, and Anne\'s profile they edited, too', ($p['reassign']['hr_payslips']['created_by'][2] ?? 0) === 5
      && ($p['reassign']['hr_payslips']['created_by'][3] ?? 0) === 5 && ($p['reassign']['users']['updated_by'][5] ?? 0) === 5
      && ($p['reassign']['employees']['created_by'][5] ?? 0) === 5);
check('their activity lines too', ($p['reassign']['audit_logs']['user_id'][1] ?? 0) === 5);
check('Anne loses her manager link (Paul\'s employee record goes)', isset($p['unlink']['employees']['manager_id'][5]));
check('their questions to the help centre stay, without their name', isset($p['unlink']['help_chat_log']['user_id'][1]));
check('their own mailbox goes, shown with its mail count', ($p['mailboxes'][0]['email'] ?? '') === 'paul.box@test.invalid' && $p['mailboxes'][0]['messages'] === 2
      && array_keys($p['delete']['mail_accounts']) == [1]);
check('its mail is left to the database (never copied): no message, thread or folder in the plan',
      empty($p['delete']['mail_messages']) && empty($p['delete']['mail_threads']) && !isset($p['reassign']['mail_messages']['seen_by'][2])
      && !isset($p['reassign']['mail_messages']['sent_by'][1]));
check('shared mailbox: their access and signature go, their note and sent mail pass to Anne',
      count($p['delete']['mail_account_members']) === 1 && count($p['delete']['mail_signatures']) === 1
      && isset($p['reassign']['mail_thread_notes']['user_id'][1], $p['reassign']['mail_messages']['sent_by'][4])
      && isset($p['reassign']['mail_account_members']['added_by'][2]));
check('the plan lists hand-overs with who takes them', (bool) array_filter($p['reassigned'], fn ($r) => $r['table'] === 'invoices' && $r['to'] === 'Anne Active'));
check('the code is tied to the account and the one who takes over', $p['picked'] === ['users#3:handover>5']);

section('plan: an account wiped too');
$p = $wiper->planRecords([$rita]);
check('allowed', $p['blocked'] === [], json_encode($p['blocked']));
check('what they made goes: their invoice (with its entry), expense, and the delivery they drove',
      isset($p['delete']['invoices'][2], $p['delete']['journal_entries'][2], $p['delete']['expenses'][1], $p['delete']['deliveries'][1]));
check('what they only validated or approved stays, without their name', isset($p['unlink']['payments']['validated_by'][1], $p['unlink']['payments']['validated_by'][2], $p['unlink']['journal_entries']['approved_by'][3])
      && empty($p['delete']['payments']));
check('the truck they usually drove stays, without them', isset($p['unlink']['vehicles']['default_driver_id'][1]) && empty($p['delete']['vehicles']));
check('their activity lines go', isset($p['delete']['audit_logs'][2]));
check('shared mailbox: their note goes, their name leaves the conversation and the « opened by »', isset($p['delete']['mail_thread_notes'][2])
      && isset($p['unlink']['mail_threads']['assigned_to'][2], $p['unlink']['mail_messages']['seen_by'][3]));
check('the shared mail itself is never taken', empty($p['delete']['mail_messages']) && empty($p['delete']['mail_threads']) && !isset($p['delete']['mail_accounts'][2]));
check('FAC-2611-0002 leaves a gap; EX-2611-0001 and BL-2611-0001 step back', count(array_filter($p['numbering'], fn ($n) => $n['kind'] === 'gap')) === 1
      && count(array_filter($p['numbering'], fn ($n) => $n['kind'] === 'back')) === 2, json_encode($p['numbering']));
$p = $wiper->planRecords([['table' => 'users', 'id' => 3, 'mode' => 'wipe']]);
check('a protected row that needs the name (a treasury account they created) refuses the wipe, and says what to do',
      (bool) array_filter($p['blocked'], fn ($b) => str_contains($b, 'Comptes de trésorerie') && str_contains($b, 'Passer à un autre compte')));
$p = $wiper->planRecords([['table' => 'users', 'id' => 3, 'mode' => 'handover', 'to' => 4], $rita]);
check('the account taking over can\'t be one deleted in the same pick', (bool) $p['blocked']);

section('the run: two accounts, one handed over, one wiped');
$done = $wiper->runRecords([$paul, $rita], $wipeUser, 'Comptes de test (test)');
check('done', $done['total'] > 0 && $done['mode'] === 'records' && $done['records'] === ['Compte Paul Essai (EMP-003)', 'Compte Rita Essai (EMP-004)']);
check('both logins and employee records are gone, the others stay',
      (int) $val("SELECT COUNT(*) FROM users") === 3 && (int) $val("SELECT COUNT(*) FROM employees") === 3 && (int) $val("SELECT COUNT(*) FROM users WHERE id IN (3, 4)") === 0);
check('Paul\'s invoice, payment and entries are Anne\'s now', (int) $val("SELECT created_by FROM invoices WHERE id = 1") === 5
      && (int) $val("SELECT logged_by FROM payments WHERE id = 1") === 5 && (int) $val("SELECT COUNT(*) FROM journal_entries WHERE created_by IN (3, 4)") === 0);
check('Rita\'s invoice, expense and delivery are gone; her validations cleared', (int) $val("SELECT COUNT(*) FROM invoices WHERE id = 2") === 0
      && (int) $val("SELECT COUNT(*) FROM expenses") === 0 && (int) $val("SELECT COUNT(*) FROM deliveries") === 0
      && (int) $val("SELECT COUNT(*) FROM payments WHERE validated_by IS NOT NULL") === 0);
check('no row anywhere still names either account', (function () use ($db, $wiper) {
    foreach ((new DataWipeStaff($db, $wiper))->columns() as $c) {
        if ((int) $db->query("SELECT COUNT(*) FROM `{$c['table']}` WHERE `{$c['column']}` IN (3, 4)")->fetchColumn() > 0) {
            return false;
        }
    }
    return true;
})());
check('Paul\'s mailbox and all its mail are gone; the shared mailbox keeps all its mail',
      (int) $val("SELECT COUNT(*) FROM mail_accounts") === 1 && (int) $val("SELECT COUNT(*) FROM mail_messages WHERE account_id = 1") === 0
      && (int) $val("SELECT COUNT(*) FROM mail_messages WHERE account_id = 2") === 2);
check('Paul\'s note is Anne\'s, Rita\'s note is gone', $db->query("SELECT GROUP_CONCAT(user_id) FROM mail_thread_notes")->fetchColumn() === '5');
check('the counters: BL and expense step back to 0, the invoice keeps its gap at 0002',
      (int) $val("SELECT last_number FROM document_counters WHERE doc_type = 'delivery'") === 0
      && (int) $val("SELECT last_number FROM document_counters WHERE doc_type = 'expense'") === 0
      && (int) $val("SELECT last_number FROM document_counters WHERE doc_type = 'invoice'") === 3);
check('the tool left no line in the activity log (the delete trigger\'s line is removed)', (int) $val("SELECT COUNT(*) FROM audit_logs WHERE user_id = 2") === 0);
check('Paul\'s photo is in the copy, and gone from the site', !is_file($root . '/' . $avatar));
$w = $db->query("SELECT * FROM data_wipes WHERE id = " . (int) $done['id'])->fetch(PDO::FETCH_ASSOC);
check('the log names what was picked', DataWipe::pickedText($w['picked']) === 'Un par un : Compte Paul Essai (EMP-003), Compte Rita Essai (EMP-004)');

section('the safety copy');
$extract = function (string $gz, string $to) use ($copies) {
    $dup = $copies . '/read-' . bin2hex(random_bytes(4)) . '.tar.gz';
    copy($gz, $dup);
    (new PharData($dup))->extractTo($to, null, true);
};
$extract(DataWipe::copyPath($w), $copies . '/x1');
$sql = (string) file_get_contents($copies . '/x1/restore.sql');
check('it holds the photo', is_file($copies . '/x1/files/' . $avatar));
check('it never holds the private mail (only the mailbox\'s settings)', !str_contains($sql, 'Courrier privé') && !str_contains($sql, 'Réponse privée')
      && str_contains($sql, 'paul.box@test.invalid'));
check('its LISEZMOI says the mail stays on the mail server', str_contains((string) file_get_contents($copies . '/x1/LISEZMOI.txt'), 'le courrier reste sur le serveur mail'));
wipe_test_sql_file($db, $copies . '/x1/restore.sql');
copy($copies . '/x1/files/' . $avatar, $root . '/' . $avatar);   // as LISEZMOI says: files back in the site folder
$changed = array_keys(array_diff_assoc($snapshot(), $before));
sort($changed);
check('restore.sql puts every table back, except the tool\'s log and the private mail (on the mail server)',
      $changed === ['data_wipes', 'mail_messages', 'mail_threads'], 'changed: ' . implode(', ', $changed));
$db->exec("INSERT INTO mail_threads (id, account_id, subject) VALUES (1, 1, 'Privé')");   // as the next sync would
$db->exec("INSERT INTO mail_messages (id, account_id, thread_id, subject, body_text, sent_by, seen_by) VALUES
           (1, 1, 1, 'Privé', 'Courrier privé de Paul', 3, NULL), (2, 1, 1, 'Re: Privé', 'Réponse privée', NULL, 3)");

section('the run: documents, and the numbers they free');
$done = $wiper->runRecords([['table' => 'invoices', 'id' => 3], ['table' => 'invoices', 'id' => 2]], $wipeUser, 'Factures en double (test)');
check('the invoice counter is back at 0001', (int) $val("SELECT last_number FROM document_counters WHERE doc_type = 'invoice'") === 1
      && count($done['numbering']) >= 1);
$db->beginTransaction();
$next = DocCounter::next($db, 'invoice', '2026-11-03');
$db->rollBack();
check('so the next invoice reuses FAC-2611-0002', $next === 'FAC-2611-0002', $next);
check('the receipt counter is back too (REC-2611-0002 went with its invoice)', (int) $val("SELECT last_number FROM document_counters WHERE doc_type = 'receipt'") === 1);
check('treasury follows: Banque 1 135 000 → 1 100 000', (float) $val("SELECT balance FROM treasury_accounts WHERE id = 1") === 1100000.0);
$w = $db->query("SELECT * FROM data_wipes WHERE id = " . (int) $done['id'])->fetch(PDO::FETCH_ASSOC);
$extract(DataWipe::copyPath($w), $copies . '/x2');
wipe_test_sql_file($db, $copies . '/x2/restore.sql');
$changed = array_keys(array_diff_assoc($snapshot(), $before));
check('restore.sql puts the documents and the counters back', $changed === ['data_wipes'], 'changed: ' . implode(', ', $changed));

section('a run that fails changes nothing');
$db->exec("CREATE TRIGGER wipe_test_fail BEFORE DELETE ON users FOR EACH ROW BEGIN IF @wipe_test_fail = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test failure'; END IF; END");
$db->exec('SET @wipe_test_fail = 1');
$mid = $snapshot();
$e = throws(fn () => $wiper->runRecords([$paul], $wipeUser, 'Essai qui échoue'));
$db->exec('SET @wipe_test_fail = NULL');
check('the failure is reported, and every hand-over is undone', $e instanceof PDOException && $snapshot() === $mid, $e ? $e->getMessage() : 'no error');
check('Paul\'s photo is still there', is_file($root . '/' . $avatar));

section('codes and wiring');
$user = ['id' => 2, 'name' => 'Support Test'];
$db->prepare("INSERT INTO data_wipe_codes (user_id, code_hash, picked_hash, expires_at) VALUES (2, ?, ?, NOW() + INTERVAL 10 MINUTE)")
   ->execute([password_hash('123456', PASSWORD_DEFAULT), DataWipe::pickHash(['users#3:handover>5'])]);
$e = throws(fn () => DataWipeAccess::checkCode($db, $user, ['users#3:handover>1'], '123456'));
check('a code doesn\'t work once another account is picked to take over', $e instanceof DomainException && str_contains($e->getMessage(), 'autre sélection'));
$e = throws(fn () => DataWipeAccess::checkCode($db, $user, ['users#3:wipe'], '123456'));
check('nor once « wipe them too » is chosen instead', $e instanceof DomainException);
check('it works for exactly what it was sent for', DataWipeAccess::checkCode($db, $user, DataWipe::recordPick($wiper->cleanRecords([$paul])[0]), '123456') > 0);
$ctl = wipe_test_code('api/v1/data_wipe_controller.php');
$sendAt = strpos($ctl, "case 'records_send_code'");
$runAt = strpos($ctl, "case 'records_run'");
$codeAt = strpos($ctl, 'DataWipeAccess::checkCode(', $runAt);
$mdBeforeSend = strpos($ctl, 'if (!DataWipeAccess::md($db))', $sendAt);
$mdBeforeRun = strpos($ctl, 'if (!DataWipeAccess::md($db))', $runAt);
check('one by one too: without an MD no code is sent and nothing is deleted',
      $mdBeforeSend !== false && $mdBeforeSend < strpos($ctl, 'DataWipeAccess::sendCode(', $sendAt) && $mdBeforeRun !== false && $mdBeforeRun < $codeAt);
check('one by one too: the code is used up inside the delete\'s transaction', str_contains($ctl, '$wiper->runRecords($records, $user, $reason, fn (PDO $db) => DataWipeAccess::consumeCode($db, $codeId))'));
$page = wipe_test_code('modules/admin/data_wipe.php');
check('the screen has both tabs, the whole-table one first and unchanged', strpos($page, 'id="dw-tab-tables"') < strpos($page, 'id="dw-tab-records"')
      && str_contains($page, 'id="dw-modules"') && str_contains($page, 'id="dw-plan"') && str_contains($page, 'id="dr-plan"'));
$quiet = true;
$mail = true;
foreach (['includes/classes/DataWipeStaff.php', 'assets/js/modules/admin-data_wipe-records.js'] as $f) {
    $quiet = $quiet && !preg_match('/\berror_log\s*\(/', wipe_test_code($f));
    $mail = $mail && !preg_match('/[A-Za-z0-9._%+-]+@(?!test\.invalid)[A-Za-z0-9.-]+\.[a-z]{2,}/', (string) file_get_contents($root . '/' . $f));
}
check('nothing written to the error log, no email address in the code', $quiet && $mail);
check('users and employees still can\'t be picked in the whole-table tab', !$wiper->wipeable('users') && !$wiper->wipeable('employees'));

wipe_test_done();
