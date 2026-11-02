<?php

namespace LPC\Mail;

/**
 * Reading mail rows. Every row is a plain array with its JSON columns decoded and its ids as int:
 *
 *   account  id, kind, email, display_name, owner_user_id, username, imap_*, smtp_*, status, last_error,
 *            capabilities (list), folders_synced_at, last_synced_at   (never the secret: see accountWithSecret)
 *   folder   id, account_id, path, name, delimiter, role, attributes (list), selectable, uidvalidity, uidnext,
 *            highestmodseq, unread_count, total_count, server_unseen, server_total, initial_synced_at, synced_at
 *   thread   id, account_id, subject, subject_key, snippet, participants (list), message_count, unread_count,
 *            has_attachments, first/last_message_at, last_inbound_at, status, assigned_*, done_*, ai_summary, ai_summary_at
 *   message  id, account_id, folder_id, thread_id, uid, message_id, message_id_hash, in_reply_to, refs, subject,
 *            from_name, from_email, to_list, cc_list, bcc_list, reply_to_list (lists of {name, email}), to_text,
 *            sent_at, received_at, size, is_* flags (bool), has_attachments, is_outbound, snippet, body_state,
 *            parts, list_unsubscribe, seen_by, seen_at, sent_by   (bodies only through MailFetch::body())
 *
 * These read without access checks: callers check with MailAccess first (docs/MAIL.md §7.1).
 */
final class Store
{
    public const ROLE_ORDER = ['inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'spam' => 4, 'trash' => 5, 'other' => 6];

    public const ROLE_LABELS = [
        'inbox' => 'Boîte de réception', 'drafts' => 'Brouillons', 'sent' => 'Envoyés',
        'archive' => 'Archives', 'spam' => 'Indésirables', 'trash' => 'Corbeille',
    ];

    private const ACCOUNT_COLUMNS = 'id, kind, email, display_name, owner_user_id, username, imap_host, imap_port, imap_encryption,
        smtp_host, smtp_port, smtp_encryption, status, last_error, capabilities, folders_synced_at, last_synced_at, created_at, updated_at';

    private const MESSAGE_COLUMNS = 'id, account_id, folder_id, thread_id, uid, message_id, message_id_hash, in_reply_to, refs, subject,
        from_name, from_email, to_list, cc_list, bcc_list, reply_to_list, to_text, sent_at, received_at, size, is_seen, is_flagged,
        is_answered, is_draft, has_attachments, is_outbound, snippet, body_state, parts, list_unsubscribe, seen_by, seen_at, sent_by,
        created_at, updated_at';

    // ------------------------------------------------------------------ accounts

    public static function account(int $id): ?array
    {
        $row = Db::one('SELECT ' . self::ACCOUNT_COLUMNS . ' FROM mail_accounts WHERE id = ?', [$id]);

        return $row ? self::accountRow($row) : null;
    }

    public static function accountByEmail(string $email): ?array
    {
        $row = Db::one('SELECT ' . self::ACCOUNT_COLUMNS . ' FROM mail_accounts WHERE email = ?', [strtolower(trim($email))]);

        return $row ? self::accountRow($row) : null;
    }

    /** With the encrypted password: only MailServer and MailCredentials use this */
    public static function accountWithSecret(int $id): ?array
    {
        $row = Db::one('SELECT ' . self::ACCOUNT_COLUMNS . ', secret FROM mail_accounts WHERE id = ?', [$id]);

        return $row ? self::accountRow($row) + ['secret' => $row['secret']] : null;
    }

    /** @param string $where SQL after WHERE, with ? placeholders @return list<array> */
    public static function accounts(string $where = '1 = 1', array $params = []): array
    {
        return array_map([self::class, 'accountRow'], Db::all('SELECT ' . self::ACCOUNT_COLUMNS . ' FROM mail_accounts WHERE ' . $where, $params));
    }

    public static function isShared(array $account): bool
    {
        return ($account['kind'] ?? '') === 'shared';
    }

    // ------------------------------------------------------------------ folders

    public static function folder(int $id): ?array
    {
        $row = Db::one('SELECT * FROM mail_folders WHERE id = ?', [$id]);

        return $row ? self::folderRow($row) : null;
    }

    /** The mailbox's folder with this role (inbox, sent, drafts, archive, spam, trash), if the server has one */
    public static function folderByRole(int $accountId, string $role): ?array
    {
        $row = Db::one('SELECT * FROM mail_folders WHERE account_id = ? AND role = ? AND selectable = 1 ORDER BY id LIMIT 1', [$accountId, $role]);

        return $row ? self::folderRow($row) : null;
    }

    /** @return list<array> standard folders first (Store::ROLE_ORDER), then the others by name */
    public static function folders(int $accountId, bool $selectableOnly = false): array
    {
        $rows = array_map([self::class, 'folderRow'], Db::all(
            'SELECT * FROM mail_folders WHERE account_id = ?' . ($selectableOnly ? ' AND selectable = 1' : ''),
            [$accountId],
        ));
        usort($rows, fn ($a, $b) => [self::ROLE_ORDER[$a['role']] ?? 9, mb_strtolower($a['name'])] <=> [self::ROLE_ORDER[$b['role']] ?? 9, mb_strtolower($b['name'])]);

        return $rows;
    }

    public static function folderLabel(array $folder): string
    {
        return self::ROLE_LABELS[$folder['role']] ?? $folder['name'];
    }

    // ------------------------------------------------------------------ threads

    public static function thread(int $id): ?array
    {
        $row = Db::one('SELECT * FROM mail_threads WHERE id = ?', [$id]);

        return $row ? self::threadRow($row) : null;
    }

    // ------------------------------------------------------------------ messages

    public static function message(int $id): ?array
    {
        $row = Db::one('SELECT ' . self::MESSAGE_COLUMNS . ' FROM mail_messages WHERE id = ?', [$id]);

        return $row ? self::messageRow($row) : null;
    }

    /** @return list<array> in received order */
    public static function messages(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }

        return array_map([self::class, 'messageRow'], Db::all(
            'SELECT ' . self::MESSAGE_COLUMNS . ' FROM mail_messages WHERE id IN (' . Db::marks(count($ids)) . ') ORDER BY received_at, id',
            $ids,
        ));
    }

