<?php

namespace LPC\Mail\Smtp;

use LPC\Mail\MailSendException;

/**
 * SMTP (RFC 5321) over a TLS socket: implicit TLS (port 465, cPanel's setting for lpc.cm), STARTTLS (587) or
 * plain (tests only), AUTH PLAIN or LOGIN, then MAIL FROM / RCPT TO / DATA. Written by hand because bureau
 * has no mail library in vendor/. LPC\Mail\MailServer is the only caller.
 *
 * Every reply that is not the expected one throws MailSendException with the SMTP code. Nothing here logs or
 * keeps the password.
 */
final class SmtpClient
{
    /** @var resource|null */
    private $stream = null;

    /** @var array<string, string> EHLO keywords (upper case) => their parameters */
    private array $extensions = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption = 'ssl',
        private readonly int $timeout = 20,
        private readonly bool $verifyTls = true,
        private readonly string $localDomain = 'bureau.lpc.cm',
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function connect(): void
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $this->verifyTls,
            'verify_peer_name' => $this->verifyTls,
            'allow_self_signed' => !$this->verifyTls,
            'SNI_enabled' => true,
            'peer_name' => $this->host,
        ]]);
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client("{$scheme}://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$stream) {
            throw MailSendException::unreachable($this->host, $this->port, trim($errstr) !== '' ? trim($errstr) : "erreur {$errno}");
        }
        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;

        $this->expect([220], 'CONNEXION');
        $this->ehlo();

        if ($this->encryption === 'starttls') {
            if (!isset($this->extensions['STARTTLS'])) {
                throw new MailSendException("Le serveur d'envoi ne propose pas de connexion sécurisée (STARTTLS).", 'unreachable', true);
            }
            $this->command('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new MailSendException("La connexion sécurisée au serveur d'envoi a échoué (STARTTLS).", 'unreachable', true);
            }
            $this->ehlo();
        }
    }

    public function login(string $username, string $password): void
    {
        $auth = strtoupper($this->extensions['AUTH'] ?? '');
        if (preg_match('/\bPLAIN\b/', $auth) || $auth === '') {
            $this->command('AUTH PLAIN ' . base64_encode("\0{$username}\0{$password}"), [235], 'AUTH');

            return;
        }
        if (preg_match('/\bLOGIN\b/', $auth)) {
            $this->command('AUTH LOGIN', [334], 'AUTH');
            $this->command(base64_encode($username), [334], 'AUTH');
            $this->command(base64_encode($password), [235], 'AUTH');

            return;
        }
        throw new MailSendException("Le serveur d'envoi ne propose aucune méthode de connexion connue.", 'auth', true);
    }

    /**
     * Send one message. $raw is the message as built (CRLF); dot-stuffing is done here.
     *
     * @param list<string> $recipients envelope addresses (To, Cc and Bcc)
     */
    public function send(string $from, array $recipients, string $raw): void
    {
        if (!$recipients) {
            throw new MailSendException("Le message n'a aucun destinataire.", 'rejected', true);
        }
        $this->command('MAIL FROM:<' . self::clean($from) . '>' . (isset($this->extensions['SMTPUTF8']) && preg_match('/[^\x20-\x7E]/', $from) ? ' SMTPUTF8' : ''), [250], 'MAIL FROM');
        foreach ($recipients as $to) {
            $this->command('RCPT TO:<' . self::clean($to) . '>', [250, 251], 'RCPT TO ' . self::clean($to));
        }
        $this->command('DATA', [354], 'DATA');

        $raw = preg_replace("/\r\n|\r|\n/", "\r\n", $raw);
        $raw = preg_replace('/^\./m', '..', $raw);
        if (!str_ends_with($raw, "\r\n")) {
            $raw .= "\r\n";
        }
        $this->write($raw . ".\r\n");
        $this->expect([250], 'DATA');
    }

    public function quit(): void
    {
        if ($this->stream) {
            try {
                $this->write("QUIT\r\n");
                $this->read();
            } catch (MailSendException) {
                // already gone
            }
        }
        $this->close();
    }

    public function close(): void
    {
        if ($this->stream) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }

    /** @return array<string, string> */
    public function extensions(): array
    {
        return $this->extensions;
    }

    private function ehlo(): void
    {
        [, $lines] = $this->command('EHLO ' . $this->localDomain, [250], 'EHLO');
        $this->extensions = [];
        foreach (array_slice($lines, 1) as $line) {
            $parts = explode(' ', trim($line), 2);
            $this->extensions[strtoupper($parts[0])] = $parts[1] ?? '';
        }
    }

    /** @return array{0: int, 1: list<string>} */
    private function command(string $line, array $ok, ?string $label = null): array
    {
        $this->write($line . "\r\n");

        return $this->expect($ok, $label ?? strtoupper(strtok($line, ' :')));
    }

    /** @return array{0: int, 1: list<string>} */
    private function expect(array $ok, string $during): array
    {
        [$code, $lines] = $this->read();
        if (!in_array($code, $ok, true)) {
            throw MailSendException::fromReply($code, implode(' ', $lines), $during);
        }

        return [$code, $lines];
    }

    /** One reply, possibly on several lines ("250-…" then "250 …"). @return array{0: int, 1: list<string>} */
    private function read(): array
    {
        $lines = [];
        $code = 0;
        while (true) {
            if (!$this->stream) {
                throw MailSendException::connection('pas de connexion ouverte');
            }
            $line = fgets($this->stream, 4096);
            if ($line === false) {
                $timedOut = stream_get_meta_data($this->stream)['timed_out'] ?? false;
                $this->close();
                throw MailSendException::connection($timedOut ? 'le serveur ne répond plus' : 'connexion fermée');
            }
            $line = rtrim($line, "\r\n");
            $code = (int) substr($line, 0, 3);
            $lines[] = (string) substr($line, 4);
            if (strlen($line) < 4 || $line[3] !== '-') {
                return [$code, $lines];
            }
        }
    }

    private function write(string $data): void
    {
        if (!$this->stream) {
            throw MailSendException::connection('pas de connexion ouverte');
        }
        $total = strlen($data);
        $written = 0;
        while ($written < $total) {
            $n = @fwrite($this->stream, substr($data, $written));
            if ($n === false || $n === 0) {
                $this->close();
                throw MailSendException::connection("pendant l'envoi");
            }
            $written += $n;
        }
    }

    private static function clean(string $address): string
    {
        return str_replace(["\r", "\n", '<', '>', ' '], '', $address);
    }
}
