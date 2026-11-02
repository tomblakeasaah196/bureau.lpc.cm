<?php

namespace LPC\Mail;

use RuntimeException;

/**
 * SMTP said no. $reason: auth (address or password refused), unreachable, rejected (the server refused the
 * message or a recipient; $permanent when it will not change on retry), temporary (try again later).
 * Messages are in French and never contain the password.
 */
class MailSendException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'rejected',
        public readonly bool $permanent = false,
        public readonly ?int $smtpCode = null,
    ) {
        parent::__construct($message);
    }

    public static function unreachable(string $host, int $port, string $detail): self
    {
        return new self("Impossible de joindre le serveur d'envoi {$host}:{$port} ({$detail}).", 'unreachable', false);
    }

    /** From an SMTP reply code and its text */
    public static function fromReply(int $code, string $text, string $during): self
    {
        $text = self::clean($text);
        if ($code === 535 || $code === 534 || ($during === 'AUTH' && $code >= 500)) {
            return new self("Le serveur d'envoi a refusé l'adresse ou le mot de passe.", 'auth', true, $code);
        }
        if ($code >= 500) {
            return new self("Le serveur d'envoi a refusé le message ({$during}) : {$text}", 'rejected', true, $code);
        }
        if ($code >= 400) {
            return new self("Le serveur d'envoi demande de réessayer plus tard ({$during}) : {$text}", 'temporary', false, $code);
        }

        return new self("Réponse inattendue du serveur d'envoi ({$during}) : {$text}", 'temporary', false, $code ?: null);
    }

    public static function connection(string $detail): self
    {
        return new self("La connexion au serveur d'envoi a été coupée ({$detail}).", 'temporary', false);
    }

    private static function clean(string $text): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 300);
    }
}
