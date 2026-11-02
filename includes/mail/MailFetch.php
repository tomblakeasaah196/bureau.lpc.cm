<?php

namespace LPC\Mail;

use LPC\Mail\Imap\BodyStructure;
use LPC\Mail\Imap\ImapException;
use LPC\Mail\Imap\ImapSession;
use LPC\Mail\Mime\Decoder;
use LPC\Mail\Mime\Headers;
use LPC\Mail\Sync\MessageWriter;

/**
 * Reading from the server what bureau doesn't keep (owner decision: 12 months copied, attachments read from the
 * server): bodies too large to copy, attachments, and mail older than 12 months. Nothing read here for older
 * mail is stored. Access is checked on every call (MailError 403/404/413; Imap\ImapException from the server).
 *
 * A live message (older than 12 months) has the keys a stored one shows: uid, subject, from_name, from_email,
 * to_list, cc_list, reply_to_list, message_id, in_reply_to, refs, sent_at, received_at (app time), is_seen,
 * is_flagged, has_attachments, size; message() adds text, html and attachments [section, filename, mime,
 * encoding, size, content_id, is_inline].
 */
final class MailFetch
{
    public static function make(): self
    {
        return new self();
    }

    /** @return array{text: string, html: ?string} the stored copy, else read from the server (and kept, unless too large) */
    public function body(int $userId, int|array $message): array
    {
        $m = is_array($message) ? Store::message((int) $message['id']) : Store::message($message);
        if (!$m) {
            throw MailError::notFound();
        }
        $this->authorizeAccount($userId, $m['account_id']);

        if ($m['body_state'] === 'cached') {
            $row = Db::one('SELECT body_text, body_html FROM mail_messages WHERE id = ?', [$m['id']]);

            return ['text' => (string) ($row['body_text'] ?? ''), 'html' => $row['body_html'] ?? null];
        }

        $parts = $m['parts'] ?? [];
        $sections = array_values(array_filter([$parts['text']['section'] ?? null, $parts['html']['section'] ?? null]));
        if (!$sections) {
            return ['text' => '', 'html' => null];
        }
        $folder = Store::folder($m['folder_id']) ?? throw MailError::notFound();
        $raw = $this->inFolder($folder, fn (ImapSession $s) => $s->fetchSections($m['uid'], $sections));

        if ($m['body_state'] === 'pending') {
            $out = MessageWriter::fillBody($m, $raw);

            return ['text' => (string) ($out['text'] ?? ''), 'html' => $out['html']];
        }
        [$text, $html] = MessageWriter::decodeBodies($parts, $raw);

        return ['text' => (string) $text, 'html' => $html];
    }

    /** @return array{filename: string, mime: string, content: string} decoded bytes */
    public function attachment(int $userId, int $attachmentId): array
    {
        $a = Store::attachment($attachmentId) ?? throw MailError::notFound();
        $m = Store::message($a['message_id']) ?? throw MailError::notFound();
        $this->authorizeAccount($userId, $m['account_id']);
        $this->guardSize($a['size']);

        $folder = Store::folder($m['folder_id']) ?? throw MailError::notFound();
        $raw = $this->inFolder($folder, fn (ImapSession $s) => $s->fetchSections($m['uid'], [$a['section']]));

        return ['filename' => $a['filename'], 'mime' => $a['mime'], 'content' => Decoder::transfer($raw[$a['section']] ?? '', $a['encoding'])];
    }

    /** An inline picture by its Content-ID (cid: in the HTML), or null */
    public function inline(int $userId, int $messageId, string $contentId): ?array
    {
        $id = Db::value('SELECT id FROM mail_attachments WHERE message_id = ? AND content_id = ? LIMIT 1', [$messageId, trim($contentId, '<> ')]);

        return $id ? $this->attachment($userId, (int) $id) : null;
    }

    /**
     * Mail older than bureau's copy, newest first, a page at a time. $beforeUid: the last UID of the previous page.
     *
     * @return array{items: list<array>, next: ?int}
     */
    public function older(int $userId, int $folderId, ?int $beforeUid = null, int $limit = 50): array
    {
        $folder = $this->folderFor($userId, $folderId);
        $cutoff = Clock::now()->modify('-' . (int) Config::get('sync.months', 12) . ' months')->setTime(0, 0);

        return $this->inFolder($folder, function (ImapSession $s) use ($cutoff, $beforeUid, $limit) {
            $uids = $s->search('BEFORE ' . $cutoff->format('j-M-Y'));
            if ($beforeUid !== null) {
                $uids = array_values(array_filter($uids, fn ($u) => $u < $beforeUid));
            }
            rsort($uids);
            $page = array_slice($uids, 0, $limit);
            $items = array_map([self::class, 'liveSummary'], $s->fetchSummaries($page));
            usort($items, fn ($a, $b) => $b['uid'] <=> $a['uid']);

            return ['items' => $items, 'next' => count($uids) > $limit ? (int) end($page) : null];
        });
    }

