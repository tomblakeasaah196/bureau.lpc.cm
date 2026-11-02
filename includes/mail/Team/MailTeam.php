<?php

namespace LPC\Mail\Team;

use LPC\Mail\Clock;
use LPC\Mail\Db;
use LPC\Mail\MailAccess;
use LPC\Mail\MailError;
use LPC\Mail\MailNotices;
use LPC\Mail\People;
use LPC\Mail\Store;
use LPC\Mail\View;

/**
 * Teamwork on a conversation in a shared mailbox (owner decision 9, docs/MAIL.md §2): assignment, Ouverte /
 * Traitée, internal notes and the activity timeline. Personal mailboxes have none of it (404 here).
 *
 * Levels (as JBS): Lecture sees the team bar, notes, activity and presence, adds notes and deletes their own;
 * Lecture et envoi also assigns and marks Ouverte / Traitée; Gestionnaire also deletes anyone's note. Only
 * people with Lecture et envoi can be assigned, since an assignee has to be able to reply.
 *
 * Activity types in mail_thread_activity: assigned / unassigned (data.to, data.from), done, reopened
 * (data.reason = 'reply' and data.message_id when new mail opened it again; user_id null), note,
 * note_deleted (data.note_id), merged (data.from), sent (PR 3: someone replied from bureau).
 */
final class MailTeam
{
    private static ?array $config = null;

    // ------------------------------------------------------------------ settings

    /** includes/mail/Team/team_config.php by key */
    public static function config(string $key, mixed $default = null): mixed
    {
        self::$config ??= require __DIR__ . '/team_config.php';

        return self::$config[$key] ?? $default;
    }

    /** Tests: override some values (null: back to the file) */
    public static function configure(?array $values): void
    {
        self::$config = $values === null ? null : array_merge(require __DIR__ . '/team_config.php', $values);
    }

    // ------------------------------------------------------------------ who

    /** Whether this person sees the team bar of this conversation (a shared mailbox they can read) */
    public static function canSee(int $userId, int $threadId): bool
    {
        $thread = $threadId > 0 ? Store::thread($threadId) : null;
        $account = $thread ? Store::account($thread['account_id']) : null;

        return $account !== null && Store::isShared($account) && MailAccess::can($userId, $account, 'read');
    }

    /**
     * People a conversation in this mailbox can be assigned to (those who can reply from it), by name.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function assignees(array $account): array
    {
        if (!Store::isShared($account)) {
            return [];
        }
        $out = [];
        foreach (MailAccess::readers($account) as $id) {
            if (MailAccess::can($id, $account, 'send')) {
                $out[] = ['id' => $id, 'name' => People::name($id)];
            }
        }
        usort($out, fn ($a, $b) => mb_strtolower($a['name']) <=> mb_strtolower($b['name']));

        return $out;
    }

    // ------------------------------------------------------------------ assignment and status

    /** Assign to someone ($toId) or to nobody (null). @return bool whether anything changed */
    public static function assign(int $byId, int $threadId, ?int $toId): bool
    {
        [$thread, $account] = self::shared($byId, $threadId, 'send');
        $toId = $toId ?: null;
        if ($toId === $thread['assigned_to']) {
            return false;
        }
        if ($toId !== null && !in_array($toId, array_column(self::assignees($account), 'id'), true)) {
            throw MailError::field('assigned_to', "Cette personne ne peut pas répondre depuis {$account['email']}. Choisissez quelqu’un qui a le niveau Lecture et envoi, ou demandez à un gestionnaire de le lui donner.");
        }

        $now = Clock::sql();
        Db::update('mail_threads', [
            'assigned_to' => $toId,
            'assigned_by' => $toId ? $byId : null,
            'assigned_at' => $toId ? $now : null,
            'updated_at' => $now,
        ], $threadId);
        self::log($threadId, $byId, $toId ? 'assigned' : 'unassigned', array_filter(['to' => $toId, 'from' => $thread['assigned_to']]));

        if ($toId && $toId !== $byId) {
            MailNotices::send(
                [$toId],
                'Conversation qui vous est assignée',
                People::name($byId) . ' vous a assigné la conversation « ' . self::subject($thread) . " » de {$account['email']}.",
                MailNotices::inboxUrl($account['id'], $threadId),
                'info',
            );
        }

        return true;
    }

