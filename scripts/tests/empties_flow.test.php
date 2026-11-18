<?php
/**
 * scripts/tests/empties_flow.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Gestion des Vides (18 Nov 2026, the training video QA,
 * docs/VIDEO_QA_FINDINGS.md §7):
 *
 *   · each client's return rate against its signed minimum, what LPC must
 *     still recover, and what the client may keep (7.1);
 *   · LPC's count books the return; the client may contest it before signing,
 *     which undoes exactly that (7.3);
 *   · a CRE reduces the client's debt for that bottle size, whatever cork or
 *     site it names (7.4);
 *   · a recycler sale cannot sell more than the stock, moves it through
 *     lpc_stock_move (7.5);
 *   · no role names: permissions decide (7.6); every CRE in the history (7.7).
 *
 * The flow was run by hand against the filming copy (MySQL): PROMETAL 200
 * delivered, 80 %: 160 to recover; a CRE of 100 against its « Siège
 * Principal » and 30 with cork both reduced the one 20L debt (30 left); a
 * contest after LPC's count put 10 back; selling 500 with 10 in stock was
 * refused. CI has no database for this app: these are wiring checks, plus the
 * arithmetic.
 *
 * Run:  php scripts/tests/empties_flow.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
function src(string $path): string { return str_replace("\r\n", "\n", (string) file_get_contents($path)); }
$cc   = src($root . '/api/v1/cre_controller.php');
$sse  = src($root . '/includes/functions/signature_side_effects.php');
$emp  = src($root . '/includes/functions/empties.php');
$js   = src($root . '/assets/js/modules/operations-empties_collection.js');
$page = src($root . '/modules/operations/empties_collection.php');
$map  = src($root . '/includes/classes/DataWipeMap.php');
$bal  = src($root . '/includes/classes/DataWipeBalances.php');
$migs = glob($root . '/migrations/*_empties_recovery_and_cre.sql');
$mig  = $migs ? src($migs[0]) : '';

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}

echo "What LPC must recover (7.1)\n";
check('the position is computed from the client minimum (100 % without one)',
      str_contains($emp, 'COALESCE({$c}.empties_return_min_pct, 100)') && str_contains($emp, 'AS to_recover') && str_contains($emp, 'AS tolerated'));
check('the Dus rows carry it', substr_count($cc, 'lpc_empties_position_sql()') >= 3);
check('the first card is « À récupérer »', str_contains($cc, "'total_to_recover'") && str_contains($js, "label: 'À récupérer'"));
check('the table shows rate, to recover, tolerated', str_contains($page, '>Taux de retour<') && str_contains($page, '>À récupérer<') && str_contains($page, '>Toléré<'));
// The arithmetic, as empties.php does it
$out = 200; $in = 130; $pct = 80;
$minimum = (int) ceil($out * $pct / 100 - 0.000001);
$recover = max(0, $minimum - $in);
$owed = $out - $in;
check('200 delivered at 80 %, 130 back: 30 to recover, 40 tolerated', $minimum === 160 && $recover === 30 && max(0, $owed - $recover) === 40);

echo "\nThe client contests LPC's count (7.3)\n";
check('a contest is accepted after LPC signed, until the client signs', str_contains($cc, "status IN ('en_transit', 'signed') FOR UPDATE")
      && str_contains($cc, "Vous avez déjà signé ce retour."));
check("LPC's booking is undone line by line", str_contains($cc, 'lpc_cre_undo_count($pdo') && str_contains($sse, 'function lpc_cre_undo_count(')
      && str_contains($sse, 'quantity_owed = quantity_owed + ?'));
check('each line keeps the row it reduced and by how much', str_contains($mig, 'ADD COLUMN ledger_id INT NULL') && str_contains($mig, 'owed_applied')
      && str_contains($sse, 'UPDATE cre_items SET ledger_id = ?, owed_applied = ? WHERE id = ?'));
check('the public route says a rule, never a database error', str_contains($cc, "error_log('cre_controller reject_cre: '"));

echo "\nOne debt per bottle size (7.4)\n";
check('the CRE finds the row by client and bottle size, any site or cork', str_contains($emp, 'p.bottle_size = me.bottle_size')
      && str_contains($sse, 'lpc_empties_target_row($db, (int) $cre[\'client_id\'], $pid)')
      && !str_contains($sse, 'AND (site_id = ? OR (? IS NULL AND site_id IS NULL))'));
check('empties in through lpc_stock_move, naming the CRE', str_contains($sse, "lpc_stock_move(\$db, \$pid, 'in_return_emp', \$qty, 'cre', \$creId,"));
check('the data wipe finds them and the row they reduced', str_contains($map, "m.reference_type = 'cre' AND m.reference_id IN ({ids})")
      && str_contains($bal, 'LEFT JOIN client_empties_ledger l ON l.id = ci.ledger_id'));

echo "\nThe recycler sale (7.5)\n";
check('only empties in stock are sold', str_contains($cc, '$lock = lpc_stock_lock($pdo, $pid);') && str_contains($cc, 'Stock insuffisant pour')
      && str_contains($cc, 'WHERE id = ? AND is_empty = 1'));
check('stock leaves through lpc_stock_move as a recycler sale', str_contains($cc, "lpc_stock_move(\$pdo, \$pid, 'out_delivery', \$qty, 'recycling_sale', (int) \$sale_id,")
      && !str_contains($cc, "INSERT INTO inventory_movements"));
check('the grid shows the stock', str_contains($js, "' en stock</p>'"));

echo "\nPermissions, not role names (7.6, 7.7)\n";
check("no role names in the empties controller", !str_contains($cc, "\$_SESSION['user_role']") && !str_contains($cc, "r.name IN ('admin', 'finance')"));
check('notices by permission', str_contains($cc, 'lpc_notify_permission($pdo, $perms,'));
check('the CF reads the page and the revenue (migration 150)', str_contains($mig, "p.name IN ('operations.empties.view', 'operations.recycling.view')")
      && str_contains($mig, "r.name = 'controleur_financier'"));
check('the treasury picker loads only for who sells', str_contains($js, 'if (canSellRecycler) loadTreasuryAccountsForRecycling();'));
check('the history lists every CRE', !str_contains($cc, 'WHERE d.operator_id = ?') && str_contains($cc, "AS operator_name"));

echo $fail ? "\n$fail check(s) failed\n" : "\nAll checks passed\n";
exit($fail ? 1 : 0);
