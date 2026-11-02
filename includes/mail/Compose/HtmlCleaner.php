<?php

namespace LPC\Mail\Compose;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * What the composer's editor sends is the browser's HTML, so nothing from it is trusted (docs/MAIL.md §7.2:
 * PR 3 cleans with its own allowlist, not PR 2's sanitiser). clean() keeps a short list of formatting tags,
 * with no attributes but a link's address (http, https, mailto or tel), and unwraps or drops everything else.
 * toText() writes the plain-text version every message carries; fromText() turns plain text (a draft written
 * elsewhere, an original quoted in a reply, an AI draft) into safe paragraphs.
 *
 * Uses PHP's DOM. If a server ever lacks it, clean() falls back to the text of the HTML as paragraphs (safe,
 * formatting lost) rather than letting anything through.
 */
final class HtmlCleaner
{
    /** Tags kept as they are */
    private const KEEP = ['p', 'br', 'div', 'b', 'strong', 'i', 'em', 'u', 's', 'a', 'ul', 'ol', 'li', 'blockquote', 'h2', 'h3', 'pre', 'code', 'hr'];

    /** Tags dropped with everything inside them */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'head', 'title', 'meta', 'link', 'img', 'svg', 'math', 'video', 'audio', 'form', 'input', 'button', 'select', 'textarea', 'base'];

    private const BLOCK = ['p', 'div', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'tr', 'ul', 'ol', 'table'];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        if (!class_exists(DOMDocument::class)) {
            return self::fromText(self::toText($html));
        }

        $doc = self::load($html);
        $root = $doc->getElementById('lpc-root');
        if (!$root) {
            return '';
        }
        self::walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        $out = trim($out);

        // An editor left with only empty lines is empty
        return self::isBlank($out) ? '' : $out;
    }

    /** Plain text of (cleaned or not) HTML: paragraphs and line breaks kept, links as "text (address)" */
    public static function toText(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        if (!class_exists(DOMDocument::class)) {
            return \LPC\Mail\Mime\Text::fromHtml($html);
        }
        $doc = self::load($html);
        $root = $doc->getElementById('lpc-root');
        $text = $root ? self::text($root) : '';
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n[ \t]+/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /** Plain text as HTML paragraphs (escaped) */
    public static function fromText(?string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $text));
        if ($text === '') {
            return '';
        }
        $paragraphs = preg_split("/\n{2,}/", $text);

        return implode('', array_map(
            fn ($p) => '<p>' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES, 'UTF-8'), false) . '</p>',
            $paragraphs,
        ));
    }

    public static function isBlank(?string $html): bool
    {
        $text = html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '<hr>'], ' ', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($text, " \t\n\r\0\x0B\u{00A0}") === '';
    }

    private static function load(string $html): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // A quoted original's own <meta charset> (Outlook: windows-1252) would win over the UTF-8 declaration
        // and garble its accents (JBS Fix 2)
        $html = preg_replace('/<meta\b[^>]*\bcharset\b[^>]*>/i', '', $html) ?? $html;
        $doc->loadHTML('<?xml encoding="utf-8"?><html><body><div id="lpc-root">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $doc;
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }
            if (!$child instanceof DOMElement) {
                $node->removeChild($child); // comments, processing instructions
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }

            self::walk($child);

            if (!in_array($tag, self::KEEP, true)) {
                // Unwrap: keep what is inside (a block becomes a line break so words don't run together)
                $isBlock = in_array($tag, ['table', 'tr', 'section', 'article', 'header', 'footer', 'h1', 'h4', 'h5', 'h6', 'center'], true);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                if ($isBlock) {
                    $node->insertBefore($node->ownerDocument->createElement('br'), $child);
                }
                $node->removeChild($child);
                continue;
            }

            $href = $tag === 'a' ? trim($child->getAttribute('href')) : null;
            foreach (iterator_to_array($child->attributes) as $attribute) {
                $child->removeAttribute($attribute->nodeName);
            }
            if ($tag === 'a') {
                if ($href !== null && preg_match('~^(https?://|mailto:|tel:)~i', $href) && !preg_match('/[\s"<>]/', $href)) {
                    $child->setAttribute('href', $href);
                } else {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                }
            }
        }
    }

    private static function text(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $out .= preg_replace('/\s+/u', ' ', $child->nodeValue);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                continue;
            }
            $inner = self::text($child);
            $out .= match (true) {
                $tag === 'br' => "\n",
                $tag === 'hr' => "\n----\n",
                $tag === 'li' => "\n- " . trim($inner),
                $tag === 'a' => self::linkText($child, $inner),
                $tag === 'blockquote' => "\n" . preg_replace('/^/m', '> ', trim($inner)) . "\n",
                in_array($tag, self::BLOCK, true) => "\n" . trim($inner, ' ') . "\n",
                default => $inner,
            };
        }

        return $out;
    }

    private static function linkText(DOMElement $a, string $inner): string
    {
        $href = preg_replace('~^(mailto|tel):~i', '', trim($a->getAttribute('href')));
        if ($href === '' || str_contains($inner, $href) || !preg_match('~^(https?://|[^@\s]+@|\+?[0-9])~i', $href)) {
            return $inner;
        }

        return $inner . ' (' . $href . ')';
    }
}