    /** 'open' (Ouverte) or 'done' (Traitée). @return bool whether anything changed */
    public static function setStatus(int $byId, int $threadId, string $status): bool
    {
        [$thread] = self::shared($byId, $threadId, 'send');
        if (!in_array($status, ['open', 'done'], true)) {
            throw MailError::invalid('Choisissez Ouverte ou Traitée.');
        }
        if ($thread['status'] === $status) {
            return false;
        }

        $now = Clock::sql();
        Db::update('mail_threads', $status === 'done'
            ? ['status' => 'done', 'done_by' => $byId, 'done_at' => $now, 'updated_at' => $now]
            : ['status' => 'open', 'done_by' => null, 'done_at' => null, 'updated_at' => $now], $threadId);
        self::log($threadId, $byId, $status === 'done' ? 'done' : 'reopened');

        return true;
    }

    /** New mail in a Traitée conversation opens it again (owner decision 9). Called by the mail.received listener. */
    public static function reopenOnReply(int $threadId, int $messageId): bool
    {
        $now = Clock::sql();
        $changed = Db::exec(
            "UPDATE mail_threads SET status = 'open', done_by = NULL, done_at = NULL, updated_at = ? WHERE id = ? AND status = 'done'",
            [$now, $threadId],
        );
        if ($changed > 0) {
            self::log($threadId, null, 'reopened', ['reason' => 'reply', 'message_id' => $messageId]);
        }

        return $changed > 0;
    }

    // ------------------------------------------------------------------ notes

    /** @return array the note as notes() shows it */
    public static function addNote(int $byId, int $threadId, string $body): array
    {
        [, $account] = self::shared($byId, $threadId, 'read');
        $body = trim(str_replace("\r\n", "\n", $body));
        $max = (int) self::config('note_max', 5000);
        if ($body === '') {
            throw MailError::field('body', 'Écrivez la note avant de l’ajouter.');
        }
        if (mb_strlen($body) > $max) {
            throw MailError::field('body', 'Cette note est trop longue : gardez-la sous ' . number_format($max, 0, ',', ' ') . ' caractères.');
        }

        $now = Clock::sql();
        $id = Db::insert('mail_thread_notes', ['thread_id' => $threadId, 'user_id' => $byId, 'body' => $body, 'created_at' => $now, 'updated_at' => $now]);
        self::log($threadId, $byId, 'note', ['note_id' => $id]);

        return self::noteRow(Db::one('SELECT * FROM mail_thread_notes WHERE id = ?', [$id]), $byId, MailAccess::can($byId, $account, 'manage'));
    }

    /** Your own note, or anyone's for a Gestionnaire. Kept in the table (deleted_at), hidden everywhere. */
    public static function deleteNote(int $byId, int $noteId): void
    {
        $note = $noteId > 0 ? Db::one('SELECT * FROM mail_thread_notes WHERE id = ? AND deleted_at IS NULL', [$noteId]) : null;
        if (!$note) {
            throw MailError::notFound('Cette note a déjà été supprimée.');
        }
        [, $account] = self::shared($byId, (int) $note['thread_id'], 'read');
        if ((int) $note['user_id'] !== $byId && !MailAccess::can($byId, $account, 'manage')) {
            throw MailError::denied('Vous pouvez supprimer vos propres notes. Celles des autres, seul un gestionnaire de la boîte peut les supprimer.');
        }

        $now = Clock::sql();
        Db::update('mail_thread_notes', ['deleted_at' => $now, 'updated_at' => $now], $noteId);
        self::log((int) $note['thread_id'], $byId, 'note_deleted', ['note_id' => $noteId]);
    }

    /** @return list<array{id: int, author_id: int, author: string, initials: string, mine: bool, body: string, at: ?string, at_label: string, can_delete: bool}> oldest first */
    public static function notes(int $userId, int $threadId): array
    {
        [, $account] = self::shared($userId, $threadId, 'read');

        return self::notesOf($threadId, $userId, MailAccess::can($userId, $account, 'manage'));
    }

    // ------------------------------------------------------------------ activity

