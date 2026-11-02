<?php

namespace LPC\Mail;

use LPC\Mail\Imap\ImapClient;
use LPC\Mail\Imap\ImapSession;
use LPC\Mail\Imap\SocketImapSession;
use LPC\Mail\Mime\Message;
use LPC\Mail\Smtp\SmtpClient;

/**
 * The one door to the mail servers: IMAP sessions and SMTP. Everything in Courrier goes through
 * MailServer::make(), so tests swap in scripts/tests/mail/support/FakeMailServer.php with MailServer::use().
 *
 * $settings everywhere: ['imap_host', 'imap_port', 'imap_encryption', 'smtp_host', 'smtp_port', 'smtp_encryption']
 * (encryption: ssl, starttls or none). Throws Imap\ImapException (IMAP) and MailSendException (SMTP).
 */
class MailServer
{
    private static ?MailServer $instance = null;

    public static function make(): MailServer
    {
        return self::$instance ??= new MailServer();
    }

    /** Tests: put a fake in (or null to go back to the real servers) */
    public static function use(?MailServer $server): void
    {
        self::$instance = $server;
    }

    /** A signed-in session on a connected mailbox (an account row; the password is read here, never passed around) */
    public function open(array $account): ImapSession
    {
        $full = Store::accountWithSecret((int) $account['id']);
        if (!$full) {
            throw MailError::notFound();
        }

        return $this->connect(self::settingsOf($full), $full['username'], MailCredentials::password($full));
    }

    /** A signed-in session with these settings (Connect uses it before anything is saved) */
    public function connect(array $settings, string $username, string $password): ImapSession
    {
        HostPolicy::check($settings);   // again, before any socket: also covers settings saved earlier
        $client = new ImapClient(
            $settings['imap_host'],
            (int) $settings['imap_port'],
            $settings['imap_encryption'],
            (int) Config::get('timeout', 20),
            (bool) Config::get('verify_tls', true),
        );
        $client->connect();
        $client->login($username, $password);

        return new SocketImapSession($client);
    }

    /** Sign in to SMTP and leave: proves the outgoing settings and password work. Throws MailSendException. */
    public function checkSmtp(array $settings, string $username, string $password): void
    {
        $smtp = $this->smtp($settings);
        try {
            $smtp->connect();
            $smtp->login($username, $password);
        } finally {
            $smtp->quit();
        }
    }

    /**
     * Send through the mailbox's own SMTP server. From must already be set (MailTransport sets the mailbox);
     * the envelope is To, Cc and Bcc. @return string the message exactly as sent (no Bcc), for the Sent folder
     */
    public function send(array $account, Message $message): string
    {
        $full = Store::accountWithSecret((int) $account['id']);
        if (!$full) {
            throw MailError::notFound();
        }
        $raw = $message->toString();
        $smtp = $this->smtp(self::settingsOf($full));
        try {
            $smtp->connect();
            $smtp->login($full['username'], MailCredentials::password($full));
            $smtp->send($full['email'], $message->recipients(), $raw);
        } finally {
            $smtp->quit();
        }

        return $raw;
    }

    public static function settingsOf(array $account): array
    {
        return [
            'imap_host' => (string) $account['imap_host'],
            'imap_port' => (int) $account['imap_port'],
            'imap_encryption' => (string) $account['imap_encryption'],
            'smtp_host' => (string) $account['smtp_host'],
            'smtp_port' => (int) $account['smtp_port'],
            'smtp_encryption' => (string) $account['smtp_encryption'],
        ];
    }

    private function smtp(array $settings): SmtpClient
    {
        HostPolicy::check($settings);   // before any socket (checkSmtp and send both come through here)

        return new SmtpClient(
            $settings['smtp_host'],
            (int) $settings['smtp_port'],
            $settings['smtp_encryption'],
            (int) Config::get('timeout', 20),
            (bool) Config::get('verify_tls', true),
            (string) Config::get('local_domain', 'bureau.lpc.cm'),
        );
    }
}
