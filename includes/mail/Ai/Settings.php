<?php

namespace LPC\Mail\Ai;

use LPC\Mail\Clock;
use LPC\Mail\Db;
use LPC\Mail\MailAccess;
use LPC\Mail\MailError;
use LPC\Mail\MailSettings;
use LPC\Mail\People;
use LPC\Mail\View;
use Throwable;

/**
 * Praxis AI's settings (docs/MAIL.md §5, §7.6), in mail_settings under ai.*:
 *
 *   ai.gemini_key   the Gemini API key, encrypted with \Crypto::encrypt() (APP_ENCRYPTION_KEY), entered by the owner
 *                   on modules/mail/ai_settings.php; read back only by key() for Gemini's request header. Never
 *                   shown again (masked() gives \Crypto::mask()), never logged, never sent to a page.
 *   ai.model        the Gemini model (gemini-…), else ai_config.php's default_model
 *   ai.usage.<id>   a person's uses in the last hour, for Limits (times only, nothing from the mail)
 *
 * Without a key Praxis AI is off: the mounts print nothing and the endpoints say so. Only admins (People::isAdmin,
 * as for shared mailboxes) change these. bureau's \AiSettings is the Help Centre's DeepSeek row and is not used for
 * mail (mail goes to Gemini only); its "leave blank to keep" rule is kept here.
 */
final class Settings
{
    public const KEY = 'ai.gemini_key';
    public const MODEL = 'ai.model';

    private static ?array $config = null;

    /** ai_config.php by dotted key */
    public static function config(string $key, mixed $default = null): mixed
    {
        self::$config ??= require __DIR__ . '/ai_config.php';
        $node = self::$config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }

        return $node;
    }

    /** Tests only */
    public static function setConfig(string $key, mixed $value): void
    {
        self::$config ??= require __DIR__ . '/ai_config.php';
        $node = &self::$config;
        foreach (explode('.', $key) as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }
        $node = $value;
    }

    /** Who changes the key and the model: admins for Mail */
    public static function canManage(?int $userId): bool
    {
        return MailAccess::canAddShared($userId);
    }

    public static function configured(): bool
    {
        return self::key() !== '';
    }

    /** The decrypted key, '' when none (or it can't be decrypted). Server side only: Gemini's request header. */
    public static function key(): string
    {
        try {
            $stored = MailSettings::get(self::KEY);
        } catch (Throwable $e) {
            error_log('mail/ai: settings not readable: ' . $e->getMessage());

            return '';
        }

        return is_string($stored) && $stored !== '' ? (string) (\Crypto::decrypt($stored) ?? '') : '';
    }

    public static function model(): string
    {
        $model = MailSettings::get(self::MODEL);

        return is_string($model) && self::validModel($model) ? $model : (string) self::config('default_model');
    }

    /** What the settings screen shows: never the key itself */
    public static function masked(): array
    {
        $key = self::key();
        $meta = Db::one('SELECT updated_by, updated_at FROM mail_settings WHERE setting_key = ?', [self::KEY]);
        $stored = MailSettings::get(self::KEY);

        return [
            'configured' => $key !== '',
            // a key is stored but can't be read back (APP_ENCRYPTION_KEY changed): ask for it again
            'unreadable' => $key === '' && is_string($stored) && $stored !== '',
            'key_masked' => \Crypto::mask($key),
            'model' => self::model(),
            'default_model' => (string) self::config('default_model'),
            'updated_by' => $key !== '' && $meta && $meta['updated_by'] ? People::name((int) $meta['updated_by']) : null,
            'updated_ago' => $key !== '' && $meta ? View::ago($meta['updated_at']) : null,
            'encryption_ready' => self::encryptionReady(),
        ];
    }

    /**
     * Save what the owner typed. A blank key keeps the stored one ("leave blank to keep"), so saving the model
     * alone never replaces the key. @throws MailError on the field to correct
     */
    public static function save(int $userId, ?string $key, ?string $model): void
    {
        if (!self::canManage($userId)) {
            throw MailError::denied('Seuls les administrateurs peuvent régler Praxis AI.');
        }
        $key = trim((string) $key);
        $model = trim((string) $model);
        if ($key !== '' && !preg_match('/^[\x21-\x7E]{20,200}$/D', $key)) {
            throw MailError::field('gemini_key', 'Cette clé ne ressemble pas à une clé Gemini. Copiez-la de nouveau depuis Google AI Studio, sans espace.');
        }
        if ($model !== '' && !self::validModel($model)) {
            throw MailError::field('model', 'Saisissez un modèle Gemini, par exemple ' . self::config('default_model') . '.');
        }
        if ($key !== '') {
            try {
                $encrypted = \Crypto::encrypt($key);
            } catch (Throwable $e) {
                error_log('mail/ai: the key could not be encrypted: ' . $e->getMessage());
                throw MailError::field('gemini_key', 'bureau ne peut pas encore enregistrer de clé en sécurité (clé de chiffrement du serveur absente). Prévenez l’administrateur du serveur.');
            }
            MailSettings::set(self::KEY, $encrypted, $userId);
        }
        if ($model !== '') {
            MailSettings::set(self::MODEL, $model, $userId);
        }
    }

    public static function removeKey(int $userId): void
    {
        if (!self::canManage($userId)) {
            throw MailError::denied('Seuls les administrateurs peuvent régler Praxis AI.');
        }
        MailSettings::forget(self::KEY);
    }

    public static function validModel(string $model): bool
    {
        // Gemini only: the model name also goes into the request's URL, so nothing else gets through
        return (bool) preg_match('/^gemini-[a-z0-9][a-z0-9.\-]{1,60}$/D', $model);
    }

    public static function encryptionReady(): bool
    {
        try {
            return \Crypto::decrypt(\Crypto::encrypt('ok')) === 'ok';
        } catch (Throwable) {
            return false;
        }
    }

    public static function flush(): void
    {
        MailSettings::flush();
    }

    /** "le 2 nov. 2026 à 14:05", without intl */
    public static function dateLabel(?string $datetime, bool $withTime = true): string
    {
        $d = Clock::parse($datetime);
        if (!$d) {
            return '';
        }
        $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
        $out = (int) $d->format('j') . ' ' . $months[(int) $d->format('n') - 1] . ' ' . $d->format('Y');

        return $withTime ? $out . ' à ' . $d->format('H:i') : $out;
    }

    /** "lundi 2 novembre 2026", for prompts */
    public static function longDate(\DateTimeInterface $d): string
    {
        $days = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

        return $days[(int) $d->format('w')] . ' ' . (int) $d->format('j') . ' ' . $months[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
    }
}
