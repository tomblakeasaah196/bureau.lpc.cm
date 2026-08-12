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
 * 4. `request_reset` and `reset` are DISABLED behind the constant below.
 *
 * WHY THE RESET ACTIONS ARE DISABLED RATHER THAN DELETED
 * ------------------------------------------------------
 * There is no SMTP on this host. `Mail::send()` detects that (MAIL_FROM empty),
 * writes the message to the PHP error log, and returns TRUE — so the old
 * `request_reset` answered "a reset link has been sent" to every caller and
 * sent nothing, for as long as the feature has existed. The user then waited
 * for an email that was never coming.
 *
 * Deleting the code would mean rewriting it when SMTP arrives. Leaving it
 * callable would mean continuing to lie. So it stays, correct and inert,
 * behind one constant. Turning it back on is: configure MAIL_FROM in .env, set
 * LPC_PASSWORD_RESET_BY_EMAIL_ENABLED to true, restore the "forgot" form on
 * password_manager.php. Nothing else.
 *
 * Until then the recovery path is human: an administrator sets a new password
 * from Paramètres -> Utilisateurs, which kills that user's sessions.
 *
 * Actions:
 *
 *   POST action=change
 *     Body: email, old_password, new_password
 *     Effect: verify old_password, apply the policy, update, kill the user's
 *             OTHER sessions (the current one survives).
 *
 *   POST action=request_reset   -> 503, disabled (see above)
 *   POST action=reset           -> 503, disabled (see above)
 *
 * All actions:
 *   - CSRF required (Csrf::requireValid())
 *   - Rate-limited per IP
 *   - Password policy from includes/functions/password_policy.php
 *
 * Response envelope: { status: 'success'|'error', message: string }
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

/**
 * Master switch for the email-token recovery flow.
 *
 * FALSE until SMTP exists. See the file header. Flipping this to true without
 * a working MAIL_FROM restores the silent-failure bug it was introduced to
 * stop, so check .env first.
 */
const LPC_PASSWORD_RESET_BY_EMAIL_ENABLED = false;

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

        // ==================== REQUEST RESET (disabled) ====================
        // Left intact below the guard so re-enabling is one constant. See the
        // file header for why it cannot be allowed to run without SMTP.
        case 'request_reset': {
            if (!LPC_PASSWORD_RESET_BY_EMAIL_ENABLED) {
                http_response_code(503);
                reply(false, "La réinitialisation par email n'est pas disponible sur cette installation. "
                           . "Demandez à un administrateur de définir un nouveau mot de passe pour vous.");
            }

            RateLimiter::guard('pw_reset_req', $ip, 3, 60);

            // Sprint 14: keyed on the email alone. Requiring code + email was
            // a second factor that only ever inconvenienced the account owner —
            // both values appear on any internal directory listing.
            $email = mb_strtolower(trim($_POST['email'] ?? ''));

            // Always return the same generic message — never leak whether the
            // address matches a user.
            $genericMsg = 'Si les informations correspondent, un lien de réinitialisation a été envoyé à votre adresse email.';

            if ($email === '') reply(true, $genericMsg);

            $stmt = $db->prepare("SELECT id, email, employee_code, status FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1");
            $stmt->execute([$email]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u || $u['status'] !== 'active') {
                error_log("[password] reset requested for unknown/inactive email={$email} ip={$ip}");
                reply(true, $genericMsg);
            }
            $code = (string) ($u['employee_code'] ?? '');

            // Invalidate any pending resets so only the latest link works.
            $db->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
               ->execute([$u['id']]);

            $rawToken   = bin2hex(random_bytes(32));
            $tokenHash  = hash('sha256', $rawToken);
            $expiresAt  = date('Y-m-d H:i:s', time() + 3600);

            $db->prepare("
                INSERT INTO password_resets (user_id, token_hash, ip, user_agent, expires_at)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$u['id'], $tokenHash, $ip, $ua, $expiresAt]);

            $base = rtrim((string) env('APP_URL', 'https://bureau.lpc.cm'), '/');
            $link = $base . '/password_reset.php?token=' . urlencode($rawToken);

            // Sprint 8: the company name and the ERP name in this email were
            // hardcoded ("Ets. La Petite Cour", "Bureau LPC"). They now come
            // from company_profile so a rename propagates to outbound mail too.
            $companyName = CompanyProfile::displayName();
            $erpName     = CompanyProfile::erpName();

            $subject = "Réinitialisation de votre mot de passe — {$erpName}";
            $body    = <<<EMAIL
Bonjour,

Une demande de réinitialisation de mot de passe a été enregistrée pour votre
compte {$erpName} (code employé : {$code}).

Pour définir un nouveau mot de passe, cliquez sur le lien ci-dessous. Ce lien
est valide pendant 1 heure et ne peut être utilisé qu'une seule fois :

{$link}

Si vous n'avez PAS demandé ce lien, ignorez cet email — votre mot de passe
actuel reste inchangé.

Adresse IP à l'origine de la demande : {$ip}

—
{$companyName}
EMAIL;

            // Required here, not at the top of the file: this is the only
            // statement in the controller that needs it, and it is unreachable
            // while LPC_PASSWORD_RESET_BY_EMAIL_ENABLED is false. Requiring it
            // unconditionally would load a class the live code path never uses.
            require_once __DIR__ . '/../../includes/classes/Mail.php';
            Mail::send($u['email'], $subject, $body);
            pw_audit($u['id'], 'Password reset requested from ' . $ip);
            reply(true, $genericMsg);
        }

        // ==================== RESET (disabled) ====================
        // A token can only exist if request_reset issued one, which it no
        // longer does — but any token minted before this deploy is still in
        // password_resets and still inside its hour, so the gate is explicit
        // rather than implied.
        case 'reset': {
            if (!LPC_PASSWORD_RESET_BY_EMAIL_ENABLED) {
                http_response_code(503);
                reply(false, "La réinitialisation par email n'est pas disponible sur cette installation. "
                           . "Demandez à un administrateur de définir un nouveau mot de passe pour vous.");
            }

            RateLimiter::guard('pw_reset', $ip, 5, 60);

            $raw = trim($_POST['token'] ?? '');
            $new = $_POST['new_password'] ?? '';
            if ($raw === '' || $new === '') reply(false, 'Lien invalide ou données manquantes.');
            $policy = lpc_password_check($new);
            if (!$policy['ok'])              reply(false, $policy['message']);

            $tokenHash = hash('sha256', $raw);
            $stmt = $db->prepare("
                SELECT id, user_id, expires_at, used_at
                  FROM password_resets
                 WHERE token_hash = ?
                 LIMIT 1
            ");
            $stmt->execute([$tokenHash]);
            $pr = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pr)                                     reply(false, 'Lien invalide.');
            if ($pr['used_at'] !== null)                  reply(false, 'Lien déjà utilisé. Demandez un nouveau lien.');
            if (strtotime($pr['expires_at']) < time())    reply(false, 'Lien expiré. Demandez un nouveau lien.');

            $newHash = lpc_password_hash($new);

            $db->beginTransaction();
            $db->prepare("
                UPDATE users
                   SET password_hash = ?, must_reset_password = 0, password_changed_at = NOW()
                 WHERE id = ?
            ")->execute([$newHash, $pr['user_id']]);

            $db->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?")
               ->execute([$pr['id']]);

            $db->prepare("UPDATE user_sessions SET logout_time = NOW() WHERE user_id = ? AND logout_time IS NULL")
               ->execute([$pr['user_id']]);
            $db->commit();

            pw_audit($pr['user_id'], 'Password reset via token from ' . $ip);
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
