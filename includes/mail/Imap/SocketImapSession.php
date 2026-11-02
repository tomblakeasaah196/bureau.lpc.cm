<?php

namespace LPC\Mail\Imap;

use DateTimeInterface;

/** ImapSession over ImapClient: the commands below are the only IMAP bureau sends. */
final class SocketImapSession implements ImapSession
{
    public const HEADER_FIELDS = 'FROM TO CC BCC REPLY-TO SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES LIST-UNSUBSCRIBE AUTO-SUBMITTED';

    private const CANONICAL_ATTRIBUTES = [
        '\\sent' => '\\Sent', '\\drafts' => '\\Drafts', '\\junk' => '\\Junk', '\\trash' => '\\Trash', '\\archive' => '\\Archive',
        '\\all' => '\\All', '\\flagged' => '\\Flagged', '\\noselect' => '\\Noselect', '\\nonexistent' => '\\NonExistent',
        '\\haschildren' => '\\HasChildren', '\\hasnochildren' => '\\HasNoChildren',
    ];

    private bool $condstore = false;

    public function __construct(private readonly ImapClient $client)
    {
        if ($client->has('CONDSTORE') && $client->has('ENABLE')) {
            try {
                $client->command('ENABLE CONDSTORE');
                $this->condstore = true;
            } catch (ImapException) {
                $this->condstore = false;
            }
        }
    }

    public function capabilities(): array
    {
        return $this->client->capabilities();
    }

    public function hasCapability(string $capability): bool
    {
        if (strtoupper($capability) === 'CONDSTORE') {
            return $this->condstore;
        }

        return $this->client->has($capability);
    }

    public function folders(): array
    {
        $special = $this->client->has('SPECIAL-USE') ? ' RETURN (SPECIAL-USE)' : '';
        $result = $this->client->command('LIST "" "*"' . $special, 'LIST');

        $out = [];
        foreach ($result['untagged'] as $r) {
            if ($r['type'] !== 'LIST' || count($r['data']) < 3) {
                continue;
            }
            [$attributes, $delimiter, $path] = $r['data'];
            $path = (string) $path;
            // Special-use and selectability flags in one spelling (servers vary in case); others as sent
            $attributes = array_map(
                fn ($a) => self::CANONICAL_ATTRIBUTES[strtolower((string) $a)] ?? (string) $a,
                is_array($attributes) ? $attributes : [],
            );
            $leaf = $path;
            if (is_string($delimiter) && $delimiter !== '' && str_contains($path, $delimiter)) {
                $leaf = substr($path, strrpos($path, $delimiter) + strlen($delimiter));
            }
            $out[] = [
                'path' => $path,
                'name' => Utf7::decode($leaf),
                'delimiter' => $delimiter,
                'attributes' => array_values($attributes),
            ];
        }

        return $out;
    }

    public function examine(string $path): array
    {
        return $this->open('EXAMINE', $path);
    }

    public function select(string $path): array
    {
        return $this->open('SELECT', $path);
    }

    public function status(string $path): array
    {
        $result = $this->client->commandParts(['STATUS ', ImapClient::astring($path), ' (MESSAGES UNSEEN UIDNEXT UIDVALIDITY)'], 'STATUS');
        $out = ['messages' => null, 'unseen' => null, 'uidnext' => null, 'uidvalidity' => null];
        foreach ($result['untagged'] as $r) {
            if ($r['type'] === 'STATUS' && isset($r['data'][1]) && is_array($r['data'][1])) {
                $items = $r['data'][1];
                for ($i = 0; $i + 1 < count($items); $i += 2) {
                    $key = strtolower((string) $items[$i]);
                    if (array_key_exists($key, $out)) {
                        $out[$key] = (int) $items[$i + 1];
                    }
                }
            }
        }

        return $out;
    }

    public function search(string $criteria): array
    {
        return $this->searchResult($this->client->command('UID SEARCH ' . $criteria, 'SEARCH'));
    }

    public function searchText(string $query, ?DateTimeInterface $before = null): array
    {
        $parts = ['UID SEARCH CHARSET UTF-8 '];
        if ($before) {
            $parts[] = 'BEFORE ' . $before->format('j-M-Y') . ' ';
        }
        $parts[] = 'TEXT ';
        $parts[] = ['literal' => $query];

        return $this->searchResult($this->client->commandParts($parts, 'SEARCH'));
    }

