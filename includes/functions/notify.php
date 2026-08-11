<?php
/**
 * includes/functions/notify.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — shared stored-notification writer.
 *
 * One insert helper for every controller that wants to push a persistent,
 * per-user notification, as opposed to the live-computed conditions in
 * api/v1/notifications_controller.php's GET feed. Use this for things that
 * HAPPEN once (an entry was posted, a transfer needs a decision, a discrepancy
 * was found) rather than standing conditions that stay true until resolved
 * (those belong in the live feed instead).
 *
 * Table: notifications(id, user_id, title, message, type, is_read,
 *                       related_url, created_at)
 * `type` drives the bell's colour grouping once read back out by
 * notifications_controller.php: 'error' -> danger, 'warning' -> warning,
 * 'info'/'success' -> info.
 *
 * TARGETING IS BY PERMISSION, NOT ROLE NAME — deliberately, and unlike the
 * three older, locally-duplicated helpers this codebase already had
 * (notifyAdmin in cre_controller.php, notifyAdminFinance in
 * invoices_controller.php / inventory_controller.php, both of which hardcode
 * role names like 'admin','finance','accountant'). Hardcoding role names
 * means a brand-new role — say a future 'procurement' or 'hr' role — gets
 * silently skipped by every notification call site until someone remembers
 * to edit the code and add its name. Targeting by permission instead means a
 * new role qualifies automatically the moment an admin grants it the right
 * permission in Rôles & Permissions — same resolution Rbac::hasPermission()
 * already uses (users -> role_permissions -> permissions), so "who gets
 * notified about X" and "who can see the page for X" can never drift apart
 * as roles evolve.
 *
 * Passing a permission key that doesn't exist, or that no role currently
 * holds, is not an error — the query just returns zero users, so the
 * notification silently reaches nobody. Check the permission actually exists
 * in migrations/001_rbac_seed.sql (or a later grant migration) before using it.
 *
 * Always best-effort: a broken notification insert must never break the
 * action that triggered it. Errors are logged, not thrown.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

/**
 * @param PDO                $db         Active connection.
 * @param string|string[]    $permission One permission key, or several — a
 *                                       user qualifies by holding ANY of them
 *                                       (e.g. ['accounting.budgets.approve']
 *                                       to reach whoever can act on a
 *                                       transfer request, regardless of which
 *                                       role grants them that permission).
 * @param string             $title      Short headline (matches existing notifyAdmin* style).
 * @param string             $message    Body text.
 * @param string|null        $relatedUrl Where the alert should link to — always
 *                                       pass one when the triggering record has
 *                                       an obvious page to land on; the bell
 *                                       falls back to the notifications index
 *                                       when this is null.
 * @param string             $type       One of info|warning|success|error (schema enum).
 * @param int[]              $excludeUserIds Skip these user ids — typically
 *                                       the actor who just performed the
 *                                       action, so they don't get notified
 *                                       about their own change.
 */
function lpc_notify_permission(
    PDO $db,
    $permission,
    string $title,
    string $message,
    ?string $relatedUrl = null,
    string $type = 'info',
    array $excludeUserIds = []
): void {
    try {
        $perms = array_values(array_unique(array_filter((array) $permission)));
        if (empty($perms)) return;

        $placeholders = implode(',', array_fill(0, count($perms), '?'));
        $sql = "
            SELECT DISTINCT u.id
              FROM users u
              JOIN role_permissions rp ON rp.role_id = u.role_id
              JOIN permissions p       ON p.id       = rp.permission_id
             WHERE p.name IN ($placeholders)
               AND u.status = 'active'
        ";
        $params = $perms;
        if (!empty($excludeUserIds)) {
            $exPlaceholders = implode(',', array_fill(0, count($excludeUserIds), '?'));
            $sql .= " AND u.id NOT IN ($exPlaceholders)";
            $params = array_merge($params, array_map('intval', $excludeUserIds));
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) return;

        $ins = $db->prepare(
            "INSERT INTO notifications (user_id, title, message, type, related_url)
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($ids as $uid) {
            $ins->execute([(int) $uid, $title, $message, $type, $relatedUrl]);
        }
    } catch (Throwable $e) {
        error_log('lpc_notify_permission: ' . $e->getMessage());
    }
}

/**
 * Notify one specific user by id — e.g. telling the person who requested a
 * budget transfer that it was approved or rejected, as opposed to broadcasting
 * to everyone holding a permission. Same best-effort contract as above.
 */
function lpc_notify_user(
    PDO $db,
    int $userId,
    string $title,
    string $message,
    ?string $relatedUrl = null,
    string $type = 'info'
): void {
    try {
        if ($userId <= 0) return;
        $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        if (!$stmt->fetchColumn()) return;

        $db->prepare(
            "INSERT INTO notifications (user_id, title, message, type, related_url)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$userId, $title, $message, $type, $relatedUrl]);
    } catch (Throwable $e) {
        error_log('lpc_notify_user: ' . $e->getMessage());
    }
}
