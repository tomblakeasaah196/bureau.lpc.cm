#!/usr/bin/env php
<?php
/**
 * scripts/cron/reindex_help_chunks.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7H · Help Centre AI Assistant — nightly reindex.
 *
 * For every PUBLISHED article body (help_article_bodies, one row per article
 * per language), decides whether its help_article_chunks are stale — never
 * embedded, edited since, or embedded under a different provider/model — and
 * if so, hands it to HelpChunkIndexer::reindexArticle() to actually re-chunk
 * and re-embed it. Everything else is left untouched, so a content edit in
 * one article never re-embeds the other few hundred.
 *
 * NOT THE ONLY WAY A CHUNK SET GETS WRITTEN. api/v1/help_controller.php's
 * save_article action calls HelpChunkIndexer::reindexArticle() synchronously
 * right after an edit, so most edits are searchable within the same request
 * — this nightly sweep is the safety net that catches whatever the
 * synchronous call missed (provider not configured yet at save time, a
 * save-time call that failed, a model switched afterwards in the settings
 * screen), not the only path anymore.
 *
 * NOT CONFIGURED IS NOT AN ERROR. If nobody has filled in the embedding
 * provider from the gear icon yet (migration 098 / AiSettings), this exits 0
 * with a clear one-line message — same "degrade, don't fatal" contract as
 * lpc_help_ready() for the help centre itself. A cron entry can be added
 * before the settings screen is configured; it will simply no-op until then.
 *
 * cPanel Cron entry (same 5-minutes-apart spacing as the other jobs, and
 * nightly rather than more often — this covers content edits, not live
 * traffic):
 *     30 0 * * * /usr/local/bin/php /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/reindex_help_chunks.php >> /home/smartqaq/logs/lpc-cron.log 2>&1
 *
 * Usage:
 *     php scripts/cron/reindex_help_chunks.php          # apply
 *     php scripts/cron/reindex_help_chunks.php --dry     # show what would run, don't call the API or write
 *
 * Exit codes:
 *   0 — success (including "nothing to do" and "not configured").
 *   1 — one or more articles failed to reindex (see STDERR / error_log for
 *       which); articles that DID succeed are still committed — a flaky API
 *       call on one article must not roll back everything that already
 *       embedded cleanly.
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/AiSettings.php';
require_once __DIR__ . '/../../includes/classes/HelpChunker.php';
require_once __DIR__ . '/../../includes/classes/EmbeddingClient.php';
require_once __DIR__ . '/../../includes/classes/HelpChunkIndexer.php';

$dry = in_array('--dry', array_slice($argv, 1), true);

if (!AiSettings::isEmbeddingConfigured()) {
    fwrite(STDOUT, "reindex_help_chunks: no-op (embedding provider not configured — see the gear icon on /modules/help/index.php).\n");
    exit(0);
}

$settings        = AiSettings::get();
$configuredModel = (string) $settings['embedding_model'];

try {
    $db = Database::getInstance()->getConnection();

    // Every published article body — the candidate set.
    $bodies = $db->query(
        "SELECT a.id AS article_id, b.lang, b.title, b.body_md, b.updated_at AS body_updated_at
           FROM help_articles a
           JOIN help_article_bodies b ON b.article_id = a.id
          WHERE a.is_published = 1
          ORDER BY a.id, b.lang"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (!$bodies) {
        fwrite(STDOUT, "reindex_help_chunks: no-op (no published help articles).\n");
        exit(0);
    }

    // What's already indexed, one row per (article_id, lang), so the staleness
    // check below is a single in-memory lookup instead of N+1 queries.
    $existing = [];
    foreach ($db->query(
        "SELECT article_id, lang, MIN(source_updated_at) AS min_src,
                GROUP_CONCAT(DISTINCT embedding_model) AS models, COUNT(*) AS n
           FROM help_article_chunks
          GROUP BY article_id, lang"
    )->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existing[$r['article_id'] . '|' . $r['lang']] = $r;
    }

    $toReindex = [];
    foreach ($bodies as $b) {
        if (trim((string) $b['body_md']) === '') { continue; }   // nothing to embed yet
        $key = $b['article_id'] . '|' . $b['lang'];
        $prev = $existing[$key] ?? null;

        $stale = $prev === null
            || $prev['min_src'] < $b['body_updated_at']
            || $prev['models'] !== $configuredModel;   // provider/model changed since last run

        if ($stale) { $toReindex[] = $b; }
    }

    if (!$toReindex) {
        fwrite(STDOUT, "reindex_help_chunks: no-op (" . count($bodies) . " article bodies, all up to date).\n");
        exit(0);
    }

    fwrite(STDOUT, 'reindex_help_chunks: ' . count($toReindex) . ' of ' . count($bodies)
        . " article bodies need (re)embedding, model={$configuredModel}" . ($dry ? " [dry run]" : '') . ".\n");

    $okArticles  = 0;
    $okChunks    = 0;
    $failed      = [];

    foreach ($toReindex as $b) {
        $label = "article #{$b['article_id']} ({$b['lang']})";
        try {
            if ($dry) {
                // Dry run never calls the embedding API or writes — just
                // report what a real run would chunk.
                $chunkTexts = HelpChunker::chunk((string) $b['body_md']);
                if (!$chunkTexts) {
                    fwrite(STDOUT, "  skip {$label}: body produced zero chunks.\n");
                    continue;
                }
                fwrite(STDOUT, "  would reindex {$label}: " . count($chunkTexts) . " chunk(s).\n");
                $okArticles++;
                $okChunks += count($chunkTexts);
                continue;
            }

            $n = HelpChunkIndexer::reindexArticle(
                (int) $b['article_id'], (string) $b['lang'], (string) $b['title'],
                (string) $b['body_md'], (string) $b['body_updated_at'], $configuredModel
            );
            if ($n === 0) {
                fwrite(STDOUT, "  skip {$label}: body produced zero chunks.\n");
                continue;
            }

            $okArticles++;
            $okChunks += $n;
            fwrite(STDOUT, "  reindexed {$label}: {$n} chunk(s).\n");
        } catch (Throwable $e) {
            error_log("reindex_help_chunks: {$label}: " . $e->getMessage());
            fwrite(STDERR, "  FAILED {$label}: {$e->getMessage()}\n");
            $failed[] = $label;
            // Deliberately continue to the next article — one bad body or one
            // flaky API call must not block everything else that's queued.
        }
    }

    fwrite(STDOUT, "reindex_help_chunks: done. {$okArticles} article(s) reindexed ({$okChunks} chunks), "
        . count($failed) . " failed.\n");

    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    error_log('reindex_help_chunks: ' . $e->getMessage());
    fwrite(STDERR, "reindex_help_chunks: ERROR — {$e->getMessage()}\n");
    exit(1);
}
