<?php
/**
 * modules/help/index.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7G · the Help Centre home.
 *
 * Reached from the "?" in the topbar, beside the +.
 *
 * Two views in one file, because they are the same page at two depths:
 *   no ?cat  → hero + search + the category grid
 *   ?cat=…   → that category's articles, then its FAQ accordion
 *
 * NO PERMISSION GATES THIS PAGE, and that is deliberate. Every authenticated
 * user may open the help centre; what they SEE is filtered per article by
 * lpc_help_categories() / lpc_help_articles(). Gating the page itself on a
 * permission would mean maintaining a second, parallel answer to "who may read
 * the manual", and the two would drift.
 *
 * Shell contract: README §5.5. Toolbar → main. No page-specific chrome in the
 * topbar, no hand-rolled control bar.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/help.php';

Rbac::requireAuth();

$lang = lpc_help_lang($_GET['lang'] ?? null);
$en   = $lang === 'en';

$catSlug    = trim((string) ($_GET['cat'] ?? ''));
$categories = lpc_help_categories($lang);

// Resolve ?cat against the RBAC-filtered list, never against the raw table —
// that is what stops ?cat=<restricted-slug> from rendering a category the user
// may not see.
$activeCat = null;
foreach ($categories as $c) {
    if ($c['slug'] === $catSlug) { $activeCat = $c; break; }
}

$articles = $faqs = [];
if ($activeCat) {
    $articles = lpc_help_articles($activeCat['id'], $lang, 'article');
    $faqs     = lpc_help_articles($activeCat['id'], $lang, 'faq');
}

$pageTitle    = $en ? 'Help Centre' : "Centre d'aide";
$pageSubtitle = $activeCat ? $activeCat['title'] : ($en ? 'Guides, procedures and FAQs' : 'Guides, procédures et FAQ');

/** Inline an icon path, falling back to a generic book. */
function help_icon(string $d): string
{
    if ($d === '') {
        $d = 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25';
    }
    return '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="22" height="22" aria-hidden="true">'
         . '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="'
         . htmlspecialchars($d, ENT_QUOTES) . '"/></svg>';
}
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

    <?php if (Rbac::hasPermission('help.manage')): ?>
    <div class="lpc-toolbar">
        <a href="/modules/admin/help_articles.php?lang=<?= $lang ?>"
           class="bg-lpc-dark hover:bg-[#004722] text-white px-4 md:px-5 py-2.5 rounded-xl font-bold shadow-md transition-all flex items-center shrink-0 no-underline">
            <svg class="w-5 h-5 md:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            <span class="hidden md:inline"><?= $en ? 'Manage articles' : 'Gérer les articles' ?></span>
        </a>
        <!-- AI assistant settings (Sprint 7H). Same gear-icon-only treatment as
             other secondary toolbar actions — the label lives in the tooltip,
             not on the button, so it doesn't compete with "Manage articles". -->
        <a href="/modules/admin/help_ai_settings.php?lang=<?= $lang ?>"
           class="w-11 h-11 rounded-xl border border-lpc-border bg-white text-gray-500 hover:text-lpc-dark hover:border-lpc-dark flex items-center justify-center shrink-0 transition-all lpc-focusable no-underline"
           title="<?= $en ? 'Praxis settings' : 'Paramètres de Praxis' ?>"
           aria-label="<?= $en ? 'Praxis settings' : 'Paramètres de Praxis' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        </a>
    </div>
    <?php endif; ?>

    <main role="main" id="main" class="lpc-page">

    <?php if (!lpc_help_ready()): ?>
        <!-- Migration 032 hasn't run. Say exactly that, the way proposal_studio
             does, rather than rendering an empty centre that looks broken. -->
        <div class="lpc-help-empty">
            <p class="font-bold text-gray-900 mb-1"><?= $en ? 'Help centre not initialised' : "Centre d'aide non initialisé" ?></p>
            <p><?= $en
                ? 'Run migrations/032_help_center_schema.sql, then 033_help_crm_articles.sql.'
                : 'Exécutez migrations/032_help_center_schema.sql, puis 033_help_crm_articles.sql.' ?></p>
        </div>

    <?php elseif (!$activeCat): ?>

        <!-- ============================= HOME ============================= -->
        <section class="lpc-help-hero">
            <h1><?= $en ? 'How can we help?' : 'Comment pouvons-nous vous aider ?' ?></h1>
            <p><?= $en
                ? 'Step-by-step guides for every module of the ERP. You only see the sections you have access to.'
                : "Des guides pas à pas pour chaque module de l'ERP. Vous ne voyez que les rubriques auxquelles vous avez accès." ?></p>

            <div class="lpc-help-searchbox">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                <input type="search" id="help-search" autocomplete="off"
                       placeholder="<?= $en ? 'Search the help centre…' : "Rechercher dans l'aide…" ?>"
                       aria-label="<?= $en ? 'Search help' : "Rechercher dans l'aide" ?>">
                <kbd>⌘K</kbd>
            </div>
        </section>

        <!-- Live results replace the grid while the user is typing. -->
        <div id="help-results" hidden>
            <p class="lpc-help-section-title" id="help-results-title"></p>
            <div class="lpc-help-list" id="help-results-list"></div>
        </div>

        <div id="help-browse">
            <p class="lpc-help-section-title"><?= $en ? 'Browse by module' : 'Parcourir par module' ?></p>

            <?php if (!$categories): ?>
                <div class="lpc-help-empty">
                    <?= $en ? 'No help articles are available for your role yet.'
                            : "Aucun article d'aide n'est disponible pour votre rôle pour le moment." ?>
                </div>
            <?php else: ?>
                <div class="lpc-help-grid">
                    <?php foreach ($categories as $c): ?>
                        <a class="lpc-help-card" href="?cat=<?= urlencode($c['slug']) ?>&lang=<?= $lang ?>">
                            <span class="lpc-help-card-icon"><?= help_icon($c['icon']) ?></span>
                            <h3><?= htmlspecialchars($c['title']) ?></h3>
                            <?php if ($c['summary'] !== ''): ?>
                                <p><?= htmlspecialchars($c['summary']) ?></p>
                            <?php endif; ?>
                            <span class="lpc-help-card-count">
                                <?= (int) $c['count'] ?> <?= $en ? 'articles' : 'articles' ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php else: ?>

        <!-- =========================== CATEGORY =========================== -->
        <nav class="lpc-help-crumbs" aria-label="<?= $en ? 'Breadcrumb' : "Fil d'Ariane" ?>">
            <a href="?lang=<?= $lang ?>"><?= $en ? 'Help centre' : "Centre d'aide" ?></a>
            <span>›</span>
            <span><?= htmlspecialchars($activeCat['title']) ?></span>
        </nav>

        <section class="lpc-help-hero">
            <h1><?= htmlspecialchars($activeCat['title']) ?></h1>
            <?php if ($activeCat['summary'] !== ''): ?>
                <p><?= htmlspecialchars($activeCat['summary']) ?></p>
            <?php endif; ?>
        </section>

        <?php if ($articles): ?>
            <p class="lpc-help-section-title"><?= $en ? 'Articles' : 'Articles' ?></p>
            <div class="lpc-help-list">
                <?php foreach ($articles as $a): ?>
                    <a class="lpc-help-row"
                       href="/modules/help/article.php?slug=<?= urlencode($a['slug']) ?>&lang=<?= $lang ?>">
                        <span class="min-w-0">
                            <span class="lpc-help-row-title"><?= htmlspecialchars($a['title']) ?></span>
                            <?php if ($a['summary'] !== ''): ?>
                                <span class="lpc-help-row-sum block"><?= htmlspecialchars($a['summary']) ?></span>
                            <?php endif; ?>
                        </span>
                        <svg class="lpc-help-row-chev" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($faqs): ?>
            <p class="lpc-help-section-title"><?= $en ? 'Frequently asked questions' : 'Questions fréquentes' ?></p>
            <?php foreach ($faqs as $f):
                $full = lpc_help_article($f['slug'], $lang);
                if (!$full) { continue; }   // belt and braces; already filtered
            ?>
                <details class="lpc-help-faq">
                    <summary><?= htmlspecialchars($full['title']) ?></summary>
                    <div class="lpc-help-faq-body">
                        <?php if ($full['fallback'] && $en): ?>
                            <div class="lpc-help-fallback">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                                English version coming soon — showing the French article.
                            </div>
                        <?php endif; ?>
                        <div class="lpc-help-prose"><?= $full['body_html'] ?></div>
                    </div>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!$articles && !$faqs): ?>
            <div class="lpc-help-empty"><?= $en ? 'Nothing here yet.' : 'Rien ici pour le moment.' ?></div>
        <?php endif; ?>

    <?php endif; ?>

    </main>
</div>

<?php require __DIR__ . '/../../includes/components/help_chat_widget.php'; ?>

<script src="<?= lpc_asset('/assets/js/modules/help-center.js') ?>" defer></script>
<script src="<?= lpc_asset('/assets/js/modules/help-chat-widget.js') ?>" defer></script>
</body>
</html>
