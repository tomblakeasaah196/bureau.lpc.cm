<?php

namespace LPC\Mail\Compose;

use DateTimeImmutable;
use LPC\Mail\Clock;
use LPC\Mail\Db;
use LPC\Mail\MailAccess;
use LPC\Mail\MailError;
use LPC\Mail\MailFetch;
use LPC\Mail\MailServer;
use LPC\Mail\MailTransport;
use LPC\Mail\Store;
use LPC\Mail\View;
use Throwable;

/**
 * Mail being written (mail_outbox; payload keys and statuses in docs/MAIL.md §12, PR 3). A row belongs to the
 * person who writes it: only they open, change, send or discard it, and only while they can send from its
 * mailbox (MailAccess 'send').
 *
 *   draft      being written; saved as they type, with a copy in the server's Drafts
 *   queued     waiting to go: send_at is now + 10 s (Annuler l'envoi) or the time chosen (Programmer)
 *   sending    taken by OutboxSender (one atomic update, so never sent twice); then sent or failed
 *   failed     did not go; the writer was told (bell) and can open it again
 *
 * The Message-ID is chosen when the row is made, so the copy in Drafts, the message sent and its copy in
 * Envoyés carry the same one, and sync credits the writer (mail_messages.sent_by) through message_id_hash.
 * Rows are plain arrays: row() decodes payload and casts ids.
 */
final class Outbox
{
    public const KINDS = ['new', 'reply', 'reply_all', 'forward'];

    public static function make(): self
    {
        return new self();
    }

