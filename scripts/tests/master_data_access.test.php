<?php
/**
 * scripts/tests/master_data_access.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Données de Base (18 Nov 2026, the training video QA,
 * docs/VIDEO_QA_FINDINGS.md §1). Who may read and who may write master data,
 * and what a save must never lose:
 *
 *   · the page and every read are open to admin.master_data.view (DG, CF,
 *     Comptable, Opérations); an admin-only gate used to refuse the
 *     Contrôleur Financier, who owns the master data with the DG;
 *   · every action that is not a read needs a POST, admin.master_data.edit
 *     and a CSRF token, checked once before any action runs, so a new write
 *     action is gated by default; the read actions write nothing;
 *   · saving a product keeps its own accounts when the form does not send
 *     them, and its reference cost is editable;
 *   · a client tariff set here is logged in the price history, and can be
 *     deleted; a supplier saved without a 401 account gets one;
 *   · the screen hides what a view-only role cannot do.
 *
 * The flows themselves were run by hand against the filming copy (MySQL) when
 * this was built. CI has no database for this app: these are wiring checks.
 *
 * Run:  php scripts/tests/master_data_access.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
$ctl  = file_get_contents($root . '/api/v1/mdm_controller.php');
$js   = file_get_contents($root . '/assets/js/modules/admin-master_data.js');
$page = file_get_contents($root . '/modules/admin/master_data.php');

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}

echo "Données de Base: who may read and write\n";

check('no role-name gate (the CF holds the permissions, not the admin role)',
      !preg_match('/user_role\'\]\s*!==?\s*\'admin\'/', $ctl));
check('the page needs admin.master_data.view',
      str_contains($page, "Rbac::requirePermission('admin.master_data.view')"));
check('the API needs admin.master_data.view',
      str_contains($ctl, "Rbac::requirePermission('admin.master_data.view')"));

// The read-only list, as the controller declares it.
preg_match("/const MDM_READ_ACTIONS = \[([^\]]*)\]/", $ctl, $m);
$reads = $m ? array_map(fn($s) => trim($s, " '\""), explode(',', $m[1])) : [];
check('the read actions are declared', count($reads) >= 3);
check('the declared reads are read, next_code and the two price histories',
      $reads == ['read', 'next_code', 'supplier_price_history', 'client_price_history']);

// The gate sits before the first action and covers everything else.
$gate  = strpos($ctl, 'if (!in_array($mdm_action, MDM_READ_ACTIONS, true))');
$first = strpos($ctl, '$action === ');
$gateBlock = $gate !== false ? substr($ctl, $gate, 600) : '';
check('one gate for every non-read action, before any action runs', $gate !== false && $gate < $first);
check('the gate requires POST', str_contains($gateBlock, "REQUEST_METHOD") && str_contains($gateBlock, "'POST'"));
check('the gate requires admin.master_data.edit', str_contains($gateBlock, "Rbac::requirePermission('admin.master_data.edit')"));
check('the gate requires a CSRF token', str_contains($gateBlock, 'Csrf::requireValid()'));

// Every action the controller handles; the reads must not write.
preg_match_all("/\\\$action === '([a-z_]+)'/", $ctl, $all);
$actions = array_values(array_unique($all[1]));
check('the controller still handles save, toggle_status and the tariff deletes',
      !array_diff(['save', 'toggle_status', 'save_category', 'save_default_price',
                   'delete_supplier_price', 'delete_client_price'], $actions));
foreach ($reads as $r) {
    $at = strpos($ctl, "\$action === '{$r}'");
    if ($at === false) { check("read action {$r} exists", false); continue; }
    $next = strpos($ctl, '$action === ', $at + 20);
    $body = substr($ctl, $at, ($next === false ? strlen($ctl) : $next) - $at);
    check("read action {$r} writes nothing",
          !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $body));
}
check('the never-applied fallback price is gone from the API',
      !str_contains($ctl, "'save_fallback'") && !str_contains($ctl, 'mdm_fallback_price'));

echo "\nWhat a save keeps\n";
check("a product's own accounts are kept when the form does not send them",
      str_contains($ctl, "array_key_exists(\$key, \$_POST)")
      && substr_count($ctl, "\$account_or_prev('") === 3);
check('the reference cost is saved on edit', (bool) preg_match('/UPDATE products SET cost_price = \? WHERE id = \?/', $ctl));
check('a new product takes the typed reference cost, else its base price',
      str_contains($ctl, '$insert_params[] = $cost_price ?? $base_price;'));
check('a client tariff goes through the logged price change',
      str_contains($ctl, 'lpc_apply_client_price_change(') && !preg_match('/INSERT INTO client_prices/', $ctl));
check('a supplier without a 401 account gets one on save',
      substr_count($ctl, 'mdm_mint_supplier_account($db, $name)') >= 2);
check('the 401 sequence counts six-digit 401nnn codes only',
      str_contains($ctl, "code REGEXP '^401[0-9]{3}\$'"));
check('a supplier needs a name', str_contains($ctl, 'La raison sociale est obligatoire.'));

echo "\nWhat the screen offers\n";
check('« Ajouter » and « Enregistrer » are hidden without the edit permission',
      substr_count($page, 'data-perm="admin.master_data.edit"') === 2);
check('rows offer « Consulter » to view-only roles', str_contains($js, 'title="Consulter"') && str_contains($js, 'if (!canEdit())'));
check('tariff rows: history for all, edit and delete for editors',
      str_contains($js, 'function tariffActions(') && str_contains($js, "delete_client_price"));
check('the form opens read-only for view-only roles', str_contains($js, "'Consulter'") && str_contains($js, 'el.disabled = true'));
check('the fallback price is no longer shown as policy', !str_contains($js, 'saveFallback') && !str_contains($js, 'fb_amount'));
check('the form sends the reference cost', str_contains($js, 'name="cost_price"'));
check('a supplier form asks only the name', substr_count($js, 'optional: true') >= 3);

echo $fail ? "\n{$fail} check(s) failed.\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
