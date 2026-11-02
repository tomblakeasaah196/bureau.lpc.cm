<?php

namespace LPC\Mail\Ai;

use LPC\Mail\Db;
use LPC\Mail\MailFetch;
use LPC\Mail\Mime\Text;
use Throwable;

/**
 * The text of a message as Praxis AI reads it: what the person wrote, with the quoted earlier messages cut off
 * (French and English mail programs: "Le … a écrit :", "De : … Envoyé :", "-----Message d'origine-----", "> "
 * lines, Gmail and Outlook quote blocks). Never an attachment: only the message's own text and HTML parts.
 */
final class MailText
{
    /**
     * A message row's text without quotes. A body bureau already copied is read from the database; one not copied
     * yet (or too large to copy) is read from the server only when $live allows it, else its preview is used.
     */
    public static function ofMessage(int $userId, array $message, bool $live = false): string
    {
        $text = '';
        $html = null;
        if ($message['body_state'] === 'cached') {
            $row = Db::one('SELECT body_text, body_html FROM mail_messages WHERE id = ?', [$message['id']]);
            $text = (string) ($row['body_text'] ?? '');
            $html = $row['body_html'] ?? null;
        } elseif ($live) {
            try {
                $body = MailFetch::make()->body($userId, $message);
                $text = (string) $body['text'];
                $html = $body['html'];
            } catch (Throwable $e) {
                // the server is not answering: the preview will do (no content in the log)
                error_log('mail/ai: body not read from the server (message ' . (int) $message['id'] . ', ' . get_class($e) . ')');
            }
        }
        if (trim($text) === '' && is_string($html) && $html !== '') {
            $text = self::fromHtml($html);
        }
        $text = self::withoutQuotes($text);

        return $text !== '' ? $text : trim((string) ($message['snippet'] ?? ''));
    }

    public static function withoutQuotes(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Everything from the first quote header on
        $cuts = [
            '/^[ \t]*On .{0,200}wrote:[ \t]*$/mi',
            '/^[ \t]*Le .{0,200}a écrit[ \t]*:[ \t]*$/mui',
            '/^[ \t]*-{2,}[ \t]*(Original Message|Message d[\'’]origine|Forwarded message|Message transféré)[ \t]*-{2,}/mui',
            '/^[ \t]*_{10,}[ \t]*$/m',
            '/^[ \t]*(From|De)[ \t]*:[ \t].+\n(?:.+\n){0,3}?[ \t]*(Sent|Date|Envoyé)[ \t]*:[ \t]/mui',
        ];
        foreach ($cuts as $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 0) {
                $text = substr($text, 0, $m[0][1]);
            }
        }

        // Any remaining "> quoted" lines
        $lines = array_filter(explode("\n", $text), fn ($l) => !str_starts_with(ltrim($l), '>'));
        $text = preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));

        return trim((string) $text);
    }

    /** Plain text of an HTML body, its quote blocks (blockquote, Gmail, Outlook, Apple) removed first */
    public static function fromHtml(string $html): string
    {
        $html = preg_replace('#<blockquote\b[^>]*>.*?</blockquote\s*>#is', ' ', $html) ?? $html;
        foreach (['gmail_quote', 'divRplyFwdMsg', 'moz-cite-prefix', 'yahoo_quoted'] as $marker) {
            $at = stripos($html, $marker);
            if ($at !== false) {
                $tag = strrpos(substr($html, 0, $at), '<');
                $html = substr($html, 0, $tag === false ? $at : $tag);
            }
        }

        return Text::fromHtml($html);
    }

    public static function clip(string $text, int $chars): string
    {
        return mb_strlen($text) > $chars ? rtrim(mb_substr($text, 0, $chars)) . ' …' : $text;
    }

    /** One line, spaces collapsed */
    public static function flat(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
