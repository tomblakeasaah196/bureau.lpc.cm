<?php

namespace LPC\Mail;

/**
 * Who may do what in Courrier (docs/MAIL.md §7.1). Every Mail screen, action and query goes through here.
 *
 * Using Mail at all: every active staff login (owner decision, 2 Nov 2026: everyone uses Mail, no
 * "admins first" stage).
 *
 * Levels on a mailbox, lowest first:
 *   read    Lecture: sees every message (opening one marks it read for everyone, as on the server)
 *   send    Lecture et envoi: also replies, sends, flags, files, archives and deletes
 *   manage  Gestionnaire: also adds people, changes their level, updates the password, renames, removes
 *   owner   the person's own (personal) mailbox: everything; nobody else sees it, not even an admin
 * A shared mailbox's levels come from mail_account_members. Admins (People::isAdmin: the mail.shared.manage
 * permission) add shared mailboxes and are managers of every shared one without being listed.
 *
 * Abilities for can(): read, send, organise (flag, move, delete; same rank as send), manage.
 * $userId is always bureau's users.id of the person acting.
 */
final class MailAccess
{
    public const RANK = ['read' => 1, 'send' => 2, 'manage' => 3, 'owner' => 4];

    public const LEVELS = ['read', 'send', 'manage'];

    public const LEVEL_LABELS = ['read' => 'Lecture', 'send' => 'Lecture et envoi', 'manage' => 'Gestionnaire', 'owner' => 'Ma boîte'];

    private const ABILITY_RANK = ['read' => 1, 'send' => 2, 'organise' => 2, 'manage' => 3];

    private static array $cache = [];

    public static function canUse(?int $userId): bool
    {
        return $userId !== null && $userId > 0 && People::isActive($userId);
    }

    /** Add shared mailboxes (admins) */
    public static function canAddShared(?int $userId): bool
    {
        return self::canUse($userId) && People::isAdmin((int) $userId);
    }

    /** 'owner', 'manage', 'send', 'read' or null */
    public static function level(?int $userId, array $account): ?string
    {
        if (!self::canUse($userId)) {
            return null;
        }
        $key = $userId . ':' . $account['id'];
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        if (!Store::isShared($account)) {
            return self::$cache[$key] = ((int) $account['owner_user_id'] === $userId) ? 'owner' : null;
        }
        if (People::isAdmin($userId)) {
            return self::$cache[$key] = 'manage';
        }
        $level = Db::value('SELECT level FROM mail_account_members WHERE account_id = ? AND user_id = ?', [$account['id'], $userId]);

        return self::$cache[$key] = in_array($level, self::LEVELS, true) ? $level : null;
    }

    public static function can(?int $userId, array $account, string $ability): bool
    {
        $level = self::level($userId, $account);
        if ($level === null) {
            return false;
        }

        return self::RANK[$level] >= (self::ABILITY_RANK[$ability] ?? PHP_INT_MAX);
    }

    /** @throws MailError 403 */
    public static function authorize(?int $userId, array $account, string $ability): void
    {
        if (!self::can($userId, $account, $ability)) {
            throw MailError::denied();
        }
    }

    /** The account of a thread or message row, then can() */
    public static function canThread(?int $userId, array $thread, string $ability): bool
    {
        $account = Store::account((int) $thread['account_id']);

        return $account !== null && self::can($userId, $account, $ability);
    }

    public static function canMessage(?int $userId, array $message, string $ability): bool
    {
        $account = Store::account((int) $message['account_id']);

        return $account !== null && self::can($userId, $account, $ability);
    }

    /**
     * The mailboxes this person can open: their own first, then shared ones by address. Each row carries
     * 'access_level' (not a column: never save it back).
     *
     * @return list<array>
     */
    public static function accounts(?int $userId, string $ability = 'read'): array
    {
        if (!self::canUse($userId)) {
            return [];
        }
        if (People::isAdmin($userId)) {
            $rows = Store::accounts("(kind = 'personal' AND owner_user_id = ?) OR kind = 'shared'", [$userId]);
        } else {
            $rows = Store::accounts(
                "(kind = 'personal' AND owner_user_id = ?) OR (kind = 'shared' AND id IN (SELECT account_id FROM mail_account_members WHERE user_id = ?))",
                [$userId, $userId],
            );
        }

        $out = [];
        foreach ($rows as $a) {
            $a['access_level'] = self::level($userId, $a);
            if (self::can($userId, $a, $ability)) {
                $out[] = $a;
            }
        }
        usort($out, fn ($a, $b) => [Store::isShared($a) ? 1 : 0, $a['email']] <=> [Store::isShared($b) ? 1 : 0, $b['email']]);

        return $out;
    }

    /** @return list<int> */
    public static function accountIds(?int $userId, string $ability = 'read'): array
    {
        return array_map(fn ($a) => (int) $a['id'], self::accounts($userId, $ability));
    }

    /**
     * Active people who can read a mailbox now: for a personal one its person; for a shared one its members and
     * the admins. PR 4 sends new-mail alerts to these.
     *
     * @return list<int>
     */
    public static function readers(array $account): array
    {
        $ids = Store::isShared($account)
            ? array_merge(array_map('intval', Db::column('SELECT user_id FROM mail_account_members WHERE account_id = ?', [$account['id']])), People::adminIds())
            : [(int) $account['owner_user_id']];

        $out = [];
        foreach (array_values(array_unique(array_filter($ids))) as $id) {
            if (self::can($id, $account, 'read')) {
                $out[] = $id;
            }
        }
        sort($out);

        return $out;
    }

    /** Managers of a mailbox (who to tell when it needs its password again) @return list<int> */
    public static function managers(array $account): array
    {
        if (!Store::isShared($account)) {
            return array_values(array_filter([(int) $account['owner_user_id']], fn ($id) => $id > 0 && People::isActive($id)));
        }
        $ids = array_merge(
            array_map('intval', Db::column("SELECT user_id FROM mail_account_members WHERE account_id = ? AND level = 'manage'", [$account['id']])),
            People::adminIds(),
        );

        $out = array_values(array_filter(array_unique($ids), fn ($id) => People::isActive($id)));
        sort($out);

        return $out;
    }

    public static function flush(): void
    {
        self::$cache = [];
        People::flush();
        MailSettings::flush();
    }
}
