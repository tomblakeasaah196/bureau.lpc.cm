<?php

namespace LPC\Mail\Read;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use LPC\Mail\Mime\Text;

/**
 * Makes a sender's HTML safe to show in the reader (docs/MAIL.md §2, §7.2), ported from JBS HQ. An allow-list over
 * DOMDocument (bureau has no mail library): tags and attributes not listed are dropped, so scripts, event handlers,
 * forms, frames, javascript: links and CSS tricks never reach the page. The reader then shows the result in a
 * sandboxed frame without scripts and with its own Content-Security-Policy (Reader::document()), as a second wall.
 *
 * Remote pictures (img src, background, CSS url()) are held back unless $remoteImages is true, and counted in
 * 'blocked' so the reader can offer "Afficher les images"; cid: pictures go through $cid (Content-ID → URL in
 * bureau). The quoted earlier message is folded into <details>.
 *
 *   HtmlSanitizer::clean($html)  →  ['html' => body HTML, 'styles' => cleaned <style> text, 'blocked' => int]
 *
 * Without PHP's DOM extension (present on the server: dompdf needs it) the text of the HTML is shown instead.
 */
final class HtmlSanitizer
{
    /** Kept, with their allowed attributes */
    private const TAGS = [
        'a', 'abbr', 'address', 'article', 'aside', 'b', 'bdi', 'bdo', 'big', 'blockquote', 'br', 'caption', 'center', 'cite',
        'code', 'col', 'colgroup', 'dd', 'del', 'details', 'dfn', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure', 'font',
        'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'i', 'img', 'ins', 'kbd', 'li', 'main', 'mark', 'nav',
        'ol', 'p', 'pre', 'q', 's', 'samp', 'section', 'small', 'span', 'strike', 'strong', 'sub', 'summary', 'sup', 'table',
        'tbody', 'td', 'tfoot', 'th', 'thead', 'time', 'tr', 'tt', 'u', 'ul', 'var', 'wbr',
    ];

    /** Removed with everything inside them */
    private const DROP = [
        'script', 'noscript', 'template', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'param', 'svg', 'math',
        'meta', 'link', 'base', 'title', 'head', 'input', 'button', 'select', 'option', 'optgroup', 'textarea', 'datalist',
        'output', 'keygen', 'audio', 'video', 'source', 'track', 'canvas', 'map', 'area', 'portal', 'dialog', 'xml', 'style',
    ];

    private const ATTRIBUTES = [
        'align', 'alt', 'bgcolor', 'border', 'cellpadding', 'cellspacing', 'class', 'color', 'cols', 'colspan', 'dir',
        'face', 'height', 'hspace', 'id', 'lang', 'open', 'rowspan', 'rows', 'size', 'span', 'start', 'style', 'summary',
        'title', 'type', 'valign', 'vspace', 'width', 'datetime', 'cite', 'abbr', 'scope', 'nowrap', 'reversed', 'value',
        'href', 'src', 'background',
    ];

    public const QUOTE_LABEL = 'Afficher le texte cité';

    private bool $remote = false;

    /** @var callable(string): ?string */
    private $cid;

    private int $blocked = 0;

    /** @var list<string> */
    private array $styles = [];

    /**
     * @param callable(string): ?string|null $cid Content-ID → URL of the picture in bureau (null: drop it)
     * @return array{html: string, styles: string, blocked: int}
     */
    public static function clean(string $html, bool $remoteImages = false, ?callable $cid = null): array
    {
        $self = new self();
        $self->remote = $remoteImages;
        $self->cid = $cid ?? fn () => null;

        if (!class_exists(DOMDocument::class)) {
            return ['html' => TextFormatter::toHtml(Text::fromHtml($html)), 'styles' => '', 'blocked' => 0];
        }

        return $self->run($html);
    }

