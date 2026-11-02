<?php

namespace LPC\Mail\Read;

use LPC\Mail\Imap\ImapException;
use LPC\Mail\MailActions;
use LPC\Mail\MailError;
use LPC\Mail\MailFetch;
use LPC\Mail\MailNav;
use LPC\Mail\People;
use LPC\Mail\Store;

/**
 * The reader (docs/MAIL.md §2), ported from JBS HQ: a conversation's messages as the page shows them, opening
 * (read for everyone, "Ouvert par"), and bodies made safe. HTML goes through HtmlSanitizer and is shown in a
 * sandboxed frame (no scripts) whose own Content-Security-Policy lets pictures load only from bureau (cid:) and
 * data: until the person allows the sender's remote pictures; plain text goes through TextFormatter.
 */
final class Reader
{
    /** Types that may open in the browser; everything else downloads (api/v1/mail/read_file.php) */
    public const VIEWABLE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain'];

    public const FILE_URL = '/api/v1/mail/read_file.php';

    /**
     * The conversation's messages in this mailbox, one per message (a copy in two folders shows once: the one in
     * this folder, else one outside Indésirables and Corbeille), oldest first.
     *
     * @param array<int, string> $roles folder id → role
     * @return list<array> message rows
     */
    public static function messages(int $threadId, int $accountId, ?int $currentFolderId, array $roles): array
    {
        $groups = [];
        foreach (Store::threadMessages($threadId) as $m) {
            if ($m['account_id'] !== $accountId) {
                continue;
            }
            $groups[$m['message_id_hash'] ?? 'row:' . $m['id']][] = $m;
        }
        $rank = fn (array $m) => match (true) {
            $m['folder_id'] === $currentFolderId => 0,
            !in_array($roles[$m['folder_id']] ?? null, ['spam', 'trash'], true) => 1,
            default => 2,
        };
        $out = [];
        foreach ($groups as $copies) {
            usort($copies, fn ($a, $b) => $rank($a) <=> $rank($b));
            $out[] = $copies[0];
        }
        usort($out, fn ($a, $b) => [$a['received_at'] ?? '', $a['id']] <=> [$b['received_at'] ?? '', $b['id']]);

        return $out;
    }

    /**
     * What toolbar actions work on: the conversation's messages in the folder in view (in a search, those outside
     * Envoyés and Brouillons), else every copy.
     *
     * @return list<array> message rows
     */
    public static function scope(int $threadId, int $accountId, ?array $folder, bool $searching, array $roles): array
    {
        $all = array_values(array_filter(Store::threadMessages($threadId), fn ($m) => $m['account_id'] === $accountId));
        $scope = (!$searching && $folder)
            ? array_filter($all, fn ($m) => $m['folder_id'] === $folder['id'])
            : array_filter($all, fn ($m) => !in_array($roles[$m['folder_id']] ?? null, ['sent', 'drafts'], true));

        return $scope ? array_values($scope) : $all;
    }