    /** @return list<array> every copy of every message in a conversation, oldest first */
    public static function threadMessages(int $threadId): array
    {
        return array_map([self::class, 'messageRow'], Db::all(
            'SELECT ' . self::MESSAGE_COLUMNS . ' FROM mail_messages WHERE thread_id = ? ORDER BY received_at, id',
            [$threadId],
        ));
    }

    /** @return list<array> the attachments of a message (details only; content through MailFetch) */
    public static function attachments(int $messageId): array
    {
        return array_map(fn ($r) => [
            'id' => (int) $r['id'], 'message_id' => (int) $r['message_id'], 'section' => $r['section'],
            'filename' => $r['filename'], 'mime' => $r['mime'], 'encoding' => $r['encoding'],
            'size' => (int) $r['size'], 'content_id' => $r['content_id'], 'is_inline' => (bool) $r['is_inline'],
        ], Db::all('SELECT * FROM mail_attachments WHERE message_id = ? ORDER BY id', [$messageId]));
    }

    public static function attachment(int $id): ?array
    {
        $r = Db::one('SELECT * FROM mail_attachments WHERE id = ?', [$id]);

        return $r ? [
            'id' => (int) $r['id'], 'message_id' => (int) $r['message_id'], 'section' => $r['section'],
            'filename' => $r['filename'], 'mime' => $r['mime'], 'encoding' => $r['encoding'],
            'size' => (int) $r['size'], 'content_id' => $r['content_id'], 'is_inline' => (bool) $r['is_inline'],
        ] : null;
    }

    /** How Message-IDs are matched: lower case, no angle brackets, sha1 */
    public static function hashId(?string $messageId): ?string
    {
        $messageId = trim((string) $messageId, " \t<>");

        return $messageId === '' ? null : sha1(strtolower($messageId));
    }

    /** Message-IDs a message answers, oldest first (References, then In-Reply-To; without brackets) */
    public static function referenceIds(array $message): array
    {
        $ids = preg_split('/\s+/', trim((string) ($message['refs'] ?? ''))) ?: [];
        if (!empty($message['in_reply_to'])) {
            $ids[] = $message['in_reply_to'];
        }

        return array_values(array_unique(array_filter($ids)));
    }

    // ------------------------------------------------------------------ row shapes

    public static function accountRow(array $r): array
    {
        unset($r['secret']);
        foreach (['id', 'imap_port', 'smtp_port'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['owner_user_id'] = $r['owner_user_id'] !== null ? (int) $r['owner_user_id'] : null;
        $r['capabilities'] = Db::decode($r['capabilities'] ?? null) ?? [];

        return $r;
    }

    public static function folderRow(array $r): array
    {
        foreach (['id', 'account_id', 'unread_count', 'total_count'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        foreach (['uidvalidity', 'uidnext', 'highestmodseq', 'server_unseen', 'server_total'] as $k) {
            $r[$k] = $r[$k] !== null ? (int) $r[$k] : null;
        }
        $r['selectable'] = (bool) $r['selectable'];
        $r['attributes'] = Db::decode($r['attributes'] ?? null) ?? [];

        return $r;
    }

    public static function threadRow(array $r): array
    {
        foreach (['id', 'account_id', 'message_count', 'unread_count'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        foreach (['assigned_to', 'assigned_by', 'done_by'] as $k) {
            $r[$k] = $r[$k] !== null ? (int) $r[$k] : null;
        }
        $r['has_attachments'] = (bool) $r['has_attachments'];
        $r['participants'] = Db::decode($r['participants'] ?? null) ?? [];
        $r['ai_summary'] = Db::decode($r['ai_summary'] ?? null);

        return $r;
    }

    public static function messageRow(array $r): array
    {
        foreach (['id', 'account_id', 'folder_id', 'uid', 'size'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        foreach (['thread_id', 'seen_by', 'sent_by'] as $k) {
            $r[$k] = $r[$k] !== null ? (int) $r[$k] : null;
        }
        foreach (['is_seen', 'is_flagged', 'is_answered', 'is_draft', 'has_attachments', 'is_outbound'] as $k) {
            $r[$k] = (bool) $r[$k];
        }
        foreach (['to_list', 'cc_list', 'bcc_list', 'reply_to_list'] as $k) {
            $r[$k] = Db::decode($r[$k] ?? null) ?? [];
        }
        $r['parts'] = Db::decode($r['parts'] ?? null) ?? [];

        return $r;
    }
}
