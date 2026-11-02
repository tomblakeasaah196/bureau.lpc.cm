<?php

namespace LPC\Mail\Compose;

/**
 * PR 3's settings (includes/mail/Compose/compose_config.php), as LPC\Mail\Config reads PR 1's:
 * ComposeConfig::get('undo_seconds'). Tests change one with set() and put them back with reset().
 */
final class ComposeConfig
{
    private static ?array $values = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::$values ??= require __DIR__ . '/compose_config.php';

        return self::$values[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$values ??= require __DIR__ . '/compose_config.php';
        self::$values[$key] = $value;
    }

    public static function reset(): void
    {
        self::$values = null;
    }
}
