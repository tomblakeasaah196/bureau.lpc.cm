<?php

namespace LPC\Mail\Tests;

use LPC\Mail\Imap\ImapException;
use LPC\Mail\Imap\ImapSession;
use DateTimeImmutable;
use DateTimeInterface;

/** The session side of FakeMailServer: the same answers SocketImapSession gives, from memory. Ported from JBS HQ. */
class FakeImapSession implements ImapSession
{
    private ?string $open = null;

    public function __construct(private FakeMailServer $server, private string $email)
    {
    }

    public function capabilities(): array
    {
        return $this->server->capabilities;
    }

    public function hasCapability(string $capability): bool
    {
        return in_array(strtoupper($capability), $this->server->capabilities, true);
    }

    public function folders(): array
    {
        $out = [];
        foreach ($this->box()['folders'] as $path => $f) {
            $leaf = str_contains($path, '.') ? substr($path, strrpos($path, '.') + 1) : $path;
            $out[] = ['path' => $path, 'name' => $leaf, 'delimiter' => '.', 'attributes' => $f['attributes']];
        }

        return $out;
    }

    public function examine(string $path): array
    {
        return $this->select($path);
    }

    public function select(string $path): array
    {
        $f = $this->folderData($path);
        $this->open = $path;

        return [
            'uidvalidity' => $f['uidvalidity'],
            'uidnext' => $f['uidnext'],
            'exists' => count($f['messages']),
            'highestmodseq' => $this->hasCapability('CONDSTORE') ? $f['modseq'] : null,
        ];
    }

    public function status(string $path): array
    {
        $f = $this->folderData($path);

        return [
            'messages' => count($f['messages']),
            'unseen' => count(array_filter($f['messages'], fn ($m) => ! in_array('\\Seen', $m['flags'], true))),
            'uidnext' => $f['uidnext'],
            'uidvalidity' => $f['uidvalidity'],
        ];
    }

    public function search(string $criteria): array
    {
        $messages = $this->current()['messages'];
        $uids = [];
        foreach ($messages as $uid => $m) {
            $date = DateTimeImmutable::createFromFormat('d-M-Y H:i:s O', $m['internaldate']);
            if (preg_match('/^SINCE (\S+)$/', $criteria, $x) && $date < new DateTimeImmutable($x[1])) {
                continue;
            }
            if (preg_match('/^BEFORE (\S+)$/', $criteria, $x) && $date >= new DateTimeImmutable($x[1])) {
                continue;
            }
            $uids[] = $uid;
        }
        sort($uids);

        return $uids;
    }

    public function searchText(string $query, ?DateTimeInterface $before = null): array
    {
        $out = [];
        foreach ($this->current()['messages'] as $uid => $m) {
            if (stripos($m['raw'], $query) !== false || stripos(implode(' ', $m['sections']), $query) !== false) {
                $out[] = $uid;
            }
        }

        return $out;
    }

    public function fetchSummaries(array $uids): array
    {
        $out = [];
        foreach ($uids as $uid) {
            $m = $this->current()['messages'][$uid] ?? null;
            if (! $m) {
                continue;
            }
            $out[] = [
                'uid' => (int) $uid, 'flags' => $m['flags'], 'internaldate' => $m['internaldate'], 'size' => $m['size'],
                'structure' => $m['structure'], 'headers' => $m['headers'], 'modseq' => $m['modseq'],
            ];
        }

        return $out;
    }

    public function fetchFlags(array $uids = [], ?int $changedSince = null): array
    {
        $out = [];
        foreach ($this->current()['messages'] as $uid => $m) {
            if ($uids && ! in_array($uid, $uids, false)) {
                continue;
            }
            if ($changedSince !== null && $m['modseq'] <= $changedSince) {
                continue;
            }
            $out[$uid] = ['flags' => $m['flags'], 'modseq' => $m['modseq']];
        }

        return $out;
    }

    public function fetchSections(int $uid, array $sections, ?int $maxBytes = null): array
    {
        return $this->fetchSectionsBatch([$uid], $sections, $maxBytes)[$uid] ?? [];
    }

    public function fetchSectionsBatch(array $uids, array $sections, ?int $maxBytes = null): array
    {
        $out = [];
        foreach ($uids as $uid) {
            $m = $this->current()['messages'][$uid] ?? null;
            foreach ($sections as $s) {
                if ($m && array_key_exists($s, $m['sections'])) {
                    $out[$uid][$s] = $maxBytes ? substr($m['sections'][$s], 0, $maxBytes) : $m['sections'][$s];
                } elseif ($m && $s === '') {
                    $out[$uid][$s] = $m['raw'];
                }
            }
        }

        return $out;
    }

    public function addFlags(array $uids, array $flags): void
    {
        foreach ($uids as $uid) {
            $m = $this->current()['messages'][$uid] ?? null;
            if ($m) {
                $this->server->setFlags($this->email, $this->open, $uid, array_values(array_unique(array_merge($m['flags'], $flags))));
            }
        }
    }

    public function removeFlags(array $uids, array $flags): void
    {
        foreach ($uids as $uid) {
            $m = $this->current()['messages'][$uid] ?? null;
            if ($m) {
                $this->server->setFlags($this->email, $this->open, $uid, array_values(array_diff($m['flags'], $flags)));
            }
        }
    }

    public function move(array $uids, string $toPath): array
    {
        $this->folderData($toPath);
        $map = [];
        foreach ($uids as $uid) {
            $m = $this->current()['messages'][$uid] ?? null;
            if (! $m) {
                continue;
            }
            $map[(int) $uid] = $this->server->deliver($this->email, $toPath, $m, $m['flags']);
            $this->server->remove($this->email, $this->open, $uid);
        }

        return $map;
    }

    public function expunge(array $uids): void
    {
        foreach ($uids as $uid) {
            $this->server->remove($this->email, $this->open, $uid);
        }
    }

    public function append(string $path, string $raw, array $flags = [], ?DateTimeInterface $date = null): ?int
    {
        [$headers] = explode("\r\n\r\n", str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw)), 2) + [1 => ''];
        $message = [
            'headers' => $headers . "\r\n\r\n",
            'structure' => ['text', 'plain', ['charset', 'utf-8'], null, null, '7bit', (string) strlen($raw), '1'],
            'sections' => ['1' => $raw],
            'internaldate' => ($date ? DateTimeImmutable::createFromInterface($date) : new DateTimeImmutable())->format('d-M-Y H:i:s O'),
            'size' => strlen($raw),
            'raw' => $raw,
        ];

        return $this->server->deliver($this->email, $path, $message, $flags);
    }

    public function createFolder(string $path): void
    {
        if (! isset($this->box()['folders'][$path])) {
            $this->server->addFolder($this->email, $path);
        }
    }

    public function logout(): void
    {
        $this->open = null;
    }

    private function box(): array
    {
        return $this->server->mailboxes[$this->email];
    }

    private function folderData(string $path): array
    {
        $f = $this->box()['folders'][$path] ?? null;
        if ($f === null) {
            throw ImapException::command('SELECT', 'NO', "Mailbox doesn't exist: {$path}");
        }

        return $f;
    }

    private function current(): array
    {
        if ($this->open === null) {
            throw ImapException::protocol('no folder selected');
        }

        return $this->folderData($this->open);
    }
}
