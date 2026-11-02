<?php

namespace LPC\Mail\Team;

use LPC\Mail\Db;
use LPC\Mail\MailAccess;
use LPC\Mail\MailNotices;
use LPC\Mail\Store;
use Throwable;

/**
 * New mail in a mailbox's Boîte de réception (the mail.received event, docs/MAIL.md §7.4, §7.5): a bell
 * notification to everyone who can read the mailbox (owner decision 10), one per message, linked to the
 * conversation. Nobody is alerted about mail they sent themselves:
 *   - mail sent from this mailbox (is_outbound) never comes here, and sent_by is skipped;
 *   - the writer of a bureau outbox row with the same Message-ID (mail written in bureau from another
 *     mailbox, e.g. their own, to this one);
 *   - a reader whose own address (their login, or their personal mailbox) is the sender (mail they wrote
 *     from their phone or webmail).
 * A Traitée conversation on a shared mailbox becomes Ouverte again. Runs inside sync: one message failing is
 * logged and the others go on; the listener (Listeners/team_new_mail.php) never throws.
 */
final class NewMailNotices
{
    /** @param list<int> $messageIds oldest first @return int bell rows written */
    public static function received(int $accountId, array $messageIds): int
    {
        $account = $accountId > 0 ? Store::account($accountId) : null;
        $ids = array_values(array_filter(array_map('intval', $messageIds)));
        if (!$account || !$ids) {
            return 0;
        }
        $messages = array_values(array_filter(Store::messages($ids), fn ($m) => $m['account_id'] === $account['id'] && !$m['is_outbound']));
        if (!$messages) {
            return 0;
        }
        $readers = MailAccess::readers($account);
        $writers = self::writers(array_column($messages, 'message_id_hash'));
        $own = self::ownAddresses($readers);
        $shared = Store::isShared($account);

        $written = 0;
        foreach ($messages as $m) {
            try {
                $skip = array_merge(
                    $m['sent_by'] ? [$m['sent_by']] : [],
                    $writers[$m['message_id_hash'] ?? ''] ?? [],
                    $own[strtolower(trim((string) $m['from_email']))] ?? [],
                );
                $to = array_values(array_diff($readers, $skip));
                if ($to) {
                    MailNotices::send($to, self::title($m), self::line($m, $account), MailNotices::inboxUrl($account['id'], $m['thread_id']), 'info');
                    $written += count($to);
                }
                if ($shared && $m['thread_id']) {
                    MailTeam::reopenOnReply($m['thread_id'], $m['id']);
                }
            } catch (Throwable $e) {
                error_log("Mail: new-mail alert for message {$m['id']} failed: " . $e->getMessage());
            }
        }

        return $written;
    }

    /** "Nouveau message : Marc Etoundi" */
    public static function title(array $message): string
    {
        $from = trim((string) ($message['from_name'] ?: $message['from_email']));

        return 'Nouveau message : ' . ($from !== '' ? $from : 'expéditeur inconnu');
    }

    /** The subject (else the first words), plus " · contact@lpc.cm" on a shared mailbox */
    public static function line(array $message, array $account): string
    {
        $line = trim(preg_replace('/\s+/u', ' ', (string) $message['subject']) ?? '');
        if ($line === '') {
            $words = preg_split('/\s+/u', trim((string) $message['snippet']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $max = (int) MailTeam::config('alert_words', 12);
            $line = implode(' ', array_slice($words, 0, $max)) . (count($words) > $max ? '…' : '');
        }
        $line = mb_strimwidth($line !== '' ? $line : '(sans objet)', 0, 200, '…');

        return Store::isShared($account) ? "{$line} · {$account['email']}" : $line;
    }

    /** @return array<string, list<int>> message_id_hash => people who wrote it in bureau */
    private static function writers(array $hashes): array
    {
        $hashes = array_values(array_unique(array_filter($hashes)));
        if (!$hashes) {
            return [];
        }
        $out = [];
        foreach (Db::all('SELECT message_id_hash, user_id FROM mail_outbox WHERE message_id_hash IN (' . Db::marks(count($hashes)) . ')', $hashes) as $r) {
            $out[$r['message_id_hash']][] = (int) $r['user_id'];
        }

        return $out;
    }

    /** @return array<string, list<int>> lower-case address => readers it belongs to (their login, their own mailbox) */
    private static function ownAddresses(array $readers): array
    {
        if (!$readers) {
            return [];
        }
        $marks = Db::marks(count($readers));
        $rows = array_merge(
            Db::all("SELECT id AS user_id, email FROM users WHERE id IN ({$marks})", $readers),
            Db::all("SELECT owner_user_id AS user_id, email FROM mail_accounts WHERE kind = 'personal' AND owner_user_id IN ({$marks})", $readers),
        );
        $out = [];
        foreach ($rows as $r) {
            $email = strtolower(trim((string) $r['email']));
            if ($email !== '') {
                $out[$email][] = (int) $r['user_id'];
            }
        }

        return $out;
    }
}
