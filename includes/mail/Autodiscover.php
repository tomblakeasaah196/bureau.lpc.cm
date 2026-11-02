<?php

namespace LPC\Mail;

/**
 * Server settings for an address: Config 'servers' for known domains (lpc.cm: mail.lpc.cm, IMAP 993 and
 * SMTP 465, both SSL/TLS, username = the full address, as cPanel's manual settings say), otherwise
 * mail.<domain> with the default ports. The person can change them before connecting.
 */
final class Autodiscover
{
    public static function for(string $email): array
    {
        $domain = strtolower(trim((string) substr(strrchr($email, '@') ?: '', 1)));
        $known = Config::get('servers', [])[$domain] ?? null;
        if (is_array($known)) {
            return self::normalise($known);
        }
        $default = Config::get('default_server', []);

        return self::normalise([
            'imap_host' => $domain !== '' ? "mail.{$domain}" : '',
            'imap_port' => $default['imap_port'] ?? 993,
            'imap_encryption' => $default['imap_encryption'] ?? 'ssl',
            'smtp_host' => $domain !== '' ? "mail.{$domain}" : '',
            'smtp_port' => $default['smtp_port'] ?? 465,
            'smtp_encryption' => $default['smtp_encryption'] ?? 'ssl',
        ]);
    }

    public static function normalise(array $s): array
    {
        $enc = fn ($v) => in_array($v, ['ssl', 'starttls', 'none'], true) ? $v : 'ssl';

        return [
            'imap_host' => strtolower(trim((string) ($s['imap_host'] ?? ''))),
            'imap_port' => (int) ($s['imap_port'] ?? 993),
            'imap_encryption' => $enc($s['imap_encryption'] ?? null),
            'smtp_host' => strtolower(trim((string) ($s['smtp_host'] ?? ''))),
            'smtp_port' => (int) ($s['smtp_port'] ?? 465),
            'smtp_encryption' => $enc($s['smtp_encryption'] ?? null),
        ];
    }
}
