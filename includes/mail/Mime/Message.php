<?php

namespace LPC\Mail\Mime;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * An outgoing message, built into RFC 5322 / MIME text (CRLF line ends). bureau has no Symfony Mailer, so
 * this is what PR 3's composer fills and MailTransport::send() sends:
 *
 *   $m = (new Message())
 *       ->to('Ada <ada@client.cm>')->cc([['name' => 'Bob', 'email' => 'bob@x.cm']])->bcc('audit@lpc.cm')
 *       ->subject('Devis n° 12')->text("Bonjour…")->html('<p>Bonjour…</p>')
 *       ->attach($pdfBytes, 'devis.pdf', 'application/pdf')
 *       ->embed($pngBytes, 'logo.png', 'image/png', 'logo@lpc')   // <img src="cid:logo@lpc">
 *       ->header('In-Reply-To', '<abc@client.cm>')->header('References', '<abc@client.cm>');
 *
 * From is set by MailTransport (always the mailbox, owner decision). Bcc travels in the envelope only:
 * toString() never writes a Bcc header; recipients() lists every envelope address. Text parts are UTF-8,
 * quoted-printable; files are base64. Header words outside ASCII are RFC 2047 encoded, file names RFC 2231.
 */
final class Message
{
    /** @var list<array{name: ?string, email: string}> */
    private array $to = [];
    private array $cc = [];
    private array $bcc = [];
    private array $replyTo = [];
    private ?array $from = null;
    private string $subject = '';
    private ?string $text = null;
    private ?string $html = null;
    /** @var list<array{content: string, filename: string, mime: string, cid: ?string}> */
    private array $attachments = [];
    /** @var array<string, string> extra headers by name (In-Reply-To, References, Auto-Submitted, …) */
    private array $headers = [];
    private ?string $messageId = null;
    private ?DateTimeInterface $date = null;

    public function from(string $email, ?string $name = null): self
    {
        $this->from = self::address($email, $name);

        return $this;
    }

    /** @param string|array $addresses "Ada <a@b.cm>, c@d.cm", one ['name' =>, 'email' =>], or a list of either */
    public function to(string|array $addresses): self
    {
        $this->to = array_merge($this->to, self::addresses($addresses));

        return $this;
    }

    public function cc(string|array $addresses): self
    {
        $this->cc = array_merge($this->cc, self::addresses($addresses));

        return $this;
    }

    public function bcc(string|array $addresses): self
    {
        $this->bcc = array_merge($this->bcc, self::addresses($addresses));

        return $this;
    }

    public function replyTo(string|array $addresses): self
    {
        $this->replyTo = array_merge($this->replyTo, self::addresses($addresses));

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = trim(str_replace(["\r", "\n"], ' ', $subject));

        return $this;
    }

