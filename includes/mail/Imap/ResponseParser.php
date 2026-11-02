<?php

namespace LPC\Mail\Imap;

/**
 * Reads IMAP responses (RFC 3501 section 9) into PHP values. ImapClient hands over one response with
 * its literals cut out: in $text each literal is "\0L<n>\0" and its bytes are $literals[n].
 *
 *   atom            "FLAGS", "\Seen", "BODY[HEADER.FIELDS (FROM)]" (brackets are kept whole)
 *   quoted / literal  the string
 *   NIL             null
 *   ( … )           a list
 */
final class ResponseParser
{
    /** @return list<mixed> */
    public static function tokens(string $text, array $literals = []): array
    {
        $pos = 0;

        return self::parseList($text, $literals, $pos, false);
    }

    /**
     * One untagged response (the part after "* "):
     *   ['type' => 'FETCH', 'num' => 12, 'data' => [...]]      numbered: EXISTS, EXPUNGE, FETCH, RECENT
     *   ['type' => 'OK', 'code' => 'UIDVALIDITY', 'args' => '3857529045', 'text' => '…']  OK/NO/BAD/BYE/PREAUTH
     *   ['type' => 'CAPABILITY'|'SEARCH'|'ENABLED', 'data' => ['IMAP4REV1', …]]
     *   ['type' => 'LIST'|'STATUS'|…, 'data' => [...tokens]]
     */
    public static function untagged(string $text, array $literals = []): array
    {
        if (preg_match('/^(OK|NO|BAD|BYE|PREAUTH)\b ?(.*)$/is', $text, $m)) {
            return ['type' => strtoupper($m[1])] + self::statusText($m[2]);
        }
        if (preg_match('/^(CAPABILITY|ENABLED)\b ?(.*)$/is', $text, $m)) {
            return ['type' => strtoupper($m[1]), 'data' => self::words(strtoupper($m[2]))];
        }
        if (preg_match('/^SEARCH\b ?(.*)$/is', $text, $m)) {
            // "* SEARCH 2 84 882 (MODSEQ 917162500)" with CONDSTORE: keep the numbers only
            $numbers = preg_replace('/\(.*\)/s', '', $m[1]);

            return ['type' => 'SEARCH', 'data' => array_map('intval', self::words($numbers))];
        }

        $tokens = self::tokens($text, $literals);
        if (isset($tokens[0], $tokens[1]) && is_string($tokens[0]) && ctype_digit($tokens[0])) {
            return ['type' => strtoupper((string) $tokens[1]), 'num' => (int) $tokens[0], 'data' => array_slice($tokens, 2)];
        }

        return ['type' => strtoupper((string) ($tokens[0] ?? '')), 'data' => array_slice($tokens, 1)];
    }

    /** "[UIDVALIDITY 3857529045] UIDs valid" → code, args, text */
    public static function statusText(string $text): array
    {
        if (preg_match('/^\[([A-Z0-9.\-]+)(?: ([^\]]*))?\]\s*(.*)$/is', $text, $m)) {
            return ['code' => strtoupper($m[1]), 'args' => $m[2] ?? '', 'text' => $m[3]];
        }

        return ['code' => null, 'args' => '', 'text' => $text];
    }

    /** A FETCH response's list as item => value, keys upper case; BODY[…]<n> keys lose the <n> origin. */
    public static function fetchItems(array $list): array
    {
        $out = [];
        for ($i = 0; $i + 1 < count($list); $i += 2) {
            $key = strtoupper((string) $list[$i]);
            if (str_starts_with($key, 'BODY[HEADER.FIELDS')) {
                $key = 'HEADER';
            } elseif (preg_match('/^(BODY\[[^\]]*\])<\d+>$/', $key, $m)) {
                $key = $m[1];
            }
            $out[$key] = $list[$i + 1];
        }

        return $out;
    }

    private static function words(string $text): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($text)), fn ($w) => $w !== ''));
    }

    private static function parseList(string $text, array $literals, int &$pos, bool $nested): array
    {
        $out = [];
        $len = strlen($text);

        while ($pos < $len) {
            $c = $text[$pos];

            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                $pos++;
                continue;
            }
            if ($c === '(') {
                $pos++;
                $out[] = self::parseList($text, $literals, $pos, true);
                continue;
            }
            if ($c === ')') {
                $pos++;
                if ($nested) {
                    return $out;
                }
                continue; // a stray ")" at the top level: skip it
            }
            if ($c === '"') {
                $out[] = self::quoted($text, $pos);
                continue;
            }
            if ($c === "\0" && ($text[$pos + 1] ?? '') === 'L') {
                $end = strpos($text, "\0", $pos + 2);
                if ($end === false) {
                    throw ImapException::protocol('marqueur de littéral cassé');
                }
                $out[] = $literals[(int) substr($text, $pos + 2, $end - $pos - 2)] ?? '';
                $pos = $end + 1;
                continue;
            }

            $atom = self::atom($text, $pos);
            $out[] = strcasecmp($atom, 'NIL') === 0 ? null : $atom;
        }

        return $out;
    }

    private static function quoted(string $text, int &$pos): string
    {
        $pos++; // opening quote
        $out = '';
        $len = strlen($text);
        while ($pos < $len) {
            $c = $text[$pos];
            if ($c === '\\' && $pos + 1 < $len) {
                $out .= $text[$pos + 1];
                $pos += 2;
                continue;
            }
            if ($c === '"') {
                $pos++;

                return $out;
            }
            $out .= $c;
            $pos++;
        }

        return $out; // unterminated: take the rest
    }

    private static function atom(string $text, int &$pos): string
    {
        $start = $pos;
        $len = strlen($text);
        $depth = 0;
        while ($pos < $len) {
            $c = $text[$pos];
            if ($c === '[') {
                $depth++;
            } elseif ($c === ']' && $depth > 0) {
                $depth--;
            } elseif ($depth === 0 && ($c === ' ' || $c === '(' || $c === ')' || $c === "\r" || $c === "\n" || $c === "\0")) {
                break;
            }
            $pos++;
        }

        return substr($text, $start, $pos - $start);
    }
}
