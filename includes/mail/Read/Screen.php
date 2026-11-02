<?php

namespace LPC\Mail\Read;

use LPC\Mail\MailError;
use LPC\Mail\Store;

/**
 * The Mail page's pieces as HTML, for the first screen (modules/mail/index.php) and for the page script
 * (api/v1/mail/read.php): the conversation list, the reader of a conversation, one message. Opening a conversation
 * here marks what it shows as read (for everyone, decision 8) unless $mark is false.
 */
final class Screen
{
    /** @return array{html: string, total: int, page: int} */
    public static function list(Inbox $inbox, string $q, string $filter, int $page, int $openId = 0): array
    {
        if (!$inbox->account || !$inbox->folder) {
            return ['html' => '', 'total' => 0, 'page' => 1];
        }
        $q = mb_substr(trim($q), 0, 200);
        $filter = in_array($filter, ThreadList::filtersFor($inbox->account), true) ? $filter : 'all';
        $list = $q !== ''
            ? ThreadList::search($inbox->userId, $inbox->account, $q, $inbox->folder, $filter, $page)
            : ThreadList::folder($inbox->userId, $inbox->folder, $filter, $page);

        return [
            'html' => Partial::render('list', ['inbox' => $inbox, 'list' => $list, 'q' => $q, 'filter' => $filter, 'openId' => $openId]),
            'total' => $list['total'],
            'page' => $list['page'],
        ];
    }

    /**
     * The reader of a conversation: its toolbar and subject ('head'), its messages ('messages'), and what the page
     * script needs ('thread': ids, the latest message for r / a / f, flags).
     *
     * @param list<int>|null $open message ids shown open (null: the unread ones and the latest)
     * @param list<int> $images message ids whose remote pictures the person chose to show
     * @param bool $openLatest also open the newest message (a reply just sent, mail:sent)
     */
    public static function thread(Inbox $inbox, array $thread, ?array $open = null, array $images = [], bool $searching = false, bool $mark = true, bool $openLatest = false): array
    {
        if (!$inbox->account || $thread['account_id'] !== $inbox->account['id']) {
            throw MailError::notFound();
        }
        $roles = $inbox->roles();
        $current = $inbox->folder['id'] ?? null;
        $messages = Reader::messages($thread['id'], $inbox->account['id'], $current, $roles);
        if (!$messages) {
            throw MailError::notFound('Cette conversation n’a plus de message : elle a peut-être été déplacée ou supprimée.');
        }
        $ids = array_map(fn ($m) => $m['id'], $messages);
        $open = $open === null ? Reader::defaultOpen($messages) : array_values(array_intersect(array_map('intval', $open), $ids));
        if (!$open || $openLatest) {
            $open = array_values(array_unique(array_merge($open, [end($ids)])));
        }

        $changed = false;
        if ($mark) {
            $changed = Reader::markOpened($inbox->userId, array_values(array_filter($messages, fn ($m) => in_array($m['id'], $open, true))));
            if ($changed) {
                $messages = Reader::messages($thread['id'], $inbox->account['id'], $current, $roles);
                $thread = Store::thread($thread['id']) ?? $thread;
            }
        }

        $items = self::items($inbox, $messages, $open, $images);
        $latest = end($items);

        return [
            'head' => Partial::render('reader_head', ['inbox' => $inbox, 'thread' => $thread, 'items' => $items, 'searching' => $searching]),
            'messages' => implode("\n", array_map(fn ($m) => Partial::render('message', ['inbox' => $inbox, 'm' => $m]), $items)),
            'thread' => [
                'id' => $thread['id'],
                'subject' => (string) ($thread['subject'] ?? ''),
                'latest' => $latest['id'],
                'latestDraft' => $latest['is_draft'],
                'flagged' => (bool) array_filter($items, fn ($m) => $m['is_flagged']),
                'open' => $open,
                'count' => count($items),
            ],
            'changed' => $changed,
        ];
    }

    /** One message of the open conversation, open (marked read) and drawn again */
    public static function message(Inbox $inbox, int $messageId, bool $images = false, bool $mark = true): array
    {
        $m = Store::message($messageId);
        if (!$m || !$inbox->account || $m['account_id'] !== $inbox->account['id'] || !$m['thread_id']) {
            throw MailError::notFound();
        }
        $changed = $mark && Reader::markOpened($inbox->userId, [$m]);
        if ($changed) {
            $m = Store::message($messageId) ?? $m;
        }
        $item = self::items($inbox, [$m], [$m['id']], $images ? [$m['id']] : [])[0];

        return ['html' => Partial::render('message', ['inbox' => $inbox, 'm' => $item]), 'changed' => $changed];
    }

    /** @return list<array> Reader::item rows */
    private static function items(Inbox $inbox, array $messages, array $open, array $images): array
    {
        $trusted = ImageTrust::trusted($inbox->userId, array_map(fn ($m) => $m['from_email'], $messages));
        $ctx = [
            'current' => $inbox->folder['id'] ?? null,
            'labels' => $inbox->labels(),
            'roles' => $inbox->roles(),
            'shared' => $inbox->shared(),
            'userId' => $inbox->userId,
        ];

        return array_map(fn ($m) => Reader::item($m, $ctx + [
            'open' => in_array($m['id'], $open, true),
            'remote' => in_array($m['id'], $images, true) || ($m['from_email'] && in_array(strtolower($m['from_email']), $trusted, true)),
        ]), $messages);
    }
}
