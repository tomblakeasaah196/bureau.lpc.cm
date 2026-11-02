<?php

namespace LPC\Mail;

/** includes/mail/mail_config.php by dotted key: Config::get('sync.months', 12). Tests may override with set(). */
final class Config
{
    private static ?array $values = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::$values ??= require __DIR__ . '/mail_config.php';
        $node = self::$values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }

        return $node;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$values ??= require __DIR__ . '/mail_config.php';
        $node = &self::$values;
        foreach (explode('.', $key) as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }
        $node = $value;
    }

    public static function reset(): void
    {
        self::$values = null;
    }
}
