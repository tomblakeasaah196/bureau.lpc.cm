<?php

namespace LPC\Mail;

use LPC\Mail\Mime\Message;
use Throwable;

/**
 * Sending one message from a mailbox (docs/MAIL.md §7.3; PR 3 builds the composer, outbox and signatures on
 * this). The From line is always the mailbox itself (its name and address); the person shows in their
 * signature, and bureau records who sent it (mail_messages.sent_by, through mail_outbox). Bcc travels in the
 * envelope only.
 *
 *   $result = MailTransport::make()->send($userId, $account, $message);
 *   // ['message_id' => 'abc@lpc.cm' (no brackets), 'raw' => message as sent, 'sent_uid' => ?int UID in Sent]
 *
 * Throws MailSendException when SMTP refuses (permanent or not) and MailError when the person may not send
 * from this mailbox. The copy in Sent is best effort: a failure there is logged; the message has gone.
 */
final class MailTransport
{
    public static function make(): self
    {
        return new self();
    }

    /** A new Message-ID (without angle brackets) on the mailbox's own domain */
    public static function newMessageId(array $account): string
    {
        $domain = substr(strrchr((string) $account['email'], '@') ?: '', 1) ?: (string) Config::get('local_domain', 'bureau.lpc.cm');

        return bin2hex(random_bytes(12)) . '.' . Clock::now()->format('YmdHis') . '@' . strtolower($domain);
    }

    /** @return array{message_id: string, raw: string, sent_uid: ?int} */
    public function send(int $userId, array $account, Message $message, bool $copyToSent = true): array
    {
        MailAccess::authorize($userId, $account, 'send');

        $message->from($account['email'], (string) $account['display_name']);
        if ($message->getMessageId() === null) {
            $message->messageId(self::newMessageId($account));
        }
        $message->date(Clock::now());

        $raw = MailServer::make()->send($account, $message);

        return [
            'message_id' => (string) $message->getMessageId(),
            'raw' => $raw,
            'sent_uid' => $copyToSent ? $this->appendToSent($account, $raw) : null,
        ];
    }

    /** Put a copy in the mailbox's Sent folder (the server's exim does not), marked read */
    public function appendToSent(array $account, string $raw): ?int
    {
        $sent = Store::folderByRole((int) $account['id'], 'sent');
        if (!$sent) {
            return null;
        }
        try {
            $session = MailServer::make()->open($account);
            try {
                return $session->append($sent['path'], $raw, ['\\Seen']);
            } finally {
                $session->logout();
            }
        } catch (Throwable $e) {
            error_log('Mail: sent, but the copy in Sent could not be saved (account ' . (int) $account['id'] . '): ' . $e->getMessage());

            return null;
        }
    }
}
