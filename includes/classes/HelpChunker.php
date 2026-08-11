<?php
/**
 * includes/classes/HelpChunker.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7H · Help Centre AI Assistant — chunking.
 *
 * Splits a help article's body_md into retrieval-sized pieces before they are
 * embedded (scripts/cron/reindex_help_chunks.php). Pure and stateless — no
 * DB, no HTTP — so it is trivial to unit-test and to re-run offline against a
 * pasted article while tuning chunk size.
 *
 * STRATEGY
 *   1. Split on blank lines into blocks (a heading, a paragraph, a list, a
 *      table, a fenced code block — body_md's own structure already gives us
 *      these for free; see lpc_help_markdown() in includes/functions/help.php
 *      for the same block vocabulary).
 *   2. Track the most recent heading. Every emitted chunk is prefixed with
 *      it (unless the chunk already starts with a heading of its own). A
 *      chunk that reads "Set the payment terms field to Net 30" is useless
 *      out of context; "## Metadata & SLA \n Set the payment terms field to
 *      Net 30" is the sentence an embedding can actually place correctly, and
 *      it is also what the answer generator (PR2) should be quoting from —
 *      the section heading IS part of the citation.
 *   3. Greedily accumulate blocks up to $targetChars. A single block longer
 *      than $targetChars (an unusually long paragraph) is split further on
 *      sentence boundaries so no chunk is ever silently truncated mid-word.
 *   4. Fenced code blocks are never split internally — a half fence breaks
 *      rendering AND breaks the reader's ability to make sense of the chunk.
 *
 * NO OVERLAP BETWEEN CHUNKS, ON PURPOSE. Help articles here are already
 * short and heading-structured (see migration 033's header: "every field
 * name … was read out of the module, not invented" — these are terse,
 * factual, not narrative prose). The heading-prefix from step 2 gives each
 * chunk enough standalone context; the sliding-window overlap that generic
 * RAG pipelines use to avoid losing a sentence at a boundary would mostly
 * just duplicate content here and dilute retrieval.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class HelpChunker
{
    /**
     * @return array<int,string> chunk texts, in reading order
     */
    public static function chunk(string $bodyMd, int $targetChars = 700): array
    {
        $bodyMd = trim(str_replace(["\r\n", "\r"], "\n", $bodyMd));
        if ($bodyMd === '') {
            return [];
        }

        $blocks = self::splitIntoBlocks($bodyMd);

        $chunks         = [];
        $buffer         = [];
        $bufferLen      = 0;
        $currentHeading = '';   // heading in effect for the block about to be read
        $bufferHeading  = '';   // heading in effect when $buffer's FIRST block was added

        $flush = function () use (&$chunks, &$buffer, &$bufferLen, &$bufferHeading) {
            if ($buffer) {
                $chunks[] = self::withHeading(trim(implode("\n\n", $buffer)), $bufferHeading);
            }
            $buffer        = [];
            $bufferLen     = 0;
            $bufferHeading = '';
        };

        foreach ($blocks as $block) {
            $h = self::headingOf($block);
            if ($h !== null) {
                $currentHeading = $h;
            }

            // A block bigger than the whole target is split on its own,
            // sentence-wise, rather than forced into an otherwise-empty chunk.
            if (mb_strlen($block) > $targetChars) {
                $flush();
                foreach (self::splitLongBlock($block, $targetChars) as $piece) {
                    $chunks[] = self::withHeading($piece, $currentHeading);
                }
                continue;
            }

            if ($bufferLen > 0 && $bufferLen + mb_strlen($block) + 2 > $targetChars) {
                $flush();
            }
            if (!$buffer) {
                $bufferHeading = $currentHeading;
            }
            $buffer[]   = $block;
            $bufferLen += mb_strlen($block) + 2;
        }
        $flush();

        // Drop anything that ended up empty or whitespace-only (can happen if
        // an article is literally just one heading, with nothing under it).
        return array_values(array_filter($chunks, static fn($c) => trim($c) !== ''));
    }

    /** Split on one-or-more blank lines, keeping fenced code blocks intact. */
    private static function splitIntoBlocks(string $md): array
    {
        $lines   = explode("\n", $md);
        $blocks  = [];
        $current = [];
        $inFence = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*```/', $line)) {
                $inFence = !$inFence;
                $current[] = $line;
                continue;
            }
            if (!$inFence && trim($line) === '') {
                if ($current) { $blocks[] = implode("\n", $current); $current = []; }
                continue;
            }
            $current[] = $line;
        }
        if ($current) { $blocks[] = implode("\n", $current); }

        return array_values(array_filter($blocks, static fn($b) => trim($b) !== ''));
    }

    /** Heading text if $block is a Markdown heading line, else null. */
    private static function headingOf(string $block): ?string
    {
        $first = self::firstLine($block);
        if (preg_match('/^(#{1,4})\s+(.*)$/', trim($first), $m)) {
            return trim($m[2]);
        }
        return null;
    }

    private static function firstLine(string $s): string
    {
        $pos = strpos($s, "\n");
        return $pos === false ? $s : substr($s, 0, $pos);
    }

    private static function withHeading(string $text, string $heading): string
    {
        if ($heading === '' || self::headingOf(self::firstLine($text)) !== null) {
            return $text;
        }
        return '## ' . $heading . "\n" . $text;
    }

    /** Split one over-long block on sentence boundaries, never mid-word. */
    private static function splitLongBlock(string $block, int $targetChars): array
    {
        // Fenced code blocks are never split — better one oversized chunk
        // than a broken fence in two pieces.
        if (preg_match('/^\s*```/', $block)) {
            return [$block];
        }

        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-ZÀ-Ý0-9])/u', $block) ?: [$block];
        $pieces = [];
        $buf = '';
        foreach ($sentences as $s) {
            if ($buf !== '' && mb_strlen($buf) + mb_strlen($s) + 1 > $targetChars) {
                $pieces[] = trim($buf);
                $buf = '';
            }
            $buf .= ($buf === '' ? '' : ' ') . $s;
        }
        if (trim($buf) !== '') { $pieces[] = trim($buf); }

        return $pieces ?: [$block];
    }
}
