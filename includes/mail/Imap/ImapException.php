<?php

namespace LPC\Mail\Imap;

use RuntimeException;

/**
 * A mail server problem. $reason says what kind, so screens can say something useful:
 *   unreachable  the server could not be reached (name, port, firewall)
 *   tls          the secure connection failed (certificate, wrong security setting)
 *   auth         the address or password was refused
 *   timeout      the server stopped answering
 *   protocol     the server answered something we could not read
 *   command      the server refused a command (NO/BAD); $serverText has its words
 * Messages are in French (people see them) and never contain the password.
 */
class ImapException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'command',
        public readonly ?string $serverText = null,
    ) {
        parent::__construct($message);
    }

    public static function unreachable(string $host, int $port, string $detail): self
    {
        return new self("Impossible de joindre {$host}:{$port} ({$detail}).", 'unreachable');
    }

    public static function tls(string $host, string $detail = ''): self
    {
        return new self("La connexion sécurisée à {$host} a échoué" . ($detail !== '' ? " ({$detail})" : '') . '.', 'tls');
    }

    public static function auth(?string $serverText = null): self
    {
        return new self("Le serveur de messagerie a refusé l'adresse ou le mot de passe.", 'auth', $serverText);
    }

    public static function timeout(string $host): self
    {
        return new self("{$host} ne répond plus.", 'timeout');
    }

    public static function protocol(string $detail): self
    {
        return new self("Réponse inattendue du serveur de messagerie : {$detail}", 'protocol');
    }

    public static function command(string $command, string $status, string $serverText): self
    {
        return new self("Le serveur de messagerie a répondu {$status} à {$command} : {$serverText}", 'command', $serverText);
    }
}
