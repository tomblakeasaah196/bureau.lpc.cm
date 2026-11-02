<?php
/**
 * api/v1/device_auth.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — quick sign-in on a remembered device, and its setup
 * (2 Nov 2026). See includes/functions/sign_in.php for the device model and
 * includes/classes/Passkey.php for the passkey checks.
 *
 * On a remembered device (the `lpc_device` cookie):
 *   POST action=pin_login          pin                -> session + redirect
 *   POST action=passkey_options                        -> WebAuthn request options
 *   POST action=passkey_login      id, clientDataJSON, authenticatorData, signature
 *   POST action=forget_device                          -> this browser is forgotten
 *
 * Signed in, on the remembered device being set up:
 *   POST action=set_pin            pin, pin_confirm
 *   POST action=passkey_register_options
 *   POST action=passkey_register   id, clientDataJSON, authenticatorData, publicKey, publicKeyAlgorithm
 *
 * Every action needs the CSRF token. A wrong PIN counts against the device:
 * the fifth forgets it, and the password is needed again. Each quick sign-in
 * opens the session through lpc_login_complete(), like a password sign-in.
 * Response: { status: 'success'|'error', message, ... }
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/sign_in.php';
require_once __DIR__ . '/../../includes/classes/Passkey.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function da_reply(bool $ok, string $msg, array $extra = []): void
{
    echo json_encode(array_merge(['status' => $ok ? 'success' : 'error', 'message' => $msg], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') da_reply(false, 'Méthode invalide.');
Csrf::requireValid();

$ip     = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
$ua     = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN', 0, 255);
$action = (string) ($_POST['action'] ?? '');

function da_audit(PDO $db, int $userId, string $note): void
{
    try {
        $db->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value) VALUES (?, 'UPDATE', 'users', ?, ?)")
           ->execute([$userId, $userId, $note]);
    } catch (Throwable $e) { error_log('device_auth audit: ' . $e->getMessage()); }
}

/** The signed-in person's remembered device in this browser, for the setup actions. */
function da_own_device(PDO $db): array
{
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        da_reply(false, 'Connectez-vous d\'abord.', ['code' => 'unauthenticated']);
    }
    $d = lpc_device_current($db);
    if (!$d || (int) $d['user_id'] !== (int) $_SESSION['user_id']) {
        da_reply(false, "Cet appareil n'est pas mémorisé pour votre compte. Cochez « Se souvenir de cet appareil » à la connexion.");
    }
    return $d;
}

