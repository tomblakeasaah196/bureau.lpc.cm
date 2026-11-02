<?php

namespace LPC\Mail\Tests;

use DateTimeImmutable;
use LPC\Mail\Imap\ImapException;
use LPC\Mail\Imap\ImapSession;
use LPC\Mail\MailCredentials;
use LPC\Mail\MailSendException;
use LPC\Mail\MailServer;
use LPC\Mail\Mime\Message;
use LPC\Mail\Store;

/**
 * An in-memory mail server for Courrier tests (docs/MAIL.md §9), ported from JBS HQ. Every PR's tests use it:
 *
 *   $server = FakeMailServer::install();                    // MailServer::use($server)
 *   $server->addMailbox('contact@lpc.cm', 'secret');
 *   $uid = $server->deliver('contact@lpc.cm', 'INBOX', FakeMailServer::message(
 *       from: 'Awa <awa@client.cm>', subject: 'Commande', text: 'Bonjour', html: '<p>Bonjour</p>',
 *       attachments: [['name' => 'bon.pdf', 'mime' => 'application/pdf', 'content' => '%PDF…']],
 *   ));
 *   MailSync::make()->account($accountId);
 *   $server->sent;   // [['account' => email, 'message' => Message, 'raw' => string, 'recipients' => list]]
 *
 * Folders by default: INBOX, INBOX.Sent (\Sent), INBOX.Drafts (\Drafts), INBOX.Junk (\Junk), INBOX.Trash (\Trash),
 * as cPanel's Dovecot. Flags, UIDs, UIDVALIDITY, MODSEQ (CONDSTORE), MOVE with COPYUID and APPEND with APPENDUID
 * behave like Dovecot.
 */
class FakeMailServer extends MailServer
{
    /** email => ['password' => string, 'folders' => [path => folder]] */
    public array $mailboxes = [];

    /** Mail sent through SMTP */
    public array $sent = [];

    /** Make the next SMTP send fail: null, 'auth', 'rejected' or 'temporary' */
    public ?string $smtpFailure = null;

    /** Number of IMAP sessions opened (to check that work is batched) */
    public int $sessions = 0;

    public array $capabilities = ['IMAP4REV1', 'LITERAL+', 'SASL-IR', 'AUTH=PLAIN', 'ENABLE', 'CONDSTORE', 'MOVE', 'UIDPLUS', 'SPECIAL-USE', 'IDLE'];

    public static function install(): self
    {
        $server = new self();
        MailServer::use($server);

        return $server;
    }

    public function addMailbox(string $email, string $password, ?array $folders = null): void
    {
        $folders ??= [
            'INBOX' => [],
            'INBOX.Sent' => ['\\Sent'],
            'INBOX.Drafts' => ['\\Drafts'],
            'INBOX.Junk' => ['\\Junk'],
            'INBOX.Trash' => ['\\Trash'],
        ];
        $this->mailboxes[strtolower($email)] = ['password' => $password, 'folders' => []];
        foreach ($folders as $path => $attributes) {
            $this->addFolder($email, $path, $attributes);
        }
    }

    public function addFolder(string $email, string $path, array $attributes = []): void
    {
        $this->mailboxes[strtolower($email)]['folders'][$path] = [
            'attributes' => $attributes, 'uidvalidity' => random_int(1000, 999999), 'uidnext' => 1, 'modseq' => 1, 'messages' => [],
        ];
    }

    public function setPassword(string $email, string $password): void
    {
        $this->mailboxes[strtolower($email)]['password'] = $password;
    }

    /** Put a message in a folder; returns its UID */
    public function deliver(string $email, string $path, array $message, array $flags = []): int
    {
        $folder = &$this->mailboxes[strtolower($email)]['folders'][$path];
        $uid = $folder['uidnext']++;
        $folder['modseq']++;
        $folder['messages'][$uid] = $message + ['flags' => $flags, 'modseq' => $folder['modseq']];
        $folder['messages'][$uid]['flags'] = $flags;
        $folder['messages'][$uid]['modseq'] = $folder['modseq'];

        return $uid;
    }

    public function setFlags(string $email, string $path, int $uid, array $flags): void
    {
        $folder = &$this->mailboxes[strtolower($email)]['folders'][$path];
        $folder['modseq']++;
        $folder['messages'][$uid]['flags'] = $flags;
        $folder['messages'][$uid]['modseq'] = $folder['modseq'];
    }

    public function remove(string $email, string $path, int $uid): void
    {
        $folder = &$this->mailboxes[strtolower($email)]['folders'][$path];
        unset($folder['messages'][$uid]);
        $folder['modseq']++;
    }

    public function renumber(string $email, string $path): void
    {
        $folder = &$this->mailboxes[strtolower($email)]['folders'][$path];
        $folder['uidvalidity']++;
        $messages = array_values($folder['messages']);
        $folder['messages'] = [];
        $folder['uidnext'] = 1;
        foreach ($messages as $m) {
            $folder['messages'][$folder['uidnext']++] = $m;
        }
    }

    /** @return array<int, array> the folder's messages by UID */
    public function folder(string $email, string $path): array
    {
        return $this->mailboxes[strtolower($email)]['folders'][$path]['messages'] ?? [];
    }

