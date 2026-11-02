<?php

namespace LPC\Mail;

/**
 * Staff as Mail sees them: bureau's users (int id, status 'active') with their name from the HR record
 * (employees, through users.employee_id), else the legacy users name columns, else the login address.
 * Every active login is staff and can use Mail (owner decision, 2 Nov 2026).
 *
 * Admins, for Mail, are the holders of the mail.shared.manage permission (or '*' / 'mail.*'): they add shared
 * mailboxes and manage every shared one. Personal mailboxes stay private, even from them.
 */
final class People
{
    public const ADMIN_PERMISSIONS = ['*', 'mail.*', 'mail.shared.manage'];

    private static array $cache = [];

    private const NAME_SQL = "COALESCE(NULLIF(TRIM(CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, ''))), ''),
                                       NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                                       u.email)";

    /** @return array{id: int, name: string, email: string, active: bool, role: ?string, job_title: ?string, employee_code: ?string}|null */
    public static function find(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        if (array_key_exists('u' . $userId, self::$cache)) {
            return self::$cache['u' . $userId];
        }
        $row = Db::one(
            'SELECT u.id, u.email, u.status, r.name AS role_name, ' . self::NAME_SQL . ' AS display_name,
                    e.job_title, e.employee_code
               FROM users u
          LEFT JOIN roles r     ON r.id = u.role_id
          LEFT JOIN employees e ON e.id = u.employee_id
              WHERE u.id = ?',
            [$userId],
        );

        return self::$cache['u' . $userId] = $row ? self::shape($row) : null;
    }

    public static function name(?int $userId): string
    {
        $p = $userId ? self::find($userId) : null;

        return $p['name'] ?? 'Une personne';
    }

    /** First name only, for short labels ("Ouvert par Awa") */
    public static function firstName(?int $userId): string
    {
        $name = self::name($userId);

        return explode(' ', trim($name))[0] ?: $name;
    }

    public static function isActive(int $userId): bool
    {
        return (bool) (self::find($userId)['active'] ?? false);
    }

    public static function isAdmin(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return self::$cache['admin' . $userId] ??= (bool) Db::value(
            'SELECT 1 FROM users u
               JOIN role_permissions rp ON rp.role_id = u.role_id
               JOIN permissions p       ON p.id = rp.permission_id
              WHERE u.id = ? AND p.name IN (' . Db::marks(count(self::ADMIN_PERMISSIONS)) . ') LIMIT 1',
            array_merge([$userId], self::ADMIN_PERMISSIONS),
        );
    }

    /** @return list<int> active admins (for Mail) */
    public static function adminIds(): array
    {
        return self::$cache['admins'] ??= array_map('intval', Db::column(
            "SELECT DISTINCT u.id FROM users u
               JOIN role_permissions rp ON rp.role_id = u.role_id
               JOIN permissions p       ON p.id = rp.permission_id
              WHERE u.status = 'active' AND p.name IN (" . Db::marks(count(self::ADMIN_PERMISSIONS)) . ')',
            self::ADMIN_PERMISSIONS,
        ));
    }

    /**
     * Active staff, by name, optionally leaving some out (people already on a mailbox).
     *
     * @return list<array{id: int, name: string, email: string, active: bool, role: ?string, job_title: ?string, employee_code: ?string}>
     */
    public static function active(array $exceptIds = []): array
    {
        $exceptIds = array_values(array_unique(array_map('intval', $exceptIds)));
        $rows = Db::all(
            "SELECT u.id, u.email, u.status, r.name AS role_name, " . self::NAME_SQL . " AS display_name, e.job_title, e.employee_code
               FROM users u
          LEFT JOIN roles r     ON r.id = u.role_id
          LEFT JOIN employees e ON e.id = u.employee_id
              WHERE u.status = 'active'" . ($exceptIds ? ' AND u.id NOT IN (' . Db::marks(count($exceptIds)) . ')' : '') . '
           ORDER BY display_name',
            $exceptIds,
        );

        return array_map([self::class, 'shape'], $rows);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    private static function shape(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => trim((string) $row['display_name']) !== '' ? trim((string) $row['display_name']) : (string) $row['email'],
            'email' => (string) $row['email'],
            'active' => strtolower((string) $row['status']) === 'active',
            'role' => $row['role_name'] ?? null,
            'job_title' => $row['job_title'] ?? null,
            'employee_code' => $row['employee_code'] ?? null,
        ];
    }
}
