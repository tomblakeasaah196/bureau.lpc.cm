<?php

namespace LPC\Mail\Imap;

use LPC\Mail\Mime\Headers;

/**
 * A FETCH BODYSTRUCTURE (RFC 3501 section 7.4.2) as a flat list of parts, and from it the parts bureau reads:
 * the text and HTML bodies and the attachments. Sync stores where the bodies are (section, encoding,
 * charset), so a body can be read again from the server without fetching the attachments.
 *
 * Part: ['section' => '1.2', 'type' => 'text/plain', 'params' => [...], 'id' => content-id, 'encoding' => 'base64',
 *        'size' => bytes as sent, 'disposition' => 'attachment'|'inline'|null, 'filename' => ?string,
 *        'parent' => 'alternative'|'related'|'mixed'|… (the multipart it sits in), 'container' => bool]
 */
final class BodyStructure
{
    /** @return list<array> */
    public static function parts(array $structure): array
    {
        $out = [];
        self::walk($structure, '', null, $out);

        return $out;
    }

    /**
     * What a message is made of, for storing.
     *
     * @return array{text: ?array, html: ?array, attachments: list<array>}
     */
    public static function summarise(array $structure): array
    {
        $parts = self::parts($structure);
        $text = $html = null;
        $attachments = [];

        foreach ($parts as $part) {
            if ($part['container']) {
                continue;
            }
            $isBody = in_array($part['type'], ['text/plain', 'text/html'], true)
                && $part['disposition'] !== 'attachment'
                && $part['filename'] === null;

            if ($isBody && $part['type'] === 'text/plain' && $text === null) {
                $text = self::bodyRef($part);
                continue;
            }
            if ($isBody && $part['type'] === 'text/html' && $html === null) {
                $html = self::bodyRef($part);
                continue;
            }
            if ($isBody) {
                continue; // a second text part of the same kind (rare): shown through the first
            }

            $inline = $part['id'] !== null && ($part['disposition'] === 'inline' || ($part['disposition'] === null && $part['parent'] === 'related'));
            $attachments[] = [
                'section' => $part['section'],
                'filename' => $part['filename'] ?? self::fallbackName($part),
                'mime' => $part['type'],
                'encoding' => $part['encoding'],
                'size' => $part['encoding'] === 'base64' ? (int) floor($part['size'] * 3 / 4) : $part['size'],
                'content_id' => $part['id'],
                'is_inline' => $inline,
            ];
        }

        return ['text' => $text, 'html' => $html, 'attachments' => $attachments];
    }

    private static function bodyRef(array $part): array
    {
        return [
            'section' => $part['section'],
            'encoding' => $part['encoding'],
            'charset' => $part['params']['charset'] ?? null,
            'size' => $part['size'],
        ];
    }

    private static function fallbackName(array $part): string
    {
        $ext = match ($part['type']) {
            'message/rfc822' => 'eml',
            'text/calendar' => 'ics',
            'text/plain' => 'txt',
            'text/html' => 'html',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            default => 'bin',
        };

        return ($part['type'] === 'message/rfc822' ? 'message' : 'attachment') . '-' . str_replace('.', '-', $part['section']) . '.' . $ext;
    }

    private static function walk(array $node, string $section, ?string $parent, array &$out): void
    {
        if (isset($node[0]) && is_array($node[0])) {
            // multipart: (part)(part)… subtype [params [disposition [language [location]]]]
            $children = [];
            $i = 0;
            while (isset($node[$i]) && is_array($node[$i])) {
                $children[] = $node[$i];
                $i++;
            }
            $subtype = strtolower((string) ($node[$i] ?? 'mixed'));
            if ($section !== '') {
                $out[] = [
                    'section' => $section, 'type' => 'multipart/' . $subtype, 'params' => [], 'id' => null, 'encoding' => null,
                    'size' => 0, 'disposition' => null, 'filename' => null, 'parent' => $parent, 'container' => true,
                ];
            }
            foreach ($children as $n => $child) {
                self::walk($child, ltrim($section . '.' . ($n + 1), '.'), $subtype, $out);
            }

            return;
        }

        // single part: type subtype params id description encoding size …
        $type = strtolower((string) ($node[0] ?? 'application')) . '/' . strtolower((string) ($node[1] ?? 'octet-stream'));
        $params = self::params($node[2] ?? null);
        $extensionAt = 7;
        if (str_starts_with($type, 'text/')) {
            $extensionAt = 8; // + lines
        } elseif ($type === 'message/rfc822') {
            $extensionAt = 10; // + envelope, body, lines
        }
        // md5 at $extensionAt, then disposition
        $disposition = $node[$extensionAt + 1] ?? null;
        $dispositionType = null;
        $dparams = [];
        if (is_array($disposition) && isset($disposition[0]) && is_string($disposition[0])) {
            $dispositionType = strtolower($disposition[0]);
            $dparams = self::params($disposition[1] ?? null);
        }

        $id = isset($node[3]) && is_string($node[3]) ? trim($node[3], " <>\t") : null;

        $out[] = [
            'section' => $section === '' ? '1' : $section,
            'type' => $type,
            'params' => $params,
            'id' => $id !== '' ? $id : null,
            'encoding' => isset($node[5]) && is_string($node[5]) ? strtolower($node[5]) : null,
            'size' => isset($node[6]) && is_numeric($node[6]) ? (int) $node[6] : 0,
            'disposition' => in_array($dispositionType, ['attachment', 'inline'], true) ? $dispositionType : null,
            'filename' => self::filename($dparams, $params),
            'parent' => $parent,
            'container' => false,
        ];
    }

    /** ("charset" "utf-8" "name" "a.pdf") → ['charset' => 'utf-8', 'name' => 'a.pdf'], keys lower case */
    private static function params(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        for ($i = 0; $i + 1 < count($list); $i += 2) {
            if (is_string($list[$i])) {
                $out[strtolower($list[$i])] = is_string($list[$i + 1]) ? $list[$i + 1] : '';
            }
        }

        return $out;
    }

    /** filename (RFC 2183), filename* and filename*0* … (RFC 2231), or the type's name; encoded words decoded */
    private static function filename(array $dparams, array $params): ?string
    {
        foreach ([$dparams, $params] as $set) {
            foreach (['filename', 'name'] as $key) {
                $value = Headers::parameter($set, $key);
                if ($value !== null && trim($value) !== '') {
                    return self::safeName($value);
                }
            }
        }

        return null;
    }

    private static function safeName(string $name): string
    {
        $name = trim(str_replace(["\0", "\r", "\n", '/', '\\'], ['', '', '', '_', '_'], $name));

        return mb_substr($name, 0, 200);
    }
}
