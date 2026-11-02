<?php

namespace LPC\Mail;

use LPC\Mail\Imap\ImapException;

/**
 * Mailbox passwords, encrypted at rest in mail_accounts.secret with the app's Crypto class (AES-256-GCM,
 * key APP_ENCRYPTION_KEY in .env; the same key as AiSettings). Only MailServer reads them back.
 * Never put a password in a log, an exception, a JSON answer, a notification or the page.
 */
final class MailCredentials
{
    /** @throws MailError when the key is missing (the person can't fix it: an admin must set APP_ENCRYPTION_KEY) */
    public static function encrypt(string $password): string
    {
        try {
            return \Crypto::encrypt($password);
        } catch (\Throwable $e) {
            error_log('Mail: passwords cannot be encrypted: ' . $e->getMessage());
            throw MailError::invalid("Les mots de passe ne peuvent pas encore être enregistrés en sécurité sur ce serveur (clé de chiffrement absente). Prévenez l'administrateur.");
        }
    }

    public static function password(array $accountWithSecret): string
    {
        $plain = \Crypto::decrypt((string) ($accountWithSecret['secret'] ?? ''));
        if ($plain === null) {
            // The key changed or the value is damaged: the person has to enter the password again
            throw new ImapException('Le mot de passe enregistré ne peut plus être lu. Saisissez-le à nouveau.', 'auth');
        }

        return $plain;
    }
}
