<?php

namespace LPC\Mail\Read;

use LPC\Mail\Db;
use LPC\Mail\MailAccess;
use LPC\Mail\Store;

/**
 * The conversation list on the Mail page (docs/MAIL.md §2), ported from JBS HQ: a folder's conversations, or a
 * search over bureau's copy (the last 12 months), with the filters and a page at a time.
 *
 * Each row is a thread row (Store::threadRow) plus fields counted over the messages in view (the folder's, or the
 * search's): row_unread, row_flagged, row_last_at, row_count, row_attach, row_draft, row_folders (folder ids).
 *
 * Filters: all, unread, and on shared mailboxes mine (assigned to the person), open, done (PR 4's teamwork columns).
 */
final class ThreadList
{
    public const PER_PAGE = 50;

    public const FILTERS = ['all', 'unread', 'mine', 'open', 'done'];

    public const FILTER_LABELS = ['all' => 'Tous', 'unread' => 'Non lus', 'mine' => 'Assignées à moi', 'open' => 'Ouvertes', 'done' => 'Traitées'];

    /** Teamwork filters exist on shared mailboxes only */
    public static function filtersFor(array $account): array
    {
        return Store::isShared($account) ? self::FILTERS : ['all', 'unread'];
    }

    /** @return array{rows: list<array>, total: int, page: int} */
    public static function folder(int $userId, array $folder, string $filter = 'all', int $page = 1): array
    {
        $account = Store::account((int) $folder['account_id']);
        if (!$account) {
            return ['rows' => [], 'total' => 0, 'page' => 1];
        }
        MailAccess::authorize($userId, $account, 'read');

        return self::page($userId, $account, 'm.folder_id = ?', [(int) $folder['id']], $filter, $page);
    }

    /**
     * Search bureau's copy of one mailbox. Every word must match the subject, people, addresses or text;
     * "de:awa" (or "from:awa") looks at the sender only. Indésirables and Corbeille are searched only from inside them.
     *
     * @return array{rows: list<array>, total: int, page: int}
     */
    public static function search(int $userId, array $account, string $query, ?array $within = null, string $filter = 'all', int $page = 1): array
    {
        MailAccess::authorize($userId, $account, 'read');
        [$words, $from] = self::parse($query);
        if (!$words && !$from) {
            return ['rows' => [], 'total' => 0, 'page' => 1];
        }

        $where = ['m.account_id = ?'];
        $params = [(int) $account['id']];
        if ($within && in_array($within['role'], ['spam', 'trash'], true)) {
            $where[] = 'm.folder_id = ?';
            $params[] = (int) $within['id'];
        } else {
            $where[] = "m.folder_id NOT IN (SELECT id FROM mail_folders WHERE account_id = ? AND role IN ('spam', 'trash'))";
            $params[] = (int) $account['id'];
        }
        foreach ($from as $f) {
            $where[] = '(m.from_email LIKE ? OR m.from_name LIKE ?)';
            $like = '%' . self::like($f) . '%';
            array_push($params, $like, $like);
        }

        if ($words) {
            $full = self::fullText($words);
            if ($full !== '') {
                $found = self::page($userId, $account, implode(' AND ', array_merge($where, ['MATCH (m.subject, m.from_name, m.from_email, m.to_text, m.body_text) AGAINST (? IN BOOLEAN MODE)'])), array_merge($params, [$full]), $filter, $page);
                if ($found['total'] > 0) {
                    return $found;
                }
                // Nothing: the index skips very common and very short words, and mail stored a moment ago; look again word by word
            }
            foreach ($words as $word) {
                $where[] = '(m.subject LIKE ? OR m.from_name LIKE ? OR m.from_email LIKE ? OR m.to_text LIKE ? OR m.body_text LIKE ?)';
                $like = '%' . self::like($word) . '%';
                array_push($params, $like, $like, $like, $like, $like);
            }
        }

        return self::page($userId, $account, implode(' AND ', $where), $params, $filter, $page);
    }

