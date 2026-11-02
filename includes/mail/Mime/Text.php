<?php

namespace LPC\Mail\Mime;

/**
 * Plain text from mail bodies: for search, the list preview (snippet) and Praxis AI. Not for showing HTML
 * mail: the reader (PR 2) shows the HTML through its own sanitiser.
 */
final class Text
{
    public static function fromHtml(string $html): string
    {
        $html = preg_replace('#<(head|style|script|title|template)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $html = preg_replace('#<!--.*?-->#s', ' ', $html);
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = preg_replace('#</(p|div|li|tr|h[1-6]|blockquote|table|section|article)\s*>#i', "\n", $html);
        $html = preg_replace('#<li\b[^>]*>#i', "\n• ", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/ *\n */", "\n", $text);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /** The first words a person wrote: quoted history and "On … wrote:" removed, whitespace collapsed */
    public static function snippet(string $text, int $length = 180): string
    {
        $lines = [];
        foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
            $trim = trim($line);
            if (str_starts_with($trim, '>')) {
                continue;
            }
            if (preg_match('/^(On .{4,200} wrote:|Le .{4,200} a écrit\s?:|-{2,} ?Original Message ?-{2,}|-{2,} ?Message d\'origine ?-{2,}|From: .+ Sent: .+)$/iu', $trim)) {
                break;
            }
            $lines[] = $trim;
        }
        $flat = trim(preg_replace('/\s+/u', ' ', implode(' ', $lines)));

        return mb_strlen($flat) > $length ? rtrim(mb_substr($flat, 0, $length - 1)) . '…' : $flat;
    }
}