    // ------------------------------------------------------------- MailServer

    public function open(array $account): ImapSession
    {
        $full = Store::accountWithSecret((int) $account['id']);

        return $this->connect(self::settingsOf($full), $full['username'], MailCredentials::password($full));
    }

    public function connect(array $settings, string $username, string $password): ImapSession
    {
        $box = $this->mailboxes[strtolower($username)] ?? null;
        if (!$box || !hash_equals($box['password'], $password)) {
            throw ImapException::auth('[AUTHENTICATIONFAILED] Authentication failed.');
        }
        $this->sessions++;

        return new FakeImapSession($this, strtolower($username));
    }

    public function checkSmtp(array $settings, string $username, string $password): void
    {
        $box = $this->mailboxes[strtolower($username)] ?? null;
        if (!$box || !hash_equals($box['password'], $password)) {
            throw new MailSendException("Le serveur d'envoi a refusé l'adresse ou le mot de passe.", 'auth', true, 535);
        }
    }

    public function send(array $account, Message $message): string
    {
        if ($this->smtpFailure) {
            $reason = $this->smtpFailure;
            $this->smtpFailure = null;
            throw new MailSendException("Échec SMTP simulé : {$reason}", $reason, $reason !== 'temporary', $reason === 'temporary' ? 451 : 550);
        }
        $full = Store::accountWithSecret((int) $account['id']);
        $this->checkSmtp(self::settingsOf($full), $full['username'], MailCredentials::password($full));
        $raw = $message->toString();
        $this->sent[] = ['account' => $full['email'], 'message' => $message, 'raw' => $raw, 'recipients' => $message->recipients()];

        return $raw;
    }

    // ------------------------------------------------------------- building messages

    /**
     * A message as the fake server keeps it: headers, BODYSTRUCTURE and the raw sections.
     *
     * @param list<array{name: string, mime: string, content: string, inline?: bool, cid?: string}> $attachments
     */
    public static function message(
        string $from = 'Awa Client <awa@client.test>',
        string $to = 'contact@lpc.cm',
        string $subject = 'Bonjour',
        ?string $text = 'Bonjour à vous.',
        ?string $html = null,
        ?string $messageId = null,
        ?string $inReplyTo = null,
        ?string $references = null,
        ?string $date = null,
        string $cc = '',
        array $attachments = [],
        string $charset = 'utf-8',
    ): array {
        $messageId ??= bin2hex(random_bytes(8)) . '@client.test';
        $date ??= (new DateTimeImmutable())->format('D, d M Y H:i:s O');

        $headers = "From: {$from}\r\nTo: {$to}\r\n" . ($cc !== '' ? "Cc: {$cc}\r\n" : '')
            . "Subject: {$subject}\r\nDate: {$date}\r\nMessage-ID: <{$messageId}>\r\n"
            . ($inReplyTo ? "In-Reply-To: <{$inReplyTo}>\r\n" : '')
            . ($references ? "References: {$references}\r\n" : '') . "\r\n";

        $textPart = fn () => ['text', 'plain', ['charset', $charset], null, null, 'quoted-printable', (string) strlen((string) $text), '1'];
        $htmlPart = fn () => ['text', 'html', ['charset', $charset], null, null, 'quoted-printable', (string) strlen((string) $html), '1'];

        $sections = [];
        if ($html !== null && $text !== null) {
            $body = [$textPart(), $htmlPart(), 'alternative'];
            $prefix = $attachments ? '1.' : '';
            $sections[$prefix . '1'] = quoted_printable_encode($text);
            $sections[$prefix . '2'] = quoted_printable_encode($html);
        } elseif ($html !== null) {
            $body = $htmlPart();
            $sections['1'] = quoted_printable_encode($html);
        } else {
            $body = $textPart();
            $sections['1'] = quoted_printable_encode((string) $text);
        }

        if ($attachments) {
            $structure = [$body];
            foreach ($attachments as $i => $a) {
                $n = (string) ($i + 2);
                $b64 = chunk_split(base64_encode($a['content']));
                $sections[$n] = $b64;
                [$type, $sub] = explode('/', $a['mime']) + [1 => 'octet-stream'];
                $disposition = !empty($a['inline']) ? ['inline', ['filename', $a['name']]] : ['attachment', ['filename', $a['name']]];
                $part = [$type, $sub, ['name', $a['name']], isset($a['cid']) ? '<' . $a['cid'] . '>' : null, null, 'base64', (string) strlen($b64)];
                if ($type === 'text') {
                    $part[] = '1';
                }
                $part[] = null; // md5
                $part[] = $disposition;
                $structure[] = $part;
            }
            $structure[] = !empty($attachments[0]['inline']) ? 'related' : 'mixed';
        } else {
            $structure = $body;
        }

        return [
            'headers' => $headers,
            'structure' => $structure,
            'sections' => $sections,
            'internaldate' => (new DateTimeImmutable($date))->format('d-M-Y H:i:s O'),
            'size' => strlen($headers) + array_sum(array_map('strlen', $sections)),
            'raw' => $headers . implode("\r\n", $sections),
        ];
    }
}
