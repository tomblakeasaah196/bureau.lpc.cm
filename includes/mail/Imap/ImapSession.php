<?php

namespace LPC\Mail\Imap;

use DateTimeInterface;

/**
 * What bureau asks of a signed-in mailbox. SocketImapSession talks to the real server; tests use
 * scripts/tests/mail/support/FakeMailServer.php. Open one with LPC\Mail\MailServer::make()->open($account).
 *
 * Commands that act on messages work in the folder last opened with examine() or select().
 * UIDs are always IMAP UIDs, never sequence numbers. Throws ImapException.
 */
interface ImapSession
{
    /** @return list<string> upper case, as the server lists them once signed in */
    public function capabilities(): array;

    public function hasCapability(string $capability): bool;

    /** @return list<array{path: string, name: string, delimiter: ?string, attributes: list<string>}> attributes like "\Sent", "\Noselect" */
    public function folders(): array;

    /** Open a folder read-only. @return array{uidvalidity: int, uidnext: ?int, exists: int, highestmodseq: ?int} */
    public function examine(string $path): array;

    /** Open a folder to change it (flags, move, delete). Same answer as examine(). */
    public function select(string $path): array;

    /** @return array{messages: ?int, unseen: ?int, uidnext: ?int, uidvalidity: ?int} */
    public function status(string $path): array;

    /** UIDs in the open folder that match an IMAP SEARCH (e.g. "SINCE 30-Oct-2025"), ascending */
    public function search(string $criteria): array;

    /** UIDs in the open folder whose text contains $query (UTF-8), ascending; $before limits to older mail */
    public function searchText(string $query, ?DateTimeInterface $before = null): array;

    /**
     * Headers, flags and structure, without bodies.
     *
     * @return list<array{uid: int, flags: list<string>, internaldate: ?string, size: int, structure: array, headers: string, modseq: ?int}>
     */
    public function fetchSummaries(array $uids): array;

    /**
     * Flags of $uids (all messages when empty). With $changedSince (CONDSTORE) only those changed since that modseq.
     *
     * @return array<int, array{flags: list<string>, modseq: ?int}> keyed by UID
     */
    public function fetchFlags(array $uids = [], ?int $changedSince = null): array;

    /**
     * Raw body parts (still transfer-encoded), without setting \Seen. $maxBytes cuts each part.
     *
     * @return array<string, string> keyed by section ("1", "1.2", "TEXT", "HEADER", "" for the whole message)
     */
    public function fetchSections(int $uid, array $sections, ?int $maxBytes = null): array;

    /**
     * The same sections of several messages in one command (sync reads bodies this way).
     *
     * @return array<int, array<string, string>> UID => section => raw
     */
    public function fetchSectionsBatch(array $uids, array $sections, ?int $maxBytes = null): array;

    public function addFlags(array $uids, array $flags): void;

    public function removeFlags(array $uids, array $flags): void;

    /** Move to another folder. @return array<int, int> old UID => new UID when the server says (UIDPLUS), else [] */
    public function move(array $uids, string $toPath): array;

    /** Delete for good (\Deleted then expunge, only these UIDs when the server has UIDPLUS) */
    public function expunge(array $uids): void;

    /** Store a whole message in a folder (Sent, Drafts). @return ?int its UID when the server says (UIDPLUS) */
    public function append(string $path, string $raw, array $flags = [], ?DateTimeInterface $date = null): ?int;

    public function createFolder(string $path): void;

    public function logout(): void;
}
