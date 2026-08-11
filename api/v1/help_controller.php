<?php
/**
 * api/v1/help_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7G · Help Centre backend.
 *
 * READ actions (any authenticated user; the data layer filters per article):
 *   article       one article + rendered HTML, for the drawer
 *   search        full-text search, for ⌘K and the centre's search box
 *   anchored      the articles bound to a page key
 *
 * WRITE actions (gated on `help.manage`, CSRF-checked):
 *   save_article  create or update an article + its FR/EN bodies
 *   toggle        publish / unpublish
 *   delete        remove an article (bodies and anchors cascade)
 *   set_anchor    bind or unbind an article to a page key
 *
 * TWO THINGS THIS FILE IS CAREFUL ABOUT
 * ------------------------------------
 * 1. It never distinguishes "no such article" from "you may not read this
 *    article". Both return 404 with the same body. A 403 here would let anyone
 *    enumerate which articles — and therefore which features — exist above
 *    their permission level.
 *
 * 2. `required_permission` on a write is validated against the canonical
 *    catalogue in includes/config/permissions.php. Without that check an editor
 *    with `help.manage` could type a permission that does not exist, and
 *    Rbac::hasPermission() would return false for everyone — quietly hiding the
 *    article from the entire company with no error anywhere. Same failure mode
 *    as migration 030's ungranted permission, one layer up.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/help.php';
require_once __DIR__ . '/../../includes/classes/AiSettings.php';
require_once __DIR__ . '/../../includes/classes/HelpChunkIndexer.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requireAuth();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$lang   = lpc_help_lang($_POST['lang'] ?? $_GET['lang'] ?? null);

/** Uniform JSON exit. */
function help_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Uniform failure. Never leaks $e->getMessage(). */
function help_fail(string $msg, int $code = 400): void
{
    help_out(['status' => 'error', 'message' => $msg], $code);
}

if (!lpc_help_ready()) {
    // Code deployed ahead of migration 032. Say so honestly rather than 500.
    help_out(['status' => 'success', 'ready' => false, 'articles' => [], 'results' => []]);
}

/** The canonical permission names an article may be gated on. */
function help_known_permissions(): array
{
    static $flat = null;
    if ($flat !== null) { return $flat; }
    $flat = [];
    $path = __DIR__ . '/../../includes/config/permissions.php';
    if (is_file($path)) {
        $LPC_PERMISSIONS = [];
        include $path;                       // populates $LPC_PERMISSIONS
        foreach ($LPC_PERMISSIONS as $group) {
            foreach ($group as $name => $_label) { $flat[$name] = true; }
        }
    }
    return $flat;
}

