<?php

namespace LPC\Mail\Sync;

use LPC\Mail\Clock;
use LPC\Mail\Config;
use LPC\Mail\Db;
use LPC\Mail\Imap\BodyStructure;
use LPC\Mail\Imap\ImapSession;
use LPC\Mail\Mime\Decoder;
use LPC\Mail\Mime\Headers;
use LPC\Mail\Mime\Text;
use LPC\Mail\Store;
use Throwable;

/**
 * Turns FETCH summaries into mail_messages rows (with attachments and a thread), then reads their bodies in
 * batches. A message that cannot be read still gets a row, so sync never retries it for ever.
 */
final class MessageWriter
{
    /**
     * @param list<array> $summaries from ImapSession::fetchSummaries()
     * @return list<array> the stored message rows
     */
    public static function store(array $account, array $folder, array $summaries, ImapSession $session): array
    {
        $stored = [];
        foreach ($summaries as $summary) {
            try {
                $stored[] = self::one($account, $folder, $summary);
            } catch (Throwable $e) {
                error_log('Mail: a message could not be read (account ' . $account['id'] . ', folder ' . $folder['id'] . ', uid ' . ($summary['uid'] ?? '?') . '): ' . $e->getMessage());
                $placeholder = self::placeholder($account, $folder, $summary);
                if ($placeholder) {
                    $stored[] = $placeholder;
                }
            }
        }

        $pendingIds = array_map(fn ($m) => $m['id'], array_filter($stored, fn ($m) => $m['body_state'] === 'pending'));
        self::bodies($session, Store::messages($pendingIds));

        return Store::messages(array_map(fn ($m) => $m['id'], $stored));
    }

    /** Read and store the bodies of these messages (all in the folder open on $session) */
    public static function bodies(ImapSession $session, array $messages): void
    {
        $max = (int) Config::get('sync.max_body_bytes', 1000000);

        // Messages whose bodies sit in the same sections are fetched together
        $groups = [];
        foreach ($messages as $m) {
            $parts = $m['parts'] ?? [];
            $sections = [];
            $tooBig = false;
            foreach (['text', 'html'] as $kind) {
                if (!empty($parts[$kind])) {
                    $sections[] = $parts[$kind]['section'];
                    // base64 grows by a third; be generous before calling it too big
                    $tooBig = $tooBig || ($parts[$kind]['size'] ?? 0) > $max * 1.4;
                }
            }
            if ($tooBig) {
                Db::update('mail_messages', ['body_state' => 'remote'], $m['id']);
                continue;
            }
            if (!$sections) {
                Db::update('mail_messages', ['body_state' => 'cached', 'body_text' => '', 'snippet' => $m['snippet'] ?? ''], $m['id']);
                continue;
            }
            $groups[implode('|', $sections)][] = $m;
        }

        foreach ($groups as $key => $group) {
            $sections = explode('|', $key);
            foreach (array_chunk($group, 25) as $chunk) {
                try {
                    $raw = $session->fetchSectionsBatch(array_map(fn ($m) => (int) $m['uid'], $chunk), $sections);
                } catch (Throwable $e) {
                    error_log('Mail: bodies could not be read: ' . $e->getMessage());
                    foreach ($chunk as $m) {
                        Db::update('mail_messages', ['body_state' => 'remote'], $m['id']);
                    }
                    continue;
                }
                foreach ($chunk as $m) {
                    self::fillBody($m, $raw[(int) $m['uid']] ?? []);
                }
            }
        }
    }

    /** Decode fetched sections into body_text / body_html and the snippet; @return array{text: ?string, html: ?string} */
    public static function fillBody(array $m, array $raw): array
    {
        [$text, $html] = self::decodeBodies($m['parts'] ?? [], $raw);
        if ($text === null && $html === null) {
            Db::update('mail_messages', ['body_state' => 'remote'], $m['id']);

            return ['text' => null, 'html' => null];
        }
        if (($text === null || trim($text) === '') && $html !== null) {
            $text = Text::fromHtml($html);
        }
        $snippet = Text::snippet((string) $text);

        Db::update('mail_messages', [
            'body_text' => $text,
            'body_html' => $html,
            'body_state' => 'cached',
            'snippet' => $snippet !== '' ? mb_substr($snippet, 0, 300) : ($m['snippet'] ?? null),
        ], $m['id']);

        return ['text' => $text, 'html' => $html];
    }

    /** @return array{0: ?string, 1: ?string} text and HTML from raw sections, using the stored parts map */
    public static function decodeBodies(array $parts, array $raw): array
    {
        $text = null;
        $html = null;
        if (!empty($parts['text']) && array_key_exists($parts['text']['section'], $raw)) {
            $text = Decoder::decode($raw[$parts['text']['section']], $parts['text']['encoding'] ?? null, $parts['text']['charset'] ?? null);
        }
        if (!empty($parts['html']) && array_key_exists($parts['html']['section'], $raw)) {
            $html = Decoder::decode($raw[$parts['html']['section']], $parts['html']['encoding'] ?? null, $parts['html']['charset'] ?? null);
        }

        return [$text, $html];
    }

