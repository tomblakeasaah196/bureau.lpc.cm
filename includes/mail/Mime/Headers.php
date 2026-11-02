<?php

namespace LPC\Mail\Mime;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Mail headers (RFC 5322, 2047, 2231): unfolding, encoded words, address lists, dates, parameters.
 * Everything returned is UTF-8.
 */
final class Headers
{
    /** A raw header block → lower-case name => list of raw values (unfolded, not decoded) */
    public static function parse(string $block): array
    {
        $block = preg_replace("/\r\n|\r/", "\n", $block);
        $block = preg_replace("/\n[ \t]+/", ' ', $block); // unfold
        $out = [];
        foreach (explode("\n", $block) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            if ($name === '' || preg_match('/\s/', $name)) {
                continue;
            }
            $out[$name][] = trim($value);
        }

        return $out;
    }

    /** First value of a header, decoded */
    public static function first(array $headers, string $name): ?string
    {
        $value = $headers[strtolower($name)][0] ?? null;

        return $value === null ? null : self::decode($value);
    }

    /** RFC 2047 encoded words (=?charset?B|Q?…?=) to UTF-8; whitespace between two encoded words is dropped */
    public static function decode(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return Decoder::toUtf8($value, null);
        }

        $value = preg_replace('/(=\?[^?\s]+\?[BbQq]\?[^?\s]*\?=)\s+(?==\?[^?\s]+\?[BbQq]\?)/', '$1', $value);

        // Adjacent words in the same charset are joined before decoding: a multibyte character can be split across them
        $decoded = preg_replace_callback('/(?:=\?([^?\s*]+)(?:\*[^?\s]*)?\?([BbQq])\?([^?\s]*)\?=)+/', function (array $m) {
            preg_match_all('/=\?([^?\s*]+)(?:\*[^?\s]*)?\?([BbQq])\?([^?\s]*)\?=/', $m[0], $words, PREG_SET_ORDER);
            $out = '';
            $bytes = '';
            $charset = null;
            foreach ($words as $w) {
                $wordCharset = strtolower($w[1]);
                if ($charset !== null && $wordCharset !== $charset) {
                    $out .= Decoder::toUtf8($bytes, $charset);
                    $bytes = '';
                }
                $charset = $wordCharset;
                $bytes .= strtoupper($w[2]) === 'B'
                    ? (string) base64_decode($w[3])
                    : quoted_printable_decode(str_replace('_', ' ', $w[3]));
            }

            return $out . Decoder::toUtf8($bytes, $charset);
        }, $value);

