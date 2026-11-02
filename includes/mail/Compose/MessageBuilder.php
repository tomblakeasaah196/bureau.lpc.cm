<?php

namespace LPC\Mail\Compose;

use DateTimeInterface;
use LPC\Mail\Clock;
use LPC\Mail\MailError;
use LPC\Mail\MailFetch;
use LPC\Mail\MailSendException;
use LPC\Mail\Mime\Message;
use LPC\Mail\Store;
use Throwable;

/**
 * Turns an outbox row into the message that goes out (payload keys in docs/MAIL.md §12, PR 3): the person's
 * text, then their signature (added here, never in the editor), then the quoted original; an HTML and a
 * plain-text version; In-Reply-To and References on answers; files (uploaded ones, and the original's when
 * forwarding, read from the server through MailFetch at send time). From is set by MailTransport (the mailbox).
 *
 * A problem that sending again will not fix (a file gone) throws MailSendException, permanent.
 */
final class MessageBuilder
{
    private const BODY_STYLE = "font-family:'Segoe UI','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;color:#1F2A24;";

    private const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    /**
     * The message to send. $forDraft: the copy kept in the server's Drafts (no signature, no files from the
     * original: the draft is for reading elsewhere; bureau keeps the full draft).
     */
    public static function build(array $outbox, int $userId, bool $forDraft = false): Message
    {
        $p = $outbox['payload'] ?? [];
        $account = Store::account((int) $outbox['account_id']) ?? throw MailError::notFound();
        $m = new Message();

        foreach (['to', 'cc', 'bcc'] as $field) {
            if (!empty($p[$field])) {
                $m->{$field}(array_map(fn ($a) => ['name' => $a['name'] ?? null, 'email' => $a['email']], $p[$field]));
            }
        }
        $m->subject((string) ($p['subject'] ?? ''));
        if (!empty($outbox['message_id'])) {
            $m->messageId((string) $outbox['message_id']);
        }
        if (!empty($p['in_reply_to'])) {
            $m->header('In-Reply-To', '<' . trim($p['in_reply_to'], '<> ') . '>');
        }
        if (!empty($p['references'])) {
            $refs = array_filter(preg_split('/\s+/', trim((string) $p['references'])) ?: []);
            if ($refs) {
                $m->header('References', implode(' ', array_map(fn ($r) => '<' . trim($r, '<>') . '>', $refs)));
            }
        }

        $style = $forDraft ? 'none' : (string) ($p['signature'] ?? 'full');
        $body = (string) ($p['html'] ?? '');
        $quote = !empty($p['quote']) && !empty($p['quote_html']);

        $html = '<div style="' . self::BODY_STYLE . '">' . self::styled($body) . '</div>'
            . Signatures::html($userId, $account, $style)
            . ($quote ? '<div style="margin-top:28px;' . self::BODY_STYLE . '">' . $p['quote_html'] . '</div>' : '');
        $m->html('<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="x-apple-disable-message-reformatting"></head><body style="margin:0;padding:0;">' . $html . '</body></html>');

        $text = HtmlCleaner::toText($body);
        if (($signature = Signatures::text($userId, $account, $style)) !== '') {
            $text .= "\n\n" . $signature;
        }
        if ($quote && !empty($p['quote_text'])) {
            $text .= "\n\n" . $p['quote_text'];
        }
        $m->text(ltrim($text) . "\n");

        foreach ($p['attachments'] ?? [] as $a) {
            if ($forDraft && ($a['source'] ?? '') !== 'upload') {
                continue;
            }
            [$content, $name, $mime] = self::attachment($outbox, $a, $userId);
            $m->attach($content, $name, $mime);
        }

        return $m;
    }

