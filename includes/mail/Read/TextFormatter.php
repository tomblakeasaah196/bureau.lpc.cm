<?php

namespace LPC\Mail\Read;

/**
 * A plain-text message as safe HTML for the reader (docs/MAIL.md §7.2), ported from JBS HQ: escaped, web and mail
 * addresses made into links, and the quoted earlier message folded ("Le … a écrit :", "On … wrote:", "> " lines,
 * Outlook's "-----Message d'origine-----" or "De : … Envoyé : …" block).
 */
final class TextFormatter
{
    public static function toHtml(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", rtrim($text));
        $lines = explode("\n", $text);
        $cut = self::quoteStart($lines);

        $own = implode("\n", $cut === null ? $lines : array_slice($lines, 0, $cut));
        $html = '<div class="mr-text">' . self::links(rtrim($own)) . '</div>';
        if ($cut !== null) {
            $html .= '<details class="mr-quote"><summary>' . HtmlSanitizer::QUOTE_LABEL . '</summary><div class="mr-text">'
                . self::links(implode("\n", array_slice($lines, $cut))) . '</div></details>';
        }

        return $html;
    }

    /** Index of the first line of the quoted part, or null (never the whole message) */
    private static function quoteStart(array $lines): ?int
    {
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = trim($lines[$i]);
            $next = isset($lines[$i + 1]) ? trim($lines[$i + 1]) : '';
            $header = preg_match('/^(On\b.{4,200}\bwrote:|Le\b.{4,200}\ba\s+écrit\s?:|-{2,}\s*(Original Message|Message d[\'’]origine)\s*-{2,}|_{20,})$/iu', $line)
                // "Le lun. 3 nov. 2026 à 10:00, Awa <awa@x.cm> a" wraps onto a second line before "écrit :"
                || (preg_match('/^(On|Le)\b/u', $line) && preg_match('/\b(wrote:|a écrit\s?:|écrit\s?:)$/u', $next))
                // Outlook's plain-text reply block: "De : …" then "Envoyé : …" (or From: / Sent:)
                || (preg_match('/^(De|From)\s?:\s*\S/u', $line) && preg_match('/^(Envoyé|Sent|Date)\s?:\s*\S/u', $next));
            $quoted = str_starts_with($line, '>') && self::restQuoted($lines, $i);
            if (($header || $quoted) && self::hasText(array_slice($lines, 0, $i))) {
                return $i;
            }
        }

        return null;
    }

    /** From here to the end, every non-empty line is quoted */
    private static function restQuoted(array $lines, int $from): bool
    {
        foreach (array_slice($lines, $from) as $line) {
            if (trim($line) !== '' && !str_starts_with(ltrim($line), '>')) {
                return false;
            }
        }

        return true;
    }

    /** Some words of the person's own (not quoted lines) */
    private static function hasText(array $lines): bool
    {
        foreach ($lines as $line) {
            if (trim($line) !== '' && !str_starts_with(ltrim($line), '>')) {
                return true;
            }
        }

        return false;
    }

    /** Escape, then link http(s) addresses and email addresses */
    public static function links(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return preg_replace_callback(
            '~\bhttps?://(?:(?!&gt;|&lt;)[^\s"\'])+(?<![.,;:!?)\]])|\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b~i',
            function ($m) {
                $value = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $href = str_contains($value, '://') ? $value : 'mailto:' . $value;

                return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer nofollow">' . $m[0] . '</a>';
            },
            $escaped,
        ) ?? $escaped;
    }
}
