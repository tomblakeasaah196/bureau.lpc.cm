<?php

namespace LPC\Mail;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Dates for the mail tables: DATETIME strings in the app's own time zone (Africa/Douala, set by bootstrap
 * from Prefs), like the rest of bureau's database. Mail headers arrive in any zone; they are converted here.
 * Tests can stop the clock with freeze().
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', self::zone());
    }

    /** 'Y-m-d H:i:s' now, or $seconds from now */
    public static function sql(int $seconds = 0): string
    {
        return self::format(self::now()->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds'));
    }

    /** Any date (any zone) as a DATETIME string in the app's zone */
    public static function format(?DateTimeInterface $date): ?string
    {
        return $date === null ? null : DateTimeImmutable::createFromInterface($date)->setTimezone(self::zone())->format('Y-m-d H:i:s');
    }

    /** A DATETIME string from the database back to a date in the app's zone */
    public static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($value, 0, 19), self::zone());

        return $date ?: null;
    }

    /** Seconds since a DATETIME string (null: never) */
    public static function ageSeconds(?string $value): ?int
    {
        $date = self::parse($value);

        return $date === null ? null : self::now()->getTimestamp() - $date->getTimestamp();
    }

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get() ?: 'Africa/Douala');
    }

    public static function freeze(?DateTimeImmutable $at): void
    {
        self::$frozen = $at?->setTimezone(self::zone());
    }
}
