<?php

namespace LPC\Mail\Ai;

use LPC\Mail\Clock;
use LPC\Mail\MailError;
use LPC\Mail\MailSettings;

/**
 * Uses per person per hour (owner decision: rate limits per person; numbers in ai_config.php 'limits'). Kept in
 * mail_settings as ai.usage.<user id> = {kind: [unix times in the last hour]}: who, what kind, when, and nothing
 * else (never a question, a draft or anything from the mail). No table of its own, so PR 5 needs no migration.
 *
 *   Limits::take($userId, 'summary');   // throws MailError 429 when the hour's uses are spent, else counts one
 */
final class Limits
{
    public const KINDS = ['write', 'summary', 'ask', 'test'];

    public const MESSAGES = [
        'write' => 'Vous avez demandé beaucoup de brouillons cette heure-ci. Réessayez un peu plus tard, ou écrivez le message vous-même.',
        'summary' => 'Vous avez demandé beaucoup de résumés cette heure-ci. Réessayez un peu plus tard.',
        'ask' => 'Vous avez posé beaucoup de questions cette heure-ci. Réessayez un peu plus tard.',
        'test' => 'La clé a déjà été testée plusieurs fois cette heure-ci. Réessayez un peu plus tard.',
    ];

    public static function allowed(int $userId, string $kind): bool
    {
        return self::used($userId, $kind) < self::limit($kind);
    }

    /** Count one use, or throw MailError 429 (on $field) when the hour's uses are spent */
    public static function take(int $userId, string $kind, ?string $field = null): void
    {
        $now = Clock::now()->getTimestamp();
        $usage = self::usage($userId, $now);
        if (count($usage[$kind] ?? []) >= self::limit($kind)) {
            throw new MailError(self::MESSAGES[$kind] ?? self::MESSAGES['ask'], $field, 429);
        }
        $usage[$kind][] = $now;
        MailSettings::set(self::key($userId), $usage, $userId);
    }

    public static function used(int $userId, string $kind): int
    {
        return count(self::usage($userId, Clock::now()->getTimestamp())[$kind] ?? []);
    }

    public static function limit(string $kind): int
    {
        return max(0, (int) Settings::config('limits.' . $kind, 0));
    }

    /** @return array<string, list<int>> this hour's uses by kind */
    private static function usage(int $userId, int $now): array
    {
        $stored = MailSettings::get(self::key($userId));
        $out = [];
        foreach (is_array($stored) ? $stored : [] as $kind => $times) {
            if (!in_array($kind, self::KINDS, true) || !is_array($times)) {
                continue;
            }
            $recent = array_values(array_filter(array_map('intval', $times), fn ($t) => $t > $now - 3600 && $t <= $now + 60));
            if ($recent) {
                $out[$kind] = $recent;
            }
        }

        return $out;
    }

    private static function key(int $userId): string
    {
        return 'ai.usage.' . $userId;
    }
}
