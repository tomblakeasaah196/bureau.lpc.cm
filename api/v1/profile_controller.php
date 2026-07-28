<?php
/**
 * api/v1/profile_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 9 · self-service profile, preferences and security.
 *
 * SCOPE — read this before adding an action.
 *   Every action here operates on $_SESSION['user_id'] and ONLY on
 *   $_SESSION['user_id']. There is no `user_id` parameter anywhere in this
 *   file, by design: editing somebody else's profile is an HR operation and
 *   belongs in mdm_controller.php behind `admin.employees.edit`. If you find
 *   yourself wanting to pass a target user here, you want the other file.
 *
 * ACTIONS (all POST, all CSRF-protected)
 *   get                  → current profile + security overview
 *   save_identity        → display_name, pronouns, about, phone
 *   save_prefs           → locale, timezone, theme, accent, density,
 *                          sidebar_collapsed, reduce_motion, landing_page
 *   save_avatar          → multipart; $_FILES['avatar']
 *   remove_avatar        → clears the avatar, unlinks the old file
 *   change_password      → old + new; reuses the policy from password_controller
 *   set_pin              → password-confirmed; stores bcrypt(pin)
 *   clear_pin            → removes the quick-login PIN
 *   sessions             → the user's own active sessions
 *   revoke_sessions      → ends every session except the current one
 *
 * Response envelope: { status: 'success'|'error', message: string, ... }
 * — identical to password_controller.php, so the front-end has one shape to
 *   handle across both modals.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/UserProfile.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// -----------------------------------------------------------------------------
// Envelope helpers. Defined before the auth guards so the guards can use them.
// -----------------------------------------------------------------------------
function pReply(bool $ok, string $msg, array $extra = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(
        array_merge(['status' => $ok ? 'success' : 'error', 'message' => $msg], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function pFail(string $msg, int $code = 400, array $extra = []): void
{
    pReply(false, $msg, $extra, $code);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pFail('Méthode invalide.', 405);
}

// Authenticated session required. Rbac::requireAuth() redirects, which is wrong
// for an XHR — the caller would get an HTML login page inside a JSON parse.
if (empty($_SESSION['user_id'])) {
    pFail('Session expirée.', 401, ['code' => 'session_expired']);
}

Csrf::requireValid();

$userId = (int) $_SESSION['user_id'];
$ip     = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
$ua     = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
$action = $_POST['action'] ?? '';
$db     = Database::getInstance()->getConnection();

/**
 * Password policy. Duplicated from password_controller.php::pwOk deliberately
 * — that function is not includable (the file executes on require) and a
 * shared class for one regex would be worse. If the policy changes, both
 * places change; the test suite asserts they agree.
 */
function profPwOk(string $p): bool
{
    return (bool) preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*(),.?":{}|<>]).{8,}$/', $p);
}

/** Structured audit row. Mirrors pw_audit() in password_controller.php. */
function profAudit(PDO $db, int $userId, string $note, array $meta = []): void
{
    try {
        $db->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, new_value)
            VALUES (?, ?, 'employee_profiles', ?, ?)
        ")->execute([
            $userId, $note, $userId,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        error_log('profAudit: ' . $e->getMessage());
    }
}

/** Every successful mutation returns the fresh profile, so the UI never has to
 *  guess what the server decided — it re-renders from the response. */
function profOk(string $msg, array $extra = []): void
{
    pReply(true, $msg, array_merge(['profile' => UserProfile::jsPayload()], $extra));
}

