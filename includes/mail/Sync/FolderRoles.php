<?php

namespace LPC\Mail\Sync;

/**
 * Which folder is the Inbox, Sent, Drafts, Archive, Spam and Trash: the server's special-use flags first
 * (RFC 6154), then common names in English and French (cPanel's Dovecot uses INBOX.Sent, INBOX.Drafts, …).
 * Each role goes to one folder only; the rest are "other".
 */
final class FolderRoles
{
    private const ATTRIBUTES = [
        '\\Sent' => 'sent',
        '\\Drafts' => 'drafts',
        '\\Junk' => 'spam',
        '\\Trash' => 'trash',
        '\\Archive' => 'archive',
    ];

    private const NAMES = [
        'sent' => ['sent', 'sent items', 'sent messages', 'sent mail', 'éléments envoyés', 'envoyés', 'messages envoyés'],
        'drafts' => ['drafts', 'draft', 'brouillons'],
        'spam' => ['junk', 'spam', 'junk e-mail', 'junk email', 'courrier indésirable', 'pourriel', 'indésirables'],
        'trash' => ['trash', 'deleted items', 'deleted messages', 'bin', 'corbeille', 'éléments supprimés'],
        'archive' => ['archive', 'archives'],
    ];

    /**
     * @param  list<array{path: string, name: string, delimiter: ?string, attributes: list<string>}>  $folders
     * @return array<string, string> path => role
     */
    public static function assign(array $folders): array
    {
        $roles = [];
        $taken = [];

        foreach ($folders as $f) {
            if (strcasecmp($f['path'], 'INBOX') === 0) {
                $roles[$f['path']] = 'inbox';
                $taken['inbox'] = true;
            }
        }

        // Special-use flags win
        foreach ($folders as $f) {
            if (isset($roles[$f['path']]) || ! self::selectable($f)) {
                continue;
            }
            foreach ($f['attributes'] as $attribute) {
                $role = self::ATTRIBUTES[$attribute] ?? null;
                if ($role && ! isset($taken[$role])) {
                    $roles[$f['path']] = $role;
                    $taken[$role] = true;
                    break;
                }
            }
        }

        // Then names, top-level (or directly under INBOX) first
        $byDepth = $folders;
        usort($byDepth, fn ($a, $b) => self::depth($a) <=> self::depth($b));
        foreach ($byDepth as $f) {
            if (isset($roles[$f['path']]) || ! self::selectable($f)) {
                continue;
            }
            $name = mb_strtolower(trim($f['name']));
            foreach (self::NAMES as $role => $names) {
                if (! isset($taken[$role]) && in_array($name, $names, true)) {
                    $roles[$f['path']] = $role;
                    $taken[$role] = true;
                    break;
                }
            }
        }

        foreach ($folders as $f) {
            $roles[$f['path']] ??= 'other';
        }

        return $roles;
    }

    public static function selectable(array $folder): bool
    {
        foreach ($folder['attributes'] as $a) {
            if (in_array(strtolower($a), ['\\noselect', '\\nonexistent'], true)) {
                return false;
            }
        }

        return true;
    }

    private static function depth(array $folder): int
    {
        $d = $folder['delimiter'] ?? null;
        if (! is_string($d) || $d === '') {
            return 0;
        }
        $path = $folder['path'];
        if (stripos($path, 'INBOX' . $d) === 0) {
            $path = substr($path, 6);
        }

        return substr_count($path, $d);
    }
}
