<?php
/**
 * api/v1/password_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — password management API.
 *
 * SPRINT 14 — WHAT CHANGED AND WHY
 * --------------------------------
 * 1. `change` is keyed on the EMAIL ADDRESS, not the employee code.
 * 2. The password rules come from lpc_password_check() (one definition, shared
 *    with the browser checklist and with both onboarding forms) instead of the
 *    local regex that hardcoded a minimum of 8 while the admin-facing
 *    preference said 10.
 * 3. Reusing the current password as the new one is refused.
 *
 * SELF-SERVICE RECOVERY (2 Nov 2026)
 * ----------------------------------
 * Sprint 14 switched the email reset off because `Mail::send()` logs and
 * returns TRUE when MAIL_FROM is empty: `request_reset` answered "a link has
 * been sent" and sent nothing. The flow is now on exactly when mail really
 * leaves the host (lpc_reset_by_email_enabled() = Mail::configured()), so the
 * same .env line that makes email work makes the reset work, and an install
 * without it still refuses honestly and points to an administrator.
 *
 * Also new: `email_hint`, for someone who forgot which address they sign in
 * with. The matricule returns the address masked, and `request_reset` accepts
 * the matricule too, so the link reaches that mailbox without the page ever
 * showing the address in full.
 *
 * Actions:
 *
 *   POST action=change
 *     Body: email, old_password, new_password
 *     Effect: verify old_password, apply the policy, update, kill the user's
 *             OTHER sessions (the current one survives).
 *
 *   POST action=request_reset
 *     Body: email OR matricule
 *     Effect: emails a single-use link valid one hour; earlier links die. The
 *             answer is the same whether or not the account exists.
 *
 *   POST action=email_hint
 *     Body: matricule
 *     Effect: the active account's address, masked (lpc_mask_email()).
 *
 *   POST action=reset
 *     Body: token, new_password
 *     Effect: apply the policy, update, consume the link, kill every session.
 *
 * All actions:
 *   - CSRF required (Csrf::requireValid())
 *   - Rate-limited per IP; the recovery actions per account too
 *   - Password policy from includes/functions/password_policy.php
 *
 * Response envelope: { status: 'success'|'error', message: string }
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/account_recovery.php';
require_once __DIR__ . '/../../includes/functions/sign_in.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Méthode invalide.']); exit;
}

// CSRF is required on every state-changing request in this controller.
Csrf::requireValid();

$ip = $_SERVER['REMOTE_ADDR']     ?? 'UNKNOWN';
$ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

$action = $_POST['action'] ?? '';

// -----------------------------------------------------------------------------
// NOTE: a local pwOk() used to live here. It hardcoded a minimum of 8 while
// the admin-facing `sec_password_min_length` preference said 10 and was read
// by nobody, and its special-character class excluded the hyphen and every
// accented character — so "Café-du-Marché-2026" was refused with a message
// that did not say which characters would count. The rule now lives in
// includes/functions/password_policy.php, shared with the browser checklist
// and with both onboarding forms.
function reply(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['status' => $ok ? 'success' : 'error', 'message' => $msg], $extra));
    exit;
}

$db = Database::getInstance()->getConnection();

try {
    switch ($action) {

        // ==================== CHANGE ====================
        case 'change': {
            RateLimiter::guard('pw_change', $ip, 10, 60);

            $email = mb_strtolower(trim($_POST['email'] ?? $_POST['employee_code'] ?? ''));
            $old   = $_POST['old_password'] ?? '';
            $new   = $_POST['new_password'] ?? '';

            if ($email === '' || $old === '' || $new === '') reply(false, 'Champs manquants.');

            // You must be signed in, and you may only change your OWN password.
            //
            // Both halves matter, and an earlier version of this guard only had
            // the second. It read
            //
            //     if (!empty($_SESSION['user_id']) && ... != $email) reject;
            //
            // which is a no-op for a caller with no session at all: an
            // anonymous request that knew (email, current password) sailed past
            // it and changed the password. That is not merely "knowing the
            // password is already enough" — it hands an attacker who has seen a
            // credential the ability to *lock the real owner out* remotely,
            // and with SMTP disabled the owner then needs an administrator to
            // get back in. Verified by direct HTTP probe, not by reading.
            //
            // Admin-initiated changes for OTHER people are a separate,
            // permission-gated path in settings_controller.php, audited as an
            // admin action against the admin's own identity.
            if (empty($_SESSION['user_id']) || empty($_SESSION['user_email'])) {
                http_response_code(401);
                reply(false, 'Vous devez etre connecte pour changer votre mot de passe.',
                      ['code' => 'unauthenticated']);
            }
            if (mb_strtolower((string) $_SESSION['user_email']) !== $email) {
                reply(false, 'Vous ne pouvez modifier que votre propre mot de passe.');
            }

            $policy = lpc_password_check($new);
            if (!$policy['ok']) reply(false, $policy['message']);

            // Rejected here as well as in the browser: the JS check is a
            // courtesy and is trivially bypassed. A "change" that changes
            // nothing would still bump password_changed_at and kill the user's
            // other sessions, which looks exactly like a successful rotation
            // in the audit log while leaving the old credential live.
            if (hash_equals($old, $new)) {
                reply(false, 'Le nouveau mot de passe doit être différent de l\'ancien.');
            }

            $stmt = $db->prepare("
                SELECT id, password_hash, status
                  FROM users
                 WHERE LOWER(TRIM(email)) = ?
                 LIMIT 1
            ");
            $stmt->execute([$email]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);

            // One message for "no such address", "locked account" and "wrong
            // password" — the caller is not necessarily the account owner, so
            // distinguishing them would confirm which addresses exist.
            // ONE message for every failure mode: no such address, locked
            // account, or wrong current password. Distinct wording here let a
            // caller enumerate which addresses are real accounts by reading
            // which error came back. The session check above already pins this
            // to the account owner, so a genuine user never sees the ambiguity
            // in practice — they are told their current password is wrong,
            // which is the only case they can actually reach.
            $bad = 'Identifiants incorrects.';
            if (!$u || $u['status'] !== 'active') reply(false, $bad);
            if (!password_verify($old, (string) ($u['password_hash'] ?? ''))) {
                reply(false, $bad);
            }

            $newHash = lpc_password_hash($new);
            $db->prepare("
                UPDATE users
                   SET password_hash = ?, must_reset_password = 0, password_changed_at = NOW()
                 WHERE id = ?
            ")->execute([$newHash, $u['id']]);

            // Invalidate all OTHER sessions (keep current one alive).
            $currentHash = !empty($_SESSION['session_token']) ? hash('sha256', $_SESSION['session_token']) : '';
            $db->prepare("
                UPDATE user_sessions
                   SET logout_time = NOW()
                 WHERE user_id = ?
                   AND logout_time IS NULL
                   AND session_token_hash <> ?
            ")->execute([$u['id'], $currentHash]);

            unset($_SESSION['force_reset']);
            pw_audit($u['id'], 'Password changed');
            reply(true, 'Mot de passe mis à jour avec succès.');
        }

        // ==================== REQUEST RESET ====================
        case 'request_reset': {
            pw_require_reset_enabled();
            // Per IP it is a wide backstop: the whole office shares one address
            // (see auth.php). The real limit is per identifier, below.
            RateLimiter::guard('pw_reset_req', $ip, 20, 60);

            // The address, or the matricule for someone who forgot the address
            // (email_hint showed it masked). Sprint 14: no second factor beyond
            // that — both values appear on any internal directory listing.
            $email     = mb_strtolower(trim($_POST['email'] ?? ''));
            $matricule = trim($_POST['matricule'] ?? '');
            if ($email === '' && $matricule === '') {
                reply(false, 'Indiquez votre adresse email ou votre matricule.');
            }

            // Three links an hour per identifier, keyed on what was TYPED so a
            // known and an unknown account hit the limit alike (a limiter keyed
            // on the account would confirm which ones exist). Without it the
            // public page could fill a colleague's inbox.
            $typed = $email !== '' ? $email : 'mat:' . mb_strtolower($matricule);
            RateLimiter::guard('pw_reset_acct', 'acct:' . substr(hash('sha256', $typed), 0, 40), 3, 60);

            // The same answer whether or not an account matched.
            $genericMsg = "Si un compte actif correspond, un lien de réinitialisation vient d'être envoyé "
                        . "à son adresse email. Il est valable 1 heure.";

            $u = $email !== '' ? pw_user_by_email($db, $email) : pw_user_by_matricule($db, $matricule);
            if (!$u || $u['status'] !== 'active' || trim((string) $u['email']) === '') {
                error_log('[password] reset requested for no active account (' . ($email !== '' ? 'email' : 'matricule') . ") ip={$ip}");
                reply(true, $genericMsg);
            }

            // Only the newest link works.
            $db->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
               ->execute([$u['id']]);

            $rawToken  = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 3600);
            $db->prepare("
                INSERT INTO password_resets (user_id, token_hash, ip, user_agent, expires_at)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$u['id'], hash('sha256', $rawToken), $ip, $ua, $expiresAt]);

            // The short /reset/{token} route is in .htaccess
            $link = rtrim((string) env('APP_URL', 'https://bureau.lpc.cm'), '/') . '/reset/' . $rawToken;

            // Sprint 8: company and ERP names come from company_profile so a
            // rename propagates to outbound mail too.
            $companyName = CompanyProfile::displayName();
            $erpName     = CompanyProfile::erpName();
            $hello       = trim((string) ($u['first_name'] ?? '')) !== '' ? 'Bonjour ' . trim($u['first_name']) . ',' : 'Bonjour,';
            $code        = (string) ($u['employee_code'] ?? '');

            $subject = "Réinitialisation de votre mot de passe — {$erpName}";
            $body    = <<<EMAIL
{$hello}

Une demande de réinitialisation de mot de passe a été enregistrée pour votre
compte {$erpName} (matricule : {$code}).

Pour définir un nouveau mot de passe, ouvrez le lien ci-dessous. Il est valable
1 heure et ne peut servir qu'une seule fois :

{$link}

Si vous n'avez PAS demandé ce lien, ignorez cet email : votre mot de passe
actuel reste inchangé.

Adresse IP à l'origine de la demande : {$ip}

—
{$companyName}
EMAIL;

            if (!Mail::send(trim($u['email']), $subject, $body)) {
                // Logged, not shown: telling the caller would confirm the account
                error_log("[password] reset email for user {$u['id']} was not handed to the mail server");
            }
            pw_audit($u['id'], 'Password reset link sent, requested from ' . $ip);
            reply(true, $genericMsg);
        }

        // ==================== EMAIL HINT ====================
        // For someone who forgot which address they sign in with. The owner
        // chose this over "ask an administrator" (2 Nov 2026), knowing that it
        // tells whoever holds a matricule part of an address: so it shows only
        // a masked form, and it is rate-limited per IP and per matricule.
        case 'email_hint': {
            RateLimiter::guard('pw_hint', $ip, 10, 60);
            $matricule = trim($_POST['matricule'] ?? '');
            if ($matricule === '') reply(false, 'Indiquez votre matricule.');
            RateLimiter::guard('pw_hint_acct', 'mat:' . substr(hash('sha256', mb_strtolower($matricule)), 0, 40), 5, 60);

            $u = pw_user_by_matricule($db, $matricule);
            if (!$u || $u['status'] !== 'active' || trim((string) $u['email']) === '') {
                reply(false, 'Aucun compte actif ne correspond à ce matricule. Vérifiez-le sur votre bulletin '
                           . 'de paie, ou demandez à un administrateur.');
            }
            pw_audit($u['id'], 'Sign-in address hint shown, requested from ' . $ip);
            reply(true, 'Votre adresse de connexion :', [
                'hint'        => lpc_mask_email(mb_strtolower(trim($u['email']))),
                'can_send'    => lpc_reset_by_email_enabled(),
            ]);
        }

        // ==================== RESET ====================
        case 'reset': {
            pw_require_reset_enabled();
            RateLimiter::guard('pw_reset', $ip, 10, 60);

            $raw = trim($_POST['token'] ?? '');
            $new = $_POST['new_password'] ?? '';
            if (!preg_match('/^[a-f0-9]{64}$/', $raw) || $new === '') reply(false, 'Lien invalide ou données manquantes.');
            $policy = lpc_password_check($new);
            if (!$policy['ok']) reply(false, $policy['message']);

            // The row is locked until the commit, so the same link opened in two
            // tabs cannot reset the password twice: the second waits, then sees
            // used_at set.
            $db->beginTransaction();
            $stmt = $db->prepare("
                SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at, u.status
                  FROM password_resets pr
                  JOIN users u ON u.id = pr.user_id
                 WHERE pr.token_hash = ?
                 LIMIT 1
                   FOR UPDATE
            ");
            $stmt->execute([hash('sha256', $raw)]);
            $pr = $stmt->fetch(PDO::FETCH_ASSOC);

            $refuse = null;
            if (!$pr)                                       $refuse = 'Lien invalide. Demandez un nouveau lien.';
            elseif ($pr['used_at'] !== null)                $refuse = 'Lien déjà utilisé. Demandez un nouveau lien.';
            elseif (strtotime($pr['expires_at']) < time())  $refuse = 'Lien expiré. Demandez un nouveau lien.';
            elseif ($pr['status'] !== 'active')             $refuse = 'Ce compte est désactivé. Contactez votre administrateur.';
            if ($refuse !== null) {
                $db->rollBack();
                reply(false, $refuse);
            }

            $db->prepare("
                UPDATE users
                   SET password_hash = ?, must_reset_password = 0, password_changed_at = NOW()
                 WHERE id = ?
            ")->execute([lpc_password_hash($new), $pr['user_id']]);
            $db->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?")
               ->execute([$pr['id']]);
            // Every session: whoever knew the old password is signed out too
            $db->prepare("UPDATE user_sessions SET logout_time = NOW() WHERE user_id = ? AND logout_time IS NULL")
               ->execute([$pr['user_id']]);
            $db->commit();
            // ...and every remembered device (outside the transaction: it must
            // not undo the reset on an install where 123 has not run yet)
            try { lpc_device_revoke_all($db, (int) $pr['user_id']); } catch (Throwable $e) { error_log('reset: ' . $e->getMessage()); }

            pw_audit($pr['user_id'], 'Password reset via emailed link from ' . $ip);
            reply(true, 'Mot de passe mis à jour. Vous pouvez maintenant vous connecter.');
        }

        default:
            reply(false, 'Action inconnue.');
    }

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('password_controller: ' . $e->getMessage());
    reply(false, 'Erreur serveur. Veuillez réessayer.');
}

// -----------------------------------------------------------------------------
function pw_audit(int $userId, string $note): void {
    try {
        $db = Database::getInstance()->getConnection();
        $db->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value)
            VALUES (?, 'UPDATE', 'users', ?, ?)
        ")->execute([$userId, $userId, $note]);
    } catch (Throwable $e) { error_log('pw audit: ' . $e->getMessage()); }
}

/** Refuse the email-link actions honestly when mail does not leave this host. */
function pw_require_reset_enabled(): void {
    if (lpc_reset_by_email_enabled()) return;
    http_response_code(503);
    reply(false, "La réinitialisation par email n'est pas disponible sur cette installation. "
               . "Demandez à un administrateur de définir un nouveau mot de passe pour vous.");
}

// The account behind an address or a matricule. Name and matricule come from
// `employees` (Sprint 15: the single source), joined through users.employee_id.
function pw_user_by_email(PDO $db, string $email): ?array {
    $s = $db->prepare("
        SELECT u.id, u.email, u.status, e.employee_code, e.first_name
          FROM users u
     LEFT JOIN employees e ON e.id = u.employee_id
         WHERE LOWER(TRIM(u.email)) = ?
         LIMIT 1
    ");
    $s->execute([$email]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pw_user_by_matricule(PDO $db, string $matricule): ?array {
    $s = $db->prepare("
        SELECT u.id, u.email, u.status, e.employee_code, e.first_name
          FROM employees e
          JOIN users u ON u.employee_id = e.id
         WHERE UPPER(TRIM(e.employee_code)) = UPPER(?)
         LIMIT 1
    ");
    $s->execute([trim($matricule)]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