    public function text(?string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function html(?string $html): self
    {
        $this->html = $html;

        return $this;
    }

    public function attach(string $content, string $filename, string $mime = 'application/octet-stream'): self
    {
        $this->attachments[] = ['content' => $content, 'filename' => self::safeName($filename), 'mime' => self::safeMime($mime), 'cid' => null];

        return $this;
    }

    /** A picture shown inside the HTML as <img src="cid:$cid"> */
    public function embed(string $content, string $filename, string $mime, string $cid): self
    {
        $cid = trim($cid, " <>\t");
        if ($cid === '' || preg_match('/[\s<>"]/', $cid)) {
            throw new InvalidArgumentException('A Content-ID needs letters, digits and @ only.');
        }
        $this->attachments[] = ['content' => $content, 'filename' => self::safeName($filename), 'mime' => self::safeMime($mime), 'cid' => $cid];

        return $this;
    }

    /** Any other header. Message-ID, Date, From, To, Cc, Bcc, Subject and the MIME headers have their own methods. */
    public function header(string $name, string $value): self
    {
        $name = trim($name);
        if (!preg_match('/^[A-Za-z0-9-]+$/', $name)) {
            throw new InvalidArgumentException('Bad header name.');
        }
        if (in_array(strtolower($name), ['from', 'to', 'cc', 'bcc', 'subject', 'date', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding'], true)) {
            throw new InvalidArgumentException("Set {$name} with its own method.");
        }
        $this->headers[$name] = trim(str_replace(["\r", "\n"], ' ', $value));

        return $this;
    }

    /** Without angle brackets */
    public function messageId(string $id): self
    {
        $this->messageId = trim($id, " <>\t");

        return $this;
    }

    public function date(DateTimeInterface $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getFrom(): ?array
    {
        return $this->from;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }

        return null;
    }

    /** @return array{to: list<array>, cc: list<array>, bcc: list<array>, reply_to: list<array>} */
    public function getAddresses(): array
    {
        return ['to' => $this->to, 'cc' => $this->cc, 'bcc' => $this->bcc, 'reply_to' => $this->replyTo];
    }

    /** @return list<string> every envelope recipient (To, Cc and Bcc), lower case, once each */
    public function recipients(): array
    {
        $out = [];
        foreach (array_merge($this->to, $this->cc, $this->bcc) as $a) {
            $out[strtolower($a['email'])] = true;
        }

        return array_keys($out);
    }

    /** The whole message as sent, without Bcc. Needs From (MailTransport sets it) and at least one recipient. */
    public function toString(): string
    {
        if ($this->from === null) {
            throw new InvalidArgumentException('From is not set.');
        }
        if (!$this->recipients()) {
            throw new InvalidArgumentException('The message has no recipient.');
        }

        $lines = [];
        $lines[] = 'Date: ' . ($this->date ?? new DateTimeImmutable())->format('D, d M Y H:i:s O');
        $lines[] = self::fold('From: ' . self::formatAddress($this->from));
        if ($this->to) {
            $lines[] = self::fold('To: ' . implode(', ', array_map([self::class, 'formatAddress'], $this->to)));
        }
        if ($this->cc) {
            $lines[] = self::fold('Cc: ' . implode(', ', array_map([self::class, 'formatAddress'], $this->cc)));
        }
        if ($this->replyTo) {
            $lines[] = self::fold('Reply-To: ' . implode(', ', array_map([self::class, 'formatAddress'], $this->replyTo)));
        }
        $lines[] = 'Subject: ' . self::encodeHeader($this->subject);
        if ($this->messageId !== null) {
            $lines[] = 'Message-ID: <' . $this->messageId . '>';
        }
        foreach ($this->headers as $name => $value) {
            $lines[] = self::fold($name . ': ' . (preg_match('/[^\x20-\x7E]/', $value) ? self::encodeHeader($value) : $value));
        }
        $lines[] = 'MIME-Version: 1.0';

        [$head, $body] = $this->bodyEntity();

        return implode("\r\n", $lines) . "\r\n" . $head . "\r\n\r\n" . $body;
    }

    // ------------------------------------------------------------------ MIME structure

    /** @return array{0: string, 1: string} the top entity's Content-* headers and its body */
    private function bodyEntity(): array
    {
        $inline = array_values(array_filter($this->attachments, fn ($a) => $a['cid'] !== null));
        $files = array_values(array_filter($this->attachments, fn ($a) => $a['cid'] === null));

        $main = $this->alternative();
        if ($inline && $this->html !== null) {
            $parts = [$main];
            foreach ($inline as $a) {
                $parts[] = self::filePart($a, true);
            }
            $main = self::multipart('related', $parts, 'type="' . ($this->text !== null ? 'multipart/alternative' : 'text/html') . '"');
        } elseif ($inline) {
            $files = array_merge($files, $inline); // no HTML to show them in: plain attachments
        }

        if ($files) {
            $parts = [$main];
            foreach ($files as $a) {
                $parts[] = self::filePart($a, false);
            }
            $main = self::multipart('mixed', $parts);
        }

        return $main;
    }

    private function alternative(): array
    {
        $text = $this->text;
        if ($text === null && $this->html === null) {
            $text = '';
        }
        if ($text !== null && $this->html !== null) {
            return self::multipart('alternative', [self::textPart('plain', $text), self::textPart('html', $this->html)]);
        }

        return $this->html !== null ? self::textPart('html', $this->html) : self::textPart('plain', (string) $text);
    }

    private static function textPart(string $subtype, string $content): array
    {
        $content = preg_replace("/\r\n|\r|\n/", "\r\n", $content);

        return [
            "Content-Type: text/{$subtype}; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable",
            quoted_printable_encode($content),
        ];
    }

    private static function filePart(array $a, bool $inline): array
    {
        $name = $a['filename'];
        $head = 'Content-Type: ' . $a['mime'] . '; ' . self::nameParam('name', $name) . "\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . 'Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; ' . self::nameParam('filename', $name);
        if ($inline) {
            $head .= "\r\nContent-ID: <" . $a['cid'] . '>';
        }

        return [$head, rtrim(chunk_split(base64_encode($a['content']), 76, "\r\n"), "\r\n")];
    }

    /** @param list<array{0: string, 1: string}> $parts */
    private static function multipart(string $subtype, array $parts, string $extra = ''): array
    {
        $boundary = '=_lpc_' . bin2hex(random_bytes(12));
        $body = '';
        foreach ($parts as [$head, $content]) {
            $body .= '--' . $boundary . "\r\n" . $head . "\r\n\r\n" . $content . "\r\n";
        }
        $body .= '--' . $boundary . '--';

        return ["Content-Type: multipart/{$subtype}; boundary=\"{$boundary}\"" . ($extra !== '' ? '; ' . $extra : ''), $body];
    }

    // ------------------------------------------------------------------ headers

    /** RFC 2047 encoded words (UTF-8, base64), split on character boundaries so no line runs long */
    public static function encodeHeader(string $value): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $value) && strlen($value) <= 900) {
            return $value;
        }
        $words = [];
        $chunk = '';
        foreach (mb_str_split($value, 1, 'UTF-8') as $char) {
            if (strlen($chunk . $char) > 42) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $char;
        }
        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }

