<?php

namespace LPC\Mail\Ai;

use LPC\Mail\Db;
use LPC\Mail\MailAccess;
use Throwable;

/**
 * Finds the mail that answers a question, over bureau's copy and only in the mailboxes the person can read
 * (docs/MAIL.md §7.6). The contract for Praxis AI: Demander à votre courrier uses it now; a later bureau-wide
 * search can call it as it is. Never Indésirables, Corbeille or Brouillons; never attachments.
 *
 *   MailSearch::for($userId, 'facture Marc septembre', 20, [$boxId])
 *   → list of {message_id, thread_id, account_id, subject, from, date ('Y-m-d H:i:s', app time), excerpt}
 *
 * FULLTEXT ft_mail_messages_search (subject, from_name, from_email, to_text, body_text) with MATCH … AGAINST
 * first; then LIKE over the same columns for words FULLTEXT can't see (under three letters, e.g. "T4") or mail
 * not indexed yet. $accountIds only narrows: never wider than MailAccess::accountIds($userId).
 */
final class MailSearch
{
    public const SKIP_ROLES = ['spam', 'trash', 'drafts'];

    /** Words that say nothing about which mail (French first, then English) */
    private const FILLER = [
        'a', 'à', 'ai', 'au', 'aux', 'avec', 'avez', 'avoir', 'avons', 'ça', 'ce', 'ceci', 'cela', 'ces', 'cet', 'cette', 'chercher',
        'cherche', 'combien', 'comment', 'concernant', 'contre', 'courriel', 'courriels', 'courrier', 'courriers', 'd', 'dans', 'de',
        'depuis', 'des', 'dernier', 'dernière', 'derniers', 'dernières', 'dire', 'dit', 'dites', 'donne', 'donner', 'du', 'e-mail',
        'e-mails', 'elle', 'elles', 'en', 'encore', 'entre', 'envoyé', 'envoyée', 'envoyés', 'est', 'est-ce', 'et', 'été', 'être',
        'eu', 'fait', 'faire', 'faut', 'il', 'ils', 'j', 'je', 'l', 'la', 'le', 'les', 'leur', 'leurs', 'lui', 'm', 'ma', 'mail',
        'mails', 'me', 'merci', 'mes', 'message', 'messages', 'moi', 'mon', 'montre', 'montrer', 'n', 'ne', 'nos', 'notre', 'nous',
        'on', 'ont', 'ou', 'où', 'par', 'parle', 'parlé', 'pas', 'peux', 'peut', 'plus', 'pour', 'pourquoi', 'propos', 'qu', 'quand',
        'que', 'quel', 'quelle', 'quelles', 'quels', 'qui', 'quoi', 'récent', 'récente', 'récents', 'reçu', 'reçue', 'reçus', 's',
        'sa', 'sait', 'se', 'ses', 'si', 'son', 'sont', 'stp', 'sujet', 'sur', 'svp', 't', 'ta', 'te', 'tes', 'toi', 'ton', 'tous',
        'tout', 'toute', 'toutes', 'trouve', 'trouver', 'tu', 'un', 'une', 'vos', 'votre', 'vous', 'y',
        'about', 'after', 'all', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'by', 'can', 'did', 'do', 'does', 'email', 'emails',
        'for', 'from', 'has', 'have', 'how', 'i', 'in', 'is', 'it', 'me', 'my', 'of', 'on', 'or', 'our', 'please', 'said', 'sent',
        'that', 'the', 'this', 'to', 'us', 'was', 'we', 'what', 'when', 'where', 'which', 'who', 'why', 'with', 'you', 'your',
    ];

    private const COLUMNS = 'm.id, m.thread_id, m.account_id, m.message_id_hash, m.subject, m.from_name, m.from_email, m.received_at, m.snippet, m.body_text';

    private const MATCH = 'MATCH(m.subject, m.from_name, m.from_email, m.to_text, m.body_text) AGAINST (? IN NATURAL LANGUAGE MODE)';