switch ($action) {

    // =========================================================================
    // READS
    // =========================================================================

    case 'article': {
        $slug = trim((string) ($_GET['slug'] ?? ''));
        if ($slug === '') { help_fail('Missing slug.', 400); }

        $a = lpc_help_article($slug, $lang);
        // Deliberately the same answer for "absent" and "forbidden".
        if (!$a) { help_fail('Not found.', 404); }

        // "See also" = the other articles anchored to the same page, minus this
        // one. Computed here rather than stored, so it stays right when an
        // article is added to a page.
        $seeAlso = [];
        if ($a['page_path'] !== '') {
            foreach (lpc_help_anchored(lpc_help_anchor_key_for($a['page_path']), $lang) as $s) {
                if ($s['slug'] !== $slug) { $seeAlso[] = $s; }
            }
        }
        $a['see_also'] = $seeAlso;

        // body_md is the editor's concern, not the reader's — don't ship it.
        unset($a['body_md']);

        lpc_help_bump_view($a['id']);
        help_out(['status' => 'success', 'article' => $a]);
    }

    case 'search': {
        $q = trim((string) ($_GET['q'] ?? ''));
        $limit = min(max((int) ($_GET['limit'] ?? 8), 1), 25);
        help_out([
            'status'  => 'success',
            'query'   => $q,
            'results' => lpc_help_search($q, $lang, $limit),
        ]);
    }

    case 'anchored': {
        $key = trim((string) ($_GET['key'] ?? ''));
        if ($key === '') { help_fail('Missing key.', 400); }
        help_out(['status' => 'success', 'articles' => lpc_help_anchored($key, $lang)]);
    }

    // =========================================================================
    // WRITES — help.manage + CSRF on every one
    // =========================================================================

    case 'save_article': {
        Rbac::requirePermission('help.manage');
        Csrf::requireValid();

        $id         = (int) ($_POST['id'] ?? 0);
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $slug       = lpc_help_slugify((string) ($_POST['slug'] ?? ''));
        $perm       = trim((string) ($_POST['required_permission'] ?? ''));
        $kind       = ($_POST['kind'] ?? 'article') === 'faq' ? 'faq' : 'article';
        $pagePath   = trim((string) ($_POST['page_path'] ?? ''));
        $sortOrder  = (int) ($_POST['sort_order'] ?? 100);
        $published  = !empty($_POST['is_published']) ? 1 : 0;

        if ($slug === '')       { help_fail('Le slug est obligatoire.'); }
        if ($categoryId <= 0)   { help_fail('Catégorie manquante.'); }

        // See the header note: an unknown permission hides the article from
        // everyone, silently. Reject it at the door.
        if ($perm !== '' && !isset(help_known_permissions()[$perm])) {
            help_fail("Permission inconnue : « $perm ». Utilisez une permission du catalogue.");
        }
        // page_path must stay inside the app.
        if ($pagePath !== '' && strpos($pagePath, '/') !== 0) {
            help_fail('Le chemin de page doit commencer par « / ».');
        }

        $db = lpc_help_db();
        if (!$db) { help_fail('Service indisponible.', 503); }

        try {
            $db->beginTransaction();

            if ($id > 0) {
                $st = $db->prepare(
                    "UPDATE help_articles
                        SET category_id = :cid, slug = :slug,
                            required_permission = :perm, kind = :kind,
                            page_path = :page, sort_order = :sort,
                            is_published = :pub, updated_by = :uid
                      WHERE id = :id"
                );
                $st->execute([
                    'cid' => $categoryId, 'slug' => $slug,
                    'perm' => $perm !== '' ? $perm : null, 'kind' => $kind,
                    'page' => $pagePath, 'sort' => $sortOrder, 'pub' => $published,
                    'uid' => $_SESSION['user_id'] ?? null, 'id' => $id,
                ]);
            } else {
                $st = $db->prepare(
                    "INSERT INTO help_articles
                        (category_id, slug, required_permission, kind, page_path,
                         sort_order, is_published, updated_by)
                     VALUES (:cid, :slug, :perm, :kind, :page, :sort, :pub, :uid)"
                );
                $st->execute([
                    'cid' => $categoryId, 'slug' => $slug,
                    'perm' => $perm !== '' ? $perm : null, 'kind' => $kind,
                    'page' => $pagePath, 'sort' => $sortOrder, 'pub' => $published,
                    'uid' => $_SESSION['user_id'] ?? null,
                ]);
                $id = (int) $db->lastInsertId();
            }

            // Bodies. An empty title for a language means "no translation" —
            // the row is deleted rather than stored blank, so the FR-fallback
            // in lpc_help_article() triggers correctly instead of serving an
            // article with an empty heading.
            //
            // $bodiesSaved records what happened per language so the AI
            // assistant's chunk index can be kept in sync after commit (see
            // below) without a second read of $_POST.
            $bodiesSaved = [];
            foreach (['fr', 'en'] as $L) {
                $title   = trim((string) ($_POST['title_' . $L]   ?? ''));
                $summary = trim((string) ($_POST['summary_' . $L] ?? ''));
                $bodyMd  = (string) ($_POST['body_' . $L]         ?? '');
                $keywords= trim((string) ($_POST['keywords_' . $L] ?? ''));

                if ($title === '' && trim($bodyMd) === '') {
                    $db->prepare("DELETE FROM help_article_bodies WHERE article_id = ? AND lang = ?")
                       ->execute([$id, $L]);
                    $bodiesSaved[$L] = ['deleted' => true];
                    continue;
                }
                $db->prepare(
                    "INSERT INTO help_article_bodies
                        (article_id, lang, title, summary, body_md, keywords)
                     VALUES (:aid, :lang, :t, :s, :b, :k)
                     ON DUPLICATE KEY UPDATE
                        title = VALUES(title), summary = VALUES(summary),
                        body_md = VALUES(body_md), keywords = VALUES(keywords)"
                )->execute(['aid'=>$id, 'lang'=>$L, 't'=>$title, 's'=>$summary,
                            'b'=>$bodyMd, 'k'=>$keywords]);
                $bodiesSaved[$L] = ['deleted' => false, 'title' => $title, 'body_md' => $bodyMd];
            }

            // Read back the DB-assigned updated_at (ON UPDATE CURRENT_TIMESTAMP,
            // set by MySQL, not PHP) for whichever languages were written, so
            // the sync reindex below stores the SAME source_updated_at the
            // nightly cron will later compare against — using PHP's own clock
            // here instead could disagree with MySQL's by enough to make the
            // cron think this just-reindexed body is still stale.
            if (array_filter($bodiesSaved, static fn($v) => !$v['deleted'])) {
                $ts = $db->prepare("SELECT lang, updated_at FROM help_article_bodies WHERE article_id = ?");
                $ts->execute([$id]);
                foreach ($ts->fetchAll(PDO::FETCH_KEY_PAIR) as $L => $updatedAt) {
                    if (isset($bodiesSaved[$L]) && !$bodiesSaved[$L]['deleted']) {
                        $bodiesSaved[$L]['updated_at'] = $updatedAt;
                    }
                }
            }

            // Anchor follows page_path automatically — one less thing for the
            // editor to keep in sync, and the anchor key is derived, not typed.
            $db->prepare("DELETE FROM help_article_anchors WHERE article_id = ?")->execute([$id]);
            if ($pagePath !== '') {
                $db->prepare(
                    "INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
                     VALUES (?, ?, ?)"
                )->execute([lpc_help_anchor_key_for($pagePath), $id, $sortOrder]);
            }

            $db->commit();

            // Keep the AI assistant's chunk index in sync with this edit
            // right away, rather than making the editor wait for the
            // nightly cron (scripts/cron/reindex_help_chunks.php) to pick it
            // up. Deliberately its OWN try/catch, per language, separate
            // from the one below: a slow or failing embedding provider is
            // exactly the kind of thing that must never turn an already-
            // successful article save into an "Enregistrement impossible"
            // error for the editor. Anything that fails here silently falls
            // back to the nightly sweep, which re-checks every article
            // regardless of whether this block ran or succeeded.
            // Unpublished (draft) articles are never joined into retrieval
            // (HelpRetrieval::search() requires a.is_published = 1, same as
            // the cron's own candidate query) — skip the API cost of
            // embedding a draft nobody can be answered from yet. Toggling it
            // published later doesn't touch help_article_bodies.updated_at,
            // so that path is handled by publish/unpublish below instead.
            if ($published && AiSettings::isEmbeddingConfigured()) {
                $embeddingModel = (string) AiSettings::get()['embedding_model'];
                foreach ($bodiesSaved as $L => $info) {
                    try {
                        if ($info['deleted']) {
                            HelpChunkIndexer::deleteArticle($id, $L);
                        } else {
                            HelpChunkIndexer::reindexArticle(
                                $id, $L, $info['title'], $info['body_md'],
                                $info['updated_at'] ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                                $embeddingModel
                            );
                        }
                    } catch (Throwable $e) {
                        error_log("help_controller save_article: sync reindex article #{$id} ({$L}): " . $e->getMessage());
                    }
                }
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('help_controller save_article: ' . $e->getMessage());
            // The only error an editor can realistically cause is a duplicate
            // slug, so name it — a generic "erreur" would send them guessing.
            if (stripos($e->getMessage(), 'uq_help_art_slug') !== false
                || stripos($e->getMessage(), 'Duplicate') !== false) {
                help_fail('Ce slug est déjà utilisé par un autre article.', 409);
            }
            help_fail("Enregistrement impossible.", 500);
        }

        help_out(['status' => 'success', 'id' => $id, 'slug' => $slug]);
    }

    case 'toggle': {
        Rbac::requirePermission('help.manage');
        Csrf::requireValid();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) { help_fail('ID manquant.'); }
        $db = lpc_help_db();
        $db->prepare(
            "UPDATE help_articles SET is_published = 1 - is_published, updated_by = ? WHERE id = ?"
        )->execute([$_SESSION['user_id'] ?? null, $id]);
        $now = $db->prepare("SELECT is_published FROM help_articles WHERE id = ?");
        $now->execute([$id]);
        $isPublished = (int) $now->fetchColumn();

        // Keep the chunk index in step with publish state, same best-effort
        // pattern as save_article above. Publishing: reindex now instead of
        // waiting for the nightly cron to notice — an admin publishing a
        // finished draft expects the assistant to know about it right away.
        // Unpublishing: drop its chunks outright, not just leave them for the
        // cron to ignore — HelpRetrieval already requires is_published = 1
        // so they'd never be served, but there is no reason to keep paying
        // storage for chunks of an article nobody can be answered from.
        if ($isPublished === 1 && AiSettings::isEmbeddingConfigured()) {
            $embeddingModel = (string) AiSettings::get()['embedding_model'];
            $bs = $db->prepare("SELECT lang, title, body_md, updated_at FROM help_article_bodies WHERE article_id = ?");
            $bs->execute([$id]);
            foreach ($bs->fetchAll(PDO::FETCH_ASSOC) as $b) {
                try {
                    HelpChunkIndexer::reindexArticle(
                        $id, $b['lang'], (string) $b['title'], (string) $b['body_md'],
                        (string) $b['updated_at'], $embeddingModel
                    );
                } catch (Throwable $e) {
                    error_log("help_controller toggle: sync reindex article #{$id} ({$b['lang']}): " . $e->getMessage());
                }
            }
        } elseif ($isPublished === 0) {
            HelpChunkIndexer::deleteArticle($id, 'fr');
            HelpChunkIndexer::deleteArticle($id, 'en');
        }

        help_out(['status' => 'success', 'is_published' => $isPublished]);
    }

    case 'delete': {
        Rbac::requirePermission('help.manage');
        Csrf::requireValid();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) { help_fail('ID manquant.'); }
        // Bodies and anchors go with it via ON DELETE CASCADE (migration 032).
        lpc_help_db()->prepare("DELETE FROM help_articles WHERE id = ?")->execute([$id]);
        help_out(['status' => 'success']);
    }

    case 'load_article': {
        // The editor's read — includes body_md, so it needs help.manage and is
        // NOT the same endpoint as the reader's `article`.
        Rbac::requirePermission('help.manage');
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) { help_fail('ID manquant.'); }

        $st = lpc_help_db()->prepare(
            "SELECT id, category_id, slug, required_permission, kind, page_path,
                    sort_order, is_published
               FROM help_articles WHERE id = ? LIMIT 1"
        );
        $st->execute([$id]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) { help_fail('Not found.', 404); }

        $bodies = [];
        $bs = lpc_help_db()->prepare(
            "SELECT lang, title, summary, body_md, keywords
               FROM help_article_bodies WHERE article_id = ?"
        );
        $bs->execute([$id]);
        foreach ($bs->fetchAll(PDO::FETCH_ASSOC) as $b) { $bodies[$b['lang']] = $b; }

        help_out(['status' => 'success', 'article' => $a, 'bodies' => $bodies]);
    }

    case 'preview': {
        Rbac::requirePermission('help.manage');
        Csrf::requireValid();
        help_out([
            'status' => 'success',
            'html'   => lpc_help_markdown((string) ($_POST['body_md'] ?? '')),
        ]);
    }

    default:
        help_fail('Unknown action.', 400);
}