        return Decoder::toUtf8($decoded ?? $value, 'utf-8');
    }

    /**
     * "Ada <ada@x.com>, \"Lovelace, A.\" <al@y.org>, team: b@z.com, c@z.com;" → [['name' => 'Ada', 'email' => 'ada@x.com'], …]
     * Invalid entries are dropped. Names are decoded.
     *
     * @return list<array{name: ?string, email: string}>
     */
    public static function addresses(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $out = [];
        foreach (self::splitAddresses($raw) as $item) {
            $item = trim($item);
            // A group "Name: a@b, c@d;" — the parts were split at commas, so strip the "Name:" and the ";"
            if (preg_match('/^[^"<]*?:\s*(.*)$/s', $item, $g) && ! preg_match('/^[^"<:]*@/', $item)) {
                $item = trim($g[1]);
            }
            $item = rtrim($item, '; ');
            if ($item === '') {
                continue;
            }

            $name = null;
            if (preg_match('/^(.*)<([^<>]*)>\s*(?:\(.*\))?$/s', $item, $m)) {
                $email = trim($m[2]);
                $name = trim($m[1]);
            } else {
                $comment = null;
                if (preg_match('/\(([^()]*)\)/', $item, $c)) {
                    $comment = $c[1];
                }
                $email = trim(preg_replace('/\([^()]*\)/', '', $item));
                $name = $comment;
            }

            $email = trim($email, " \t\"'");
            if ($email === '' || ! str_contains($email, '@')) {
                continue;
            }

            if ($name !== null) {
                $name = trim($name);
                if (strlen($name) >= 2 && $name[0] === '"' && str_ends_with($name, '"')) {
                    $name = stripcslashes(substr($name, 1, -1));
                }
                $name = trim(self::decode($name), " \t\"'");
                if ($name === '' || strcasecmp($name, $email) === 0) {
                    $name = null;
                }
            }

            $out[] = ['name' => $name !== null ? mb_substr($name, 0, 200) : null, 'email' => mb_substr($email, 0, 254)];
        }

        return $out;
    }

    /** Message-IDs in a header (Message-ID, In-Reply-To, References), without angle brackets, in order */
    public static function messageIds(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        if (preg_match_all('/<([^<>\s]+)>/', $raw, $m)) {
            return array_values(array_unique($m[1]));
        }
        $bare = trim($raw);

        return preg_match('/^\S+@\S+$/', $bare) ? [$bare] : [];
    }

    /** A Date header or IMAP INTERNALDATE, in UTC; null when it can't be read */
    public static function date(?string $raw): ?DateTimeImmutable
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $utc = new DateTimeZone('UTC');
        $clean = trim(preg_replace('/\([^)]*\)/', '', $raw));
        $clean = preg_replace('/\s+/', ' ', $clean);
        // "|" resets what a format leaves out (seconds), so a date never takes the current time's
        foreach (['d-M-Y H:i:s O|', 'D, d M Y H:i:s O|', 'd M Y H:i:s O|', 'D, d M Y H:i O|', 'd M Y H:i O|', 'D, d M Y H:i:s T|'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $clean);
            if ($date !== false && (int) $date->format('Y') > 1970) {
                return $date->setTimezone($utc);
            }
        }
        try {
            $date = new DateTimeImmutable($clean);

            return (int) $date->format('Y') > 1970 ? $date->setTimezone($utc) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A MIME parameter from a parsed list (keys lower case), with RFC 2231 forms: name*=utf-8''%E2%82%AC.pdf and
     * name*0*=…; name*1*=…. Falls back to the plain form, with encoded words decoded.
     */
    public static function parameter(array $params, string $name): ?string
    {
        $name = strtolower($name);

        if (isset($params[$name . '*'])) {
            return self::rfc2231($params[$name . '*'], true);
        }

        $pieces = [];
        $extended = false;
        foreach ($params as $key => $value) {
            if (preg_match('/^' . preg_quote($name, '/') . '\*(\d+)(\*?)$/', $key, $m)) {
                $pieces[(int) $m[1]] = [$value, $m[2] === '*'];
                $extended = $extended || $m[2] === '*';
            }
        }
        if ($pieces) {
            ksort($pieces);
            $charset = null;
            $bytes = '';
            foreach ($pieces as $i => [$value, $encoded]) {
                if ($encoded && $i === array_key_first($pieces) && preg_match("/^([^']*)'[^']*'(.*)$/s", $value, $m)) {
                    $charset = $m[1] !== '' ? $m[1] : null;
                    $value = $m[2];
                }
                $bytes .= $encoded ? rawurldecode($value) : $value;
            }

            return Decoder::toUtf8($bytes, $extended ? $charset : null);
        }

        if (isset($params[$name])) {
            return self::decode($params[$name]);
        }

        return null;
    }

    private static function rfc2231(string $value, bool $withCharset): string
    {
        if ($withCharset && preg_match("/^([^']*)'[^']*'(.*)$/s", $value, $m)) {
            return Decoder::toUtf8(rawurldecode($m[2]), $m[1] !== '' ? $m[1] : null);
        }

        return Decoder::toUtf8(rawurldecode($value), null);
    }

    /** Split at commas that are outside quotes, angle brackets and comments */
    private static function splitAddresses(string $raw): array
    {
        $items = [];
        $current = '';
        $inQuote = false;
        $angle = 0;
        $comment = 0;
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $c = $raw[$i];
            if ($inQuote) {
                if ($c === '\\' && $i + 1 < $len) {
                    $current .= $c . $raw[++$i];
                    continue;
                }
                if ($c === '"') {
                    $inQuote = false;
                }
                $current .= $c;
                continue;
            }
            if ($c === '"') {
                $inQuote = true;
            } elseif ($c === '<') {
                $angle++;
            } elseif ($c === '>') {
                $angle = max(0, $angle - 1);
            } elseif ($c === '(') {
                $comment++;
            } elseif ($c === ')') {
                $comment = max(0, $comment - 1);
            } elseif (($c === ',' || $c === ';') && $angle === 0 && $comment === 0) {
                $items[] = $current;
                $current = '';
                continue;
            }
            $current .= $c;
        }
        $items[] = $current;

        return $items;
    }
}