function da_passkeys(PDO $db, int $userId): array
{
    $s = $db->prepare("SELECT id, credential_id, public_key_pem, algorithm, sign_count FROM user_passkeys WHERE user_id = ? AND revoked_at IS NULL");
    $s->execute([$userId]);
    return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$db = Database::getInstance()->getConnection();

try {
    switch ($action) {

        // ==================== PIN SIGN-IN ====================
        case 'pin_login': {
            RateLimiter::guard('pin_ip', $ip, 30, 15);
            $d = lpc_device_current($db);
            if (!$d) da_reply(false, 'Appareil non reconnu. Connectez-vous avec votre mot de passe.', ['code' => 'no_device']);
            if (empty($d['pin_hash'])) da_reply(false, "Aucun code n'est défini sur cet appareil.", ['code' => 'no_pin']);

            $pin = (string) ($_POST['pin'] ?? '');
            if (!password_verify($pin, (string) $d['pin_hash'])) {
                $db->prepare("UPDATE trusted_devices SET pin_failures = pin_failures + 1 WHERE id = ?")->execute([$d['id']]);
                $db->prepare("INSERT INTO user_sessions (user_id, login_identifier, ip_address, user_agent, login_status) VALUES (?, ?, ?, ?, 'failed_password')")
                   ->execute([$d['user_id'], $d['email'], $ip, $ua]);
                $left = LPC_PIN_MAX_FAILURES - ((int) $d['pin_failures'] + 1);
                if ($left <= 0) {
                    lpc_device_revoke($db, (int) $d['id']);
                    lpc_device_clear_cookie();
                    da_audit($db, (int) $d['user_id'], "Remembered device #{$d['id']} forgotten after " . LPC_PIN_MAX_FAILURES . " wrong PINs, from {$ip}");
                    da_reply(false, 'Trop de codes erronés : cet appareil a été oublié. Connectez-vous avec votre mot de passe.', ['code' => 'device_forgotten']);
                }
                da_reply(false, 'Code incorrect. ' . $left . ($left > 1 ? ' essais restants.' : ' essai restant.'), ['code' => 'wrong_pin']);
            }

            $user = lpc_signin_user($db, (int) $d['user_id']);
            if (!$user || $user['status'] !== 'active') da_reply(false, 'Ce compte est désactivé. Contactez votre administrateur.');
            $next = lpc_login_complete($db, $user, (string) $user['email'], $ip, $ua);
            lpc_device_renew($db, (int) $d['id']);
            da_audit($db, (int) $user['id'], "Signed in with the PIN of remembered device #{$d['id']} from {$ip}");
            da_reply(true, 'Bienvenue.', ['redirect' => $next]);
        }

        // ==================== PASSKEY SIGN-IN ====================
        case 'passkey_options': {
            $d = lpc_device_current($db);
            if (!$d) da_reply(false, 'Appareil non reconnu.', ['code' => 'no_device']);
            $keys = da_passkeys($db, (int) $d['user_id']);
            if (!$keys) da_reply(false, "Aucune clé d'accès pour ce compte.", ['code' => 'no_passkey']);
            da_reply(true, 'ok', ['publicKey' => [
                'challenge'        => Passkey::issueChallenge('login'),
                'rpId'             => Passkey::rpId(),
                'timeout'          => 60000,
                'userVerification' => 'required',
                'allowCredentials' => array_map(static fn ($k) => ['type' => 'public-key', 'id' => $k['credential_id']], $keys),
            ]]);
        }

        case 'passkey_login': {
            RateLimiter::guard('passkey_ip', $ip, 30, 15);
            $d = lpc_device_current($db);
            if (!$d) da_reply(false, 'Appareil non reconnu.', ['code' => 'no_device']);
            $challenge = Passkey::takeChallenge('login');
            if ($challenge === null) da_reply(false, 'La demande a expiré. Réessayez.');

            $credId   = (string) ($_POST['id'] ?? '');
            $client   = Passkey::b64urlDecode((string) ($_POST['clientDataJSON'] ?? ''));
            $authData = Passkey::b64urlDecode((string) ($_POST['authenticatorData'] ?? ''));
            $sig      = Passkey::b64urlDecode((string) ($_POST['signature'] ?? ''));

            $key = null;
            foreach (da_passkeys($db, (int) $d['user_id']) as $k) {
                if (hash_equals($k['credential_id'], $credId)) { $key = $k; break; }
            }
            $ad = Passkey::parseAuthData($authData);
            $problem = $key === null ? 'clé inconnue pour ce compte'
                     : (Passkey::clientDataProblem($client, 'webauthn.get', $challenge, Passkey::origin())
                        ?? Passkey::authDataProblem($ad, Passkey::rpId())
                        ?? (Passkey::signatureValid($key['public_key_pem'], $authData, $client, $sig) ? null : 'signature invalide'));
            // A counter that does not move forward means a copied key; authenticators
            // that do not count (most passkeys) report 0 every time.
            if ($problem === null && $ad['signCount'] > 0 && (int) $key['sign_count'] > 0 && $ad['signCount'] <= (int) $key['sign_count']) {
                $problem = 'compteur de la clé incohérent';
            }
            if ($problem !== null) {
                $db->prepare("INSERT INTO user_sessions (user_id, login_identifier, ip_address, user_agent, login_status) VALUES (?, ?, ?, ?, 'failed_password')")
                   ->execute([$d['user_id'], $d['email'], $ip, $ua]);
                error_log("[device_auth] passkey refused for user {$d['user_id']}: {$problem}");
                da_reply(false, "La clé d'accès n'a pas été acceptée ({$problem}).");
            }

            $user = lpc_signin_user($db, (int) $d['user_id']);
            if (!$user || $user['status'] !== 'active') da_reply(false, 'Ce compte est désactivé. Contactez votre administrateur.');
            $db->prepare("UPDATE user_passkeys SET sign_count = ?, last_used_at = NOW() WHERE id = ?")->execute([$ad['signCount'], $key['id']]);
            $next = lpc_login_complete($db, $user, (string) $user['email'], $ip, $ua);
            lpc_device_renew($db, (int) $d['id']);
            da_audit($db, (int) $user['id'], "Signed in with passkey #{$key['id']} on remembered device #{$d['id']} from {$ip}");
            da_reply(true, 'Bienvenue.', ['redirect' => $next]);
        }

        case 'forget_device': {
            $d = lpc_device_current($db);
            if ($d) {
                lpc_device_revoke($db, (int) $d['id']);
                da_audit($db, (int) $d['user_id'], "Remembered device #{$d['id']} forgotten from the login page, {$ip}");
            }
            lpc_device_clear_cookie();
            da_reply(true, 'Cet appareil a été oublié.');
        }

        // ==================== SETUP (signed in) ====================
        // For someone who did not tick "Se souvenir" at sign-in: the session
        // already proves who they are, so this browser can be remembered now.
        case 'remember_here': {
            if (empty($_SESSION['user_id'])) {
                http_response_code(401);
                da_reply(false, 'Connectez-vous d\'abord.', ['code' => 'unauthenticated']);
            }
            $cur = lpc_device_current($db);
            if (!$cur || (int) $cur['user_id'] !== (int) $_SESSION['user_id']) {
                if ($cur) lpc_device_revoke($db, (int) $cur['id']);
                lpc_device_remember($db, (int) $_SESSION['user_id'], $ip, $ua);
                da_audit($db, (int) $_SESSION['user_id'], 'Device remembered from the setup page (' . lpc_device_label($ua) . ')');
            }
            da_reply(true, 'Cet appareil est mémorisé pour ' . LPC_DEVICE_DAYS . ' jours.');
        }

        case 'set_pin': {
            $d   = da_own_device($db);
            $pin = (string) ($_POST['pin'] ?? '');
            if ($pin !== (string) ($_POST['pin_confirm'] ?? '')) da_reply(false, 'Les deux codes ne correspondent pas.');
            $problem = lpc_pin_problem($pin);
            if ($problem !== null) da_reply(false, $problem);
            $db->prepare("UPDATE trusted_devices SET pin_hash = ?, pin_failures = 0 WHERE id = ?")
               ->execute([password_hash($pin, PASSWORD_DEFAULT), $d['id']]);
            da_audit($db, (int) $d['user_id'], "PIN set on remembered device #{$d['id']}");
            da_reply(true, 'Code enregistré pour cet appareil.');
        }

        case 'passkey_register_options': {
            $d    = da_own_device($db);
            $user = lpc_signin_user($db, (int) $d['user_id']);
            da_reply(true, 'ok', ['publicKey' => [
                'challenge' => Passkey::issueChallenge('register'),
                'rp'        => ['id' => Passkey::rpId(), 'name' => CompanyProfile::erpName()],
                'user'      => [
                    'id'          => Passkey::b64urlEncode('lpc-user-' . $user['id']),
                    'name'        => (string) $user['email'],
                    'displayName' => trim(($user['emp_first_name'] ?? '') . ' ' . ($user['emp_last_name'] ?? '')) ?: (string) $user['email'],
                ],
                'pubKeyCredParams'       => [['type' => 'public-key', 'alg' => Passkey::ES256], ['type' => 'public-key', 'alg' => Passkey::RS256]],
                'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'required'],
                'attestation'            => 'none',
                'timeout'                => 60000,
                'excludeCredentials'     => array_map(static fn ($k) => ['type' => 'public-key', 'id' => $k['credential_id']], da_passkeys($db, (int) $user['id'])),
            ]]);
        }

        case 'passkey_register': {
            $d = da_own_device($db);
            $challenge = Passkey::takeChallenge('register');
            if ($challenge === null) da_reply(false, 'La demande a expiré. Réessayez.');

            $credId   = (string) ($_POST['id'] ?? '');
            $client   = Passkey::b64urlDecode((string) ($_POST['clientDataJSON'] ?? ''));
            $authData = Passkey::b64urlDecode((string) ($_POST['authenticatorData'] ?? ''));
            $alg      = (int) ($_POST['publicKeyAlgorithm'] ?? 0);
            $pem      = Passkey::spkiToPem(Passkey::b64urlDecode((string) ($_POST['publicKey'] ?? '')), $alg);
            $ad       = Passkey::parseAuthData($authData);

            $problem = Passkey::clientDataProblem($client, 'webauthn.create', $challenge, Passkey::origin())
                    ?? Passkey::authDataProblem($ad, Passkey::rpId())
                    ?? (!isset($ad['credentialId']) || !hash_equals(Passkey::b64urlEncode($ad['credentialId']), $credId) ? 'identifiant de clé incohérent' : null)
                    ?? ($pem === '' ? 'clé publique illisible ou algorithme non pris en charge' : null);
            if ($problem !== null) da_reply(false, "La clé d'accès n'a pas pu être enregistrée ({$problem}).");

            $db->prepare("
                INSERT INTO user_passkeys (user_id, credential_id, public_key_pem, algorithm, sign_count, label)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$d['user_id'], $credId, $pem, $alg, $ad['signCount'], lpc_device_label($ua)]);
            da_audit($db, (int) $d['user_id'], 'Passkey added (' . lpc_device_label($ua) . ')');
            da_reply(true, "Clé d'accès enregistrée.");
        }

        default:
            da_reply(false, 'Action inconnue.');
    }
} catch (Throwable $e) {
    error_log('device_auth: ' . $e->getMessage());
    da_reply(false, 'Erreur serveur. Veuillez réessayer.');
}
