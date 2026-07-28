<?php
/**
 * api/v1/password_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — password management API (Sprint-2 hardened).
 *
 * Actions:
 *
 *   POST action=change
 *     Body: employee_code, old_password, new_password
 *     Effect: password_verify old_password, update to new.
 *
 *   POST action=request_reset
 *     Body: employee_code, email
 *     Effect: if the combination matches a user, INSERT a password_resets row
 *             with a hashed token and email a signed reset link. Response is
 *             ALWAYS a generic success to avoid account enumeration.
 *
 *   POST action=reset
 *     Body: token, new_password
 *     Effect: validate token (hash + not-expired + not-used), update password,
 *             mark token used, clear must_reset_password, kill all other
 *             sessions for the user.
 *
 * All actions:
 *   · CSRF required (Csrf::requireValid())
 *   · Rate-limited (10 change/hour/IP; 3 request_reset/hour/IP; 5 reset/hour/IP)
 *   · Password policy: min 8 chars, ≥1 uppercase, ≥1 digit, ≥1 special
 *
 * Response envelope: { status: 'success'|'error', message: string }
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Mail.php';

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
function pwOk(string $p): bool {
    return (bool) preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*(),.?":{}|<>]).{8,}$/', $p);
}
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

            $code = trim($_POST['employee_code'] ?? '');
            $old  = $_POST['old_password'] ?? '';
            $new  = $_POST['new_password'] ?? '';

            if ($code === '' || $old === '' || $new === '') reply(false, 'Champs manquants.');
            if (!pwOk($new)) reply(false, 'Le nouveau mot de passe ne respecte pas les critères de sécurité.');

            $stmt = $db->prepare("SELECT id, password_hash, status FROM users WHERE employee_code = ?");
            $stmt->execute([$code]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u || $u['status'] !== 'active') reply(false, 'Identifiants incorrects.');
            if (!password_verify($old, $u['password_hash'])) reply(false, 'L\'ancien mot de passe est incorrect.');

            $newHash = password_hash($new, PASSWORD_BCRYPT, ['cost' => (int) env('BCRYPT_COST', 12)]);
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
            RateLimiter::guard('pw_reset_req', $ip, 3, 60);

            $code  = trim($_POST['employee_code'] ?? '');
            $email = trim($_POST['email'] ?? '');

            // Always return the same generic message — never leak whether the
            // pair matches a user.
            $genericMsg = 'Si les informations correspondent, un lien de réinitialisation a été envoyé à votre adresse email.';

            if ($code === '' || $email === '') reply(true, $genericMsg);

            $stmt = $db->prepare("SELECT id, email, status FROM users WHERE employee_code = ?");
            $stmt->execute([$code]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$u || strcasecmp($u['email'], $email) !== 0 || $u['status'] !== 'active') {
                error_log("[password] reset requested for unknown/mismatched code={$code} email={$email} ip={$ip}");
                reply(true, $genericMsg);
            }

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

            Mail::send($u['email'], $subject, $body);
            pw_audit($u['id'], 'Password reset requested from ' . $ip);
            reply(true, $genericMsg);
        }

        // ==================== RESET ====================
        case 'reset': {
            RateLimiter::guard('pw_reset', $ip, 5, 60);

            $raw = trim($_POST['token'] ?? '');
            $new = $_POST['new_password'] ?? '';
            if ($raw === '' || $new === '') reply(false, 'Lien invalide ou données manquantes.');
            if (!pwOk($new))                 reply(false, 'Le mot de passe ne respecte pas les critères de sécurité.');

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

            $newHash = password_hash($new, PASSWORD_BCRYPT, ['cost' => (int) env('BCRYPT_COST', 12)]);

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
