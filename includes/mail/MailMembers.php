<?php

namespace LPC\Mail;

/**
 * Who shares a shared mailbox and at what level (Lecture, Lecture et envoi, Gestionnaire). Only its managers
 * (and admins) change this. A mailbox always keeps at least one manager of its own, so it is never left to the
 * admins alone by accident. Every change is audited and the person added is told (bell).
 */
final class MailMembers
{
    /**
     * @return list<array{id: int, user_id: int, name: string, email: string, level: string, level_label: string, active: bool}>
     */
    public static function list(int $byUserId, int $accountId): array
    {
        $account = Store::account($accountId) ?? throw MailError::notFound();
        MailAccess::authorize($byUserId, $account, 'read');
        if (!Store::isShared($account)) {
            return [];
        }
        $out = [];
        foreach (Db::all('SELECT id, user_id, level FROM mail_account_members WHERE account_id = ? ORDER BY id', [$accountId]) as $r) {
            $p = People::find((int) $r['user_id']);
            $out[] = [
                'id' => (int) $r['id'],
                'user_id' => (int) $r['user_id'],
                'name' => $p['name'] ?? 'Compte supprimé',
                'email' => $p['email'] ?? '',
                'level' => $r['level'],
                'level_label' => MailAccess::LEVEL_LABELS[$r['level']] ?? $r['level'],
                'active' => (bool) ($p['active'] ?? false),
            ];
        }
        usort($out, fn ($a, $b) => [MailAccess::RANK[$b['level']], mb_strtolower($a['name'])] <=> [MailAccess::RANK[$a['level']], mb_strtolower($b['name'])]);

        return $out;
    }

    public static function add(int $byUserId, int $accountId, int $personId, string $level): void
    {
        $account = self::guard($byUserId, $accountId, $level);
        $person = People::find($personId);
        if (!$person || !$person['active']) {
            throw MailError::field('invite_user', 'Cette personne n’a pas de compte bureau actif.');
        }
        if (Db::value('SELECT 1 FROM mail_account_members WHERE account_id = ? AND user_id = ?', [$accountId, $personId])) {
            throw MailError::field('invite_user', "{$person['name']} a déjà accès. Changez son niveau dans la liste.");
        }
        $now = Clock::sql();
        Db::insert('mail_account_members', ['account_id' => $accountId, 'user_id' => $personId, 'level' => $level, 'added_by' => $byUserId, 'created_at' => $now, 'updated_at' => $now]);
        MailConnector::audit($byUserId, 'INSERT', $accountId, "Accès « " . MailAccess::LEVEL_LABELS[$level] . " » à {$account['email']} donné à {$person['name']}.");
        MailNotices::memberAdded($account, $personId, $level, $byUserId);
        MailAccess::flush();
    }

    public static function change(int $byUserId, int $accountId, int $memberId, string $level): void
    {
        $account = self::guard($byUserId, $accountId, $level);
        $member = Db::one('SELECT * FROM mail_account_members WHERE id = ? AND account_id = ?', [$memberId, $accountId]) ?? throw MailError::notFound();
        if ($member['level'] === $level) {
            return;
        }
        if ($member['level'] === 'manage') {
            self::keepAManager($accountId, $memberId);
        }
        Db::update('mail_account_members', ['level' => $level, 'updated_at' => Clock::sql()], $memberId);
        MailConnector::audit($byUserId, 'UPDATE', $accountId, 'Accès de ' . People::name((int) $member['user_id']) . " à {$account['email']} : « "
            . MailAccess::LEVEL_LABELS[$member['level']] . ' » → « ' . MailAccess::LEVEL_LABELS[$level] . ' ».');
        MailAccess::flush();
    }

    public static function remove(int $byUserId, int $accountId, int $memberId): void
    {
        $account = Store::account($accountId) ?? throw MailError::notFound();
        MailAccess::authorize($byUserId, $account, 'manage');
        if (!Store::isShared($account)) {
            throw MailError::notFound();
        }
        $member = Db::one('SELECT * FROM mail_account_members WHERE id = ? AND account_id = ?', [$memberId, $accountId]) ?? throw MailError::notFound();
        if ($member['level'] === 'manage') {
            self::keepAManager($accountId, $memberId);
        }
        Db::tx(function () use ($member, $accountId) {
            // Their claims on conversations go with them
            Db::exec('DELETE FROM mail_presence WHERE user_id = ? AND thread_id IN (SELECT id FROM mail_threads WHERE account_id = ?)', [$member['user_id'], $accountId]);
            Db::exec('DELETE FROM mail_account_members WHERE id = ?', [$member['id']]);
        });
        MailConnector::audit($byUserId, 'DELETE', $accountId, 'Accès de ' . People::name((int) $member['user_id']) . " à {$account['email']} retiré.");
        MailAccess::flush();
    }

    /** Active staff who can be added: not yet members @return list<array{id: int, name: string, email: string}> */
    public static function invitable(int $byUserId, int $accountId): array
    {
        $account = Store::account($accountId) ?? throw MailError::notFound();
        MailAccess::authorize($byUserId, $account, 'manage');
        $members = array_map('intval', Db::column('SELECT user_id FROM mail_account_members WHERE account_id = ?', [$accountId]));

        return array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'email' => $p['email'], 'job_title' => $p['job_title']], People::active($members));
    }

    private static function guard(int $byUserId, int $accountId, string $level): array
    {
        $account = Store::account($accountId) ?? throw MailError::notFound();
        MailAccess::authorize($byUserId, $account, 'manage');
        if (!Store::isShared($account)) {
            throw MailError::notFound();
        }
        if (!in_array($level, MailAccess::LEVELS, true)) {
            throw MailError::field('invite_level', 'Choisissez Lecture, Lecture et envoi, ou Gestionnaire.');
        }

        return $account;
    }

    private static function keepAManager(int $accountId, int $leavingMemberId): void
    {
        $others = (int) Db::value("SELECT COUNT(*) FROM mail_account_members WHERE account_id = ? AND level = 'manage' AND id <> ?", [$accountId, $leavingMemberId]);
        if ($others === 0) {
            throw MailError::field('members', 'Une boîte partagée garde au moins un gestionnaire. Nommez d’abord quelqu’un d’autre gestionnaire.');
        }
    }
}
