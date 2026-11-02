<?php
/**
 * scripts/tests/account_recovery.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — self-service recovery (2 Nov 2026): the masked address
 * hint, and the properties of the reset flow that a refactor must not lose.
 *
 * The flow itself (request a link, receive it, reset, reuse refused, expiry,
 * limits, MAIL_FROM unset) was run end to end against MySQL and an SMTP sink
 * when it was built. CI has no database for this app, so here the masking is
 * tested for real and the controller's guarantees are checked in its source.
 *
 * Run:  php scripts/tests/account_recovery.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
// account_recovery.php needs env() through Mail::configured(); a stub is enough here
if (!function_exists('env')) {
    function env(string $k, $default = null) { return $GLOBALS['__env'][$k] ?? $default; }
}
require_once $root . '/includes/functions/account_recovery.php';

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}

echo "─── the masked address ───\n";
foreach ([
    'paul.directeur@lpc.cm'       => 'p***.d***@lpc.cm',
    'jean-baptiste.assah@lpc.cm'  => 'j***-b***.a***@lpc.cm',
    'admin@lpc.cm'                => 'a***@lpc.cm',
    'x@lpc.cm'                    => 'x***@lpc.cm',
    'élodie.ngo@lpc.cm'           => 'é***.n***@lpc.cm',
    'no-at-sign'                  => '***',
    '@lpc.cm'                     => '***',
] as $in => $want) {
    $got = lpc_mask_email($in);
    check(sprintf('%-28s -> %s%s', $in, $want, $got === $want ? '' : " (got {$got})"), $got === $want);
}
check('the local part never survives whole', !str_contains(lpc_mask_email('directeur@lpc.cm'), 'directeur'));

echo "\n─── on only when mail leaves the host ───\n";
$GLOBALS['__env'] = ['MAIL_FROM' => ''];
check('MAIL_FROM unset: off', lpc_reset_by_email_enabled() === false);
$GLOBALS['__env'] = ['MAIL_FROM' => 'not an address'];
check('MAIL_FROM not an address: off', lpc_reset_by_email_enabled() === false);
$GLOBALS['__env'] = ['MAIL_FROM' => 'no-reply@bureau.lpc.cm'];
check('MAIL_FROM set: on', lpc_reset_by_email_enabled() === true);

echo "\n─── the controller keeps its guarantees ───\n";
$src  = file_get_contents($root . '/api/v1/password_controller.php');
$code = preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $src);
$case = static function (string $name) use ($code): string {
    $i = strpos($code, "case '{$name}':");
    if ($i === false) return '';
    $j = strpos($code, "\n        case '", $i + 1);
    $k = strpos($code, "\n        default:", $i + 1);
    $end = min(array_filter([$j, $k, strlen($code)], static fn ($v) => $v !== false));
    return substr($code, $i, $end - $i);
};
$req = $case('request_reset'); $hint = $case('email_hint'); $reset = $case('reset');

check('request_reset and reset refuse when mail is not configured',
      str_contains($req, 'pw_require_reset_enabled()') && str_contains($reset, 'pw_require_reset_enabled()'));
check('request_reset answers unknown and known accounts with the same message',
      substr_count($req, 'reply(true, $genericMsg)') === 2 && !preg_match('/reply\(false/', substr($req, strpos($req, '$genericMsg ='))));
check('request_reset is limited per typed identifier, before the lookup',
      strpos($req, "'pw_reset_acct'") !== false && strpos($req, "'pw_reset_acct'") < strpos($req, 'pw_user_by_'));
check('the token is 32 random bytes and only its hash is stored',
      str_contains($req, 'bin2hex(random_bytes(32))') && str_contains($req, "hash('sha256', \$rawToken)")
      && !preg_match('/INSERT INTO password_resets[^;]*\$rawToken\b(?!\))/', $req));
check('a new link kills the earlier ones', str_contains($req, 'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'));
check('reset locks the link row (no double use)', (bool) preg_match('/FROM password_resets[\s\S]*FOR UPDATE/', $reset));
check('reset refuses used, expired and inactive', str_contains($reset, "\$pr['used_at'] !== null")
      && str_contains($reset, "strtotime(\$pr['expires_at']) < time()") && str_contains($reset, "\$pr['status'] !== 'active'"));
check('reset closes every session of the account', str_contains($reset, 'UPDATE user_sessions SET logout_time = NOW() WHERE user_id = ? AND logout_time IS NULL'));
check('reset applies the password policy', str_contains($reset, 'lpc_password_check($new)'));
check('the hint is masked and rate-limited per matricule',
      str_contains($hint, 'lpc_mask_email(') && str_contains($hint, "'pw_hint_acct'") && !preg_match("/'hint'\s*=>\s*\\\$u\['email'\]/", $hint));
check('every action needs a CSRF token', str_contains($code, 'Csrf::requireValid();'));

echo "\n─── the mail goes out with a matching envelope ───\n";
$mail = file_get_contents($root . '/includes/classes/Mail.php');
check('Mail::send passes -f MAIL_FROM to the mail server', (bool) preg_match('/@mail\(\$to,\s*\$subjectEncoded,\s*\$body,\s*\$hdrString,\s*\$envelope\)/', $mail)
      && str_contains($mail, "'-f' . \$from"));

echo $fail ? "\n{$fail} check(s) FAILED.\n" : "\nAll recovery checks passed.\n";
exit($fail ? 1 : 0);