    /** @return list<array{type: string, text: string, at: ?string, at_label: string}> newest first */
    public static function activity(int $userId, int $threadId, ?int $limit = null): array
    {
        self::shared($userId, $threadId, 'read');
        $limit = max(1, min(200, $limit ?? (int) self::config('activity_limit', 30)));
        $rows = Db::all(
            "SELECT id, user_id, type, data, created_at FROM mail_thread_activity WHERE thread_id = ? ORDER BY created_at DESC, id DESC LIMIT {$limit}",
            [$threadId],
        );

        return array_map(fn ($r) => [
            'type' => $r['type'],
            'text' => self::sentence($r['type'], $r['user_id'] !== null ? (int) $r['user_id'] : null, Db::decode($r['data']) ?? [], $userId),
            'at' => self::iso($r['created_at']),
            'at_label' => self::when($r['created_at']),
        ], $rows);
    }

    /** A line in the timeline. PR 3 writes 'sent' rows itself; anyone may call this. */
    public static function log(int $threadId, ?int $userId, string $type, array $data = []): void
    {
        Db::insert('mail_thread_activity', [
            'thread_id' => $threadId,
            'user_id' => $userId,
            'type' => mb_substr($type, 0, 40),
            'data' => $data ? Db::json($data) : null,
            'created_at' => Clock::sql(),
        ]);
    }

    /** Sync joined conversations (mail.threads_merged): notes and activity already moved, so say so in the timeline */
    public static function merged(int $intoId, array $fromIds): void
    {
        if (Db::value('SELECT 1 FROM mail_threads WHERE id = ?', [$intoId])) {
            self::log($intoId, null, 'merged', ['from' => array_values(array_map('intval', $fromIds))]);
        }
    }

    // ------------------------------------------------------------------ the team bar

    /**
     * Everything the team bar shows, for one person (api/v1/mail/team.php). activity only when asked.
     *
     * @return array{thread_id: int, account_id: int, mailbox: string, status: string, done_by: ?array, done_at_label: string,
     *               assignee: ?array, assignees: list<array>, can: array{send: bool, manage: bool}, notes: list<array>,
     *               presence: list<array>, activity: ?list<array>, poll_seconds: int}
     */
    public static function state(int $userId, int $threadId, bool $withActivity = false): array
    {
        [$thread, $account] = self::shared($userId, $threadId, 'read');
        $canSend = MailAccess::can($userId, $account, 'send');
        $canManage = MailAccess::can($userId, $account, 'manage');
        $assignees = self::assignees($account);
        $assignee = null;
        if ($thread['assigned_to']) {
            $assignee = self::person($thread['assigned_to'], $userId)
                + ['can_reply' => in_array($thread['assigned_to'], array_column($assignees, 'id'), true)];
        }

        return [
            'thread_id' => $threadId,
            'account_id' => $account['id'],
            'mailbox' => $account['email'],
            'status' => $thread['status'],
            'done_by' => $thread['status'] === 'done' && $thread['done_by'] ? self::person($thread['done_by'], $userId) : null,
            'done_at_label' => $thread['status'] === 'done' ? self::when($thread['done_at']) : '',
            'assignee' => $assignee,
            'assignees' => $canSend ? array_map(fn ($p) => $p + ['me' => $p['id'] === $userId], $assignees) : [],
            'can' => ['send' => $canSend, 'manage' => $canManage],
            'notes' => self::notesOf($threadId, $userId, $canManage),
            'presence' => MailPresence::others($userId, $threadId),
            'activity' => $withActivity ? self::activity($userId, $threadId) : null,
            'poll_seconds' => (int) self::config('poll_seconds', 20),
        ];
    }

    // ------------------------------------------------------------------ helpers

    /** "10:42" today, "hier, 10:42", "3 nov., 10:42", "3 nov. 2025, 10:42" */
    public static function when(?string $datetime): string
    {
        $d = Clock::parse($datetime);
        if ($d === null) {
            return '';
        }
        $now = Clock::now();
        if ($d->format('Y-m-d') === $now->format('Y-m-d')) {
            return $d->format('H:i');
        }
        if ($d->format('Y-m-d') === $now->modify('-1 day')->format('Y-m-d')) {
            return 'hier, ' . $d->format('H:i');
        }
        $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
        $day = (int) $d->format('j') . ' ' . $months[(int) $d->format('n') - 1];

        return $day . ($d->format('Y') !== $now->format('Y') ? ' ' . $d->format('Y') : '') . ', ' . $d->format('H:i');
    }

    public static function subject(array $thread): string
    {
        $subject = trim(preg_replace('/\s+/u', ' ', (string) ($thread['subject'] ?? '')) ?? '');

        return mb_strimwidth($subject !== '' ? $subject : '(sans objet)', 0, 80, '…');
    }