        return implode("\r\n ", $words);
    }

    public static function formatAddress(array $a): string
    {
        $name = trim((string) ($a['name'] ?? ''));
        if ($name === '') {
            return $a['email'];
        }
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            $name = self::encodeHeader($name);
        } elseif (preg_match('/[()<>\[\]:;@\\\\,."]/', $name)) {
            $name = '"' . addcslashes($name, '"\\') . '"';
        }

        return $name . ' <' . $a['email'] . '>';
    }

    /** Fold a long header at commas and spaces (encoded words carry their own breaks) */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 78 || str_contains($line, "\r\n")) {
            return $line;
        }
        $out = '';
        $current = '';
        foreach (explode(' ', $line) as $piece) {
            if ($current !== '' && strlen($current) + strlen($piece) + 1 > 76) {
                $out .= rtrim($current) . "\r\n ";
                $current = '';
            }
            $current .= ($current === '' ? '' : ' ') . $piece;
        }

        return $out . $current;
    }

    private static function nameParam(string $param, string $name): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $name)) {
            return $param . '="' . addcslashes($name, '"\\') . '"';
        }
        // RFC 2231 for readers that know it, an encoded word in the plain form for the rest
        $plain = str_replace(["\r", "\n"], '', self::encodeHeader($name));

        return $param . "*=utf-8''" . rawurlencode($name) . '; ' . $param . '="' . $plain . '"';
    }

    /** @return list<array{name: ?string, email: string}> */
    private static function addresses(string|array $input): array
    {
        if (is_string($input)) {
            $list = Headers::addresses($input);
            if (!$list && trim($input) !== '') {
                throw new InvalidArgumentException('Adresse invalide : ' . mb_substr($input, 0, 120));
            }
        } elseif (isset($input['email'])) {
            $list = [$input];
        } else {
            $list = [];
            foreach ($input as $item) {
                $list = array_merge($list, is_string($item) ? Headers::addresses($item) : [$item]);
            }
        }

        return array_map(fn ($a) => self::address((string) ($a['email'] ?? ''), $a['name'] ?? null), $list);
    }

    private static function address(string $email, ?string $name): array
    {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Adresse invalide : ' . mb_substr($email, 0, 120));
        }
        $name = $name !== null ? trim(str_replace(["\r", "\n", "\0"], ' ', $name)) : null;

        return ['name' => $name !== '' ? $name : null, 'email' => $email];
    }

    private static function safeName(string $name): string
    {
        $name = trim(str_replace(["\0", "\r", "\n", '/', '\\', '"'], ['', '', '', '_', '_', "'"], $name));

        return $name !== '' ? mb_substr($name, 0, 200) : 'piece-jointe';
    }

    private static function safeMime(string $mime): string
    {
        $mime = strtolower(trim($mime));

        return preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime) ? $mime : 'application/octet-stream';
    }
}