    private static function one(array $account, array $folder, array $summary): array
    {
        $h = Headers::parse($summary['headers'] ?? '');
        $raw = fn (string $name) => $h[$name][0] ?? null;

        $from = Headers::addresses($raw('from'))[0] ?? null;
        $messageId = Headers::messageIds($raw('message-id'))[0] ?? null;
        $inReplyTo = Headers::messageIds($raw('in-reply-to'));
        $references = array_slice(Headers::messageIds($raw('references')), -30);
        $sentAt = Headers::date($raw('date'));
        $receivedAt = Headers::date($summary['internaldate'] ?? null) ?? $sentAt ?? Clock::now();
        $flags = array_map('strtolower', $summary['flags'] ?? []);
        $structure = BodyStructure::summarise($summary['structure'] ?? []);
        $to = Headers::addresses($raw('to'));
        $cc = Headers::addresses($raw('cc'));
        $subject = Headers::first($h, 'subject');

        $fromEmail = $from['email'] ?? null;
        $outbound = ($fromEmail !== null && strcasecmp($fromEmail, $account['email']) === 0)
            || in_array($folder['role'], ['sent', 'drafts'], true);

        $hash = Store::hashId($messageId);
        $sentBy = null;
        if ($outbound && $hash) {
            $sentBy = Db::value(
                "SELECT user_id FROM mail_outbox WHERE account_id = ? AND message_id_hash = ? AND status IN ('sending', 'sent') LIMIT 1",
                [$account['id'], $hash],
            );
        }
        $nonInline = array_filter($structure['attachments'], fn ($a) => !$a['is_inline']);
        $lu = Headers::first($h, 'list-unsubscribe');
        $now = Clock::sql();

        return Db::tx(function () use ($account, $folder, $summary, $messageId, $hash, $inReplyTo, $references, $subject, $from, $to, $cc, $raw, $sentAt, $receivedAt, $flags, $structure, $nonInline, $outbound, $sentBy, $lu, $now) {
            $id = Db::insert('mail_messages', [
                'account_id' => (int) $account['id'],
                'folder_id' => (int) $folder['id'],
                'uid' => (int) $summary['uid'],
                'message_id' => $messageId !== null ? mb_substr($messageId, 0, 255) : null,
                'message_id_hash' => $hash,
                'in_reply_to' => $inReplyTo ? mb_substr(end($inReplyTo), 0, 255) : null,
                'refs' => $references ? implode(' ', $references) : null,
                'subject' => $subject !== null ? mb_substr($subject, 0, 500) : null,
                'from_name' => isset($from['name']) ? mb_substr($from['name'], 0, 255) : null,
                'from_email' => isset($from['email']) ? mb_substr($from['email'], 0, 255) : null,
                'to_list' => $to,
                'cc_list' => $cc,
                'bcc_list' => Headers::addresses($raw('bcc')),
                'reply_to_list' => Headers::addresses($raw('reply-to')),
                'to_text' => self::addressText(array_merge($to, $cc)),
                'sent_at' => Clock::format($sentAt),
                'received_at' => Clock::format($receivedAt),
                'size' => (int) ($summary['size'] ?? 0),
                'is_seen' => in_array('\\seen', $flags, true),
                'is_flagged' => in_array('\\flagged', $flags, true),
                'is_answered' => in_array('\\answered', $flags, true),
                'is_draft' => in_array('\\draft', $flags, true),
                'has_attachments' => count($nonInline) > 0,
                'is_outbound' => $outbound,
                'body_state' => 'pending',
                'parts' => ['text' => $structure['text'], 'html' => $structure['html']],
                'list_unsubscribe' => $lu !== null ? mb_substr($lu, 0, 500) : null,
                'sent_by' => $sentBy !== null ? (int) $sentBy : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($structure['attachments']) {
                Db::insertMany('mail_attachments', array_map(fn ($a) => [
                    'message_id' => $id,
                    'section' => $a['section'],
                    'filename' => mb_substr($a['filename'], 0, 255),
                    'mime' => mb_substr($a['mime'], 0, 127),
                    'encoding' => $a['encoding'],
                    'size' => (int) $a['size'],
                    'content_id' => $a['content_id'] !== null ? mb_substr($a['content_id'], 0, 255) : null,
                    'is_inline' => $a['is_inline'] ? 1 : 0,
                ], $structure['attachments']));
            }

            $message = Store::message($id);
            $message['thread_id'] = Threader::assign($message);

            return $message;
        });
    }

    private static function placeholder(array $account, array $folder, array $summary): ?array
    {
        $uid = (int) ($summary['uid'] ?? 0);
        $existing = Db::value('SELECT id FROM mail_messages WHERE folder_id = ? AND uid = ?', [$folder['id'], $uid]);
        if ($existing) {
            return Store::message((int) $existing);
        }
        try {
            $now = Clock::sql();
            $id = Db::insert('mail_messages', [
                'account_id' => (int) $account['id'],
                'folder_id' => (int) $folder['id'],
                'uid' => $uid,
                'subject' => '(Ce message ne peut pas être lu dans bureau)',
                'received_at' => Clock::format(Headers::date($summary['internaldate'] ?? null) ?? Clock::now()),
                'size' => (int) ($summary['size'] ?? 0),
                'is_seen' => in_array('\\seen', array_map('strtolower', $summary['flags'] ?? []), true),
                'body_state' => 'error',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $message = Store::message($id);
            $message['thread_id'] = Threader::assign($message);

            return $message;
        } catch (Throwable $e) {
            error_log('Mail: placeholder failed: ' . $e->getMessage());

            return null;
        }
    }

    private static function addressText(array $addresses): string
    {
        return mb_substr(implode(' ', array_map(fn ($a) => trim(($a['name'] ?? '') . ' ' . $a['email']), $addresses)), 0, 5000);
    }
}