    /**
     * @param list<int>|null $accountIds narrow to these mailboxes (still only those the person can read)
     * @return list<array{message_id: int, thread_id: int, account_id: int, subject: string, from: string, date: ?string, excerpt: string}>
     */
    public static function for(int $userId, string $question, int $limit = 20, ?array $accountIds = null): array
    {
        $readable = MailAccess::accountIds($userId, 'read');
        $accounts = $accountIds === null ? $readable : array_values(array_intersect($readable, array_map('intval', $accountIds)));
        if (!$accounts || $limit < 1) {
            return [];
        }
        $folders = array_map('intval', Db::column(
            'SELECT id FROM mail_folders WHERE account_id IN (' . Db::marks(count($accounts)) . ') AND role NOT IN (' . Db::marks(count(self::SKIP_ROLES)) . ')',
            array_merge($accounts, self::SKIP_ROLES),
        ));
        if (!$folders) {
            return [];
        }
        // Both lists, every time: a folder id alone is never trusted to belong to a readable mailbox
        $where = 'm.account_id IN (' . Db::marks(count($accounts)) . ') AND m.folder_id IN (' . Db::marks(count($folders)) . ') AND m.thread_id IS NOT NULL';
        $params = array_merge($accounts, $folders);

        $terms = self::terms($question);
        if (!$terms) {
            $rows = Db::all('SELECT ' . self::COLUMNS . " FROM mail_messages m WHERE {$where} ORDER BY m.received_at DESC, m.id DESC LIMIT " . ($limit * 2), $params);

            return self::shape(self::unique($rows), [], $limit);
        }

        $found = [];
        try {
            $words = implode(' ', $terms);
            $found = Db::all(
                'SELECT ' . self::COLUMNS . ', ' . self::MATCH . " AS score FROM mail_messages m WHERE {$where} AND " . self::MATCH
                . ' ORDER BY score DESC, m.received_at DESC LIMIT ' . ($limit * 3),
                array_merge([$words], $params, [$words]),
            );
        } catch (Throwable $e) {
            // no FULLTEXT on this database: LIKE alone still answers
            error_log('mail/ai: FULLTEXT search unavailable: ' . get_class($e));
        }

        if (count(self::unique($found)) < $limit) {
            $ors = [];
            $likeParams = [];
            foreach ($terms as $t) {
                $p = '%' . addcslashes($t, '\\%_') . '%';
                $ors[] = '(m.subject LIKE ? OR m.from_name LIKE ? OR m.from_email LIKE ? OR m.to_text LIKE ? OR m.body_text LIKE ?)';
                array_push($likeParams, $p, $p, $p, $p, $p);
            }
            $seen = array_map(fn ($r) => (int) $r['id'], $found);
            $like = Db::all(
                'SELECT ' . self::COLUMNS . " FROM mail_messages m WHERE {$where} AND (" . implode(' OR ', $ors) . ')'
                . ($seen ? ' AND m.id NOT IN (' . Db::marks(count($seen)) . ')' : '')
                . ' ORDER BY m.received_at DESC, m.id DESC LIMIT 300',
                array_merge($params, $likeParams, $seen),
            );
            // More of the question's words first, then newer
            usort($like, fn ($a, $b) => [self::hits($b, $terms), (string) $b['received_at'], (int) $b['id']] <=> [self::hits($a, $terms), (string) $a['received_at'], (int) $a['id']]);
            $found = array_merge($found, $like);
        }

        return self::shape(self::unique($found), $terms, $limit);
    }

    /** The words of a question worth searching for (at most 8) @return list<string> */
    public static function terms(string $question): array
    {
        $words = preg_split('/[^\p{L}\p{N}@._-]+/u', mb_strtolower($question), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_map(fn ($w) => trim($w, '._-'), $words);
        $words = array_filter($words, fn ($w) => mb_strlen($w) >= 2 && !in_array($w, self::FILLER, true));
        $words = array_slice(array_values(array_unique($words)), 0, 8);

        // A French plural also looks for its singular: neither FULLTEXT nor LIKE '%factures%' finds « facture »,
        // so « Quelles factures fournisseurs… » missed the very invoice it asked about (found while filming the
        // training videos). Only words of five letters or more: « avis », « pas » and the like stay as typed.
        $singulars = [];
        foreach ($words as $w) {
            if (mb_strlen($w) >= 5 && preg_match('/[^s]s$/u', $w)) {
                $singulars[] = mb_substr($w, 0, -1);
            } elseif (mb_strlen($w) >= 5 && str_ends_with($w, 'aux')) {
                $singulars[] = mb_substr($w, 0, -3) . 'al';
            }
        }

        return array_values(array_unique(array_merge($words, $singulars)));
    }

    private static function hits(array $row, array $terms): int
    {
        $hay = mb_strtolower(implode(' ', [$row['subject'], $row['from_name'], $row['from_email'], $row['body_text']]));

        return count(array_filter($terms, fn ($t) => str_contains($hay, $t)));
    }

    /** One per message (a copy in Boîte de réception and Archives counts once), best first */
    private static function unique(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r['message_id_hash'] ?: 'id' . $r['id']] ??= $r;
        }

        return array_values($out);
    }

    private static function shape(array $rows, array $terms, int $limit): array
    {
        return array_map(fn ($r) => [
            'message_id' => (int) $r['id'],
            'thread_id' => (int) $r['thread_id'],
            'account_id' => (int) $r['account_id'],
            'subject' => (string) ($r['subject'] ?: '(sans objet)'),
            'from' => trim(($r['from_name'] ? $r['from_name'] . ' ' : '') . ($r['from_email'] ? '<' . $r['from_email'] . '>' : '')) ?: 'Expéditeur inconnu',
            'date' => $r['received_at'] ?: null,
            'excerpt' => self::excerpt((string) $r['body_text'], (string) $r['snippet'], $terms),
        ], array_slice($rows, 0, $limit));
    }

    private static function excerpt(string $body, string $snippet, array $terms): string
    {
        $width = (int) Settings::config('excerpt_chars', 400);
        $text = MailText::flat(MailText::withoutQuotes($body));
        if ($text === '') {
            return $snippet;
        }
        $at = null;
        foreach ($terms as $t) {
            $p = mb_stripos($text, $t);
            if ($p !== false && ($at === null || $p < $at)) {
                $at = $p;
            }
        }
        $start = max(0, ($at ?? 0) - 120);
        $piece = mb_substr($text, $start, $width);

        return ($start > 0 ? '… ' : '') . $piece . (mb_strlen($text) > $start + $width ? ' …' : '');
    }
}
