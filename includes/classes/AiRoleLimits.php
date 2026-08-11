<?php
/**
 * includes/classes/AiRoleLimits.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7H · Help Centre AI Assistant — per-role daily quota.
 *
 * One number per role: how many BILLABLE questions (help_chat_log.billable = 1
 * — a successful DeepSeek answer; a "nothing found" or a provider failure
 * never counts) that role's members may ask per calendar day. NULL = unlimited.
 *
 * "NO ROW YET" IS NOT "UNLIMITED". A role with no ai_role_limits row (created
 * after migration 104, before an admin has visited the settings screen) gets
 * the same 50/day every pre-existing role was seeded with — see
 * dailyLimitFor(). Only an EXPLICIT NULL stored against a role means
 * unlimited; the absence of a row must never silently mean the same thing,
 * or a newly created role starts out with no cap at all by accident.
 *
 * TIMEZONE NOTE: todayUsageFor() computes "today" using PHP's configured
 * timezone (Prefs::str('locale_timezone', …), set in bootstrap.php) but
 * compares against help_chat_log.created_at, which MySQL populated using the
 * DB server's own timezone. A one-hour skew at the exact day boundary is
 * possible if the two disagree. Acceptable for a soft usage cap; not worth a
 * full timezone-conversion layer for this.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class AiRoleLimits
{
    /** Applied when a role has no ai_role_limits row at all — same default
     *  every role got when migration 104 first seeded the table. */
    private const FALLBACK_DEFAULT = 50;

    /** The configured limit for a role, or null for unlimited. */
    public static function dailyLimitFor(?int $roleId): ?int
    {
        if ($roleId === null || $roleId <= 0) {
            return self::FALLBACK_DEFAULT;   // no role resolved — fail toward a cap, not toward unlimited
        }

        try {
            $stmt = Database::getInstance()->getConnection()
                ->prepare("SELECT daily_limit FROM ai_role_limits WHERE role_id = ?");
            $stmt->execute([$roleId]);
            $val = $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('AiRoleLimits::dailyLimitFor: ' . $e->getMessage());
            return self::FALLBACK_DEFAULT;
        }

        // fetchColumn() returns exactly `false` for "no row" and an actual
        // `null` for a row whose daily_limit column IS NULL — the two are
        // distinguishable, which is what makes "no row -> default" vs
        // "row with NULL -> unlimited" possible to tell apart at all.
        if ($val === false) {
            return self::FALLBACK_DEFAULT;
        }
        return $val === null ? null : (int) $val;
    }

    /** How many billable questions this user has already asked today. */
    public static function todayUsageFor(int $userId): int
    {
        try {
            $todayStart = (new DateTimeImmutable('today'))->format('Y-m-d H:i:s');
            $stmt = Database::getInstance()->getConnection()->prepare(
                "SELECT COUNT(*) FROM help_chat_log
                  WHERE user_id = ? AND billable = 1 AND created_at >= ?"
            );
            $stmt->execute([$userId, $todayStart]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('AiRoleLimits::todayUsageFor: ' . $e->getMessage());
            return 0;   // fail OPEN on a read error — a DB hiccup must not lock everyone out
        }
    }

    /**
     * Every role with its effective limit, for the settings screen.
     *
     * @return array<int,array{role_id:int,role_name:string,daily_limit:?int}>
     */
    public static function allWithRoles(): array
    {
        try {
            $rows = Database::getInstance()->getConnection()->query(
                "SELECT r.id AS role_id, r.name AS role_name,
                        l.role_id AS has_row, l.daily_limit
                   FROM roles r
              LEFT JOIN ai_role_limits l ON l.role_id = r.id
               ORDER BY (r.name = 'admin') DESC, r.name"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('AiRoleLimits::allWithRoles: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $hasRow = $r['has_row'] !== null;
            $out[] = [
                'role_id'     => (int) $r['role_id'],
                'role_name'   => (string) $r['role_name'],
                'daily_limit' => $hasRow
                    ? ($r['daily_limit'] !== null ? (int) $r['daily_limit'] : null)
                    : self::FALLBACK_DEFAULT,
            ];
        }
        return $out;
    }

    /**
     * Save a batch of limits. $limits is [role_id => raw form value]; an
     * empty string or null means unlimited. Unknown role_ids fail silently
     * per-row (logged) rather than aborting the whole batch — one bad key
     * submitted alongside four good ones shouldn't cost the admin their
     * other three edits.
     *
     * @param array<int|string,mixed> $limits
     * @return int rows written
     */
    public static function save(array $limits, ?int $userId = null): int
    {
        if (!$limits) {
            return 0;
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "INSERT INTO ai_role_limits (role_id, daily_limit, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE daily_limit = VALUES(daily_limit), updated_by = VALUES(updated_by)"
        );

        $written = 0;
        foreach ($limits as $roleId => $raw) {
            $roleId = (int) $roleId;
            if ($roleId <= 0) { continue; }

            $raw   = is_string($raw) ? trim($raw) : $raw;
            $limit = ($raw === '' || $raw === null) ? null : max(0, (int) $raw);

            try {
                $stmt->execute([$roleId, $limit, $userId]);
                $written++;
            } catch (Throwable $e) {
                // Most likely an unknown role_id tripping the FK constraint.
                error_log("AiRoleLimits::save role {$roleId}: " . $e->getMessage());
            }
        }
        return $written;
    }
}