    /** The messages open when a conversation opens: the unread ones and the latest @return list<int> */
    public static function defaultOpen(array $messages): array
    {
        $ids = array_map(fn ($m) => $m['id'], array_filter($messages, fn ($m) => !$m['is_seen']));
        if ($messages) {
            $ids[] = end($messages)['id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * The person opened these messages in bureau: read for everyone (one server call for all), and who opened each
     * first ("Ouvert par", decision 8). A server that refuses leaves them unread; reading goes on.
     *
     * @return bool something changed (the counts must be read again)
     */
    public static function markOpened(int $userId, array $messages): bool
    {
        $inbound = array_values(array_filter($messages, fn ($m) => !$m['is_outbound'] && !$m['is_draft']));
        $unread = array_map(fn ($m) => $m['id'], array_filter($inbound, fn ($m) => !$m['is_seen']));
        $unrecorded = array_filter($inbound, fn ($m) => !$m['seen_by']);
        if (!$unread && !$unrecorded) {
            return false;
        }
        $actions = MailActions::make();
        try {
            if ($unread) {
                $actions->markRead($userId, $unread, true);
            }
            foreach ($unrecorded as $m) {
                $actions->opened($userId, $m['id']);
            }
        } catch (ImapException $e) {
            error_log('mail/read: marking read: ' . $e->reason . ' ' . $e->getMessage());
        }
        MailNav::forget();

        return true;
    }

    /**
     * One message as the reader shows it.
     *
     * @param array{open: bool, remote: bool, current: ?int, labels: array<int, string>, shared: bool, userId: int} $ctx
     */
    public static function item(array $m, array $ctx): array
    {
        $from = trim((string) ($m['from_name'] ?? '')) ?: ((string) ($m['from_email'] ?? '') ?: 'Expéditeur inconnu');
        $files = array_values(array_filter(Store::attachments($m['id']), fn ($a) => !$a['is_inline'] || !$a['content_id']));
        $openedBy = null;
        if ($ctx['shared'] && $m['seen_by'] && !$m['is_outbound']) {
            $who = $m['seen_by'] === $ctx['userId'] ? 'vous' : People::firstName($m['seen_by']);
            $openedBy = $who . ($m['seen_at'] ? ', ' . Format::when($m['seen_at'], true) : '');
        }

        return [
            'id' => $m['id'],
            'thread_id' => $m['thread_id'],
            'from' => $from,
            'from_email' => $m['from_email'],
            'initials' => Format::initials($from),
            'to' => $m['to_list'],
            'cc' => $m['cc_list'],
            'date' => Format::when($m['received_at'], true),
            'date_full' => Format::full($m['received_at']),
            'folder' => $m['folder_id'] !== $ctx['current'] ? ($ctx['labels'][$m['folder_id']] ?? null) : null,
            'opened_by' => $openedBy,
            'sent_by' => $ctx['shared'] && $m['sent_by'] ? People::name($m['sent_by']) : null,
            'snippet' => (string) ($m['snippet'] ?? ''),
            'is_unread' => !$m['is_seen'],
            'is_flagged' => $m['is_flagged'],
            'is_draft' => $m['is_draft'] || (($ctx['roles'][$m['folder_id']] ?? null) === 'drafts'),
            'attachments' => array_map(fn ($a) => $a + [
                'url' => self::FILE_URL . '?a=' . $a['id'],
                'viewable' => in_array(strtolower($a['mime']), self::VIEWABLE, true),
            ], $files),
            'open' => $ctx['open'],
            'remote' => $ctx['remote'],
            'body' => $ctx['open'] ? self::body($ctx['userId'], $m, $ctx['remote']) : null,
        ];
    }

    /** @return array{kind: string, html?: string, doc?: string, blocked?: int} */
    public static function body(int $userId, array $m, bool $remote): array
    {
        try {
            $body = MailFetch::make()->body($userId, $m);
        } catch (ImapException $e) {
            error_log('mail/read: body ' . $m['id'] . ': ' . $e->reason . ' ' . $e->getMessage());

            return ['kind' => 'error'];
        } catch (MailError) {
            return ['kind' => 'error'];
        }
        $cids = [];
        foreach (Store::attachments($m['id']) as $a) {
            if ($a['content_id']) {
                $cids[strtolower(trim($a['content_id'], '<> '))] = true;
            }
        }
        $cid = fn (string $id) => isset($cids[strtolower(trim($id, '<> '))])
            ? self::FILE_URL . '?m=' . $m['id'] . '&cid=' . rawurlencode(trim($id, '<> ')) : null;

        return self::present((string) ($body['text'] ?? ''), $body['html'] ?? null, $remote, $cid);
    }

    /** A body as the reader shows it: HTML in a sandboxed frame, text inline */
    public static function present(string $text, ?string $html, bool $remote, callable $cid): array
    {
        if ($html !== null && trim($html) !== '') {
            $clean = HtmlSanitizer::clean($html, $remote, $cid);
            if (trim(strip_tags($clean['html'], '<img>')) !== '') {
                return ['kind' => 'html', 'doc' => self::document($clean['html'], $clean['styles'], $remote), 'blocked' => $clean['blocked']];
            }
        }
        if (trim($text) === '') {
            return ['kind' => 'empty', 'blocked' => 0];
        }

        return ['kind' => 'text', 'html' => TextFormatter::toHtml($text), 'blocked' => 0];
    }

    /**
     * The document inside the reader's frame. The frame runs no scripts (sandbox without allow-scripts) and its
     * policy lets pictures load only from bureau (cid:) and data:, or from anywhere once the person allowed them.
     * Mail is written for a white page, so the frame stays white in the dark theme too (as webmails do).
     */
    public static function document(string $html, string $styles, bool $remote): string
    {
        $img = "'self' data:" . ($remote ? ' https: http:' : '');
        $policy = "default-src 'none'; img-src {$img}; style-src 'unsafe-inline'; font-src data:" . ($remote ? ' https:' : '')
            . "; base-uri 'none'; form-action 'none'";
        $base = 'html,body{margin:0;padding:0;background:#ffffff}'
            // flow-root: the body holds its children's margins, so the page can measure it to size the frame
            . 'body{display:flow-root;font-family:Inter,"Segoe UI",Arial,sans-serif;font-size:14px;line-height:1.55;color:#111827;overflow-wrap:anywhere;word-break:break-word}'
            . 'img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}a{color:#005A2B}'
            . '.mr-mail-body{overflow-x:auto}'
            . '.mr-quote{margin-top:12px}.mr-quote>summary{cursor:pointer;display:inline-block;padding:2px 10px;border-radius:999px;background:#F1F5F9;color:#475467;font-size:12px;list-style:none}'
            . '.mr-quote>summary::-webkit-details-marker{display:none}.mr-quote[open]>summary{margin-bottom:8px}'
            . 'blockquote{margin:0 0 0 4px;padding-left:12px;border-left:2px solid #E6EAEE;color:#475467}';

        return '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta http-equiv="Content-Security-Policy" content="' . htmlspecialchars($policy, ENT_QUOTES, 'UTF-8') . '">'
            . '<base target="_blank"><style>' . $base . '</style>'
            . ($styles !== '' ? '<style>' . $styles . '</style>' : '')
            . '</head><body>' . $html . '</body></html>';
    }

    /**
     * An older message (past bureau's 12 months) read live from the server, as the reader shows it. Nothing is kept.
     *
     * @return array|null null when the server no longer has it
     */
    public static function live(int $userId, array $folder, int $uid, bool $remote, string $folderLabel): ?array
    {
        $item = MailFetch::make()->message($userId, $folder['id'], $uid);
        if (!$item) {
            return null;
        }
        $link = fn (array $a) => self::FILE_URL . '?folder=' . $folder['id'] . '&uid=' . $uid . '&section=' . rawurlencode($a['section']);
        $byCid = [];
        foreach ($item['attachments'] as $a) {
            if (!empty($a['content_id'])) {
                $byCid[strtolower(trim($a['content_id'], '<> '))] = $a;
            }
        }
        $cid = fn (string $id) => isset($byCid[strtolower(trim($id, '<> '))]) ? $link($byCid[strtolower(trim($id, '<> '))]) . '&view=1' : null;
        $trusted = ImageTrust::trusted($userId, [$item['from_email'] ?? '']);
        $remote = $remote || ($item['from_email'] && in_array(strtolower($item['from_email']), $trusted, true));
        $from = trim((string) ($item['from_name'] ?? '')) ?: ((string) ($item['from_email'] ?? '') ?: 'Expéditeur inconnu');

        return [
            'uid' => $uid,
            'folder_id' => $folder['id'],
            'subject' => (string) ($item['subject'] ?? ''),
            'from' => $from,
            'from_email' => $item['from_email'] ?? null,
            'initials' => Format::initials($from),
            'to' => $item['to_list'] ?? [],
            'cc' => $item['cc_list'] ?? [],
            'date_full' => Format::full($item['received_at'] ?? $item['sent_at'] ?? null),
            'folder' => $folderLabel,
            'attachments' => array_map(fn ($a) => $a + [
                'url' => $link($a),
                'viewable' => in_array(strtolower((string) $a['mime']), self::VIEWABLE, true),
            ], array_values(array_filter($item['attachments'], fn ($a) => !$a['is_inline'] || empty($a['content_id'])))),
            'remote' => $remote,
            'body' => self::present((string) ($item['text'] ?? ''), $item['html'] ?? null, $remote, $cid),
        ];
    }

    /** A live item for the list: plain values only */
    public static function liveRow(array $item): array
    {
        return [
            'uid' => (int) $item['uid'],
            'subject' => (string) ($item['subject'] ?? ''),
            'from' => trim((string) ($item['from_name'] ?? '')) ?: ((string) ($item['from_email'] ?? '') ?: 'Expéditeur inconnu'),
            'received_at' => $item['received_at'] ?? $item['sent_at'] ?? null,
            'is_seen' => (bool) ($item['is_seen'] ?? true),
            'is_flagged' => (bool) ($item['is_flagged'] ?? false),
            'has_attachments' => (bool) ($item['has_attachments'] ?? false),
        ];
    }
}
