<?php
/**
 * includes/functions/admin_2fa.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · « Imposer la 2FA aux administrateurs » (Paramètres →
 * Préférences → Sécurité, the owner's decision 24, 18/11/2026).
 *
 * The lightest sound second factor the sign-in allows: after a correct
 * password, an account with the role « Administrateur » or « Directeur
 * Général » receives a 6-digit code by email (10 minutes, 5 tries) and the
 * session opens only once it is typed (public/auth/verify_code.php →
 * api/v1/auth_2fa.php). A browser the person remembered after such a sign-in
 * (« Se souvenir de cet appareil ») is itself the second factor: its PIN or
 * passkey sign-in, and a password sign-in from it, ask no code.
 *
 * Never a lock-out:
 *   · off by default; an installation that sends no email (Mail::configured()
 *     false) cannot deliver a code, so it asks none and logs why;
 *   · « Mot de passe oublié » is untouched (its link goes to the same mailbox);
 *   · JBS Praxis Support, or anyone with the server, turns it off with
 *     `php scripts/admin/admin_2fa_off.php`.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/sign_in.php';
require_once __DIR__ . '/../classes/Mail.php';

const LPC_2FA_ROLES       = ['admin', 'directeur_general'];
const LPC_2FA_TTL         = 600;   // seconds
const LPC_2FA_MAX_TRIES   = 5;

/** Does this successful password sign-in need the emailed code? */
function lpc_2fa_required(PDO $db, array $user): bool
{
    if (!Prefs::bool('sec_require_2fa_admin', false)) return false;
    if (!in_array(strtolower((string) ($user['role_name'] ?? '')), LPC_2FA_ROLES, true)) return false;
    if (!Mail::configured()) {
        error_log('[2fa] required for user ' . (int) $user['id'] . ' but this installation sends no email: not asked');
        return false;
    }
    // A browser remembered after a verified sign-in is the second factor.
    $d = lpc_device_current($db);
    if ($d && (int) $d['user_id'] === (int) $user['id']) return false;
    return true;
}

/**
 * Hold the sign-in, email the code, and send the browser to the code page.
 * Nothing about the session is opened yet: $_SESSION holds only the pending
 * attempt (user, hashed code, expiry, tries).
 */
function lpc_2fa_start(PDO $db, array $user, string $identifier, bool $remember): void
{
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['pending_2fa'] = [
        'user_id'    => (int) $user['id'],
        'identifier' => $identifier,
        'code_hash'  => password_hash($code, PASSWORD_DEFAULT),
        'expires'    => time() + LPC_2FA_TTL,
        'tries'      => 0,
        'remember'   => $remember,
    ];
    $name = trim((string) ($user['emp_first_name'] ?? '')) ?: 'Bonjour';
    $body = "{$name},\n\nVotre code de connexion à Bureau LPC : {$code}\n\n"
          . "Il est valable 10 minutes. Si vous n'êtes pas en train de vous connecter, ignorez ce message "
          . "et changez votre mot de passe.\n\n" . CompanyProfile::displayName();
    if (!Mail::send(trim((string) $user['email']), 'Votre code de connexion Bureau LPC', $body)) {
        error_log('[2fa] code email for user ' . (int) $user['id'] . ' was not handed to the mail server');
    }
    header('Location: /verify_code.php');
    exit;
}

/**
 * Check a typed code against the pending attempt.
 * @return array{ok:bool, user?:array, remember?:bool, identifier?:string, message?:string}
 */
function lpc_2fa_check(PDO $db, string $code): array
{
    $p = $_SESSION['pending_2fa'] ?? null;
    if (!$p) return ['ok' => false, 'message' => 'Aucune connexion en attente : reconnectez-vous.', 'restart' => true];
    if (time() > (int) $p['expires']) {
        unset($_SESSION['pending_2fa']);
        return ['ok' => false, 'message' => 'Le code a expiré : reconnectez-vous pour en recevoir un nouveau.', 'restart' => true];
    }
    if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, (string) $p['code_hash'])) {
        $_SESSION['pending_2fa']['tries'] = (int) $p['tries'] + 1;
        $left = LPC_2FA_MAX_TRIES - $_SESSION['pending_2fa']['tries'];
        $db->prepare("INSERT INTO user_sessions (user_id, login_identifier, ip_address, user_agent, login_status) VALUES (?, ?, ?, ?, 'failed_password')")
           ->execute([(int) $p['user_id'], (string) $p['identifier'], $_SERVER['REMOTE_ADDR'] ?? '', mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
        if ($left <= 0) {
            unset($_SESSION['pending_2fa']);
            return ['ok' => false, 'message' => 'Trop de codes erronés : reconnectez-vous.', 'restart' => true];
        }
        return ['ok' => false, 'message' => 'Code incorrect. ' . $left . ($left > 1 ? ' essais restants.' : ' essai restant.')];
    }
    $user = lpc_signin_user($db, (int) $p['user_id']);
    unset($_SESSION['pending_2fa']);
    if (!$user || $user['status'] !== 'active') return ['ok' => false, 'message' => 'Ce compte est désactivé.', 'restart' => true];
    return ['ok' => true, 'user' => $user, 'remember' => !empty($p['remember']), 'identifier' => (string) $p['identifier']];
}