try {
    switch ($action) {

    // =========================================================================
    case 'get': {
        pReply(true, 'OK', [
            'profile'  => UserProfile::jsPayload(),
            'security' => prof_security_overview($db, $userId),
            'landing'  => prof_landing_options(),
            'zones'    => prof_timezone_options(),
        ]);
    }

    // =========================================================================
    case 'save_identity': {
        RateLimiter::guard('profile_save', $ip, 40, 15);

        $fresh = UserProfile::save([
            'display_name' => $_POST['display_name'] ?? '',
            'pronouns'     => $_POST['pronouns']     ?? '',
            'about'        => $_POST['about']        ?? '',
            'phone'        => $_POST['phone']        ?? '',
        ]);
        profAudit($db, $userId, 'Profile identity updated', [
            'display_name' => $fresh['displayName'],
        ]);
        profOk('Profil mis à jour.');
    }

    // =========================================================================
    case 'save_prefs': {
        RateLimiter::guard('profile_save', $ip, 60, 15);

        // Only forward keys the client actually sent. Sending the whole set
        // every time would let a stale modal (opened before another tab
        // changed the theme) silently revert the other tab's change.
        $map = ['locale','timezone','theme','accent','density',
                'sidebar_collapsed','reduce_motion','landing_page'];
        $fields = [];
        foreach ($map as $k) {
            if (array_key_exists($k, $_POST)) $fields[$k] = $_POST[$k];
        }
        if (!$fields) pFail('Aucune préférence à enregistrer.');

        UserProfile::save($fields);
        profAudit($db, $userId, 'Preferences updated', $fields);
        profOk('Préférences enregistrées.');
    }

    // =========================================================================
    case 'save_avatar': {
        RateLimiter::guard('profile_avatar', $ip, 10, 15);
        require_once __DIR__ . '/../../includes/classes/Uploads.php';

        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            pFail('Aucune image reçue.');
        }

        $previous = UserProfile::current()['avatar'];

        try {
            // sanitize_img re-encodes through GD: strips EXIF (including GPS
            // coordinates from a phone photo) and any polyglot payload hidden
            // after the image data. Same options as mdm_controller.php:230,
            // with a tighter cap because the client already compresses.
            $up = Uploads::saveUploaded('avatar', 'avatars', [
                'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp'],
                'max_bytes'    => 2 * 1024 * 1024,
                'sanitize_img' => true,
            ]);
        } catch (Throwable $e) {
            pFail('Image refusée : ' . $e->getMessage());
        }

        UserProfile::save(['avatar' => $up['path']]);
        prof_unlink_avatar($previous);
        profAudit($db, $userId, 'Avatar updated');
        profOk('Photo de profil mise à jour.');
    }

    // =========================================================================
    case 'remove_avatar': {
        $previous = UserProfile::current()['avatar'];
        UserProfile::save(['avatar' => null]);
        prof_unlink_avatar($previous);
        profAudit($db, $userId, 'Avatar removed');
        profOk('Photo de profil supprimée.');
    }

    // =========================================================================
    case 'change_password': {
        RateLimiter::guard('pw_change', $ip, 10, 60);

        $old = (string) ($_POST['old_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $rpt = (string) ($_POST['confirm_password'] ?? '');

        if ($old === '' || $new === '') pFail('Champs manquants.');
        if ($rpt !== '' && $rpt !== $new) pFail('Les deux nouveaux mots de passe ne correspondent pas.');
        if ($new === $old) pFail('Le nouveau mot de passe doit être différent de l\'ancien.');
        if (!profPwOk($new)) {
            pFail('8 caractères minimum, avec au moins une majuscule, un chiffre et un caractère spécial.');
        }

        $st = $db->prepare("SELECT password_hash, status FROM users WHERE id = ?");
        $st->execute([$userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u || $u['status'] !== 'active') pFail('Compte indisponible.', 403);
        if (!password_verify($old, $u['password_hash'])) pFail('L\'ancien mot de passe est incorrect.');

        $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => (int) env('BCRYPT_COST', 12)]);
        $db->prepare("
            UPDATE users
               SET password_hash = ?, must_reset_password = 0, password_changed_at = NOW()
             WHERE id = ?
        ")->execute([$hash, $userId]);

        // Kill every OTHER session. A password change is the user's response to
        // "someone may have my password" often enough that keeping other
        // sessions alive would defeat the point. The current session survives
        // so the modal can close instead of bouncing to the login screen.
        $currentHash = !empty($_SESSION['session_token']) ? hash('sha256', $_SESSION['session_token']) : '';
        $db->prepare("
            UPDATE user_sessions SET logout_time = NOW()
             WHERE user_id = ? AND logout_time IS NULL AND session_token_hash <> ?
        ")->execute([$userId, $currentHash]);

        // A PIN derived from the old credentials is now stale trust — clear it
        // and make the user re-set it deliberately.
        if (UserProfile::hasPin()) {
            $db->prepare("UPDATE employee_profiles SET login_pin_hash = NULL, login_pin_set_at = NULL WHERE user_id = ?")
               ->execute([$userId]);
        }

        unset($_SESSION['force_reset']);
        profAudit($db, $userId, 'Password changed');
        pReply(true, 'Mot de passe mis à jour. Vos autres sessions ont été fermées.', [
            'profile'  => UserProfile::jsPayload(),
            'security' => prof_security_overview($db, $userId),
        ]);
    }

    // =========================================================================
    case 'set_pin': {
        RateLimiter::guard('profile_pin', $ip, 10, 15);

        $pin     = preg_replace('/\D/', '', (string) ($_POST['pin'] ?? ''));
        $confirm = preg_replace('/\D/', '', (string) ($_POST['pin_confirm'] ?? ''));
        $pw      = (string) ($_POST['password'] ?? '');

        if (strlen($pin) < 4 || strlen($pin) > 8) pFail('Le code doit contenir 4 à 8 chiffres.');
        if ($confirm !== '' && $confirm !== $pin) pFail('Les deux codes ne correspondent pas.');

        // Reject the sequences everyone reaches for first. This is not security
        // theatre: a 4-digit space is 10 000 wide, and roughly a fifth of real
        // users pick from this list, so refusing them removes the cheapest
        // possible shoulder-surf guess.
        $weak = ['0000','1111','2222','3333','4444','5555','6666','7777','8888','9999',
                 '1234','4321','0123','1212','2580','1122'];
        if (in_array($pin, $weak, true)) pFail('Ce code est trop courant. Choisissez-en un autre.');

        // Setting a PIN is a credential operation — confirm the password.
        $st = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $st->execute([$userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u || !password_verify($pw, $u['password_hash'])) {
            pFail('Mot de passe incorrect.');
        }

        $hash = password_hash($pin, PASSWORD_BCRYPT, ['cost' => (int) env('BCRYPT_COST', 12)]);
        $db->prepare("
            INSERT INTO employee_profiles (user_id, login_pin_hash, login_pin_set_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE login_pin_hash = VALUES(login_pin_hash),
                                    login_pin_set_at = VALUES(login_pin_set_at)
        ")->execute([$userId, $hash]);

        profAudit($db, $userId, 'Quick-login PIN set');
        UserProfile::current(true);
        profOk('Code rapide enregistré.', ['security' => prof_security_overview($db, $userId)]);
    }

    // =========================================================================
    case 'clear_pin': {
        $db->prepare("UPDATE employee_profiles SET login_pin_hash = NULL, login_pin_set_at = NULL WHERE user_id = ?")
           ->execute([$userId]);
        profAudit($db, $userId, 'Quick-login PIN removed');
        UserProfile::current(true);
        profOk('Code rapide supprimé.', ['security' => prof_security_overview($db, $userId)]);
    }

    // =========================================================================
    case 'sessions': {
        pReply(true, 'OK', ['security' => prof_security_overview($db, $userId)]);
    }

    // =========================================================================
    case 'revoke_sessions': {
        $currentHash = !empty($_SESSION['session_token']) ? hash('sha256', $_SESSION['session_token']) : '';
        $st = $db->prepare("
            UPDATE user_sessions SET logout_time = NOW()
             WHERE user_id = ? AND logout_time IS NULL AND session_token_hash <> ?
        ");
        $st->execute([$userId, $currentHash]);
        $n = $st->rowCount();
        profAudit($db, $userId, 'Other sessions revoked', ['count' => $n]);
        pReply(true, $n > 0
            ? "$n session(s) fermée(s)."
            : 'Aucune autre session active.',
            ['security' => prof_security_overview($db, $userId)]);
    }

    // =========================================================================
    default:
        pFail('Action inconnue.');
    }

} catch (RuntimeException $e) {
    // UserProfile::save() throws these with user-facing French messages.
    pFail($e->getMessage());
} catch (Throwable $e) {
    error_log('profile_controller: ' . $e->getMessage());
    pFail('Une erreur est survenue. Réessayez.', 500);
}

// =============================================================================
// Helpers
// =============================================================================

/**
 * Everything the Security tab shows. One query per fact rather than one big
 * join, because user_sessions can be large and the login_attempts read wants a
 * different index.
 */
function prof_security_overview(PDO $db, int $userId): array
{
    $out = [
        'passwordChangedAt' => null,
        'hasPin'            => UserProfile::hasPin(),
        'pinSetAt'          => UserProfile::current()['pin_set_at'],
        'lastLogin'         => null,
        'lastLoginIp'       => null,
        'activeSessions'    => 0,
        'recentFailures'    => 0,
        'sessions'          => [],
    ];

    try {
        $st = $db->prepare("SELECT password_changed_at FROM users WHERE id = ?");
        $st->execute([$userId]);
        $out['passwordChangedAt'] = $st->fetchColumn() ?: null;
    } catch (Throwable $e) { /* column added in migration 002; degrade quietly */ }

    try {
        $currentHash = !empty($_SESSION['session_token']) ? hash('sha256', $_SESSION['session_token']) : '';
        $st = $db->prepare("
            SELECT login_time, ip_address, user_agent, logout_time,
                   (session_token_hash = ?) AS is_current
              FROM user_sessions
             WHERE user_id = ? AND logout_time IS NULL
          ORDER BY login_time DESC
             LIMIT 10
        ");
        $st->execute([$currentHash, $userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $r) {
            $out['sessions'][] = [
                'loginTime' => $r['login_time'],
                'ip'        => $r['ip_address'],
                'device'    => prof_describe_ua((string) $r['user_agent']),
                'current'   => (bool) $r['is_current'],
            ];
        }
        $out['activeSessions'] = count($rows);
        if ($rows) {
            $out['lastLogin']   = $rows[0]['login_time'];
            $out['lastLoginIp'] = $rows[0]['ip_address'];
        }
    } catch (Throwable $e) { /* user_sessions shape varies pre-002 */ }

    return $out;
}

/**
 * A readable device label from a user-agent string.
 *
 * Deliberately crude. The goal is "is this the laptop I am sitting at, or
 * something I do not recognise?" — a full UA parser would be a dependency for
 * a line of text in one modal.
 */
function prof_describe_ua(string $ua): string
{
    if ($ua === '') return 'Appareil inconnu';

    $os = 'Inconnu';
    foreach ([
        'Windows NT 10' => 'Windows', 'Windows'  => 'Windows',
        'Android'       => 'Android', 'iPhone'   => 'iPhone',
        'iPad'          => 'iPad',    'Mac OS X' => 'macOS',
        'Linux'         => 'Linux',
    ] as $needle => $label) {
        if (stripos($ua, $needle) !== false) { $os = $label; break; }
    }

    $browser = 'Navigateur';
    foreach ([
        'Edg/'     => 'Edge',   'OPR/'    => 'Opera',
        'Chrome/'  => 'Chrome', 'Firefox/'=> 'Firefox',
        'Safari/'  => 'Safari',
    ] as $needle => $label) {
        if (strpos($ua, $needle) !== false) { $browser = $label; break; }
    }

    return "$browser · $os";
}

/**
 * Pages the user may pick as their landing page — the same list the sidebar
 * renders, so it is permission-filtered for free.
 */
function prof_landing_options(): array
{
    if (!function_exists('lpc_nav_sections')) return [];
    $out = [];
    foreach (lpc_nav_sections(UserProfile::locale()) as $section) {
        foreach ($section['items'] as $item) {
            $out[] = [
                'value' => strtok($item['href'], '#'),
                'label' => $section['heading'] . ' · ' . $item['label'],
            ];
        }
    }
    return $out;
}

/**
 * A short list of zones rather than all ~400 — this is a Cameroon-based ERP
 * with staff who travel, not a global SaaS. "Other" is not offered because a
 * zone outside this list can still be saved via the API (UserProfile::save
 * validates against timezone_identifiers_list), it just is not in the picker.
 */
function prof_timezone_options(): array
{
    return [
        'Africa/Douala', 'Africa/Lagos', 'Africa/Abidjan', 'Africa/Libreville',
        'Africa/Kinshasa', 'Africa/Bangui', 'Africa/Ndjamena',
        'Europe/Paris', 'Europe/London', 'UTC',
        'America/New_York', 'Asia/Dubai', 'Asia/Shanghai',
    ];
}

/**
 * Delete a superseded avatar file.
 *
 * Wrapped in every guard that matters: the stored value must be under
 * /uploads/avatars/, the resolved realpath must still be inside the uploads
 * root after symlink resolution, and failure is never fatal — an orphaned file
 * is a housekeeping problem, a deleted wrong file is a bug report.
 */
function prof_unlink_avatar(?string $webPath): void
{
    if (!$webPath) return;
    if (strpos($webPath, '/uploads/avatars/') !== 0) return;

    $root = realpath(__DIR__ . '/../../uploads');
    $file = realpath(__DIR__ . '/../../' . ltrim($webPath, '/'));
    if (!$root || !$file) return;
    if (strpos($file, $root . DIRECTORY_SEPARATOR) !== 0) return;
    if (!is_file($file)) return;

    @unlink($file);
}
