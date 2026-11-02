<?php

namespace LPC\Mail\Imap;

/**
 * The IMAP4rev1 wire protocol over a TLS socket (RFC 3501, plus LITERAL+, SASL-IR, CONDSTORE, MOVE and
 * UIDPLUS where the server has them). Written by hand because the server has neither the imap extension
 * nor Composer. SocketImapSession builds the commands bureau needs on top of this.
 *
 * Every command waits for its tagged answer; a NO or BAD answer throws ImapException. Nothing here logs
 * or keeps the password.
 */
final class ImapClient
{
    /** @var resource|null */
    private $stream = null;

    private int $tag = 0;

    /** @var list<string> upper case */
    private array $capabilities = [];

    private bool $authenticated = false;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption = 'ssl',
        private readonly int $timeout = 20,
        private readonly bool $verifyTls = true,
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
            'allow_self_signed' => ! $this->verifyTls,
            'SNI_enabled' => true,
            'peer_name' => $this->host,
        ]]);

        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client("{$scheme}://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (! $stream) {
            $detail = trim($errstr) !== '' ? trim($errstr) : "erreur {$errno}";
            // PHP's warning names the scheme ("ssl://host:993"): only a certificate or handshake problem is a TLS failure
            $why = str_ireplace(['ssl://', 'tcp://'], '', $detail . ' ' . (error_get_last()['message'] ?? ''));
            if ($this->encryption === 'ssl' && preg_match('/certificate|crypto|handshake|SSL operation|SSL routines|\bTLS\b/i', $why)) {
                throw ImapException::tls($this->host, $detail);
            }
            throw ImapException::unreachable($this->host, $this->port, $detail);
        }
        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;

        [$greeting] = $this->readResponse();
        if (preg_match('/^\* BYE/i', $greeting)) {
            throw ImapException::protocol('le serveur a fermé la connexion : ' . substr($greeting, 6));
        }
        if (! preg_match('/^\* (OK|PREAUTH)/i', $greeting)) {
            throw ImapException::protocol(substr($greeting, 0, 120));
        }
        $this->authenticated = (bool) preg_match('/^\* PREAUTH/i', $greeting);
        $this->capabilitiesFrom($greeting);

        if ($this->encryption === 'starttls') {
            if (! $this->capabilities) {
                $this->refreshCapabilities();
            }
            if (! $this->has('STARTTLS')) {
                throw ImapException::tls($this->host, 'le serveur ne propose pas STARTTLS');
            }
            $this->command('STARTTLS');
            if (! @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw ImapException::tls($this->host, 'échec de STARTTLS');
            }
            $this->capabilities = [];
        }

        if (! $this->capabilities) {
            $this->refreshCapabilities();
        }
    }

    public function login(string $username, string $password): void
    {
        if ($this->authenticated) {
            return;
        }

        try {
            if ($this->has('AUTH=PLAIN') && $this->has('SASL-IR')) {
                $result = $this->command('AUTHENTICATE PLAIN ' . base64_encode("\0{$username}\0{$password}"), 'AUTHENTICATE');
            } else {
                $result = $this->commandParts(['LOGIN ', self::astring($username), ' ', self::astring($password)], 'LOGIN');
            }
        } catch (ImapException $e) {
            if ($e->reason === 'command') {
                throw ImapException::auth($e->serverText);
            }
            throw $e;
        }

        $this->authenticated = true;
        // Servers list more capabilities once signed in (Dovecot puts them in the OK)
        $this->capabilities = [];
        $this->capabilitiesFrom($result['text']);
        if (! $this->capabilities) {
            $this->refreshCapabilities();
        }
    }

    /** @return list<string> upper case */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function has(string $capability): bool
    {
        return in_array(strtoupper($capability), $this->capabilities, true);
    }

    /**
     * Send one command and wait for its tagged answer.
     *
     * @return array{status: string, code: ?string, args: string, text: string, untagged: list<array>}
     */
    public function command(string $command, ?string $label = null): array
    {
        return $this->commandParts([$command], $label);
    }

    /**
     * A command with literals: $parts alternates text and ['literal' => bytes]. With LITERAL+ the bytes follow
     * at once; otherwise each literal waits for the server's "+" go-ahead.
     */
    public function commandParts(array $parts, ?string $label = null): array
    {
        $this->ensureOpen();
        $tag = 'A' . str_pad((string) ++$this->tag, 4, '0', STR_PAD_LEFT);
        $label ??= strtoupper(strtok(is_string($parts[0]) ? $parts[0] : 'COMMAND', ' '));

        $buffer = $tag . ' ';
        foreach ($parts as $part) {
            if (is_array($part)) {
                $bytes = (string) $part['literal'];
                if ($this->has('LITERAL+')) {
                    $buffer .= '{' . strlen($bytes) . "+}\r\n" . $bytes;
                    continue;
                }
                $this->write($buffer . '{' . strlen($bytes) . "}\r\n");
                $buffer = '';
                $this->awaitContinuation($tag, $label);
                $buffer = $bytes;
                continue;
            }
            $buffer .= $part;
        }
        $this->write($buffer . "\r\n");

        $untagged = [];
        while (true) {
            [$text, $literals] = $this->readResponse();

            if (str_starts_with($text, '* ')) {
                $untagged[] = ResponseParser::untagged(substr($text, 2), $literals);
                continue;
            }
            if (str_starts_with($text, '+')) {
                throw ImapException::protocol("suite inattendue après {$label}");
            }
            if (str_starts_with($text, $tag . ' ')) {
                $rest = substr($text, strlen($tag) + 1);
                $status = strtoupper(strtok($rest, ' '));
                $info = ResponseParser::statusText((string) substr($rest, strlen($status) + 1));
                if ($status !== 'OK') {
                    throw ImapException::command($label, $status, trim(($info['code'] ? "[{$info['code']}] " : '') . $info['text']));
                }

                return ['status' => $status, 'code' => $info['code'], 'args' => $info['args'], 'text' => $rest, 'untagged' => $untagged];
            }
            // Anything else (another tag) is not ours: ignore it
        }
    }

    public function logout(): void
    {
        if ($this->stream) {
            try {
                $this->command('LOGOUT');
            } catch (ImapException) {
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
        $this->authenticated = false;
    }

    /** A string argument: an atom-safe quoted string, or a literal for anything with CR, LF, quotes or 8-bit. */
    public static function astring(string $value): string|array
    {
        if ($value !== '' && preg_match('/^[\x20-\x7E]*$/', $value) && strlen($value) < 1000) {
            return '"' . addcslashes($value, '"\\') . '"';
        }
        if ($value === '') {
            return '""';
        }

        return ['literal' => $value];
    }

    /** UIDs as a compact IMAP set: [1,2,3,7,9,10] → "1:3,7,9:10" */
    public static function uidSet(array $uids): string
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        sort($uids);
        $ranges = [];
        $start = $prev = null;
        foreach ($uids as $uid) {
            if ($start === null) {
                $start = $prev = $uid;
                continue;
            }
            if ($uid === $prev + 1) {
                $prev = $uid;
                continue;
            }
            $ranges[] = $start === $prev ? (string) $start : "{$start}:{$prev}";
            $start = $prev = $uid;
        }
        if ($start !== null) {
            $ranges[] = $start === $prev ? (string) $start : "{$start}:{$prev}";
        }

        return implode(',', $ranges);
    }

    private function refreshCapabilities(): void
    {
        foreach ($this->command('CAPABILITY')['untagged'] as $response) {
            if ($response['type'] === 'CAPABILITY') {
                $this->capabilities = $response['data'];
            }
        }
    }

    private function capabilitiesFrom(string $text): void
    {
        if (preg_match('/\[CAPABILITY ([^\]]*)\]/i', $text, $m)) {
            $this->capabilities = array_values(array_filter(explode(' ', strtoupper(trim($m[1])))));
        }
    }

    private function awaitContinuation(string $tag, string $label): void
    {
        while (true) {
            [$text] = $this->readResponse();
            if (str_starts_with($text, '+')) {
                return;
            }
            if (str_starts_with($text, $tag . ' ')) {
                $rest = substr($text, strlen($tag) + 1);
                $status = strtoupper(strtok($rest, ' '));
                throw ImapException::command($label, $status, trim(substr($rest, strlen($status))));
            }
        }
    }

    /**
     * One whole response: its lines joined, each literal cut out into $literals.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function readResponse(): array
    {
        $text = '';
        $literals = [];
        while (true) {
            $line = $this->readLine();
            if (preg_match('/\{(\d+)\+?\}$/', $line, $m)) {
                $text .= substr($line, 0, -strlen($m[0])) . "\0L" . count($literals) . "\0";
                $literals[] = $this->readBytes((int) $m[1]);
                continue;
            }

            return [$text . $line, $literals];
        }
    }

    private function readLine(): string
    {
        $this->ensureOpen();
        $line = fgets($this->stream);
        if ($line === false) {
            $this->failRead();
        }
        while (! str_ends_with($line, "\n")) {
            $more = fgets($this->stream);
            if ($more === false) {
                $this->failRead();
            }
            $line .= $more;
        }

        return rtrim($line, "\r\n");
    }

    private function readBytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->stream, min(65536, $length - strlen($data)));
            if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                $this->failRead();
            }
            if ($chunk === '' && stream_get_meta_data($this->stream)['timed_out']) {
                $this->failRead();
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function write(string $data): void
    {
        $this->ensureOpen();
        $total = strlen($data);
        $written = 0;
        while ($written < $total) {
            $n = @fwrite($this->stream, substr($data, $written));
            if ($n === false || $n === 0) {
                $this->close();
                throw ImapException::protocol('la connexion a été coupée pendant l\'envoi');
            }
            $written += $n;
        }
    }

    private function failRead(): never
    {
        $timedOut = $this->stream && stream_get_meta_data($this->stream)['timed_out'];
        $this->close();
        throw $timedOut ? ImapException::timeout($this->host) : ImapException::protocol('la connexion a été coupée');
    }

    private function ensureOpen(): void
    {
        if (! $this->stream) {
            throw ImapException::protocol('pas de connexion ouverte');
        }
    }
}