    public function fetchSummaries(array $uids): array
    {
        if (! $uids) {
            return [];
        }
        $items = 'UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE BODY.PEEK[HEADER.FIELDS (' . self::HEADER_FIELDS . ')]' . ($this->condstore ? ' MODSEQ' : '');
        $result = $this->client->command('UID FETCH ' . ImapClient::uidSet($uids) . " ({$items})", 'FETCH');

        $out = [];
        foreach ($this->fetchResponses($result) as $item) {
            if (! isset($item['UID'])) {
                continue;
            }
            $out[] = [
                'uid' => (int) $item['UID'],
                'flags' => self::flags($item['FLAGS'] ?? []),
                'internaldate' => is_string($item['INTERNALDATE'] ?? null) ? $item['INTERNALDATE'] : null,
                'size' => (int) ($item['RFC822.SIZE'] ?? 0),
                'structure' => is_array($item['BODYSTRUCTURE'] ?? null) ? $item['BODYSTRUCTURE'] : [],
                'headers' => (string) ($item['HEADER'] ?? ''),
                'modseq' => self::modseq($item),
            ];
        }

        return $out;
    }

    public function fetchFlags(array $uids = [], ?int $changedSince = null): array
    {
        $set = $uids ? ImapClient::uidSet($uids) : '1:*';
        $command = "UID FETCH {$set} (UID FLAGS" . ($this->condstore ? ' MODSEQ' : '') . ')';
        if ($changedSince !== null && $this->condstore) {
            $command .= " (CHANGEDSINCE {$changedSince})";
        }

        $out = [];
        foreach ($this->fetchResponses($this->client->command($command, 'FETCH')) as $item) {
            if (isset($item['UID'])) {
                $out[(int) $item['UID']] = ['flags' => self::flags($item['FLAGS'] ?? []), 'modseq' => self::modseq($item)];
            }
        }

        return $out;
    }

    public function fetchSections(int $uid, array $sections, ?int $maxBytes = null): array
    {
        return $this->fetchSectionsBatch([$uid], $sections, $maxBytes)[$uid] ?? [];
    }

    public function fetchSectionsBatch(array $uids, array $sections, ?int $maxBytes = null): array
    {
        if (! $uids || ! $sections) {
            return [];
        }
        $items = implode(' ', array_map(
            fn ($s) => 'BODY.PEEK[' . $s . ']' . ($maxBytes ? "<0.{$maxBytes}>" : ''),
            $sections,
        ));

        $out = [];
        $result = $this->client->command('UID FETCH ' . ImapClient::uidSet($uids) . " (UID {$items})", 'FETCH');
        foreach ($this->fetchResponses($result) as $item) {
            $uid = (int) ($item['UID'] ?? 0);
            if (! in_array($uid, $uids, false)) {
                continue;
            }
            foreach ($sections as $s) {
                $key = 'BODY[' . strtoupper($s) . ']';
                if (array_key_exists($key, $item)) {
                    $out[$uid][$s] = (string) $item[$key];
                }
            }
        }

        return $out;
    }

    public function addFlags(array $uids, array $flags): void
    {
        $this->store($uids, '+FLAGS.SILENT', $flags);
    }

    public function removeFlags(array $uids, array $flags): void
    {
        $this->store($uids, '-FLAGS.SILENT', $flags);
    }

    public function move(array $uids, string $toPath): array
    {
        if (! $uids) {
            return [];
        }
        $set = ImapClient::uidSet($uids);

        if ($this->client->has('MOVE')) {
            $result = $this->client->commandParts(["UID MOVE {$set} ", ImapClient::astring($toPath)], 'MOVE');
            $map = $this->copyUidMap($result['untagged'], $result);

            return $map;
        }

        $result = $this->client->commandParts(["UID COPY {$set} ", ImapClient::astring($toPath)], 'COPY');
        $map = $this->copyUidMap([], $result);
        $this->expunge($uids);

        return $map;
    }

    public function expunge(array $uids): void
    {
        if (! $uids) {
            return;
        }
        $set = ImapClient::uidSet($uids);
        $this->client->command("UID STORE {$set} +FLAGS.SILENT (\\Deleted)", 'STORE');
        $this->client->command($this->client->has('UIDPLUS') ? "UID EXPUNGE {$set}" : 'EXPUNGE', 'EXPUNGE');
    }

    public function append(string $path, string $raw, array $flags = [], ?DateTimeInterface $date = null): ?int
    {
        $raw = preg_replace("/\r?\n/", "\r\n", $raw);
        $parts = ['APPEND ', ImapClient::astring($path), ' '];
        if ($flags) {
            $parts[] = '(' . implode(' ', $flags) . ') ';
        }
        if ($date) {
            $parts[] = '"' . $date->format('d-M-Y H:i:s O') . '" ';
        }
        $parts[] = ['literal' => $raw];

        $result = $this->client->commandParts($this->joinText($parts), 'APPEND');
        if ($result['code'] === 'APPENDUID' && preg_match('/^\d+ (\d+)$/', trim($result['args']), $m)) {
            return (int) $m[1];
        }

        return null;
    }