    private function run(string $html): array
    {
        if (trim($html) === '') {
            return ['html' => '', 'styles' => '', 'blocked' => 0];
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // The XML declaration makes libxml read the string as UTF-8 (MIME decoding already converted it). The sender's
        // own <meta charset> (Outlook: windows-1252) would win over it and garble the text ("donâ€™t"), so it goes first.
        $html = preg_replace('/<meta\b[^>]*\bcharset\b[^>]*>/i', '', $html) ?? $html;
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // <style> in the head is the usual place for a newsletter's CSS: kept, cleaned, outside the body
        foreach (iterator_to_array($doc->getElementsByTagName('style')) as $style) {
            $css = $this->css((string) $style->textContent, true);
            if (trim($css) !== '') {
                $this->styles[] = $css;
            }
            $style->parentNode?->removeChild($style);
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return ['html' => '', 'styles' => implode("\n", $this->styles), 'blocked' => $this->blocked];
        }

        $this->children($body);
        $this->foldQuotes($doc, $body);

        // The body's own colours and spacing move to a wrapper
        $wrapper = $doc->createElement('div');
        $wrapper->setAttribute('class', 'mr-mail-body');
        $style = [];
        foreach (['bgcolor', 'style'] as $attr) {
            if ($body->hasAttribute($attr)) {
                $value = $this->attribute('div', $attr, $body->getAttribute($attr));
                if ($value !== null) {
                    $style[] = $attr === 'bgcolor' ? 'background-color:' . $value : rtrim($value, '; ');
                }
            }
        }
        if ($style) {
            $wrapper->setAttribute('style', implode(';', $style));
        }
        while ($body->firstChild) {
            $wrapper->appendChild($body->firstChild);
        }

        return ['html' => (string) $doc->saveHTML($wrapper), 'styles' => implode("\n", $this->styles), 'blocked' => $this->blocked];
    }

    private function children(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            $this->node($node);
        }
    }

