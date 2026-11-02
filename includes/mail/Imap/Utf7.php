<?php

namespace LPC\Mail\Imap;

/**
 * IMAP folder names travel in "modified UTF-7" (RFC 3501 section 5.1.3): printable ASCII as is, "&" as "&-",
 * anything else as UTF-16 in base64 (with "," for "/") between "&" and "-".
 */
final class Utf7
{
    public static function decode(string $name): string
    {
        return preg_replace_callback('/&([A-Za-z0-9+,]*)-/', function (array $m) {
            if ($m[1] === '') {
                return '&';
            }
            $b64 = str_replace(',', '/', $m[1]);
            $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
            $utf16 = base64_decode($b64, true);
            if ($utf16 === false || strlen($utf16) % 2 !== 0) {
                return $m[0];
            }

            return mb_convert_encoding($utf16, 'UTF-8', 'UTF-16BE');
        }, $name);
    }

    public static function encode(string $name): string
    {
        $out = '';
        $buffer = '';
        $flush = function () use (&$out, &$buffer) {
            if ($buffer !== '') {
                $out .= '&' . str_replace('/', ',', rtrim(base64_encode(mb_convert_encoding($buffer, 'UTF-16BE', 'UTF-8')), '=')) . '-';
                $buffer = '';
            }
        };

        foreach (mb_str_split($name, 1, 'UTF-8') as $char) {
            $code = mb_ord($char, 'UTF-8');
            if ($code >= 0x20 && $code <= 0x7E) {
                $flush();
                $out .= $char === '&' ? '&-' : $char;
            } else {
                $buffer .= $char;
            }
        }
        $flush();

        return $out;
    }
}
