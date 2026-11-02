<?php
/**
 * scripts/tests/quick_signin.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — quick sign-in on a remembered device (2 Nov 2026): the
 * passkey checks of includes/classes/Passkey.php against keys this test makes
 * itself (it plays the authenticator), the PIN rules, and the wiring that
 * keeps PIN and passkey sign-ins as strict as a password sign-in.
 *
 * The whole flow (remember, set a PIN, sign in with it, lock out after five
 * wrong ones, register and use a passkey, invitations) was also run against
 * MySQL when it was built. CI has no database for this app.
 *
 * Run:  php scripts/tests/quick_signin.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
if (!function_exists('env')) {
    function env(string $k, $default = null) { return $k === 'APP_URL' ? 'https://bureau.lpc.cm' : $default; }
}
require_once $root . '/includes/classes/Passkey.php';
require_once $root . '/includes/functions/sign_in.php';

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}

// An authenticator, played by this test: a key pair, a credential id, a counter.
function make_key(int $alg)
{
    // PHP on Windows cannot make keys without being pointed at its openssl.cnf
    $cfg = [];
    $cnf = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
    if (DIRECTORY_SEPARATOR === '\\' && is_file($cnf)) $cfg['config'] = $cnf;
    return $alg === Passkey::ES256
        ? openssl_pkey_new($cfg + ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'])
        : openssl_pkey_new($cfg + ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
}
function spki_der($key): string
{
    $pem = openssl_pkey_get_details($key)['key'];
    return base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem));
}
function auth_data(string $rpId, int $flags, int $count, string $credId = ''): string
{
    $ad = hash('sha256', $rpId, true) . chr($flags) . pack('N', $count);
    if ($credId !== '') $ad .= str_repeat("\0", 16) . pack('n', strlen($credId)) . $credId;
    return $ad;
}
function client_data(string $type, string $challenge, string $origin): string
{
    return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
}

$rp = Passkey::rpId();
$origin = Passkey::origin();
echo "─── relying party ───\n";
check("rpId is APP_URL's host ({$rp})", $rp === 'bureau.lpc.cm');
check("origin is APP_URL's origin ({$origin})", $origin === 'https://bureau.lpc.cm');

echo "\n─── registration ───\n";
$_SESSION = [];
$challenge = Passkey::issueChallenge('register');
$credId = random_bytes(16);
$key = make_key(Passkey::ES256);
$regAuth = auth_data($rp, 0x45, 0, $credId);    // UP | UV | AT
$ad = Passkey::parseAuthData($regAuth);
check('authenticator data parses, with the credential id', $ad !== null && ($ad['credentialId'] ?? '') === $credId);
check('a good registration passes the client-data check',
      Passkey::clientDataProblem(client_data('webauthn.create', $challenge, $origin), 'webauthn.create', Passkey::takeChallenge('register'), $origin) === null);
check('the challenge is single-use', Passkey::takeChallenge('register') === null);
check('the authenticator data check passes', Passkey::authDataProblem($ad, $rp) === null);
$pem = Passkey::spkiToPem(spki_der($key), Passkey::ES256);
check('an EC public key is accepted for ES256', $pem !== '');
check('the same key is refused as RS256', Passkey::spkiToPem(spki_der($key), Passkey::RS256) === '');
check('garbage is refused as a key', Passkey::spkiToPem('not a key', Passkey::ES256) === '');
check('an unknown algorithm is refused', Passkey::spkiToPem(spki_der($key), -8) === '');

echo "\n─── sign-in ───\n";
$c2 = Passkey::issueChallenge('login');
$cd = client_data('webauthn.get', $c2, $origin);
$authData = auth_data($rp, 0x05, 1);               // UP | UV
openssl_sign($authData . hash('sha256', $cd, true), $sig, $key, OPENSSL_ALGO_SHA256);
check('a genuine assertion verifies', Passkey::signatureValid($pem, $authData, $cd, $sig));
check('the right challenge is accepted', Passkey::clientDataProblem($cd, 'webauthn.get', $c2, $origin) === null);
check('another challenge is refused', Passkey::clientDataProblem($cd, 'webauthn.get', Passkey::b64urlEncode(random_bytes(32)), $origin) !== null);
check('a registration is not accepted as a sign-in', Passkey::clientDataProblem(client_data('webauthn.create', $c2, $origin), 'webauthn.get', $c2, $origin) !== null);
check('another origin is refused', Passkey::clientDataProblem(client_data('webauthn.get', $c2, 'https://evil.example'), 'webauthn.get', $c2, $origin) !== null);
check('another site (rpId) is refused', Passkey::authDataProblem(Passkey::parseAuthData(auth_data('evil.example', 0x05, 1)), $rp) !== null);
check('user verification is required', Passkey::authDataProblem(Passkey::parseAuthData(auth_data($rp, 0x01, 1)), $rp) !== null);
check('user presence is required', Passkey::authDataProblem(Passkey::parseAuthData(auth_data($rp, 0x04, 1)), $rp) !== null);
$tampered = $authData; $tampered[36] = chr(ord($tampered[36]) ^ 1);
check('changed authenticator data fails the signature', !Passkey::signatureValid($pem, $tampered, $cd, $sig));
check('changed client data fails the signature', !Passkey::signatureValid($pem, $authData, str_replace('webauthn.get', 'webauthn.gex', $cd), $sig));
check("another key's signature fails", !Passkey::signatureValid(Passkey::spkiToPem(spki_der(make_key(Passkey::ES256)), Passkey::ES256), $authData, $cd, $sig));
check('an empty signature fails', !Passkey::signatureValid($pem, $authData, $cd, ''));
check('short authenticator data is refused', Passkey::parseAuthData('short') === null);
$rsa = make_key(Passkey::RS256);
openssl_sign($authData . hash('sha256', $cd, true), $rsaSig, $rsa, OPENSSL_ALGO_SHA256);
check('an RS256 key verifies too', Passkey::signatureValid(Passkey::spkiToPem(spki_der($rsa), Passkey::RS256), $authData, $cd, $rsaSig));
check('base64url round-trips', Passkey::b64urlDecode(Passkey::b64urlEncode("\xff\xfe\x00a")) === "\xff\xfe\x00a");
check('base64url refuses other characters', Passkey::b64urlDecode('ab+/') === '');

echo "\n─── PIN rules ───\n";
foreach (['2580' => true, '7391' => true, '1111' => false, '1234' => false, '4321' => false, '123' => false, '12345' => false, 'abcd' => false] as $pin => $ok) {
    check(sprintf('%-5s %s', $pin, $ok ? 'accepted' : 'refused'), (lpc_pin_problem((string) $pin) === null) === $ok);
}
check('device labels are readable', lpc_device_label('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit/605 Version/17.0 Mobile Safari/604.1') === 'Safari · iPhone'
      && lpc_device_label('Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36') === 'Chrome · Windows');

echo "\n─── wiring ───\n";
$strip = static fn (string $f) => preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', file_get_contents($root . '/' . $f));
$auth = $strip('api/v1/auth.php');
$dev  = $strip('api/v1/device_auth.php');
$pw   = $strip('api/v1/password_controller.php');
$set  = $strip('api/v1/settings_controller.php');
check('password sign-in opens the session through lpc_login_complete()', str_contains($auth, 'lpc_login_complete($db, $user'));
check('PIN and passkey sign-ins do too', substr_count($dev, 'lpc_login_complete($db, $user') === 2);
check('every device action needs the CSRF token', str_contains($dev, 'Csrf::requireValid();'));
check('a wrong PIN is counted, and the fifth forgets the device',
      str_contains($dev, 'pin_failures = pin_failures + 1') && str_contains($dev, 'lpc_device_revoke($db') && LPC_PIN_MAX_FAILURES === 5);
check('PIN and passkey sign-ins are rate-limited per IP', str_contains($dev, "RateLimiter::guard('pin_ip'") && str_contains($dev, "RateLimiter::guard('passkey_ip'"));
check('the passkey must belong to the device owner', (bool) preg_match('/da_passkeys\(\$db, \(int\) \$d\[.user_id.\]\)/', $dev));
check('the setup actions need the signed-in owner of the device', str_contains($dev, "(int) \$d['user_id'] !== (int) \$_SESSION['user_id']"));
check('a reset link forgets every remembered device', str_contains($pw, 'lpc_device_revoke_all($db'));
check('an administrator-set password does too', str_contains($set, 'lpc_device_revoke_all($db, $uid)'));
check('only the hash of the device secret is stored', str_contains($strip('includes/functions/sign_in.php'), "hash('sha256', \$secret)"));
check('the device cookie is httpOnly and SameSite', (bool) preg_match("/'httponly'\s*=>\s*true/", $strip('includes/functions/sign_in.php')));
check('every login is inserted through lpc_insert_login() (fills the legacy users name columns)',
      substr_count($set, 'INSERT INTO users') === 1 && substr_count($set, 'lpc_insert_login($db,') === 2);
check('invitations need mail and expire after 72 hours',
      str_contains($set, 'lpc_reset_by_email_enabled()') && str_contains($set, 'time() + 72 * 3600'));
check('the setup page only redirects within the site', str_contains(file_get_contents($root . '/public/auth/device_setup.php'), "preg_match('#^/(?![/\\\\\\\\])"));

echo $fail ? "\n{$fail} check(s) FAILED.\n" : "\nAll quick sign-in checks passed.\n";
exit($fail ? 1 : 0);
