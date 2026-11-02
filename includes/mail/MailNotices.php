<?php

namespace LPC\Mail;

use Throwable;

/**
 * Bell notifications about mailboxes themselves (PR 1). New-mail alerts are PR 4's (a mail.received listener).
 * Rows go into bureau's `notifications` table (user_id, title, message, type, related_url), so the topbar bell
 * shows them like any other. Links are relative and always use the URLs of docs/MAIL.md §6.1.
 */
final class MailNotices
{
    public static function needsPassword(array $account): void
    {
        self::send(
            MailAccess::managers($account),
            'Courrier : mot de passe à saisir',
            "{$account['email']} ne se met plus à jour : le serveur de messagerie a refusé son mot de passe. Saisissez le mot de passe actuel dans Courrier, Boîtes mail.",
            self::mailboxesUrl((int) $account['id']),
            'warning',
        );
    }

    public static function memberAdded(array $account, int $personId, string $level, ?int $byId): void
    {
        $what = match ($level) {
            'manage' => 'la lire, écrire depuis elle et la gérer',
            'send' => 'la lire et écrire depuis elle',
            default => 'la lire',
        };
        self::send(
            [$personId],
            'Nouvelle boîte mail partagée',
            ($byId ? People::name($byId) . ' vous a donné' : 'Vous avez maintenant') . " accès à {$account['email']} : vous pouvez {$what} dans Courrier.",
            self::inboxUrl((int) $account['id']),
            'info',
        );
    }

    /** @param list<int> $userIds */
    public static function send(array $userIds, string $title, string $message, ?string $link, string $type = 'info'): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        foreach ($userIds as $id) {
            try {
                Db::insert('notifications', [
                    'user_id' => $id,
                    'title' => mb_substr($title, 0, 150),
                    'message' => $message,
                    'type' => $type,
                    'related_url' => $link !== null ? mb_substr($link, 0, 255) : null,
                ]);
            } catch (Throwable $e) {
                error_log('Mail: notification not saved: ' . $e->getMessage());
            }
        }
    }

    /** /modules/mail/index.php?box=…&thread=… (docs/MAIL.md §6.1) */
    public static function inboxUrl(int $accountId, ?int $threadId = null, ?string $folder = null): string
    {
        $q = ['box' => $accountId];
        if ($folder !== null) {
            $q['folder'] = $folder;
        }
        if ($threadId !== null) {
            $q['thread'] = $threadId;
        }

        return '/modules/mail/index.php?' . http_build_query($q);
    }

    public static function mailboxesUrl(?int $accountId = null): string
    {
        return '/modules/mail/mailboxes.php' . ($accountId ? '?box=' . $accountId : '');
    }
}
