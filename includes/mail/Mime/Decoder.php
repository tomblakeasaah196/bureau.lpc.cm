<?php

namespace LPC\Mail\Mime;

use Throwable;

/**
 * Body bytes to UTF-8 text: Content-Transfer-Encoding (base64, quoted-printable) then the charset.
 * Unknown or wrong charsets never throw: the result is always valid UTF-8.
 */
final class Decoder
{
    private const ALIASES = [
        'utf8' => 'UTF-8',
        'unicode-1-1-utf-7' => 'UTF-7',
        'latin1' => 'ISO-8859-1',
        'latin-1' => 'ISO-8859-1',
        'iso8859-1' => 'ISO-8859-1',
        'iso-8859-8-i' => 'ISO-8859-8',
        'ks_c_5601-1987' => 'CP949',
        'ks_c_5601' => 'CP949',
        'x-sjis' => 'SJIS',
        'shift-jis' => 'SJIS',
        'gb2312' => 'CP936',
        'gbk' => 'CP936',
        'x-gbk' => 'CP936',
        'cp-1252' => 'Windows-1252',
        'win-1252' => 'Windows-1252',
        'ansi_x3.4-1968' => 'ASCII',
        'us-ascii' => 'ASCII',
        'x-user-defined' => 'Windows-1252',
        'macintosh' => 'MacRoman',
    ];

    public static function decode(string $data, ?string $encoding, ?string $charset): string
    {
        return self::toUtf8(self::transfer($data, $encoding), $charset);
    }

    /** Undo the Content-Transfer-Encoding; 7bit, 8bit and binary are left as they are */
    public static function transfer(string $data, ?string $encoding): string
    {
        return match (strtolower(trim((string) $encoding))) {
            'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $data)),
            'quoted-printable' => quoted_printable_decode($data),
            default => $data,
        };
    }

    public static function toUtf8(string $bytes, ?string $charset): string
    {
        $charset = strtolower(trim((string) $charset, " \t\"'"));

        if ($charset === '' || in_array($charset, ['utf-8', 'us-ascii', 'ascii', 'utf8'], true)) {
            if (mb_check_encoding($bytes, 'UTF-8')) {
                return $bytes;
            }
            // Labelled UTF-8 (or not at all) but it isn't: most often Windows-1252
            return self::convert($bytes, 'Windows-1252') ?? mb_scrub($bytes, 'UTF-8');
        }

        $target = self::ALIASES[$charset] ?? $charset;

        return self::convert($bytes, $target) ?? mb_scrub($bytes, 'UTF-8');
    }

    private static function convert(string $bytes, string $from): ?string
    {
        try {
            if (in_array(strtolower($from), array_map('strtolower', mb_list_encodings()), true)) {
                $out = mb_convert_encoding($bytes, 'UTF-8', $from);

                return mb_check_encoding($out, 'UTF-8') ? $out : mb_scrub($out, 'UTF-8');
            }
        } catch (Throwable) {
            // fall through to iconv
        }

        try {
            $out = @iconv($from, 'UTF-8//IGNORE', $bytes);
            if ($out !== false) {
                return mb_scrub($out, 'UTF-8');
            }
        } catch (Throwable) {
            // unknown charset
        }

        return null;
    }
}