    public function createFolder(string $path): void
    {
        $this->client->commandParts(['CREATE ', ImapClient::astring($path)], 'CREATE');
        try {
            $this->client->commandParts(['SUBSCRIBE ', ImapClient::astring($path)], 'SUBSCRIBE');
        } catch (ImapException) {
            // not every server keeps subscriptions
        }
    }

    public function logout(): void
    {
        $this->client->logout();
    }

    private function open(string $verb, string $path): array
    {
        $parts = [$verb . ' ', ImapClient::astring($path)];
        if ($this->condstore) {
            $parts[] = ' (CONDSTORE)';
        }
        $result = $this->client->commandParts($this->joinText($parts), $verb);

        $out = ['uidvalidity' => 0, 'uidnext' => null, 'exists' => 0, 'highestmodseq' => null];
        foreach ($result['untagged'] as $r) {
            if ($r['type'] === 'EXISTS') {
                $out['exists'] = (int) $r['num'];
            } elseif ($r['type'] === 'OK' && $r['code'] === 'UIDVALIDITY') {
                $out['uidvalidity'] = (int) $r['args'];
            } elseif ($r['type'] === 'OK' && $r['code'] === 'UIDNEXT') {
                $out['uidnext'] = (int) $r['args'];
            } elseif ($r['type'] === 'OK' && $r['code'] === 'HIGHESTMODSEQ') {
                $out['highestmodseq'] = (int) $r['args'];
            } elseif ($r['type'] === 'OK' && $r['code'] === 'NOMODSEQ') {
                $out['highestmodseq'] = null;
            }
        }

        return $out;
    }

    private function store(array $uids, string $mode, array $flags): void
    {
        if (! $uids || ! $flags) {
            return;
        }
        $this->client->command('UID STORE ' . ImapClient::uidSet($uids) . " {$mode} (" . implode(' ', $flags) . ')', 'STORE');
    }

    /** @return list<array> the FETCH responses' items */
    private function fetchResponses(array $result): array
    {
        $out = [];
        foreach ($result['untagged'] as $r) {
            if ($r['type'] === 'FETCH' && isset($r['data'][0]) && is_array($r['data'][0])) {
                $out[] = ResponseParser::fetchItems($r['data'][0]);
            }
        }

        return $out;
    }

    private function searchResult(array $result): array
    {
        $uids = [];
        foreach ($result['untagged'] as $r) {
            if ($r['type'] === 'SEARCH') {
                array_push($uids, ...$r['data']);
            }
        }
        $uids = array_values(array_unique(array_filter($uids)));
        sort($uids);

        return $uids;
    }

    /** COPYUID in an untagged OK (MOVE) or the tagged OK (COPY): "uidvalidity src-set dst-set" */
    private function copyUidMap(array $untagged, array $tagged): array
    {
        $args = null;
        foreach ($untagged as $r) {
            if ($r['type'] === 'OK' && $r['code'] === 'COPYUID') {
                $args = $r['args'];
            }
        }
        if ($args === null && ($tagged['code'] ?? null) === 'COPYUID') {
            $args = $tagged['args'];
        }
        if ($args === null || ! preg_match('/^\d+ (\S+) (\S+)$/', trim($args), $m)) {
            return [];
        }
        $from = self::expandSet($m[1]);
        $to = self::expandSet($m[2]);

        return count($from) === count($to) ? array_combine($from, $to) : [];
    }

    private static function expandSet(string $set): array
    {
        $out = [];
        foreach (explode(',', $set) as $piece) {
            if (str_contains($piece, ':')) {
                [$a, $b] = array_map('intval', explode(':', $piece));
                foreach (range(min($a, $b), max($a, $b)) as $n) {
                    $out[] = $n;
                }
            } else {
                $out[] = (int) $piece;
            }
        }

        return $out;
    }

    private static function flags(mixed $flags): array
    {
        return is_array($flags) ? array_values(array_map('strval', array_filter($flags, 'is_string'))) : [];
    }

    private static function modseq(array $item): ?int
    {
        $value = $item['MODSEQ'] ?? null;
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /** Merge neighbouring text parts so literals stay the only array items */
    private function joinText(array $parts): array
    {
        $out = [];
        foreach ($parts as $part) {
            if (is_string($part) && $out && is_string(end($out))) {
                $out[array_key_last($out)] .= $part;
            } else {
                $out[] = $part;
            }
        }

        return $out;
    }
}
