<?php

namespace LPC\Mail;

use RuntimeException;

/**
 * Something the person can fix or must be told, in plain French: a field to correct (422), no access (403),
 * not found (404), too large (413). Pages and api/v1/mail/* show getMessage() as it is and use $field to put
 * it next to the right input. Never carries a password.
 *
 *   throw MailError::field('password', 'Le serveur de messagerie a refusé ce mot de passe.');
 *   throw MailError::denied();
 */
class MailError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $field = null,
        public readonly int $status = 422,
    ) {
        parent::__construct($message, $status);
    }

    public static function field(string $field, string $message): self
    {
        return new self($message, $field, 422);
    }

    public static function invalid(string $message): self
    {
        return new self($message, null, 422);
    }

    public static function denied(string $message = "Vous n'avez pas accès à cette boîte mail."): self
    {
        return new self($message, null, 403);
    }

    public static function notFound(string $message = 'Introuvable : la boîte mail ou le message a peut-être été retiré.'): self
    {
        return new self($message, null, 404);
    }

    public static function tooLarge(string $message): self
    {
        return new self($message, null, 413);
    }
}
