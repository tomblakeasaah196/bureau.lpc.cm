<?php
/**
 * modules/help/article.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7G · a single help article, full page.
 *
 * The drawer's "open full article" target, and where ⌘K sends you. Same content
 * as the drawer, with room for the table of contents and the "see also" rail.
 *
 * A MISSING ARTICLE AND A FORBIDDEN ARTICLE RENDER THE SAME PAGE. lpc_help_article()
 * returns null for both, and this file does not ask which it was. Distinguishing
 * them would turn the help centre into a directory of features the reader is not
 * cleared for — "you may not read the guide to Payroll" tells them there is a
 * payroll module and they are outside it.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/help.php';

Rbac::requireAuth();

$lang = lpc_help_lang($_GET['lang'] ?? null);
$en   = $lang === 'en';
$slug = trim((string) ($_GET['slug'] ?? ''));

$article = $slug !== '' ? lpc_help_article($slug, $lang) : null;
$toc     = $article ? lpc_help_toc($article['body_md']) : [];

// Other articles anchored to the same page — the natural "what's next".
$seeAlso = [];
if ($article && $article['page_path'] !== '') {
    foreach (lpc_help_anchored(lpc_help_anchor_key_for($article['page_path']), $lang) as $s) {
        if ($s['slug'] !== $slug) { $seeAlso[] = $s; }
    }
}

if ($article) { lpc_help_bump_view($article['id']); }

$pageTitle    = $article ? $article['title'] : ($en ? 'Article not found' : 'Article introuvable');
$pageSubtitle = $article ? $article['category']['title'] : ($en ? 'Help centre' : "Centre d'aide");
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | LPC ERP</title>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>

<div id="lpc-shell-main">

    <?php if ($article && ($article['page_path'] !== '' || Rbac::hasPermission('help.manage'))): ?>
    <div class="lpc-toolbar">
        <?php if ($article['page_path'] !== ''): ?>
            <!-- Straight to the thing being documented. The point of a manual is
                 to get you back to the work. -->
            <a href="<?= htmlspecialchars($article['page_path']) ?>?lang=<?= $lang ?>"
               class="bg-lpc-dark hover:bg-[#004722] text-white px-4 md:px-5 py-2.5 rounded-xl font-bold shadow-md transition-all flex items-center shrink-0 no-underline">
                <svg class="w-5 h-5 md:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                <span class="hidden md:inline"><?= $en ? 'Go to this page' : 'Aller à cette page' ?></span>
            </a>
        <?php endif; ?>
        <?php if (Rbac::hasPermission('help.manage')): ?>
            <a href="/modules/admin/help_articles.php?edit=<?= (int) $article['id'] ?>&lang=<?= $lang ?>"
               class="w-11 h-11 rounded-xl border border-lpc-border bg-white text-gray-500 hover:text-lpc-dark hover:border-lpc-dark flex items-center justify-center shrink-0 transition-all lpc-focusable no-underline"
               title="<?= $en ? 'Edit this article' : 'Modifier cet article' ?>"
               aria-label="<?= $en ? 'Edit this article' : 'Modifier cet article' ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <main role="main" id="main" class="lpc-page">

    <?php if (!$article): ?>
        <div class="lpc-help-empty">
            <p class="font-bold text-gray-900 mb-1"><?= $en ? 'Article not available' : 'Article indisponible' ?></p>
            <p class="mb-4"><?= $en
                ? 'It may have been moved, or it may not be part of your access.'
                : "Il a peut-être été déplacé, ou il ne fait pas partie de vos accès." ?></p>
            <a class="lpc-help-link font-bold" href="/modules/help/index.php?lang=<?= $lang ?>">
                ← <?= $en ? 'Back to the help centre' : "Retour au centre d'aide" ?>
            </a>
        </div>
    <?php else: ?>

        <nav class="lpc-help-crumbs" aria-label="<?= $en ? 'Breadcrumb' : "Fil d'Ariane" ?>">
            <a href="/modules/help/index.php?lang=<?= $lang ?>"><?= $en ? 'Help centre' : "Centre d'aide" ?></a>
            <span>›</span>
            <a href="/modules/help/index.php?cat=<?= urlencode($article['category']['slug']) ?>&lang=<?= $lang ?>"><?= htmlspecialchars($article['category']['title']) ?></a>
            <span>›</span>
            <span><?= htmlspecialchars($article['title']) ?></span>
        </nav>

        <div class="lpc-help-article-wrap">
            <div>
                <?php if ($article['fallback'] && $en): ?>
                    <div class="lpc-help-fallback">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                        English version coming soon — showing the French article.
                    </div>
                <?php endif; ?>

                <h1 class="text-2xl font-black text-gray-900 mb-1"><?= htmlspecialchars($article['title']) ?></h1>
                <?php if ($article['summary'] !== ''): ?>
                    <p class="text-sm text-gray-500 mb-6 max-w-2xl"><?= htmlspecialchars($article['summary']) ?></p>
                <?php endif; ?>

                <!-- Server-rendered from escaped Markdown by lpc_help_markdown().
                     Author input cannot introduce tags — see includes/functions/help.php. -->
                <div class="lpc-help-prose"><?= $article['body_html'] ?></div>

                <?php if ($seeAlso): ?>
                    <p class="lpc-help-section-title"><?= $en ? 'See also' : 'Voir aussi' ?></p>
                    <div class="lpc-help-list">
                        <?php foreach ($seeAlso as $s): ?>
                            <a class="lpc-help-row" href="?slug=<?= urlencode($s['slug']) ?>&lang=<?= $lang ?>">
                                <span class="lpc-help-row-title"><?= htmlspecialchars($s['title']) ?></span>
                                <svg class="lpc-help-row-chev" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (count($toc) > 1): ?>
            <aside class="lpc-help-toc" aria-label="<?= $en ? 'On this page' : 'Sur cette page' ?>">
                <p class="lpc-help-toc-title"><?= $en ? 'On this page' : 'Sur cette page' ?></p>
                <?php foreach ($toc as $h): ?>
                    <a href="#<?= htmlspecialchars($h['id']) ?>" data-level="<?= (int) $h['level'] ?>"><?= htmlspecialchars($h['text']) ?></a>
                <?php endforeach; ?>
            </aside>
            <?php endif; ?>
        </div>

    <?php endif; ?>

    </main>
</div>

<?php require __DIR__ . '/../../includes/components/help_chat_widget.php'; ?>

<script src="<?= lpc_asset('/assets/js/modules/help-chat-widget.js') ?>" defer></script>
</body>
</html>