    /** @return array{0: list<string>, 1: list<string>} words, and de:/from: values */
    public static function parse(string $query): array
    {
        $words = $from = [];
        preg_match_all('/(de:|from:)?("([^"]*)"|\S+)/iu', mb_substr(trim($query), 0, 200), $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $value = trim($m[3] ?? '') !== '' ? trim($m[3]) : trim($m[2], '"');
            if ($value === '') {
                continue;
            }
            if ($m[1] !== '') {
                $from[] = $value;
            } else {
                $words[] = $value;
            }
        }

        return [array_slice($words, 0, 8), array_slice($from, 0, 3)];
    }

    /** Boolean mode: every word required, as a prefix, split where the index splits ("awa@client.cm" is three words) */
    public static function fullText(array $words): string
    {
        $terms = [];
        foreach ($words as $word) {
            foreach (preg_split('/[^\p{L}\p{N}_]+/u', $word) ?: [] as $token) {
                if (mb_strlen($token) >= 3) {
                    $terms[] = '+' . $token . '*';
                }
            }
        }

        return implode(' ', array_unique($terms));
    }

    private static function like(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /** @return array{rows: list<array>, total: int, page: int} */
    private static function page(int $userId, array $account, string $where, array $params, string $filter, int $page): array
    {
        $agg = "SELECT m.thread_id,
                       SUM(CASE WHEN m.is_seen = 0 THEN 1 ELSE 0 END) AS row_unread,
                       MAX(m.is_flagged) AS row_flagged,
                       MAX(m.received_at) AS row_last_at,
                       COUNT(*) AS row_count,
                       MAX(m.has_attachments) AS row_attach,
                       MAX(m.is_draft) AS row_draft,
                       GROUP_CONCAT(DISTINCT m.folder_id) AS row_folders
                  FROM mail_messages m
                 WHERE {$where} AND m.thread_id IS NOT NULL
              GROUP BY m.thread_id";

        $filterSql = '';
        $filterParams = [];
        $filter = in_array($filter, self::filtersFor($account), true) ? $filter : 'all';
        switch ($filter) {
            case 'unread':
                $filterSql = ' AND agg.row_unread > 0';
                break;
            case 'mine':
                $filterSql = ' AND t.assigned_to = ?';
                $filterParams[] = $userId;
                break;
            case 'open':
                $filterSql = " AND t.status = 'open'";
                break;
            case 'done':
                $filterSql = " AND t.status = 'done'";
                break;
        }

        $from = " FROM mail_threads t JOIN ({$agg}) agg ON agg.thread_id = t.id WHERE t.account_id = ?{$filterSql}";
        $all = array_merge($params, [(int) $account['id']], $filterParams);

        $total = (int) Db::value('SELECT COUNT(*)' . $from, $all);
        $page = max(1, min($page, (int) ceil(max($total, 1) / self::PER_PAGE)));
        $rows = Db::all(
            'SELECT t.*, agg.row_unread, agg.row_flagged, agg.row_last_at, agg.row_count, agg.row_attach, agg.row_draft, agg.row_folders'
            . $from . ' ORDER BY agg.row_last_at DESC, t.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $all,
        );

        return ['rows' => array_map(function (array $r) {
            $extra = [
                'row_unread' => (int) $r['row_unread'],
                'row_flagged' => (bool) $r['row_flagged'],
                'row_last_at' => $r['row_last_at'],
                'row_count' => (int) $r['row_count'],
                'row_attach' => (bool) $r['row_attach'],
                'row_draft' => (bool) $r['row_draft'],
                'row_folders' => array_map('intval', array_filter(explode(',', (string) $r['row_folders']))),
            ];
            foreach (array_keys($extra) as $k) {
                unset($r[$k]);
            }

            return Store::threadRow($r) + $extra;
        }, $rows), 'total' => $total, 'page' => $page];
    }
}
