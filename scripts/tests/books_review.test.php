<?php
/**
 * scripts/tests/books_review.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Comptabilité 1 (Plan Comptable, Bilan d'Ouverture,
 * Écritures, Grand Livre OHADA), the training video's QA (18/11/2026):
 *
 *   · 11.1  « Validations en attente » and the closing check count drafts (the
 *           status vocabulary is draft / posted / reversed: 'pending' never was);
 *   · 11.4  a cancelled entry stays posted beside its mirror, so it counts once
 *           (it used to drop out of every report while its mirror stayed);
 *           migration 147 puts back the ones already stamped;
 *   · Écritures: writes need the CSRF token, database errors are never shown,
 *           a draft can be deleted (never a posted entry), a manual line needs
 *           an active account and one side, the « Journal des Écritures » read,
 *           the old 6-digit chart tab is gone (the Plan Comptable page has it);
 *   · Grand Livre: lettrage no longer calls LPC.csrf.token() (a string), the
 *           tiers cover 46x and 471, an inactive account with lines stays in;
 *   · Bilan d'Ouverture: no pre-filled treasury balances on a frozen year, the
 *           471 to clear, CSRF on save, no raw database error.
 *
 * CI has no MySQL for this app: the code is checked by reading it; the flows
 * were run by hand on the filming copy (copy C, 18/11/2026).
 *
 * Run:  php scripts/tests/books_review.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
$read = fn (string $p) => (string) file_get_contents($root . '/' . $p);
$ac   = $read('api/v1/accounting_controller.php');
$rc   = $read('api/v1/review_controller.php');
$ob   = $read('api/v1/opening_balance_controller.php');
$fin  = $read('api/v1/finance_dashboard_api.php');
$yec  = $read('includes/classes/YearEndClosing.php');
$jp   = $read('includes/classes/JournalPoster.php');
$exp  = $read('api/v1/expenses_controller.php');
$jeJs = $read('assets/js/modules/accounting-journal_entry.js');
$glJs = $read('assets/js/modules/accounting-ledger.js');
$obJs = $read('assets/js/modules/accounting-opening_balance.js');
$page = $read('modules/accounting/journal_entry.php');
$mig  = $read('migrations/147_books_reversals_and_help.sql');

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}

echo "11.1 · what waits for validation\n";
check('no query looks for a journal status « pending »',
      // (the Finance card also counts treasury correction requests, whose own status is « pending »)
      !preg_match("/journal_entries\s+WHERE\s+status\s*=\s*'pending'|je\.status\s*=\s*'pending'/", $fin) && !str_contains($yec, "AND status = 'pending'"));
check('the finance card counts drafts', (bool) preg_match("/FROM journal_entries\s+WHERE status = 'draft'/", $fin));
check('the closing check counts drafts', str_contains($yec, "YEAR(date) = ? AND status = 'draft'"));

echo "\n11.4 · a cancelled entry counts once\n";
$rev = substr($jp, (int) strpos($jp, 'public static function reverseSource'), 4000);
check('reverseSource keeps the original posted', !str_contains($rev, "SET status = 'reversed'") && str_contains($rev, 'SET reversed_by_entry_id = ?'));
check('reverseSource skips an entry already reversed', str_contains($rev, 'reversed_by_entry_id IS NULL'));
check('deleting a paid expense keeps its entry posted', !str_contains($exp, "SET status='reversed'"));
check('migration 147 puts reversed originals back to posted, outside locked years',
      str_contains($mig, "SET je.status = 'posted'") && str_contains($mig, "NOT IN ('locked', 'final_closed')"));

echo "\nÉcritures\n";
$post = substr($ac, (int) strpos($ac, "else if (\$method === 'POST')"));
check('every write checks the CSRF token first', str_starts_with(ltrim(substr($post, strpos($post, '{') + 1)), '// Every write') && str_contains(substr($post, 0, 300), 'Csrf::requireValid()'));
check('database errors are logged, not shown', !str_contains($ac, "'Erreur base de données: ' . \$e->getMessage()") && str_contains($ac, 'function acc_db_message'));
check('a draft can be deleted, never a posted entry',
      (bool) preg_match("/action === 'delete_draft'.*?status'\] !== 'draft'.*?DELETE FROM journal_entries WHERE id = \? AND status = 'draft'/s", $ac));
check('a manual line needs an active account', str_contains($ac, "est désactivé : choisissez un compte actif"));
check('a manual line is on one side only', str_contains($ac, 'soit au débit, soit au crédit'));
check('the journal read lists posted entries with their module', str_contains($ac, "case 'journal':") && str_contains($ac, 'acc_source_label'));
check('the page has the journal tab, not the 6-digit chart', str_contains($page, 'id="tab-journal"') && !str_contains($page, 'new_acc_code') && str_contains($page, '/modules/admin/chart_of_accounts.php'));
check('the assistant puts today back after a reset', str_contains($jeJs, "document.getElementById('wiz_date').value = doualaToday();"));
check('the queue page no longer says module entries wait there', !str_contains($page, "Ces écritures ont été générées par l'ERP"));

echo "\nGrand Livre\n";
check('lettrage no longer calls LPC.csrf.token()', !str_contains($glJs, 'LPC.csrf.token()'));
check('auto-lettrage confirm passes its title as an option', str_contains($glJs, "{ title: 'Lettrage automatique'"));
check('the tiers cover 466 (Smart Logistics) and 471', str_contains($rc, "'466'") && str_contains($rc, "'471'"));
check('an inactive account with posted lines stays in the balance', str_contains($rc, 'OR EXISTS (SELECT 1 FROM journal_lines x JOIN journal_entries y'));
check('a parent or class with nothing hides with the zero toggle', str_contains($glJs, "row-master \${masterZero ? 'zero-row' : ''}"));
check('?account=CODE opens that account', str_contains($glJs, "get('account')"));

echo "\nBilan d'Ouverture\n";
check('save checks the CSRF token', (bool) preg_match("/action === 'save'\) \{\s*Rbac::requirePermission\('accounting\.opening_balance\.enter'\);\s*Csrf::requireValid\(\);/", $ob));
check('a database error is not shown', !str_contains($ob, "'Erreur base de données : ' . \$e->getMessage()"));
check('treasury balances pre-fill only an empty year', str_contains($obJs, 'if (!STATE.existing && STATE.editable)'));
check('the 471 waiting to be cleared is shown', str_contains($ob, "'suspense'") && str_contains($obJs, 'function renderSuspense'));

echo "\nOpening debts per client and supplier (the owner's answer 26, register 11.6)\n";
$inv  = $read('api/v1/invoices_controller.php');
$proc = $read('api/v1/procurement_controller.php');
$m160 = $read('migrations/160_cnps_group_and_opening_tiers.sql');
$tierDocs = substr($ob, (int) strpos($ob, 'function opening_tier_documents'), 3500);
check('saving creates the documents', str_contains($ob, '$docs = opening_tier_documents($pdo, $year, $date, $clean, (int) $user_id);'));
check('a client that owes LPC: an open invoice OUV-YYYY-C<id>, posting nothing', str_contains($tierDocs, "sprintf('OUV-%d-C%d'") && str_contains($tierDocs, "'unpaid', ?, ?, 1)") && !str_contains($tierDocs, 'JournalPoster'));
check('a supplier LPC owes: an unpaid received debt OUV-YYYY-F<id>', str_contains($tierDocs, "sprintf('OUV-%d-F%d'") && str_contains($tierDocs, "VALUES (?, ?, ?, 'received', ?, ?, 'unpaid'"));
check('only in the first year of the books (a closed year keeps its own documents)', str_contains($tierDocs, "WHERE status = 'posted' AND date < ?"));
check('re-saving replaces only documents without a payment', str_contains($tierDocs, 'NOT EXISTS (SELECT 1 FROM payments p WHERE p.invoice_id = invoices.id)') && str_contains($tierDocs, 'payment_je_id IS NULL'));
check('migration 160 adds is_opening to invoices and purchase orders', str_contains($m160, "table_name = 'invoices' AND column_name = 'is_opening'") && str_contains($m160, "table_name = 'purchase_orders' AND column_name = 'is_opening'"));
$letter = substr($jp, (int) strpos($jp, 'public static function letterDocument'), 4500);
check('a settled document letters its lines, the opening line included', str_contains($letter, 'opening_balance_journal_id') && str_contains($letter, 'abs($net) >= 0.5') && str_contains($letter, 'UPDATE journal_lines SET lettrage = ?'));
check('a payment that settles an invoice letters it', str_contains($inv, "JournalPoster::letterDocument(\$db, 'invoice', (int) \$invoice_id)"));
check('a supplier settlement letters the order', str_contains($proc, "JournalPoster::letterDocument(\$db, 'purchase_order', \$po_id)"));
check('an opening debt is not cancelled as an order', str_contains($proc, "C'est un solde d'ouverture"));
check('the invoice shows « Solde d\'ouverture » as its line', str_contains($read('api/v1/get_invoice.php'), "Solde d'ouverture au ") && str_contains($read('includes/functions/document_pdf.php'), "Solde d'ouverture au "));
check('the screen says what becomes of a client\'s or supplier\'s balance', str_contains($obJs, 'devient une facture'));

echo "\n" . ($fail ? "$fail check(s) failed.\n" : "All checks passed.\n");
exit($fail ? 1 : 0);