    /** Search the server's whole folder (all dates) for text; newest first, at most $limit */
    public function searchServer(int $userId, int $folderId, string $query, int $limit = 50): array
    {
        $folder = $this->folderFor($userId, $folderId);
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        return $this->inFolder($folder, function (ImapSession $s) use ($query, $limit) {
            $uids = $s->searchText($query);
            rsort($uids);
            $items = array_map([self::class, 'liveSummary'], $s->fetchSummaries(array_slice($uids, 0, $limit)));
            usort($items, fn ($a, $b) => $b['uid'] <=> $a['uid']);

            return $items;
        });
    }

    /** One live message with its bodies and attachment list (not stored) */
    public function message(int $userId, int $folderId, int $uid): ?array
    {
        $folder = $this->folderFor($userId, $folderId);

        return $this->inFolder($folder, function (ImapSession $s) use ($uid) {
            $summary = $s->fetchSummaries([$uid])[0] ?? null;
            if (!$summary) {
                return null;
            }
            $item = self::liveSummary($summary);
            $structure = BodyStructure::summarise($summary['structure']);
            $sections = array_values(array_filter([$structure['text']['section'] ?? null, $structure['html']['section'] ?? null]));
            $raw = $sections ? $s->fetchSections($uid, $sections) : [];
            [$text, $html] = MessageWriter::decodeBodies(['text' => $structure['text'], 'html' => $structure['html']], $raw);
            $item['text'] = (string) $text;
            $item['html'] = $html;
            $item['attachments'] = $structure['attachments'];

            return $item;
        });
    }

    /** An attachment of a live message, by section */
    public function liveAttachment(int $userId, int $folderId, int $uid, string $section): array
    {
        $message = $this->message($userId, $folderId, $uid) ?? throw MailError::notFound();
        $part = null;
        foreach ($message['attachments'] as $a) {
            if ($a['section'] === $section) {
                $part = $a;
            }
        }
        if (!$part) {
            throw MailError::notFound();
        }
        $this->guardSize($part['size']);
        $folder = Store::folder($folderId);
        $raw = $this->inFolder($folder, fn (ImapSession $s) => $s->fetchSections($uid, [$section]));

        return ['filename' => $part['filename'], 'mime' => $part['mime'], 'content' => Decoder::transfer($raw[$section] ?? '', $part['encoding'])];
    }

    public static function liveSummary(array $summary): array
    {
        $h = Headers::parse($summary['headers'] ?? '');
        $raw = fn (string $name) => $h[$name][0] ?? null;
        $from = Headers::addresses($raw('from'))[0] ?? null;
        $flags = array_map('strtolower', $summary['flags'] ?? []);
        $structure = BodyStructure::summarise($summary['structure'] ?? []);
        $inReplyTo = Headers::messageIds($raw('in-reply-to'));

        return [
            'uid' => (int) $summary['uid'],
            'subject' => Headers::first($h, 'subject'),
            'from_name' => $from['name'] ?? null,
            'from_email' => $from['email'] ?? null,
            'to_list' => Headers::addresses($raw('to')),
            'cc_list' => Headers::addresses($raw('cc')),
            'reply_to_list' => Headers::addresses($raw('reply-to')),
            'message_id' => Headers::messageIds($raw('message-id'))[0] ?? null,
            'in_reply_to' => $inReplyTo ? end($inReplyTo) : null,
            'refs' => implode(' ', Headers::messageIds($raw('references'))),
            'sent_at' => Clock::format(Headers::date($raw('date'))),
            'received_at' => Clock::format(Headers::date($summary['internaldate'] ?? null) ?? Headers::date($raw('date'))),
            'is_seen' => in_array('\\seen', $flags, true),
            'is_flagged' => in_array('\\flagged', $flags, true),
            'has_attachments' => (bool) array_filter($structure['attachments'], fn ($a) => !$a['is_inline']),
            'size' => (int) ($summary['size'] ?? 0),
        ];
    }

    private function folderFor(int $userId, int $folderId): array
    {
        $folder = Store::folder($folderId) ?? throw MailError::notFound();
        $this->authorizeAccount($userId, $folder['account_id']);

        return $folder;
    }

    private function authorizeAccount(int $userId, int $accountId): void
    {
        $account = Store::account($accountId) ?? throw MailError::notFound();
        MailAccess::authorize($userId, $account, 'read');
    }

    private function guardSize(int $size): void
    {
        if ($size > (int) Config::get('max_attachment_bytes', 30 * 1024 * 1024)) {
            throw MailError::tooLarge('Cette pièce jointe est trop volumineuse pour être ouverte dans bureau. Ouvrez-la dans une autre application de messagerie.');
        }
    }

    private function inFolder(array $folder, callable $work): mixed
    {
        $account = Store::account($folder['account_id']) ?? throw MailError::notFound();
        $session = MailServer::make()->open($account);
        try {
            $session->examine($folder['path']);

            return $work($session);
        } finally {
            try {
                $session->logout();
            } catch (ImapException) {
                // closing anyway
            }
        }
    }
}