    private function node(DOMNode $node): void
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return;
        }
        if (!$node instanceof DOMElement) {
            // comments (Outlook's <!--[if mso]>), processing instructions, CDATA
            $node->parentNode?->removeChild($node);

            return;
        }

        $tag = strtolower($node->nodeName);
        if (in_array($tag, self::DROP, true)) {
            $node->parentNode?->removeChild($node);

            return;
        }

        $this->children($node);

        if (!in_array($tag, self::TAGS, true)) {
            // form, label, fieldset, o:p, html, body inside body…: keep the words, lose the element
            $this->unwrap($node);

            return;
        }

        foreach (iterator_to_array($node->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = in_array($name, self::ATTRIBUTES, true) ? $this->attribute($tag, $name, $attr->nodeValue ?? '') : null;
            $node->removeAttribute($attr->nodeName);
            if ($value !== null) {
                $node->setAttribute($name, $value);
            }
        }

        if ($tag === 'a') {
            if ($node->hasAttribute('href')) {
                // In-page anchors stay in the frame; everything else opens in a new tab, without telling the site where from
                $inPage = str_starts_with($node->getAttribute('href'), '#');
                $node->setAttribute('target', $inPage ? '_self' : '_blank');
                if (!$inPage) {
                    $node->setAttribute('rel', 'noopener noreferrer nofollow');
                }
            }
        } elseif ($tag === 'img' && !$node->hasAttribute('src')) {
            // a held-back or unsafe picture: its words stay, the frame doesn't
            $alt = trim($node->getAttribute('alt'));
            if ($alt === '') {
                $node->parentNode?->removeChild($node);
            } else {
                $node->parentNode?->replaceChild($node->ownerDocument->createTextNode('[' . $alt . ']'), $node);
            }
        }
    }

    /** The value to keep, or null to drop the attribute */
    private function attribute(string $tag, string $name, string $value): ?string
    {
        return match ($name) {
            'href' => $tag === 'a' ? $this->link($value) : null,
            'src' => $tag === 'img' ? $this->image($value) : null,
            'background' => in_array($tag, ['table', 'td', 'th', 'tr', 'div'], true) ? $this->image($value) : null,
            'style' => ($css = trim($this->css($value, false))) !== '' ? $css : null,
            'cite' => $this->link($value) === null ? null : $value,
            'type' => in_array($tag, ['ol', 'ul', 'li', 'blockquote'], true) ? mb_substr($value, 0, 20) : null,
            default => mb_substr($value, 0, 500),
        };
    }

    /** Links: web, mail, phone and in-page anchors only */
    private function link(string $url): ?string
    {
        $url = trim(preg_replace('/[\x00-\x20\x7F]+/u', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if ($url === '') {
            return null;
        }
        if ($url[0] === '#') {
            return $url;
        }

        return preg_match('~^(https?://|mailto:|tel:)~i', $url) ? $url : null;
    }

    /** Pictures: cid: through bureau, small data: images, remote ones only when allowed */
    private function image(string $url): ?string
    {
        $url = trim(preg_replace('/[\x00-\x20\x7F]+/u', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if ($url === '') {
            return null;
        }
        if (preg_match('/^cid:(.+)$/i', $url, $m)) {
            return ($this->cid)(rawurldecode($m[1]));
        }
        if (preg_match('~^data:image/(png|gif|jpe?g|webp);base64,[a-z0-9+/=]+$~i', $url)) {
            return $url;
        }
        if (preg_match('~^(https?:)?//~i', $url)) {
            if (!$this->remote) {
                $this->blocked++;

                return null;
            }

            return str_starts_with($url, '//') ? 'https:' . $url : $url;
        }

        return null;
    }

    /**
     * CSS from a style attribute or a <style> block. CSS escapes are decoded first so they can't hide a word;
     * expression(), behaviours, bindings, @import and script URLs go; url() follows the picture rules, and
     * image-set() (which loads pictures without url()) is made void.
     */
    private function css(string $css, bool $block): string
    {
        $css = preg_replace('~/\*.*?(\*/|$)~s', '', $css) ?? '';
        $css = preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', fn ($m) => mb_chr(hexdec($m[1]) ?: 0xFFFD, 'UTF-8') ?: '', $css) ?? '';
        $css = preg_replace('/\\\\(.)/s', '$1', $css) ?? '';
        $css = str_replace(['<', "\0"], '', $css);

        if (preg_match('/expression\s*\(|javascript:|vbscript:|-moz-binding|behaviou?r\s*:|@import|@charset|@namespace/i', $css)) {
            if (!$block) {
                return '';
            }
            $css = preg_replace('/@(import|charset|namespace)[^;]*;?/i', '', $css) ?? '';
            $css = preg_replace('/[^{};]*(expression\s*\(|javascript:|vbscript:|-moz-binding|behaviou?r\s*:)[^;}]*;?/i', '', $css) ?? '';
        }
        $css = $this->withoutImageSet($css);

        return preg_replace_callback('/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/is', function ($m) {
            $url = $this->image($m[2]);

            return $url === null ? 'none' : 'url("' . str_replace(['"', '\\', "\n", "\r"], '', $url) . '")';
        }, $css) ?? '';
    }

    /** image-set(…) loads pictures from plain strings, without url(): each one becomes none, brackets and all */
    private function withoutImageSet(string $css): string
    {
        while (preg_match('/(-webkit-)?image-set\s*\(/i', $css, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
            $depth = 0;
            $end = strlen($css);
            for ($i = $start + strlen($m[0][0]) - 1; $i < strlen($css); $i++) {
                if ($css[$i] === '(') {
                    $depth++;
                } elseif ($css[$i] === ')' && --$depth === 0) {
                    $end = $i + 1;
                    break;
                }
            }
            $css = substr($css, 0, $start) . 'none' . substr($css, $end);
        }

        return $css;
    }

    private function unwrap(DOMElement $node): void
    {
        $parent = $node->parentNode;
        if (!$parent) {
            return;
        }
        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }

    /**
     * Fold the quoted earlier message: Gmail's div.gmail_quote, Apple's blockquote type=cite, Outlook's
     * divRplyFwdMsg (and everything after it), Yahoo's quote, Thunderbird's moz-cite-prefix.
     */
    private function foldQuotes(DOMDocument $doc, DOMElement $body): void
    {
        $xpath = new DOMXPath($doc);
        $queries = [
            '//*[contains(concat(" ", normalize-space(@class), " "), " gmail_quote ")]',
            '//*[@id="divRplyFwdMsg" or @id="appendonsend" or @id="mail-editor-reference-message-container"]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " yahoo_quoted ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " moz-cite-prefix ")]',
            '//blockquote[@type="cite"]',
        ];

        foreach ($queries as $i => $query) {
            $found = $xpath->query($query, $body);
            $start = $found && $found->length ? $found->item(0) : null;
            if (!$start instanceof DOMElement || $start->isSameNode($body)) {
                continue;
            }
            // Outlook and Thunderbird put the header line first and the quote after it: fold from there to the end
            $toEnd = $i === 1 || $i === 3;
            $nodes = [];
            for ($node = $start; $node; $node = $toEnd ? $node->nextSibling : null) {
                $nodes[] = $node;
            }

            // Never fold the whole message, nor a forwarded one (that is what the person was sent)
            $words = fn (DOMNode $n) => preg_replace('/\s+/u', '', (string) $n->textContent) ?? '';
            $folded = implode('', array_map($words, $nodes));
            if (mb_strlen($words($body)) - mb_strlen($folded) < 1
                || preg_match('/forwarded message|message transféré/iu', mb_substr((string) $start->textContent, 0, 300))) {
                return;
            }

            $details = $doc->createElement('details');
            $details->setAttribute('class', 'mr-quote');
            $details->appendChild($doc->createElement('summary', self::QUOTE_LABEL));
            $start->parentNode->insertBefore($details, $start);
            foreach ($nodes as $node) {
                $details->appendChild($node);
            }

            return;
        }
    }
}
