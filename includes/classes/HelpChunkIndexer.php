<?php
/**
 * includes/classes/HelpChunkIndexer.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7H · Help Centre AI Assistant — per-article indexing.
 *
 * The one place that knows how to turn a single article body into its
 * help_article_chunks rows: chunk (HelpChunker) -> embed (EmbeddingClient) ->
 * delete-and-replace that (article_id, lang)'s chunk set. Extracted out of
 * scripts/cron/reindex_help_chunks.php so the exact same logic can be called
 * from two places without drifting apart:
 *
 *   1. The nightly cron (scripts/cron/reindex_help_chunks.php) — sweeps every
 *      published article, re-embeds whatever is new/edited/under a stale
 *      model. The safety net: catches anything the synchronous path below
 *      missed (embedding wasn't configured yet at save time, a save-time
 *      call failed, a model was switched in the settings screen, etc).
 *
 *   2. api/v1/help_controller.php's save_article action — calls
 *      reindexArticle() synchronously, right after an edit is committed, so
 *      an editor doesn't have to wait for the next nightly run before the
 *      assistant can cite their change. Best-effort there (wrapped in
 *      try/catch by the caller): a slow or failing embedding provider must
 *      never block saving an article. Anything that fails synchronously is
 *      still picked up by the nightly sweep, same as before this existed.
 *
 * NOT RESPONSIBLE FOR DECIDING WHETHER TO RUN. Staleness detection (is this
 * body new/edited/under a different model?) stays in the cron script, whose
 * job is exactly that sweep. The save-path caller doesn't need a staleness
 * check at all — a save is definitionally an edit.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

require_once __DIR__ . '/HelpChunker.php';
require_once __DIR__ . '/EmbeddingClient.php';

final class HelpChunkIndexer
{
    /**
     * (Re)chunk and (re)embed one article body, replacing its entire
     * (article_id, lang) chunk set in a single transaction — either the old
     * set is fully gone and the new one fully in, or (on failure) neither
     * changed, never a half-written mix of old and new chunk_index rows.
     *
     * @throws Throwable on any chunking/embedding/DB failure — the caller
     *         decides whether that's fatal (cron: log and move to the next
     *         article) or swallowable (save path: log and let the save
     *         still succeed; the nightly sweep will retry it).
     * @return int chunks written (0 means the body produced no chunks — e.g.
     *         blank — and nothing was written; any previously-stored chunk
     *         set for this (article_id, lang) is left untouched in that case)
     */
    public static function reindexArticle(
        int $articleId,
        string $lang,
        string $title,
        string $bodyMd,
        string $sourceUpdatedAt,
        string $embeddingModel
    ): int {
        $chunkTexts = HelpChunker::chunk($bodyMd);
        if (!$chunkTexts) {
            return 0;
        }

        // Title-prefixed for embedding context, stored clean — see the same
        // note previously in reindex_help_chunks.php: the model benefits from
        // the title on every chunk, but the chunk_text shown to the user/LLM
        // shouldn't repeat it on every one of an article's chunks.
        $forEmbedding = array_map(
            static fn($c) => trim($title) . "\n\n" . $c,
            $chunkTexts
        );
        $vectors = EmbeddingClient::embedBatch($forEmbedding);

        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM help_article_chunks WHERE article_id = ? AND lang = ?")
               ->execute([$articleId, $lang]);

            $ins = $db->prepare(
                "INSERT INTO help_article_chunks
                    (article_id, lang, chunk_index, chunk_text, token_count,
                     embedding_model, embedding_dims, embedding, source_updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($chunkTexts as $i => $text) {
                $vec = $vectors[$i]['vector'];
                $ins->execute([
                    $articleId, $lang, $i, $text,
                    (int) ceil(mb_strlen($text) / 4),   // rough token estimate; informational only
                    $embeddingModel, $vectors[$i]['dims'],
                    EmbeddingClient::packVector($vec),
                    $sourceUpdatedAt,
                ]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }

        return count($chunkTexts);
    }

    /**
     * Drop an article's chunk set outright — used when a body is removed
     * (save_article deletes a help_article_bodies row when title+body are
     * both blank) rather than edited. Nothing to chunk or embed, so there is
     * nothing for reindexArticle() to do; this just clears what's stale.
     */
    public static function deleteArticle(int $articleId, string $lang): void
    {
        try {
            Database::getInstance()->getConnection()
                ->prepare("DELETE FROM help_article_chunks WHERE article_id = ? AND lang = ?")
                ->execute([$articleId, $lang]);
        } catch (Throwable $e) {
            error_log("HelpChunkIndexer::deleteArticle article #{$articleId} ({$lang}): " . $e->getMessage());
        }
    }
}
