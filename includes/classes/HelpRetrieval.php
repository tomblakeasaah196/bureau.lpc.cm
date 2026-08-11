<?php
/**
 * includes/classes/HelpRetrieval.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7H · Help Centre AI Assistant — retrieval.
 *
 * search($question) returns the help_article_chunks most relevant to a
 * question, cosine-similarity ranked, ALREADY FILTERED so that nothing the
 * current user may not read ever leaves this function. This is the one file
 * in the AI assistant feature that is not optional to get right — see
 * migration 099's header, and lpc_help_visible() in includes/functions/help.php,
 * which is THE gate everywhere else in the help centre. This class calls it
 * per-candidate, same as lpc_help_search(), before anything is scored,
 * returned, or ever reaches the DeepSeek prompt.
 *
 * NO VECTOR INDEX. Candidates are pulled from MySQL (filtered to the
 * currently-configured embedding_model, same guard the reindex job uses so
 * differently-dimensioned vectors are never compared), unpacked, and scored
 * with cosine similarity in a PHP loop — see migration 099's header for why
 * that is the right call at this content's scale rather than new
 * infrastructure.
 *
 * KNOWN LIMITATION (documented, not fixed here): candidates are restricted to
 * chunks in the REQUESTED language. lpc_help_article()'s FR-fallback (serve
 * the French body with a notice when no English one exists) is not mirrored
 * here yet — an EN-only reader may miss a FR-only article the assistant could
 * have cited. Worth revisiting once there is real usage data on how often
 * that actually happens.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

require_once __DIR__ . '/AiSettings.php';
require_once __DIR__ . '/EmbeddingClient.php';
require_once __DIR__ . '/../functions/help.php';   // lpc_help_visible(), lpc_help_lang()

final class HelpRetrieval
{
    /** Safety cap on candidate rows pulled per query — comfortably above this
     *  content's realistic chunk count (a few hundred to low thousands). */
    private const CANDIDATE_LIMIT = 800;
    /** At most this many chunks from any one article, so six slots are never
     *  eaten by one long article at the expense of everything else relevant. */
    private const MAX_PER_ARTICLE = 2;
    private const TOP_K           = 6;
    /** Below this cosine similarity, a chunk is treated as "not actually
     *  relevant" rather than padding the context with noise. Conservative on
     *  purpose — a false negative here means "I don't know" (safe); a false
     *  positive means citing an unrelated article (worse). Revisit once real
     *  questions show whether this is too strict. */
    private const MIN_SIMILARITY  = 0.15;

    /**
     * @return array<int,array{article_id:int,slug:string,title:string,category:string,chunk_text:string,url:string,similarity:float}>
     */
    public static function search(string $question, ?string $lang = null): array
    {
        $question = trim($question);
        if ($question === '' || !AiSettings::isEmbeddingConfigured()) {
            return [];
        }

        $lang     = lpc_help_lang($lang);
        $settings = AiSettings::get();
        $model    = (string) $settings['embedding_model'];

        try {
            $qVec = EmbeddingClient::embedOne($question)['vector'];
        } catch (Throwable $e) {
            error_log('HelpRetrieval::search embed question: ' . $e->getMessage());
            return [];
        }

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                "SELECT c.article_id, c.chunk_text, c.embedding, c.embedding_dims,
                        a.slug, a.required_permission,
                        COALESCE(b.title, bfr.title) AS title,
                        cat.title_fr AS cat_title_fr, cat.title_en AS cat_title_en
                   FROM help_article_chunks c
                   JOIN help_articles a   ON a.id = c.article_id AND a.is_published = 1
                   JOIN help_categories cat ON cat.id = a.category_id AND cat.is_published = 1
              LEFT JOIN help_article_bodies b   ON b.article_id = a.id AND b.lang = :lang
              LEFT JOIN help_article_bodies bfr ON bfr.article_id = a.id AND bfr.lang = 'fr'
                  WHERE c.lang = :lang2 AND c.embedding_model = :model
                  LIMIT " . self::CANDIDATE_LIMIT
            );
            $stmt->execute(['lang' => $lang, 'lang2' => $lang, 'model' => $model]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('HelpRetrieval::search query: ' . $e->getMessage());
            return [];
        }

        $qDims  = count($qVec);
        $scored = [];

        foreach ($rows as $r) {
            // THE gate. Not optional, not moved, not duplicated elsewhere —
            // exactly what lpc_help_search() does for keyword search.
            if (!lpc_help_visible($r['required_permission'])) {
                continue;
            }
            if ((int) $r['embedding_dims'] !== $qDims) {
                continue;   // stale row from a since-changed provider; reindex will fix it
            }

            $vec = EmbeddingClient::unpackVector((string) $r['embedding'], (int) $r['embedding_dims']);
            $sim = self::cosine($qVec, $vec);
            if ($sim < self::MIN_SIMILARITY) {
                continue;
            }

            $scored[] = [
                'article_id' => (int) $r['article_id'],
                'slug'       => $r['slug'],
                'title'      => $r['title'] !== null ? (string) $r['title'] : $r['slug'],
                'category'   => ($lang === 'en' && $r['cat_title_en'] !== '') ? $r['cat_title_en'] : $r['cat_title_fr'],
                'chunk_text' => (string) $r['chunk_text'],
                'url'        => '/modules/help/article.php?slug=' . rawurlencode($r['slug']) . '&lang=' . rawurlencode($lang),
                'similarity' => $sim,
            ];
        }

        usort($scored, static fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        $perArticle = [];
        $out = [];
        foreach ($scored as $s) {
            $n = $perArticle[$s['article_id']] ?? 0;
            if ($n >= self::MAX_PER_ARTICLE) {
                continue;
            }
            $perArticle[$s['article_id']] = $n + 1;
            $out[] = $s;
            if (count($out) >= self::TOP_K) {
                break;
            }
        }

        return $out;
    }

    /** @param array<int,float> $a @param array<int,float> $b */
    private static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) { return 0.0; }

        $dot = 0.0; $na = 0.0; $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] * $a[$i];
            $nb  += $b[$i] * $b[$i];
        }
        if ($na <= 0.0 || $nb <= 0.0) { return 0.0; }

        return $dot / (sqrt($na) * sqrt($nb));
    }
}