    public static function row(string $id): ?array
    {
        $r = Db::one('SELECT * FROM mail_outbox WHERE id = ?', [$id]);
        if (!$r) {
            return null;
        }
        foreach (['account_id', 'user_id', 'attempts'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        foreach (['thread_id', 'reply_to_message_id'] as $k) {
            $r[$k] = $r[$k] !== null ? (int) $r[$k] : null;
        }
        $r['payload'] = Db::decode($r['payload']) ?? [];

        return $r;
    }

    /** A new draft: new mail (from $accountId, else the person's first mailbox they can send from), or an answer to / forward of a message */
    public function start(int $userId, string $kind, ?int $accountId = null, ?int $originalId = null, ?string $to = null): array
    {
        if (!in_array($kind, self::KINDS, true) || ($kind !== 'new' && !$originalId)) {
            throw MailError::invalid('Ce message ne peut pas être ouvert ainsi.');
        }
        $original = null;
        if ($originalId) {
            $original = Store::message($originalId) ?? throw MailError::notFound('Ce message n’est plus là. Actualisez la page, puis réessayez.');
            if (!MailAccess::canMessage($userId, $original, 'read')) {
                throw MailError::notFound();
            }
            $accountId = $original['account_id'];

            // Carry on with an unfinished answer to the same message rather than start another
            $open = Db::value(
                "SELECT id FROM mail_outbox WHERE user_id = ? AND reply_to_message_id = ? AND kind = ? AND status = 'draft'
                  AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.touched')) = 'true' ORDER BY updated_at DESC LIMIT 1",
                [$userId, $originalId, $kind],
            );
            if ($open) {
                return self::row((string) $open);
            }
        }
        $account = $accountId ? Store::account($accountId) : null;
        // New mail from a mailbox the person can only read: from one they can write from instead
        if ($kind === 'new' && (!$account || !MailAccess::can($userId, $account, 'send'))) {
            $account = MailAccess::accounts($userId, 'send')[0] ?? null;
            if ($account) {
                $account = Store::account($account['id']);   // access_level is not a column (docs/MAIL.md §7.1)
            }
        }
        if (!$account || !MailAccess::can($userId, $account, 'send')) {
            throw MailError::denied($account
                ? 'Vous pouvez lire cette boîte mais pas écrire depuis elle. Demandez le niveau « Lecture et envoi » à l’un de ses gestionnaires.'
                : 'Aucune boîte mail ne vous permet d’écrire. Connectez la vôtre sur Boîtes mail.');
        }

        $payload = self::emptyPayload($kind);
        if ($to !== null && trim($to) !== '') {
            $payload['to'] = Recipients::parse(preg_replace('/^mailto:/i', '', $to))['valid'];
        }
        if ($original) {
            $payload = $this->answer($userId, $kind, $account, $original) + $payload;
        }

        return $this->create($userId, $account, $kind, $payload, $original['thread_id'] ?? null, $original['id'] ?? null);
    }

    /** Open one of the person's own rows: a draft, or a scheduled or unsent message, which goes back to draft */
    public function open(int $userId, string $outboxId): array
    {
        $row = $this->own($userId, $outboxId, ['draft', 'queued', 'failed']);
        if ($row['status'] !== 'draft') {
            $p = $row['payload'];
            unset($p['scheduled']);
            $done = Db::exec(
                "UPDATE mail_outbox SET status = 'draft', send_at = NULL, payload = ?, updated_at = ? WHERE id = ? AND status IN ('queued', 'failed')",
                [Db::json($p), Clock::sql(), $outboxId],
            );
            if (!$done) {
                throw MailError::invalid('Ce message est déjà parti.');
            }
            $row = self::row($outboxId);
        }

        return $row;
    }

    /** A message in the server's Drafts: bureau's own draft row when bureau wrote it, else a new draft made from it */
    public function fromServerDraft(int $userId, int $messageId): array
    {
        $draft = Store::message($messageId) ?? throw MailError::notFound('Ce brouillon n’est plus là. Actualisez la page, puis réessayez.');
        if (!MailAccess::canMessage($userId, $draft, 'read')) {
            throw MailError::notFound();
        }
        $account = Store::account($draft['account_id']) ?? throw MailError::notFound();
        if (!MailAccess::can($userId, $account, 'send')) {
            throw MailError::denied('Vous pouvez lire cette boîte mais pas écrire depuis elle. Demandez le niveau « Lecture et envoi » à l’un de ses gestionnaires.');
        }
        if ($draft['message_id_hash']) {
            $mine = Db::value(
                "SELECT id FROM mail_outbox WHERE user_id = ? AND account_id = ? AND message_id_hash = ? AND status IN ('draft', 'queued', 'failed') LIMIT 1",
                [$userId, $account['id'], $draft['message_id_hash']],
            );
            if ($mine) {
                return $this->open($userId, (string) $mine);
            }
            if (Db::value("SELECT 1 FROM mail_outbox WHERE user_id <> ? AND account_id = ? AND message_id_hash = ? AND status IN ('draft', 'queued', 'sending') LIMIT 1", [$userId, $account['id'], $draft['message_id_hash']])) {
                throw MailError::denied('Ce brouillon est en cours d’écriture par une autre personne.');
            }
        }

        try {
            $body = MailFetch::make()->body($userId, $draft);
        } catch (Throwable) {
            $body = ['text' => (string) $draft['snippet'], 'html' => null];
        }
        $original = null;
        if ($draft['in_reply_to']) {
            $oid = Db::value('SELECT id FROM mail_messages WHERE account_id = ? AND message_id_hash = ? AND id <> ? ORDER BY id LIMIT 1', [$account['id'], Store::hashId($draft['in_reply_to']), $draft['id']]);
            $original = $oid ? Store::message((int) $oid) : null;
        }

        $kind = $original ? 'reply' : 'new';
        $p = self::emptyPayload($kind);
        $p['signature'] = 'none';   // a draft written elsewhere may already hold its signature
        $p['to'] = Recipients::parse($draft['to_list'])['valid'];
        $p['cc'] = Recipients::parse($draft['cc_list'])['valid'];
        $p['bcc'] = Recipients::parse($draft['bcc_list'])['valid'];
        $p['subject'] = (string) $draft['subject'];
        $p['html'] = !empty($body['html']) ? HtmlCleaner::clean($body['html']) : HtmlCleaner::fromText($body['text']);
        $p['in_reply_to'] = $draft['in_reply_to'];
        $p['references'] = $draft['refs'];
        foreach (Store::attachments($draft['id']) as $a) {
            if (!$a['is_inline']) {
                $p['attachments'][] = ['key' => 'o' . $a['id'], 'name' => $a['filename'], 'mime' => $a['mime'], 'size' => $a['size'], 'source' => 'original', 'attachment_id' => $a['id']];
            }
        }
        $folder = Store::folder($draft['folder_id']);
        $p['server_draft'] = $folder ? ['path' => $folder['path'], 'uid' => $draft['uid']] : null;
        $p['touched'] = true;

        return $this->create($userId, $account, $kind, $p, $draft['thread_id'], $original['id'] ?? null);
    }

    /**
     * Save what the person wrote (as they type). $fields: account_id, to, cc, bcc (lists of {name, email} or
     * "Name <a@b>" strings), subject, html, signature (full|slim|none), quote (bool). Fields left out keep
     * their value. @return array{row: array, invalid: array<string, list<string>>} what could not be read as an address (not kept)
     */
    public function save(int $userId, string $outboxId, array $fields): array
    {
        $row = $this->own($userId, $outboxId, ['draft']);
        $p = $row['payload'];
        $invalid = [];
        $set = [];

        if (isset($fields['account_id']) && (int) $fields['account_id'] > 0 && (int) $fields['account_id'] !== $row['account_id']) {
            $account = Store::account((int) $fields['account_id']);
            if (!$account || !MailAccess::can($userId, $account, 'send')) {
                throw MailError::field('account_id', 'Vous ne pouvez pas écrire depuis cette boîte.');
            }
            $this->removeServerDraft($row);
            $p = self::row($outboxId)['payload'];
            $messageId = MailTransport::newMessageId($account);
            $set += ['account_id' => $account['id'], 'message_id' => $messageId, 'message_id_hash' => Store::hashId($messageId)];
        }
        foreach (['to', 'cc', 'bcc'] as $field) {
            if (array_key_exists($field, $fields)) {
                $parsed = Recipients::parse(is_array($fields[$field]) ? $fields[$field] : (string) $fields[$field]);
                $p[$field] = $parsed['valid'];
                if ($parsed['invalid']) {
                    $invalid[$field] = $parsed['invalid'];
                }
            }
        }
        if (array_key_exists('subject', $fields)) {
            $p['subject'] = mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', (string) $fields['subject'])), 0, 250);
        }
        if (array_key_exists('html', $fields)) {
            $p['html'] = HtmlCleaner::clean(mb_substr((string) $fields['html'], 0, 2000000));
        }
        if (isset($fields['signature']) && isset(Signatures::STYLES[$fields['signature']])) {
            $p['signature'] = $fields['signature'];
        }
        if (array_key_exists('quote', $fields)) {
            $p['quote'] = (bool) $fields['quote'] && !empty($p['quote_html']);
        }
        $p['touched'] = true;

        Db::update('mail_outbox', $set + ['payload' => Db::json($p), 'updated_at' => Clock::sql()], $outboxId);

        return ['row' => self::row($outboxId), 'invalid' => $invalid];
    }

    /**
     * Send it: in 10 seconds (the undo window), or at $sendAt (scheduled). Throws MailError on the field
     * that stops it (no recipient, too many, files too large, a time in the past).
     */
    public function queue(int $userId, string $outboxId, ?DateTimeImmutable $sendAt = null): array
    {
        $row = $this->own($userId, $outboxId, ['draft']);
        $p = $row['payload'];
        $count = count($p['to'] ?? []) + count($p['cc'] ?? []) + count($p['bcc'] ?? []);
        if ($count === 0) {
            throw MailError::field('to', 'Ajoutez au moins un destinataire.');
        }
        $max = (int) ComposeConfig::get('max_recipients', 100);
        if ($count > $max) {
            throw MailError::field('to', "Un message peut partir vers {$max} personnes au plus. Répartissez la liste sur plusieurs messages.");
        }
        if (self::attachmentBytes($p) > self::maxBytes()) {
            throw MailError::field('attachments', 'Les pièces jointes dépassent ' . View::size(self::maxBytes()) . ' au total. Retirez-en, ou partagez un lien à la place.');
        }
        if ($sendAt !== null) {
            $now = Clock::now();
            if ($sendAt < $now->modify('+1 minute') || $sendAt > $now->modify('+1 year')) {
                throw MailError::field('send_at', 'Choisissez un moment au moins une minute plus tard, et dans l’année.');
            }
        }

        $p['scheduled'] = $sendAt !== null;
        $done = Db::exec(
            "UPDATE mail_outbox SET status = 'queued', payload = ?, send_at = ?, attempts = 0, last_error = NULL, updated_at = ? WHERE id = ? AND status = 'draft'",
            [Db::json($p), $sendAt ? Clock::format($sendAt) : Clock::sql((int) ComposeConfig::get('undo_seconds', 10)), Clock::sql(), $outboxId],
        );
        if (!$done) {
            throw MailError::invalid('Ce message ne peut plus être modifié.');
        }

        return self::row($outboxId);
    }

    /** Annuler l'envoi, or cancel a schedule: back to a draft, if it hasn't started going. @return bool done */
    public function undo(int $userId, string $outboxId): bool
    {
        $row = $this->own($userId, $outboxId, ['queued', 'sending', 'sent', 'draft', 'failed']);
        $p = $row['payload'];
        unset($p['scheduled']);

        return Db::exec(
            "UPDATE mail_outbox SET status = 'draft', send_at = NULL, payload = ?, updated_at = ? WHERE id = ? AND status = 'queued'",
            [Db::json($p), Clock::sql(), $outboxId],
        ) > 0;
    }

    /** Throw the draft away (bureau's row, its files, and its copy in the server's Drafts). @return bool false when it had already started going */
    public function discard(int $userId, string $outboxId): bool
    {
        $row = $this->own($userId, $outboxId, ['draft', 'failed', 'queued']);
        if ($row['status'] === 'queued' && !$this->undo($userId, $outboxId)) {
            return false;
        }
        $this->removeServerDraft(self::row($outboxId));
        Db::exec("DELETE FROM mail_outbox WHERE id = ? AND status IN ('draft', 'failed')", [$outboxId]);   // files go with it (foreign key)

        return true;
    }

    /** Attach a file (bytes as uploaded). Throws MailError when it would make the files too large. */
    public function addUpload(int $userId, string $outboxId, string $name, string $content): array
    {
        $row = $this->own($userId, $outboxId, ['draft']);
        $p = $row['payload'];
        $size = strlen($content);
        if ($size === 0) {
            throw MailError::field('attachments', 'Ce fichier est vide.');
        }
        if (self::attachmentBytes($p) + $size > self::maxBytes()) {
            throw MailError::tooLarge('Avec ce fichier, les pièces jointes dépasseraient ' . View::size(self::maxBytes()) . '. Partagez un lien vers les gros fichiers à la place.');
        }
        $key = 'u' . bin2hex(random_bytes(6));
        $name = self::fileName($name);
        Files::put($outboxId, $key, $content);

        // Read again: another file may have arrived while this one was stored
        $p = self::row($outboxId)['payload'];
        $attachment = ['key' => $key, 'name' => $name, 'mime' => self::mimeOf($name, $content), 'size' => $size, 'source' => 'upload'];
        $p['attachments'][] = $attachment;
        $p['touched'] = true;
        Db::update('mail_outbox', ['payload' => Db::json($p), 'updated_at' => Clock::sql()], $outboxId);

        return $attachment;
    }

    public function removeAttachment(int $userId, string $outboxId, string $key): void
    {
        $row = $this->own($userId, $outboxId, ['draft']);
        $p = $row['payload'];
        $p['attachments'] = array_values(array_filter($p['attachments'] ?? [], fn ($a) => $a['key'] !== $key));
        Files::remove($outboxId, $key);
        Db::update('mail_outbox', ['payload' => Db::json($p), 'updated_at' => Clock::sql()], $outboxId);
    }

    /**
     * Keep the draft in the server's Drafts folder too (so other mail apps see it): the new copy goes in, then
     * the previous one is removed. Best effort: bureau keeps the draft whatever the server says.
     */
    public function saveServerDraft(int $userId, string $outboxId): bool
    {
        $row = $this->own($userId, $outboxId, ['draft']);
        $account = Store::account($row['account_id']);
        $drafts = $account ? Store::folderByRole($account['id'], 'drafts') : null;
        if (!$drafts || $account['status'] !== 'active' || !$this->hasContent($row['payload'])) {
            return false;
        }

        try {
            $m = MessageBuilder::build($row, $userId, true);
            $m->from($account['email'], (string) $account['display_name']);
            $m->date(Clock::now());
            if (!$m->recipients()) {
                $m->to($account['email']);   // a draft without anyone yet: MIME needs a To; nobody receives a draft
            }
            $raw = $m->toString();
            $old = $row['payload']['server_draft'] ?? null;
            $session = MailServer::make()->open($account);
            try {
                $uid = $session->append($drafts['path'], $raw, ['\\Draft', '\\Seen']);
                if ($old && !empty($old['uid'])) {
                    $session->select($old['path']);
                    $session->expunge([(int) $old['uid']]);
                    self::forgetCopy($account['id'], $old);
                }
            } finally {
                try {
                    $session->logout();
                } catch (Throwable) {
                }
            }
        } catch (Throwable $e) {
            error_log('Mail: draft not saved on the server (outbox ' . $outboxId . '): ' . $e->getMessage());

            return false;
        }

        $p = self::row($outboxId)['payload'];
        $p['server_draft'] = $uid ? ['path' => $drafts['path'], 'uid' => $uid] : null;
        $p['server_draft_at'] = Clock::sql();
        Db::update('mail_outbox', ['payload' => Db::json($p)], $outboxId);

        return true;
    }

    /** Remove the draft's copy from the server's Drafts (after sending, or when discarded). Best effort. */
    public function removeServerDraft(?array $row): void
    {
        $old = $row['payload']['server_draft'] ?? null;
        if (!$row || !$old || empty($old['uid'])) {
            return;
        }
        $account = Store::account($row['account_id']);
        try {
            if ($account) {
                $session = MailServer::make()->open($account);
                try {
                    $session->select($old['path']);
                    $session->expunge([(int) $old['uid']]);
                } finally {
                    try {
                        $session->logout();
                    } catch (Throwable) {
                    }
                }
                self::forgetCopy($account['id'], $old);
            }
        } catch (Throwable $e) {
            error_log('Mail: old draft not removed from the server (outbox ' . $row['id'] . '): ' . $e->getMessage());
        }
        $p = self::row($row['id'])['payload'] ?? null;
        if ($p !== null) {
            $p['server_draft'] = null;
            Db::update('mail_outbox', ['payload' => Db::json($p)], $row['id']);
        }
    }

    /** Anything worth keeping: a recipient, a subject, some text or a file */
    public function hasContent(array $p): bool
    {
        return !empty($p['to']) || !empty($p['cc']) || !empty($p['bcc']) || trim((string) ($p['subject'] ?? '')) !== ''
            || !HtmlCleaner::isBlank($p['html'] ?? '') || (bool) array_filter($p['attachments'] ?? [], fn ($a) => ($a['source'] ?? '') === 'upload');
    }

    /** A draft to keep when the composer closes: new mail with anything in it; an answer only with text or a file of its own */
    public function worthKeeping(array $row): bool
    {
        $p = $row['payload'] ?? [];
        if ($row['kind'] === 'new') {
            return $this->hasContent($p);
        }

        return !HtmlCleaner::isBlank($p['html'] ?? '') || (bool) array_filter($p['attachments'] ?? [], fn ($a) => ($a['source'] ?? '') === 'upload');
    }

    /** Drafts opened and never written in (the page was left): removed after a day. @return int how many */
    public function pruneUntouched(): int
    {
        $ids = Db::column(
            "SELECT id FROM mail_outbox WHERE status = 'draft' AND updated_at < ?
               AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.touched')), 'false') <> 'true'",
            [Clock::sql(-3600 * (int) ComposeConfig::get('untouched_hours', 24))],
        );
        foreach ($ids as $id) {
            Db::exec("DELETE FROM mail_outbox WHERE id = ? AND status = 'draft'", [$id]);
        }

        return count($ids);
    }

    /**
     * The person's own row in one of these states, from a mailbox they can still send from. Throws MailError:
     * 404 for someone else's row (it does not exist for them), 403 without send level, 422 in another state.
     */
    public function own(int $userId, string $outboxId, array $statuses): array
    {
        $row = preg_match('/^[0-9a-f-]{36}$/', $outboxId) ? self::row($outboxId) : null;
        if (!$row || $row['user_id'] !== $userId) {
            throw MailError::notFound('Ce message n’est plus là. Actualisez la page, puis réessayez.');
        }
        $account = Store::account($row['account_id']);
        if (!$account || !MailAccess::can($userId, $account, 'send')) {
            throw MailError::denied('Vous ne pouvez plus écrire depuis cette boîte mail.');
        }
        if (!in_array($row['status'], $statuses, true)) {
            throw MailError::invalid(match ($row['status']) {
                'sending', 'sent' => 'Ce message est déjà parti.',
                'queued' => 'Ce message attend de partir. Annulez l’envoi d’abord pour le modifier.',
                default => 'Ce message ne peut plus être modifié.',
            });
        }

        return $row;
    }

    /**
     * The person's messages that wait or did not go, and their drafts, for "Programmés et non envoyés"
     * (modules/mail/compose_outbox.php). @return array{scheduled: list<array>, failed: list<array>, drafts: list<array>}
     */
    public function listFor(int $userId): array
    {
        $ids = MailAccess::accountIds($userId, 'send');
        $out = ['scheduled' => [], 'failed' => [], 'drafts' => []];
        if (!$ids) {
            return $out;
        }
        $rows = Db::all(
            "SELECT id FROM mail_outbox WHERE user_id = ? AND account_id IN (" . Db::marks(count($ids)) . ")
                AND (status IN ('queued', 'failed') OR (status = 'draft' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.touched')) = 'true'))
           ORDER BY COALESCE(send_at, updated_at) DESC LIMIT 200",
            array_merge([$userId], $ids),
        );
        foreach ($rows as $r) {
            $row = self::row($r['id']);
            $group = match ($row['status']) {
                'queued' => 'scheduled',
                'failed' => 'failed',
                default => 'drafts',
            };
            $out[$group][] = $row;
        }
        usort($out['scheduled'], fn ($a, $b) => strcmp((string) $a['send_at'], (string) $b['send_at']));

        return $out;
    }

    public static function attachmentBytes(array $p): int
    {
        return (int) array_sum(array_map(fn ($a) => (int) ($a['size'] ?? 0), $p['attachments'] ?? []));
    }

    public static function maxBytes(): int
    {
        return (int) ComposeConfig::get('max_attachment_bytes', 18 * 1024 * 1024);
    }

    public static function emptyPayload(string $kind): array
    {
        return [
            'to' => [], 'cc' => [], 'bcc' => [], 'subject' => '', 'html' => '',
            'signature' => in_array($kind, ['reply', 'reply_all'], true) ? 'slim' : 'full',
            'quote' => false, 'quote_html' => null, 'quote_text' => null,
            'in_reply_to' => null, 'references' => null,
            'attachments' => [], 'server_draft' => null, 'touched' => false,
        ];
    }

    /**
     * A file's type from its first bytes, then its name (the live PHP has no fileinfo: docs/MAIL.md §3, JBS
     * Fix 2). Only labels the attachment for the recipient's mail app; nothing here trusts it.
     */
    public static function mimeOf(string $name, string $content): string
    {
        $head = substr($content, 0, 8);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $byExt = [
            'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'heic' => 'image/heic', 'txt' => 'text/plain', 'csv' => 'text/csv',
            'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'zip' => 'application/zip', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg',
            'eml' => 'message/rfc822', 'ics' => 'text/calendar', 'xml' => 'application/xml', 'json' => 'application/json',
        ];
        return match (true) {
            str_starts_with($head, '%PDF') => 'application/pdf',
            str_starts_with($head, "\x89PNG") => 'image/png',
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, 'GIF8') => 'image/gif',
            str_starts_with($head, 'RIFF') && substr($content, 8, 4) === 'WEBP' => 'image/webp',
            default => $byExt[$ext] ?? 'application/octet-stream',
        };
    }

    private function create(int $userId, array $account, string $kind, array $payload, ?int $threadId, ?int $originalId): array
    {
        $id = self::uuid();
        $messageId = MailTransport::newMessageId($account);
        $now = Clock::sql();
        Db::insert('mail_outbox', [
            'id' => $id,
            'account_id' => $account['id'],
            'user_id' => $userId,
            'kind' => $kind,
            'status' => 'draft',
            'thread_id' => $threadId,
            'reply_to_message_id' => $originalId,
            'payload' => Db::json($payload),
            'message_id' => $messageId,
            'message_id_hash' => Store::hashId($messageId),
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return self::row($id);
    }

    /** Recipients, subject, threading headers, quote and (forward) files of an answer */
    private function answer(int $userId, string $kind, array $account, array $original): array
    {
        $own = [$account['email']];
        $sender = $original['reply_to_list'] ?: array_filter([['name' => $original['from_name'], 'email' => (string) $original['from_email']]], fn ($a) => $a['email'] !== '');
        $to = $cc = [];
        if ($kind === 'reply' || $kind === 'reply_all') {
            $to = Recipients::parse($original['is_outbound'] ? ($original['to_list'] ?? []) : $sender)['valid'];
            if ($kind === 'reply_all') {
                $others = Recipients::parse(array_merge($original['is_outbound'] ? [] : ($original['to_list'] ?? []), $original['cc_list'] ?? []))['valid'];
                $cc = Recipients::without(Recipients::without($others, $to), $own);
            }
            $without = Recipients::without($to, $own);
            $to = $without ?: $to;   // writing back to yourself stays possible
        }

        $subject = trim((string) $original['subject']);
        $subject = $kind === 'forward'
            ? (preg_match('/^\s*(fwd?|tr)\s*:/iu', $subject) ? $subject : 'TR : ' . $subject)
            : (preg_match('/^\s*(re|aw|réf?)\s*:/iu', $subject) ? $subject : 'RE : ' . $subject);

        try {
            $body = MailFetch::make()->body($userId, $original);
        } catch (Throwable) {
            $body = ['text' => (string) $original['snippet'], 'html' => null];
        }
        $quote = MessageBuilder::quote($kind, $original, $body);

        $references = Store::referenceIds($original);
        if ($original['message_id']) {
            $references[] = $original['message_id'];
        }
        $references = array_slice(array_values(array_unique($references)), -20);

        $attachments = [];
        if ($kind === 'forward') {
            foreach (Store::attachments($original['id']) as $a) {
                if (!$a['is_inline']) {
                    $attachments[] = ['key' => 'o' . $a['id'], 'name' => $a['filename'], 'mime' => $a['mime'], 'size' => $a['size'], 'source' => 'original', 'attachment_id' => $a['id']];
                }
            }
        }

        return [
            'to' => $to,
            'cc' => $cc,
            'subject' => mb_substr($subject, 0, 250),
            'quote' => true,
            'quote_html' => $quote['html'],
            'quote_text' => $quote['text'],
            'in_reply_to' => $kind === 'forward' ? null : $original['message_id'],
            'references' => $kind === 'forward' ? null : (implode(' ', $references) ?: null),
            'attachments' => $attachments,
        ];
    }

    /** bureau's copy of a server draft goes now rather than at the next sync */
    private static function forgetCopy(int $accountId, array $copy): void
    {
        $folder = Db::value('SELECT id FROM mail_folders WHERE account_id = ? AND path = ?', [$accountId, $copy['path']]);
        if ($folder) {
            Db::exec('DELETE FROM mail_messages WHERE folder_id = ? AND uid = ?', [(int) $folder, (int) $copy['uid']]);
        }
    }

    private static function fileName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $name));

        return mb_substr($name !== '' ? $name : 'piece-jointe', 0, 150);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
