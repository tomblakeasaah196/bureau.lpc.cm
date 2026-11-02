<?php

namespace LPC\Mail;

/**
 * Small helpers for Courrier's screens (French only, owner decision): how a mailbox is shown, relative times,
 * sizes. Any PR may call these; only PR 1 changes them (docs/MAIL.md §5).
 */
final class View
{
    /** A mailbox as the Boîtes mail page and its API show it (no secret) */
    public static function mailboxRow(array $a, int $userId): array
    {
        $level = $a['access_level'] ?? MailAccess::level($userId, $a);
        $inbox = Store::folderByRole($a['id'], 'inbox');
        $manage = $level !== null && MailAccess::RANK[$level] >= MailAccess::RANK['manage'];
        $firstDone = $inbox && $inbox['initial_synced_at'] !== null;

        return [
            'id' => $a['id'],
            'kind' => $a['kind'],
            'email' => $a['email'],
            'display_name' => $a['display_name'],
            'initials' => self::initials($a['display_name'] ?: $a['email']),
            'status' => $a['status'],
            'status_label' => self::statusLabel($a['status'], $a['last_synced_at'], $firstDone),
            'status_tone' => $a['status'] === 'active' ? ($a['last_error'] ? 'warning' : 'success') : ($a['status'] === 'paused' ? 'neutral' : 'danger'),
            'last_error' => $manage ? $a['last_error'] : null,
            'unread' => $inbox['unread_count'] ?? 0,
            'level' => $level,
            'level_label' => MailAccess::LEVEL_LABELS[$level] ?? '',
            'can_manage' => $manage,
            'members' => Store::isShared($a) ? (int) Db::value('SELECT COUNT(*) FROM mail_account_members WHERE account_id = ?', [$a['id']]) : 0,
            'settings' => $manage ? MailServer::settingsOf($a) : null,
        ];
    }

    public static function statusLabel(string $status, ?string $lastSynced, bool $firstCopyDone): string
    {
        if ($status === 'needs_reconnect') {
            return 'Mot de passe à saisir';
        }
        if ($status === 'paused') {
            return 'En pause';
        }
        if ($lastSynced === null) {
            return 'Première mise à jour en attente';
        }
        if (!$firstCopyDone) {
            return 'Première copie en cours';
        }

        return 'À jour, ' . self::ago($lastSynced);
    }

    /** "à l'instant", "il y a 5 min", "il y a 2 h", "hier", "le 3 nov." */
    public static function ago(?string $datetime): string
    {
        $s = Clock::ageSeconds($datetime);
        if ($s === null) {
            return '';
        }
        if ($s < 60) {
            return 'à l’instant';
        }
        if ($s < 3600) {
            return 'il y a ' . intdiv($s, 60) . ' min';
        }
        if ($s < 86400) {
            return 'il y a ' . intdiv($s, 3600) . ' h';
        }
        if ($s < 172800) {
            return 'hier';
        }
        $d = Clock::parse($datetime);
        $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

        return 'le ' . (int) $d->format('j') . ' ' . $months[(int) $d->format('n') - 1];
    }

    /** 1 234 567 octets → "1,2 Mo" (plain arithmetic: the live PHP has no intl) */
    public static function size(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' o';
        }
        if ($bytes < 1024 * 1024) {
            return str_replace('.', ',', (string) round($bytes / 1024)) . ' Ko';
        }

        return str_replace('.', ',', (string) round($bytes / 1048576, 1)) . ' Mo';
    }

    public static function initials(string $name): string
    {
        $name = trim(preg_replace('/@.*$/', '', $name));
        $parts = array_values(array_filter(preg_split('/[\s._-]+/u', $name)));
        $out = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $out .= mb_strtoupper(mb_substr($p, 0, 1));
        }

        return $out !== '' ? $out : '@';
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
