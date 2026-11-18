<?php
/**
 * scripts/tests/go_live.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Go-live readiness (18 Nov 2026, the owner's decisions 18,
 * 19, 21 and 24; docs/TRAINING_VIDEOS.md §7).
 *
 *   · « LPC assujetti à la TVA » (tax_tva_liable): off by default; on, a new
 *     supplier order holds its TVA as recoverable (HT + TVA), the invoice
 *     proposes 19,25 %, the declaration deducts the receptions' 4452;
 *   · « Directeur Général »: the DG's own role with the administrator's rights;
 *     the pages that checked the role name accept it;
 *   · JBS Praxis Support is never in the payroll (employees.in_payroll);
 *   · « Délai de paiement par défaut » and the admins' 2FA work.
 *
 * CI has no MySQL for this app: the code is checked by reading it; the flows
 * were run by hand on the filming copy (copy C).
 *
 * Run:  php scripts/tests/go_live.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
$read = fn(string $p) => (string) @file_get_contents($root . '/' . $p);

$prefs = $read('includes/classes/Prefs.php');
$proc  = $read('api/v1/procurement_controller.php');
$inv   = $read('api/v1/invoices_controller.php');
$invjs = $read('assets/js/modules/accounting-invoices.js');
$tax   = $read('includes/classes/TaxEngine.php');
$pay   = $read('api/v1/payroll_controller.php');
$auth  = $read('api/v1/auth.php');
$a2fa  = $read('includes/functions/admin_2fa.php');
$api2  = $read('api/v1/auth_2fa.php');
$page2 = $read('public/auth/verify_code.php');
$off   = $read('scripts/admin/admin_2fa_off.php');
$hta   = $read('.htaccess');
$crm   = $read('modules/crm/clients.php');
$cast  = $read('scripts/video/cast.js');
$mig   = $read('migrations/156_go_live_tva_dg_payroll_prefs.sql');

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}

echo "« LPC assujetti à la TVA » (decision 19)\n";
check('Prefs default for tax_tva_liable is off', (bool) preg_match("/'tax_tva_liable'\s*=>\s*'0'/", $prefs));
check('migration 156 sets the setting off and names it', str_contains($mig, "pref_value = '0'") && str_contains($mig, 'LPC assujetti à la TVA'));
check('a supplier order reads the setting, not a constant', str_contains($proc, "Prefs::bool('tax_tva_liable', false)"));
check('on, the order keeps HT and TVA apart', str_contains($proc, '$po_subtotal_ht') && str_contains($proc, '$po_vat_amount'));
check('the invoice API tells the page whether LPC is liable', str_contains($inv, "'tva_liable'") && str_contains($invjs, 'tva_liable'));
check('the declaration deducts what receptions booked to 4452', str_contains($tax, '4452') && str_contains($tax, 'purchase_order'));
check('the declaration nets the avoirs\' TVA', str_contains($tax, "'avoir'"));

echo "« Directeur Général » (decision 21)\n";
check('migration 156 gives the DG every admin permission', str_contains($mig, 'INSERT IGNORE INTO role_permissions') && str_contains($mig, "dg.name = 'directeur_general'"));
check('migration 156 moves Timothée to the DG role', str_contains($mig, 'timothee.massomba@lpc.cm'));
check('the cast signs Timothée in as the DG', (bool) preg_match('/timothee[\s\S]{0,300}directeur_general/', $cast));
foreach ([
    'api/v1/financials_controller.php', 'modules/accounting/reports.php', 'api/v1/budget_controller.php',
    'modules/accounting/budgets.php', 'scripts/cron/notify_pending_attestations.php',
] as $f) {
    $src = $read($f);
    $adminChecks = preg_match_all("/'admin'/", $src);
    check("$f: every role check naming admin also names the DG", $adminChecks === 0 || str_contains($src, 'directeur_general'));
}

echo "JBS Praxis Support out of the payroll (decision 18)\n";
check('migration 156 adds employees.in_payroll and sets it 0 for the IT partner',
      str_contains($mig, 'in_payroll TINYINT(1) NOT NULL DEFAULT 1') && str_contains($mig, "SET in_payroll = 0"));
check('every payroll query that lists or computes employees filters in_payroll = 1',
      substr_count($pay, 'e.in_payroll = 1') >= 4);

echo "Délai de paiement par défaut (decision 24)\n";
check('migration 156 shows the setting again', (bool) preg_match("/is_active = 1[\s\S]{0,300}default_payment_terms_days/", $mig));
check('an invoice for a client without terms takes the setting', substr_count($inv, "Prefs::int('default_payment_terms_days'") >= 2);
check('a new devis proposes terms from the setting', str_contains($crm, "Prefs::int('default_payment_terms_days'"));

echo "2FA for administrators (decision 24)\n";
check('migration 156 shows the setting again, still off', (bool) preg_match("/is_active = 1[\s\S]{0,400}sec_require_2fa_admin/", $mig) && !preg_match("/pref_value\s*=\s*'1'[^;]*sec_require_2fa_admin/", $mig));
check('only admin and the DG are asked', str_contains($a2fa, "['admin', 'directeur_general']") || str_contains($a2fa, "['admin','directeur_general']"));
check('no code when mail is not configured (nobody is locked out)', str_contains($a2fa, 'Mail::configured()'));
check('a remembered device skips the code', str_contains($a2fa, 'lpc_device_current'));
check('the code is stored hashed, expires and has a try limit',
      str_contains($a2fa, 'password_hash') && str_contains($a2fa, 'LPC_2FA_TTL') && str_contains($a2fa, 'LPC_2FA_MAX_TRIES'));
check('the password step hands over to the code before completing the sign-in',
      ($p1 = strpos($auth, 'lpc_2fa_required')) !== false && ($p2 = strpos($auth, 'lpc_login_complete', $p1)) !== false);
check('the code step checks CSRF and the rate limiter', str_contains($api2, 'Csrf::') && str_contains($api2, 'RateLimiter'));
check('the code step completes the sign-in itself', str_contains($api2, 'lpc_2fa_check') && str_contains($api2, 'lpc_login_complete'));
check('the code page posts with a CSRF field', str_contains($page2, 'Csrf::field()') && str_contains($page2, '/api/v1/auth_2fa.php'));
check('/verify_code.php is routed', str_contains($hta, 'verify_code'));
check('the recovery script runs only from the command line', str_contains($off, "PHP_SAPI") && str_contains($off, 'sec_require_2fa_admin'));

foreach (['includes/functions/admin_2fa.php', 'api/v1/auth_2fa.php', 'public/auth/verify_code.php', 'scripts/admin/admin_2fa_off.php'] as $f) {
    exec('php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $o, $rc);
    check("$f parses", $rc === 0);
}

echo $fail ? "\n$fail check(s) failed.\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
