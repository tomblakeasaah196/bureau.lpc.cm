<?php
/**
 * scripts/tests/proposal_flow.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Proposition commerciale (18 Nov 2026, the training video
 * QA, docs/VIDEO_QA_FINDINGS.md §4 and 3.6):
 *
 *   · a client's signature carries the devis downstream (4.1): the signed
 *     prices become its tariff, Net 15 and the empties minimum go onto the
 *     client, a prospect becomes active, orders link to the devis, invoices
 *     date their due date from the terms, Gestion des Vides reads the minimum,
 *     the promised buffer does NOT raise the product's « Seuil Min. » (owner, #16);
 *   · Article 1 follows the chosen policy (4.2);
 *   · « Signé » means the client signed, in the list, the detail and the edit
 *     lock; « Je souhaite discuter » reads « À revoir » (4.4);
 *   · no hardcoded link, no fake link on a network error (4.5);
 *   · « Convertir » keeps the client's 411 (4.6); real debts, no demo row
 *     (4.7); the AIR rate error in words, buttons per permission (4.8); no
 *     « + consigne » in the product picker (4.9).
 *
 * The parsers run here; the flow was run by hand against the filming copy
 * (MySQL): PROMETAL created, its devis signed through the public link, then
 * an order and the invoice form checked. CI has no database for this app:
 * the rest are wiring checks.
 *
 * Run:  php scripts/tests/proposal_flow.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
require $root . '/includes/functions/proposal_terms.php';

$read = static fn(string $p): string => (string) file_get_contents($root . '/' . $p);
$terms   = $read('includes/functions/proposal_terms.php');
$sse     = $read('includes/functions/signature_side_effects.php');
$pricing = $read('includes/functions/pricing.php');
$create  = $read('api/v1/create_proposal.php');
$detail  = $read('api/v1/proposal_detail.php');
$list    = $read('api/v1/fetch_proposals.php');
$update  = $read('api/v1/update_proposal.php');
$sales   = $read('api/v1/sales_controller.php');
$inv     = $read('api/v1/invoices_controller.php');
$invJs   = $read('assets/js/modules/accounting-invoices.js');
$cre     = $read('api/v1/cre_controller.php');
$vidJs   = $read('assets/js/modules/operations-empties_collection.js');
$cliJs   = $read('assets/js/modules/crm-clients.js');
$devJs   = $read('assets/js/modules/crm-devis.js');
$quoteJs = $read('assets/js/modules/documents-quote.js');
$quote   = $read('public/documents/quote.php');
$sign    = $read('public/documents/sign_quote.php');
$page    = $read('modules/crm/clients.php');
$convert = $read('api/v1/convert_client.php');
$cclient = $read('api/v1/create_client.php');
$uclient = $read('api/v1/update_client.php');
$fclient = $read('api/v1/fetch_clients.php');
$picker  = $read('assets/js/lpc-product-picker.js');
$migs    = glob($root . '/migrations/*_proposal_acceptance.sql');
$mig     = $migs ? (string) file_get_contents($migs[0]) : '';

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}

echo "The terms, read from the devis\n";
check('« Net 15 Jours » is 15 days', lpc_proposal_terms_days('Net 15 Jours') === 15);
check('« Net 30 Jours » is 30 days', lpc_proposal_terms_days('Net 30 Jours') === 30);
check('« Paiement à la Livraison » is 0 days', lpc_proposal_terms_days('Paiement à la Livraison') === 0);
check('no terms, no number', lpc_proposal_terms_days('') === null);
check('« Retour Minimum Exigé (80%) » is 80 %', lpc_proposal_empties_pct('Retour Minimum Exigé (80%)') === 80);
check('« Échange 1-pour-1 (100%) » is 100 %, not 1', lpc_proposal_empties_pct('Échange 1-pour-1 (100%)') === 100);
check('a policy with no percentage gives none', lpc_proposal_empties_pct('Asset-Tracked Swap') === null);
check('Article 1 at 80 % says « au minimum 80 % »',
      str_contains(lpc_proposal_empties_clause('Retour Minimum Exigé (80%)'), 'au minimum 80 %'));
check('Article 1 at 100 % says « 1 pour 1 »',
      str_contains(lpc_proposal_empties_clause('Échange 1-pour-1 (100%)'), '(1 pour 1)'));
check('Article 1 in English', str_contains(lpc_proposal_empties_clause('Retour Minimum Exigé (70%)', 'en'), 'at least 70 %'));
check('1 667 a month with 2 weeks of buffer is 770 bottles',
      (int) ceil(1667 * 2 / LPC_WEEKS_PER_MONTH - 1e-9) === 770);

echo "\nAcceptance carries the devis downstream (4.1, 3.6)\n";
check('the client signature applies lpc_proposal_accept', str_contains($sse, '$done = lpc_proposal_accept($db, $proposalId);'));
check('the signed prices become the tariff, logged as « proposal »',
      str_contains($terms, "'proposal', \$prop['reference']") && str_contains($pricing, "['order', 'client_file', 'import', 'proposal']"));
check('terms, minimum, devis and status go onto the client',
      str_contains($terms, 'payment_terms_days     = COALESCE(?, payment_terms_days)')
      && str_contains($terms, "status                 = IF(status = 'prospect', 'active', status)"));
check('a signed devis leaves « Seuil Min. » alone (owner, #16)',
      !str_contains($terms, 'UPDATE products SET min_stock_level'));
check('the migration adds the columns and the history source',
      str_contains($mig, "column_name = 'client_id'") && str_contains($mig, "column_name = 'payment_terms_days'")
      && str_contains($mig, "column_name = 'empties_return_min_pct'") && str_contains($mig, "column_name = 'terms_proposal_id'")
      && str_contains($mig, "ENUM('order', 'client_file', 'import', 'proposal')"));
check('a new devis records its client, its author and the client details',
      str_contains($create, ":client_id, :client_name") && str_contains($create, "'created_by'         => (int) \$_SESSION['user_id']")
      && str_contains($create, "'client_email'       => \$client['email'] ?: null"));
check('an order is made under the client\'s signed devis',
      str_contains($sales, '(reference, client_id, proposal_id, date,') && str_contains($sales, "\$proposal_id = !empty(\$cliRow['terms_proposal_id'])"));
check('the order detail names the devis', str_contains($sales, 'pr.reference AS proposal_reference'));
check('invoices date their due date from the client\'s terms',
      str_contains($inv, "'payment_terms_days' => \$terms_days") && str_contains($inv, 'SELECT payment_terms_days FROM clients WHERE id = ?')
      && str_contains($invJs, 'json.payment_terms_days'));
check('Gestion des Vides reads the minimum return', str_contains($cre, 'c.empties_return_min_pct')
      && str_contains($vidJs, 'Retour minimum ${minPct} %'));
check('the devis detail shows what was applied and its orders',
      str_contains($detail, "'applied'         => \$applied") && str_contains($detail, 'WHERE proposal_id = ? AND deleted_at IS NULL')
      && str_contains($devJs, 'function appliedBlock(d)') && str_contains($devJs, 'function ordersBlock(d)'));

echo "\nThe document (4.2, 4.3)\n";
check('Article 1 is followed by the policy sentence', str_contains($quote, '<span id="dyn_tc_1_policy"></span>')
      && str_contains($quoteJs, 'function emptiesClause(policy, lang)'));
check('the short address /quote/<token> loads the devis', str_contains($quoteJs, 'function quoteToken()'));
check('the signing page shows payment, delivery and the empties clause',
      str_contains($sign, 'lpc_proposal_empties_clause($raw[\'empties_policy\'] ?? \'\')') && str_contains($sign, 'Qté / mois'));
check('no « barème des consignes » in the consent', !str_contains($sign, 'barème des consignes'));

echo "\nStatuses (4.4)\n";
check('the detail says « Signé » on the client\'s signature only',
      str_contains($detail, "DocumentSignature::latestByParty('quote', (int) \$p['id'], 'external')") && !str_contains($detail, 'latestAny('));
check('only the client\'s signature locks the devis', str_contains($update, "AND party = 'external'"));
check('« Je souhaite discuter » is « À revoir »', str_contains($list, "WHEN {\$declinedExpr} THEN 'declined'")
      && str_contains($devJs, "row.derived_status === 'declined'") && str_contains($page, 'data-status="declined">À revoir'));
check('a revised devis goes out again', str_contains($update, "status             = IF(status = 'rejected', 'sent', status)"));

echo "\nThe client screen (4.5 to 4.9)\n";
check('the quote link is this server\'s, never a made-up one',
      str_contains($cliJs, '${window.location.origin}/quote.php?token=') && !str_contains($cliJs, 'sec_a8f93j2k')
      && !str_contains($cliJs, 'https://bureau.lpc.cm/quote.php'));
check('« Convertir » reuses the client\'s 411', str_contains($convert, "\$new_account_id = (int) (\$client['account_id'] ?? 0);"));
check('a new client is a prospect', str_contains($cclient, ": 'prospect',"));
check('the duplicate 411 accounts are deactivated, never deleted', str_contains($mig, 'SET dup.is_active = 0')
      && str_contains($mig, 'NOT EXISTS (SELECT 1 FROM journal_lines l WHERE l.account_id = dup.id)'));
check('real debts per client, no demo row', str_contains($fclient, 'AS financial_debt') && str_contains($fclient, 'AS empties_owed')
      && !str_contains($page, 'Prometal S.A.') && !str_contains($cliJs, '>0 FCFA</div>'));
check('the AIR rate refusal is said in words', str_contains($cclient, 'if ($e instanceof UserFacingException)')
      && str_contains($uclient, 'if ($e instanceof UserFacingException)'));
check('the write endpoints check CSRF', str_contains($cclient, 'Csrf::requireValid();') && str_contains($uclient, 'Csrf::requireValid();')
      && str_contains($convert, 'Csrf::requireValid();') && str_contains($create, 'Csrf::requireValid();'));
check('buttons per permission', str_contains($page, 'data-lpc-new data-perm="crm.clients.create"')
      && str_contains($cliJs, "can('crm.proposals.create')") && str_contains($cliJs, "can('crm.clients.edit')"));
check('no « Consigne Payée » policy', !str_contains($page, 'Consigne Payée') && !str_contains($devJs, 'Consigne Payée'));
check('the picker adds no « + consigne »', !str_contains($picker, "'+ ' + t('ui.picker.consigne'"));

echo "\n" . ($fail ? "$fail check(s) failed.\n" : "All checks passed.\n");
exit($fail ? 1 : 0);