    /** @return array{id: int, name: string, first_name: string, initials: string, me: bool} */
    public static function person(int $id, int $me): array
    {
        $name = People::name($id);

        return ['id' => $id, 'name' => $name, 'first_name' => People::firstName($id), 'initials' => View::initials($name), 'me' => $id === $me];
    }

    /**
     * The thread and its mailbox, after checking the mailbox is shared and the person has this level on it.
     *
     * @return array{0: array, 1: array}
     * @throws MailError 404 (unknown, not theirs, personal) or 403 (level too low)
     */
    private static function shared(int $userId, int $threadId, string $ability): array
    {
        $thread = $threadId > 0 ? Store::thread($threadId) : null;
        $account = $thread ? Store::account($thread['account_id']) : null;
        if (!$thread || !$account || MailAccess::level($userId, $account) === null) {
            throw MailError::notFound('Introuvable : cette conversation a peut-être été retirée.');
        }
        if (!Store::isShared($account)) {
            throw MailError::notFound('Le travail en équipe existe seulement dans les boîtes partagées.');
        }
        if (!MailAccess::can($userId, $account, $ability)) {
            throw MailError::denied($ability === 'send'
                ? "Pour assigner une conversation ou la marquer traitée, il faut le niveau Lecture et envoi sur {$account['email']}. Demandez-le à un gestionnaire de la boîte."
                : "Vous n'avez pas accès à cette boîte mail.");
        }

        return [$thread, $account];
    }

    private static function notesOf(int $threadId, int $userId, bool $canManage): array
    {
        return array_map(
            fn ($r) => self::noteRow($r, $userId, $canManage),
            Db::all('SELECT * FROM mail_thread_notes WHERE thread_id = ? AND deleted_at IS NULL ORDER BY created_at, id', [$threadId]),
        );
    }

    private static function noteRow(array $r, int $userId, bool $canManage): array
    {
        $author = (int) $r['user_id'];
        $name = People::find($author) ? People::name($author) : 'Ancien collègue';

        return [
            'id' => (int) $r['id'],
            'author_id' => $author,
            'author' => $name,
            'initials' => View::initials($name),
            'mine' => $author === $userId,
            'body' => (string) $r['body'],
            'at' => self::iso($r['created_at']),
            'at_label' => self::when($r['created_at']),
            'can_delete' => $author === $userId || $canManage,
        ];
    }

    /** A timeline line in French, "Vous" for the person reading it */
    private static function sentence(string $type, ?int $by, array $data, int $me): string
    {
        $mine = $by !== null && $by === $me;
        $who = $by !== null ? People::name($by) : null;
        $to = isset($data['to']) ? (int) $data['to'] : null;

        return match ($type) {
            'assigned' => match (true) {
                $by !== null && $to === $by => $mine ?'Vous avez pris cette conversation' : "{$who} a pris cette conversation",
                $mine => 'Vous l’avez assignée à ' . People::name($to),
                $to === $me => ($who ?? 'Quelqu’un') . ' vous l’a assignée',
                default => ($who ?? 'Quelqu’un') . ' l’a assignée à ' . People::name($to),
            },
            'unassigned' => $mine ? 'Vous avez retiré l’assignation' : ($who ?? 'Quelqu’un') . ' a retiré l’assignation',
            'done' => $mine ? 'Vous l’avez marquée traitée' : ($who ?? 'Quelqu’un') . ' l’a marquée traitée',
            'reopened' => ($data['reason'] ?? null) === 'reply'
                ? 'Rouverte : un nouveau message est arrivé'
                : ($mine ? 'Vous l’avez rouverte' : ($who ?? 'Quelqu’un') . ' l’a rouverte'),
            'note' => $mine ? 'Vous avez ajouté une note' : ($who ?? 'Quelqu’un') . ' a ajouté une note',
            'note_deleted' => $mine ? 'Vous avez supprimé une note' : ($who ?? 'Quelqu’un') . ' a supprimé une note',
            'merged' => 'Réunie avec une autre conversation du même échange',
            'sent' => $mine ? 'Vous avez répondu' : ($who ?? 'Quelqu’un') . ' a répondu',
            default => ($who ? "{$who} : " : '') . str_replace('_', ' ', $type),
        };
    }

    private static function iso(?string $datetime): ?string
    {
        return Clock::parse($datetime)?->format(DATE_ATOM);
    }
}
