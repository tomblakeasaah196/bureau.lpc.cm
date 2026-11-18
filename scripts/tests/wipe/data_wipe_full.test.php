<?php
/**
 * scripts/tests/wipe/data_wipe_full.test.php
 * -----------------------------------------------------------------------------
 * « Remise à zéro avant démarrage » (DataWipe::planFull / runFull; the owner's
 * decisions 16 and 17, 18/11/2026), on the wipe tests' model of bureau:
 *
 *   · everything goes but the staff, roles, settings, the chart and the
 *     employees: every non-protected table is empty afterwards;
 *   · treasury accounts go with their own sub-accounts (5211), clients and
 *     suppliers with theirs (411001, 401001), even with a manual entry on them
 *     (every entry goes); a parent's own row (« 411 », « 521 ») never goes,
 *     even when a treasury account pointed at it;
 *   · every numbering counter restarts; activity lines about the wiped tables
 *     go, those about the staff stay;
 *   · « Garder les produits » / « Garder les fournisseurs » keep them, with the
 *     supplier's 401 and tariffs, and nothing blocks;
 *   · the code is tied to the choices; the history names a full reset.
 *
 * Run: WIPE_TEST_DB_PORT=3307 WIPE_TEST_DB_NAME=bureau_wipe_test php scripts/tests/wipe/data_wipe_full.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';
$root = $GLOBALS['__wipe_root'];
$copies = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/bureau_wipe_full_copies_' . getmypid();
$GLOBALS['__env'] = ['WIPE_COPY_DIR' => $copies, 'MAIL_FROM' => ''];
register_shutdown_function(function () use ($copies) {
    foreach (glob($copies . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($copies);
});

$db = null;
require_once $root . '/includes/classes/DataWipe.php';

/** A small business: staff, a chart, two treasury accounts, clients, a supplier, products, sales, money, entries. */
function full_fixture(): PDO
{
    $db = wipe_test_db();
    $db->exec("CREATE TRIGGER bd_journal_entries_no_post_delete BEFORE DELETE ON journal_entries FOR EACH ROW
               BEGIN IF OLD.status = 'posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal_entries: cannot delete a posted entry.'; END IF; END");
    $db->exec("INSERT INTO roles (id, name) VALUES (1, 'admin')");
    $db->exec("INSERT INTO employees (id, employee_code, first_name, last_name) VALUES (1, 'LPC-MD-01', 'Directeur', 'Test'), (2, 'EMP-007', 'Chauffeur', 'Test')");
    $db->exec("INSERT INTO users (id, employee_id, role_id, employee_code, first_name, last_name, email) VALUES (1, 1, 1, 'LPC-MD-01', 'Directeur', 'Test', 'md@test.invalid')");
    $db->exec("INSERT INTO app_preferences (pref_key, pref_value) VALUES ('company_name', 'LPC')");
    $db->exec("INSERT INTO ohada_accounts (id, account_number) VALUES (1, '401'), (2, '411'), (3, '521'), (4, '701')");
    $db->exec("INSERT INTO chart_of_accounts (id, ohada_account_id, code, name) VALUES
               (1, 2, '411', 'Clients'), (2, 3, '5211', 'CCA Bank'), (3, 4, '701', 'Ventes'), (4, 2, '411001', 'Client - Alpha'),
               (6, 1, '401001', 'Fournisseur - Gamma'), (7, 3, '521', 'Banques')");
    // The Caisse points at a parent's own row: the parent must stay
    $db->exec("INSERT INTO treasury_accounts (id, name, balance, coa_account_id) VALUES (1, 'CCA Bank', 1150000, 2), (2, 'Caisse', 30000, 7)");
    $db->exec("INSERT INTO clients (id, name, account_id) VALUES (1, 'Alpha', 4)");
    $db->exec("INSERT INTO suppliers (id, name, account_id) VALUES (1, 'Gamma', 6)");
    $db->exec("INSERT INTO products (id, code, name, linked_empty_id, cump) VALUES (1, 'VIDE', 'Bonbonne vide', NULL, 800), (2, 'EAU', 'Eau', 1, 1500)");
    $db->exec("INSERT INTO vehicles (id, plate_number) VALUES (1, 'LT-1')");
    $db->exec("INSERT INTO proposals (id, reference, client_name) VALUES (1, 'DEV-2611-0001', 'Alpha')");
    $db->exec("INSERT INTO proposal_items (id, proposal_id, product_id) VALUES (1, 1, 2)");
    $db->exec("INSERT INTO sales_orders (id, reference, client_id, total_amount, payment_status) VALUES (1, 'CMD-2611-0001', 1, 100000, 'paid')");
    $db->exec("INSERT INTO sales_order_items (id, sales_order_id, product_id, quantity) VALUES (1, 1, 2, 40)");
    $db->exec("INSERT INTO deliveries (id, reference, sales_order_id, client_id, vehicle_id, status, created_at, signed_at) VALUES
               (1, 'BL-2611-0001', 1, 1, 1, 'completed', '2026-11-02 09:00:00', '2026-11-02 11:00:00')");
    $db->exec("INSERT INTO delivery_items (id, delivery_id, product_id, quantity, delivered_quantity, returned_empty_qty) VALUES (1, 1, 2, 40, 40, 0)");
    $db->exec("INSERT INTO invoices (id, reference, client_id, total_amount, status) VALUES (1, 'FAC-2611-0001', 1, 100000, 'paid')");
    $db->exec("INSERT INTO invoice_items (id, invoice_id, product_id) VALUES (1, 1, 2)");
    $db->exec("INSERT INTO invoice_deliveries (invoice_id, delivery_id) VALUES (1, 1)");
    $db->exec("INSERT INTO payments (id, reference, invoice_id, client_id, amount, payment_method) VALUES (1, 'REC-2611-0001', 1, 1, 100000, 'bank')");
    $db->exec("INSERT INTO purchase_orders (id, reference, supplier_id) VALUES (1, 'PO-2611-0001', 1)");
    $db->exec("INSERT INTO journal_entries (id, reference, date, status, source_type, source_id) VALUES
               (1, 'INV-FAC-2611-0001-AA', '2026-11-02', 'posted', 'invoice', 1),
               (2, 'PAY-REC-2611-0001-BB', '2026-11-02', 'posted', NULL, NULL),
               (3, 'OD-MANUEL-1', '2026-11-02', 'posted', NULL, NULL),
               (4, 'OUV-TRS-1', '2026-11-01', 'posted', 'treasury_opening', 1)");
    // A manual entry on 5211 and on 411001: the full reset takes it, and the sub-accounts with it
    $db->exec("INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit) VALUES
               (1, 4, 100000, 0), (1, 3, 0, 100000), (2, 2, 100000, 0), (2, 4, 0, 100000),
               (3, 2, 1000, 0), (3, 4, 0, 1000), (4, 2, 1000000, 0), (4, 6, 0, 1000000)");
    $db->exec("INSERT INTO treasury_transactions (id, account_id, transaction_type, amount, reference) VALUES
               (1, 1, 'in_other', 1000000, 'OUVERTURE'), (2, 1, 'in_client_payment', 100000, 'PAY-REC-2611-0001'), (3, 2, 'in_other', 30000, 'OUVERTURE')");
    $db->exec("INSERT INTO inventory_movements (id, product_id, movement_type, quantity, reference_id, date) VALUES
               (1, 2, 'in_supplier', 100, 1, '2026-11-01 08:00:00'), (2, 2, 'out_delivery', 40, 1, '2026-11-02 09:00:00')");
    $db->exec("INSERT INTO expenses (id, reference) VALUES (1, 'EX-2611-0001')");
    $db->exec("INSERT INTO notifications (user_id, title, message) VALUES (1, 'Facture', 'Facture FAC-2611-0001 réglée.'), (1, 'Bienvenue', 'Rien à voir')");
    $db->exec("INSERT INTO audit_logs (user_id, action, table_name, record_id) VALUES
               (1, 'INSERT', 'invoices', 1), (1, 'UPDATE', 'treasury_accounts', 1), (1, 'UPDATE', 'users', 1), (1, 'UPDATE', 'app_preferences', 1)");
    $db->exec("INSERT INTO document_counters (doc_type, period, last_number) VALUES ('invoice', '2611', 1), ('order', '2611', 1), ('quote', '2611', 1)");
    $db->exec("INSERT INTO financial_years (id, year, state) VALUES (1, 2026, 'open')");
    return $db;
}

function rows(PDO $db, string $t): int
{
    return (int) $db->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
}

/** Every base table of the test database. */
function all_tables(PDO $db): array
{
    return $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
}

$user = ['id' => 1, 'name' => 'Directeur Test'];

// ---- Everything --------------------------------------------------------------------
section('Remise à zéro: everything but the staff, the settings and the chart');
$db = full_fixture();
$wiper = new DataWipe($db);
$kept = ['users', 'roles', 'employees', 'app_preferences', 'ohada_accounts'];
$before = [];
foreach ($kept as $t) {
    $before[$t] = rows($db, $t);
}
$plan = $wiper->planFull(false, false);
check('the plan is not blocked', !$plan['blocked'], implode(' ', $plan['blocked']));
check('the plan takes the treasury accounts', !empty($plan['delete']['treasury_accounts']) && count($plan['delete']['treasury_accounts']) === 2);
check('the plan is marked full', ($plan['mode'] ?? '') === 'full' && $plan['options'] === ['products' => false, 'suppliers' => false]);
$done = $wiper->runFull(false, false, $user, 'Remise à zéro du test');
check('the run reports a full reset', ($done['mode'] ?? '') === 'full' && $done['total'] > 0);

$left = [];
foreach (all_tables($db) as $t) {
    if (!DataWipeMap::isProtected($t) && !in_array($t, DataWipeMap::FOLLOWERS_ONLY, true) && rows($db, $t) > 0) {
        $left[] = $t;
    }
}
check('every table that is not kept is empty', !$left, implode(', ', $left));
check('the treasury accounts are gone', rows($db, 'treasury_accounts') === 0);
check('every numbering counter restarts', rows($db, 'document_counters') === 0);
check('no journal line is left', rows($db, 'journal_lines') === 0 && rows($db, 'journal_entries') === 0);
$codes = $db->query("SELECT code FROM chart_of_accounts ORDER BY code")->fetchAll(PDO::FETCH_COLUMN);
check('the sub-accounts of the treasury account, the client and the supplier go (manual entries on them or not)',
      !array_intersect(['5211', '411001', '401001'], $codes), implode(', ', $codes));
check('the parents\' own rows stay, even the one a treasury account pointed at', $codes === ['411', '521', '701'], implode(', ', $codes));
foreach ($kept as $t) {
    check("{$t} untouched", rows($db, $t) === $before[$t]);
}
$logs = $db->query("SELECT table_name FROM audit_logs ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
check('activity about the staff and the settings stays; about the wiped data it goes', $logs === ['app_preferences', 'users'], implode(', ', $logs));
check('the history names a full reset',
      DataWipe::pickedText((string) $db->query("SELECT picked FROM data_wipes ORDER BY id DESC LIMIT 1")->fetchColumn())
      === 'Remise à zéro avant démarrage (produits effacés, fournisseurs effacés)');

// ---- Keeping products and suppliers --------------------------------------------------
section('Remise à zéro: products and suppliers kept');
$db = full_fixture();
$wiper = new DataWipe($db);
$plan = $wiper->planFull(true, true);
check('nothing blocks when both are kept', !$plan['blocked'], implode(' ', $plan['blocked']));
$done = $wiper->runFull(true, true, $user, 'Remise à zéro, produits et fournisseurs gardés');
check('the products stay', rows($db, 'products') === 2);
check('the supplier stays, with its 401', rows($db, 'suppliers') === 1
      && (int) $db->query("SELECT COUNT(*) FROM chart_of_accounts WHERE code = '401001'")->fetchColumn() === 1);
check('its purchase order goes', rows($db, 'purchase_orders') === 0);
check('the clients go with their 411', rows($db, 'clients') === 0
      && (int) $db->query("SELECT COUNT(*) FROM chart_of_accounts WHERE code = '411001'")->fetchColumn() === 0);
check('the stock movements go', rows($db, 'inventory_movements') === 0);
check('the treasury accounts go', rows($db, 'treasury_accounts') === 0);

// ---- The code is tied to the choices -------------------------------------------------
section('Remise à zéro: the code');
$hashes = array_map(fn ($p) => DataWipe::pickHash(DataWipe::fullPick($p[0], $p[1])), [[false, false], [true, false], [false, true], [true, true]]);
check('each choice has its own code', count(array_unique($hashes)) === 4);
check('a full-reset code never matches a table pick', !in_array(DataWipe::pickHash(['invoices']), $hashes, true));
check('the four tables the owner keeps optional are listed',
      DataWipeMap::FULL_RESET_PRODUCTS === ['products'] && in_array('supplier_category_rebates', DataWipeMap::FULL_RESET_SUPPLIERS, true));

wipe_test_done();