    /**
     * The original, quoted below an answer or forwarded, as HTML and plain text (prepared when the composer
     * opens and kept in the payload, so the message can still go if the original moves).
     *
     * @param array{text: string, html: ?string} $body
     * @return array{html: string, text: string}
     */
    public static function quote(string $kind, array $original, array $body): array
    {
        $text = trim((string) ($body['text'] ?? ''));
        if ($text === '' && !empty($body['html'])) {
            $text = HtmlCleaner::toText($body['html']);
        }
        $text = mb_substr($text, 0, 100000);
        $when = self::when(Clock::parse($original['sent_at'] ?? null) ?? Clock::parse($original['received_at'] ?? null));
        $from = Recipients::format(['name' => $original['from_name'] ?? null, 'email' => (string) ($original['from_email'] ?? '')]);
        $e = fn (string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        if ($kind === 'forward') {
            $lines = array_filter([
                'De : ' . $from,
                $when ? 'Date : ' . $when : null,
                'Objet : ' . ($original['subject'] ?? ''),
                ($to = implode(', ', array_map([Recipients::class, 'format'], $original['to_list'] ?? []))) !== '' ? 'À : ' . $to : null,
                ($cc = implode(', ', array_map([Recipients::class, 'format'], $original['cc_list'] ?? []))) !== '' ? 'Cc : ' . $cc : null,
            ]);
            $head = "---------- Message transféré ----------\n" . implode("\n", $lines);

            return [
                'html' => '<p style="margin:0 0 12px;color:#5E6B63;">' . nl2br($e($head), false) . '</p>' . self::styled(HtmlCleaner::fromText($text)),
                'text' => $head . "\n\n" . $text,
            ];
        }

        $intro = ($when ? "Le {$when}, " : '') . "{$from} a écrit :";

        return [
            'html' => '<p style="margin:0 0 8px;color:#5E6B63;">' . $e($intro) . '</p>'
                . '<blockquote style="margin:0 0 0 4px;padding:0 0 0 12px;border-left:2px solid #D3DED6;color:#4A5850;">' . self::styled(HtmlCleaner::fromText($text)) . '</blockquote>',
            'text' => $intro . "\n" . preg_replace('/^/m', '> ', $text),
        ];
    }

    /** "2 nov. 2026 à 10:42" in the app's time zone (no intl on the live server) */
    public static function when(?DateTimeInterface $at): ?string
    {
        if (!$at) {
            return null;
        }
        $d = \DateTimeImmutable::createFromInterface($at)->setTimezone(Clock::zone());

        $day = (int) $d->format('j');

        return ($day === 1 ? '1er' : (string) $day) . ' ' . self::MONTHS[(int) $d->format('n') - 1] . ' ' . $d->format('Y') . ' à ' . $d->format('H:i');
    }

    /** Paragraph spacing written into the tags (mail apps ignore most style sheets) */
    private static function styled(string $html): string
    {
        return str_replace(
            ['<p>', '<blockquote>', '<ul>', '<ol>', '<h2>', '<h3>', '<pre>'],
            [
                '<p style="margin:0 0 12px;">',
                '<blockquote style="margin:0 0 12px 4px;padding:0 0 0 12px;border-left:2px solid #D3DED6;">',
                '<ul style="margin:0 0 12px;padding-left:24px;">',
                '<ol style="margin:0 0 12px;padding-left:24px;">',
                '<h2 style="margin:0 0 10px;font-size:18px;line-height:24px;">',
                '<h3 style="margin:0 0 8px;font-size:15px;line-height:22px;">',
                '<pre style="margin:0 0 12px;white-space:pre-wrap;font-family:Consolas,Menlo,monospace;font-size:13px;">',
            ],
            $html,
        );
    }

    /** @return array{0: string, 1: string, 2: string} content, file name, MIME type */
    private static function attachment(array $outbox, array $a, int $userId): array
    {
        $name = (string) ($a['name'] ?? 'piece-jointe');
        if (($a['source'] ?? '') === 'upload') {
            try {
                return [Files::get((string) $outbox['id'], (string) $a['key']), $name, (string) ($a['mime'] ?? '') ?: 'application/octet-stream'];
            } catch (MailError) {
                throw new MailSendException("Le fichier {$name} n’est plus dans bureau. Retirez-le, joignez-le de nouveau, puis renvoyez.", 'rejected', true);
            }
        }

        try {
            $file = MailFetch::make()->attachment($userId, (int) ($a['attachment_id'] ?? 0));
        } catch (MailError $e) {
            throw new MailSendException("La pièce jointe {$name} du message d’origine ne peut plus être envoyée depuis bureau ({$e->getMessage()}). Retirez-la, puis renvoyez.", 'rejected', true);
        } catch (Throwable) {
            throw new MailSendException("La pièce jointe {$name} n’a pas pu être lue sur le serveur de messagerie. Bureau réessaiera.", 'temporary', false);
        }

        return [$file['content'], $file['filename'] ?: $name, $file['mime'] ?: 'application/octet-stream'];
    }
}
