<?php

namespace LPC\Mail\Read;

use LPC\Mail\Clock;
use LPC\Mail\Db;

/**
 * Senders whose remote pictures a person always loads ("Toujours pour cet expéditeur", migration 310). Per person,
 * never shared: Awa trusting a newsletter changes nothing for Paul.
 */
final class ImageTrust
{
    /** @param list<?string> $senders @return list<string> those the person trusts, lower case */
    public static function trusted(int $userId, array $senders): array
    {
        $senders = array_values(array_unique(array_filter(array_map(fn ($s) => strtolower(trim((string) $s)), $senders))));
        if (!$senders || $userId <= 0) {
            return [];
        }

        return array_map('strval', Db::column(
            'SELECT sender FROM mail_image_trust WHERE user_id = ? AND sender IN (' . Db::marks(count($senders)) . ')',
            array_merge([$userId], $senders),
        ));
    }

    public static function trust(int $userId, string $sender): bool
    {
        $sender = strtolower(trim($sender));
        if ($userId <= 0 || $sender === '' || strlen($sender) > 190 || !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        Db::exec('INSERT IGNORE INTO mail_image_trust (user_id, sender, created_at) VALUES (?, ?, ?)', [$userId, $sender, Clock::sql()]);

        return true;
    }
}
