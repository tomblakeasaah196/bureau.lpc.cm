<?php

namespace LPC\Mail\Compose;

/**
 * A document link sent from Courrier (first use: a devis from Base Clients & Devis): the person's text, a
 * branded button to the document, the same link written out for mail apps that drop the button, then the
 * rest of the text. The mailbox signature is added by MessageBuilder, as for any message.
 *
 * Built here, from plain text, rather than typed in the composer: HtmlCleaner keeps no style attributes and
 * no tables, so a button cannot survive Outbox::save(). Everything that comes from a person is escaped
 * before it reaches the markup; the only markup is this file's. The result goes into the draft as its html,
 * which MessageBuilder sends as it is (wrapped in its body style, followed by the signature), and from which
 * it derives the plain-text part with HtmlCleaner::toText().
 *
 * Mail-app safe: a one-cell table with a background colour for the button (Outlook ignores padding on <a>
 * but paints a <td>), inline styles only, no images, no web fonts.
 */
final class DocumentMail
{
    /** The fallback colour when the company has none configured (brand green) */
    public const BRAND = '#005A2B';

    /**
     * @param string $before text above the button (paragraphs separated by blank lines)
     * @param string $after  text below the link (closing lines)
     * @param string $url    absolute https link to the document
     * @param string $label  button text, e.g. "Consulter la proposition"
     */
    public static function html(string $before, string $after, string $url, string $label, string $brand = self::BRAND, string $lang = 'fr'): string
    {
        if (!preg_match('~^https?://[^\s"<>]+$~i', $url)) {
            throw new \InvalidArgumentException('DocumentMail: the document link must be an absolute http(s) URL.');
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $brand)) {
            $brand = self::BRAND;
        }
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $u = $e($url);
        $fallback = $lang === 'en'
            ? 'If the button does not work, copy this link into your browser:'
            : 'Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :';

        $button = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:20px 0 14px;border-collapse:separate;">'
            . '<tr><td align="center" bgcolor="' . $brand . '" style="border-radius:8px;background:' . $brand . ';">'
            . '<a href="' . $u . '" target="_blank" rel="noopener" style="display:inline-block;padding:13px 26px;'
            . "font-family:'Segoe UI','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:15px;line-height:20px;"
            . 'font-weight:bold;color:#FFFFFF;text-decoration:none;border-radius:8px;">' . $e($label) . '</a>'
            . '</td></tr></table>'
            . '<p style="margin:0 0 18px;font-size:12px;line-height:18px;color:#6B7280;">' . $e($fallback) . '<br>'
            . '<a href="' . $u . '" target="_blank" rel="noopener" style="color:' . $brand . ';word-break:break-all;">' . $u . '</a></p>';

        return self::paragraphs($before) . $button . self::paragraphs($after);
    }

    /** Plain text to <p>s: blank lines separate paragraphs, single line breaks stay as <br> */
    public static function paragraphs(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return '';
        }
        $out = '';
        foreach (preg_split("/\n\s*\n/", $text) as $para) {
            $out .= '<p>' . nl2br(htmlspecialchars(trim($para), ENT_QUOTES, 'UTF-8'), false) . '</p>';
        }

        return $out;
    }

    /**
     * A share template (Studio Proposition « share_email_body ») split around its {link} line, with the
     * template's own sign-off taken off: the mailbox signature follows the message, so "Cordialement, {sender},
     * La Petite Cour" would sign it twice. Keeps the closing word itself ("Cordialement,").
     *
     * @param array<string, string> $vars {contact}, {reference}, {sender}… ({link} is the button)
     * @return array{before: string, after: string}
     */
    public static function splitTemplate(string $template, array $vars): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $template));
        $at = null;
        foreach ($lines as $i => $line) {
            if (str_contains($line, '{link}')) {
                $at = $i;
                break;
            }
        }
        $before = $at === null ? $lines : array_slice($lines, 0, $at);
        $after = $at === null ? [] : array_slice($lines, $at + 1);

        // Sign-off: keep "Cordialement," / "Best regards," and drop what follows it (sender, company)
        foreach ($after as $i => $line) {
            if (preg_match('/^\s*(bien\s+)?(cordialement|sinc[eè]res salutations|best regards|kind regards|regards)\b/iu', $line)) {
                $after = array_slice($after, 0, $i + 1);
                break;
            }
        }

        $fill = static function (array $lines) use ($vars): string {
            $text = strtr(implode("\n", $lines), $vars + ['{link}' => '']);

            return trim(preg_replace("/\n{3,}/", "\n\n", $text));
        };

        return ['before' => $fill($before), 'after' => $fill($after)];
    }
}
