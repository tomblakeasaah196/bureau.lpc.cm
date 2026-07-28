<?php
/**
 * modules/admin/help_articles.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7G · the help article editor.
 *
 * Why this page exists at all: the help centre covers thirteen modules. If every
 * article were a migration, documenting the ERP would mean a deploy per
 * paragraph, and the manual would go stale exactly the way the pre-Sprint-7C
 * sidebars did. Articles are data; this is where they are written.
 *
 * TWO TABS, ONE FORM
 *   list  — everything, with its permission, languages present and publish state
 *   edit  — FR and EN side by side, plus the metadata that gates and places it
 *
 * THE PERMISSION FIELD IS A <select>, NOT A TEXT BOX, and that is the important
 * design decision on this page. It is populated from the canonical catalogue in
 * includes/config/permissions.php. A typo in a hand-typed permission name makes
 * Rbac::hasPermission() return false for every user in the company, hiding the
 * article completely, with no error and nothing in any log — the same silent
 * failure that migration 031 was written to fix, one layer up. The API validates
 * the submitted value against the same catalogue, so the guard survives someone
 * POSTing around the form.
 *
 * Shell contract: README §5.5.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/help.php';

Rbac::requirePermission('help.manage');

$lang = lpc_help_lang($_GET['lang'] ?? null);
$en   = $lang === 'en';

$ready      = lpc_help_ready();
$editId     = (int) ($_GET['edit'] ?? 0);
$isNew      = isset($_GET['new']);
$tab        = ($editId > 0 || $isNew) ? 'edit' : 'list';

$categories = $ready ? lpc_help_all_categories() : [];
$articles   = $ready ? lpc_help_all_articles()   : [];

// The permission picker's options, straight from the catalogue.
$LPC_PERMISSIONS = [];
require __DIR__ . '/../../includes/config/permissions.php';

$pageTitle    = $en ? 'Help articles' : "Articles d'aide";
$pageSubtitle = $en ? 'Help centre · authoring' : "Centre d'aide · rédaction";
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
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>

<div id="lpc-shell-main">

    <div class="lpc-toolbar">
        <a href="?new=1&lang=<?= $lang ?>"
           class="bg-lpc-dark hover:bg-[#004722] text-white px-4 md:px-5 py-2.5 rounded-xl font-bold shadow-md transition-all flex items-center shrink-0 no-underline">
            <svg class="w-5 h-5 md:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            <span class="hidden md:inline"><?= $en ? 'New article' : 'Nouvel article' ?></span>
        </a>
        <span class="lpc-toolbar-sep"></span>
        <a href="/modules/help/index.php?lang=<?= $lang ?>"
           class="text-sm font-bold text-gray-500 hover:text-lpc-dark no-underline">
            <?= $en ? 'View the help centre →' : "Voir le centre d'aide →" ?>
        </a>
    </div>

    <nav class="lpc-tabs" role="tablist">
        <a role="tab" href="?lang=<?= $lang ?>"
           class="px-4 py-2 text-sm font-bold <?= $tab === 'list' ? 'text-lpc-dark' : 'border-transparent text-gray-400' ?>">
            <?= $en ? 'All articles' : 'Tous les articles' ?> (<?= count($articles) ?>)
        </a>
        <a role="tab" href="?new=1&lang=<?= $lang ?>"
           class="px-4 py-2 text-sm font-bold <?= $tab === 'edit' ? 'text-lpc-dark' : 'border-transparent text-gray-400' ?>">
            <?= $en ? 'Editor' : 'Éditeur' ?>
        </a>
    </nav>

    <main role="main" id="main" class="lpc-page">

    <?php if (!$ready): ?>
        <div class="lpc-help-empty">
            <p class="font-bold text-gray-900 mb-1"><?= $en ? 'Help centre not initialised' : "Centre d'aide non initialisé" ?></p>
            <p><?= $en
                ? 'Run migrations/032_help_center_schema.sql before writing articles.'
                : "Exécutez migrations/032_help_center_schema.sql avant de rédiger des articles." ?></p>
        </div>

    <?php elseif ($tab === 'list'): ?>

        <!-- =============================== LIST =============================== -->
        <?php if (!$articles): ?>
            <div class="lpc-help-empty"><?= $en ? 'No articles yet.' : 'Aucun article pour le moment.' ?></div>
        <?php else: ?>
        <div class="bg-white rounded-2xl border border-lpc-border shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 text-left text-[11px] uppercase tracking-wider text-gray-500">
                        <th class="px-4 py-3 font-extrabold"><?= $en ? 'Title' : 'Titre' ?></th>
                        <th class="px-4 py-3 font-extrabold"><?= $en ? 'Category' : 'Catégorie' ?></th>
                        <th class="px-4 py-3 font-extrabold"><?= $en ? 'Gated by' : 'Permission' ?></th>
                        <th class="px-4 py-3 font-extrabold">FR / EN</th>
                        <th class="px-4 py-3 font-extrabold"><?= $en ? 'Page' : 'Page' ?></th>
                        <th class="px-4 py-3 font-extrabold text-right"><?= $en ? 'Views' : 'Vues' ?></th>
                        <th class="px-4 py-3 font-extrabold"><?= $en ? 'State' : 'État' ?></th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($articles as $a): ?>
                    <tr class="border-t border-lpc-border hover:bg-gray-50/60" data-id="<?= (int) $a['id'] ?>">
                        <td class="px-4 py-3">
                            <span class="font-bold text-gray-900"><?= htmlspecialchars((string) ($a['title_fr'] ?: $a['slug'])) ?></span>
                            <?php if ($a['kind'] === 'faq'): ?>
                                <span class="lpc-help-badge lpc-help-badge--off ml-1">FAQ</span>
                            <?php endif; ?>
                            <span class="block text-[11px] text-gray-400 font-mono"><?= htmlspecialchars($a['slug']) ?></span>
                        </td>
                        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars((string) $a['category_title']) ?></td>
                        <td class="px-4 py-3">
                            <?php if ($a['required_permission']): ?>
                                <span class="lpc-help-badge lpc-help-badge--perm"><?= htmlspecialchars($a['required_permission']) ?></span>
                            <?php else: ?>
                                <span class="text-[11px] text-gray-400"><?= $en ? 'all users' : 'tous' ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="lpc-help-badge <?= $a['title_fr'] ? 'lpc-help-badge--on' : 'lpc-help-badge--off' ?>">FR</span>
                            <span class="lpc-help-badge <?= $a['title_en'] ? 'lpc-help-badge--on' : 'lpc-help-badge--off' ?>">EN</span>
                        </td>
                        <td class="px-4 py-3 text-[11px] text-gray-500 font-mono"><?= htmlspecialchars((string) $a['page_path']) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-500"><?= (int) $a['view_count'] ?></td>
                        <td class="px-4 py-3">
                            <button type="button" class="lpc-help-badge <?= $a['is_published'] ? 'lpc-help-badge--on' : 'lpc-help-badge--off' ?>"
                                    data-help-toggle="<?= (int) $a['id'] ?>">
                                <?= $a['is_published'] ? ($en ? 'Published' : 'Publié') : ($en ? 'Draft' : 'Brouillon') ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a class="text-lpc-dark font-bold no-underline hover:underline"
                               href="?edit=<?= (int) $a['id'] ?>&lang=<?= $lang ?>"><?= $en ? 'Edit' : 'Modifier' ?></a>
                            <button type="button" class="ml-3 text-red-600 font-bold"
                                    data-help-delete="<?= (int) $a['id'] ?>"><?= $en ? 'Delete' : 'Supprimer' ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

    <?php else: ?>

        <!-- ============================== EDITOR ============================== -->
        <form id="help-article-form" class="space-y-5" data-article-id="<?= (int) $editId ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $editId ?>">

            <div class="bg-white rounded-2xl border border-lpc-border shadow-sm p-5 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

                <label class="block">
                    <span class="block text-[11px] font-extrabold uppercase tracking-wider text-gray-500 mb-1"><?= $en ? 'Category' : 'Catégorie' ?></span>
                    <select name="category_id" required class="w-full border border-lpc-border rounded-xl px-3 py-2 text-sm">
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['title_fr']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="block">
                    <span class="block text-[11px] font-extrabold uppercase tracking-wider text-gray-500 mb-1">Slug</span>
                    <input type="text" name="slug" required placeholder="creer-un-client"
                           class="w-full border border-lpc-border rounded-xl px-3 py-2 text-sm font-mono">
                    <span class="block text-[11px] text-gray-400 mt-1"><?= $en ? 'Used in the URL. Must be unique.' : "Utilisé dans l'URL. Doit être unique." ?></span>
                </label>

                <label class="block">
                    <span class="block text-[11px] font-extrabold uppercase tracking-wider text-gray-500 mb-1"><?= $en ? 'Visible to' : 'Visible par' ?></span>
                    <select name="required_permission" class="w-full border border-lpc-border rounded-xl px-3 py-2 text-sm font-mono">
                        <option value=""><?= $en ? '— every signed-in user —' : '— tout utilisateur connecté —' ?></option>
                        <?php foreach ($LPC_PERMISSIONS as $module => $perms): ?>
                            <optgroup label="<?= htmlspecialchars(strtoupper($module)) ?>">
                                <?php foreach ($perms as $name => $label): ?>
                                    <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <span class="block text-[11px] text-gray-400 mt-1">
                        <?= $en
                            ? 'Pick the permission that gates the page this documents.'
                            : "Choisissez la permission qui protège la page documentée." ?>
                    </span>
                </label>

                <label class="block">
                    <span class="block text-[11px] font-extrabold uppercase tracking-wider text-gray-500 mb-1"><?= $en ? 'Documents this page' : 'Documente cette page' ?></span>
                    <input type="text" name="page_path" placeholder="/modules/crm/clients.php"
                           class="w-full border border-lpc-border rounded-xl px-3 py-2 text-sm font-mono">
                    <span class="block text-[11px] text-gray-400 mt-1">
                        <?= $en
                            ? 'Optional. Binds the "?" button on that page to this article.'
                            : 'Facultatif. Lie le bouton « ? » de cette page à cet article.' ?>
                    </span>
                </label>

                <label class="block">
                    <span class="block text-[11px] font-extrabold uppercase tracking-wider text-gray-500 mb-1"><?= $en ? 'Type' : 'Type' ?></span>
                    <select name="kind" class="w-full border border-lpc-border rounded-xl px-3 py-2 text-sm">
                        <option value="article"><?= $en ? 'Article' : 'Article' ?></option>
                        <option value="faq">FAQ</option>
                    </select>
                </label>

                <div class="flex items-end gap-4">
                    <label class="block flex-1">
                        <span class="block text-[11px] font-extrabold uppercase tracking-wider text-gray-500 mb-1"><?= $en ? 'Order' : 'Ordre' ?></span>
                        <input type="number" name="sort_order" value="100"
                               class="w-full border border-lpc-border rounded-xl px-3 py-2 text-sm">
                    </label>
                    <label class="flex items-center gap-2 pb-2.5 whitespace-nowrap">
                        <input type="checkbox" name="is_published" value="1" checked
                               class="w-4 h-4 rounded border-lpc-border text-lpc-dark">
                        <span class="text-sm font-bold text-gray-700"><?= $en ? 'Published' : 'Publié' ?></span>
                    </label>
                </div>
            </div>

            <!-- FR and EN side by side. Leaving EN blank is a valid, expected
                 state: readers fall back to the French article with a notice
                 rather than seeing nothing. -->
            <div class="lpc-help-editor-grid">
                <?php foreach (['fr' => 'Français', 'en' => 'English'] as $L => $LName): ?>
                <div class="lpc-help-editor-pane">
                    <header>
                        <span class="lpc-help-lang-tag"><?= strtoupper($L) ?></span>
                        <?= htmlspecialchars($LName) ?>
                        <?php if ($L === 'en'): ?>
                            <span class="ml-auto normal-case tracking-normal font-semibold text-gray-400"><?= $en ? 'optional' : 'facultatif' ?></span>
                        <?php endif; ?>
                    </header>
                    <input type="text" name="title_<?= $L ?>" placeholder="<?= $en ? 'Title' : 'Titre' ?>">
                    <input type="text" name="summary_<?= $L ?>" placeholder="<?= $en ? 'One-line summary' : 'Résumé en une ligne' ?>"
                           style="font-weight:400;font-size:.8125rem">
                    <textarea name="body_<?= $L ?>" spellcheck="true"
                              placeholder="## <?= $en ? 'Heading' : 'Titre de section' ?>&#10;&#10;<?= $en ? 'Markdown: **bold**, lists, | tables |, &gt; [!tip] callouts, ```code```' : 'Markdown : **gras**, listes, | tableaux |, &gt; [!tip] encadrés, ```code```' ?>"></textarea>
                    <input type="text" name="keywords_<?= $L ?>" placeholder="<?= $en ? 'Search keywords, comma separated' : 'Mots-clés de recherche, séparés par des virgules' ?>"
                           style="font-weight:400;font-size:.75rem;border-bottom:0;border-top:1px solid var(--lpc-border)">
                </div>
                <?php endforeach; ?>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="bg-lpc-dark hover:bg-[#004722] text-white px-6 py-2.5 rounded-xl font-bold shadow-md transition-all">
                    <?= $en ? 'Save article' : "Enregistrer l'article" ?>
                </button>
                <button type="button" id="help-preview-btn"
                        class="border border-lpc-border bg-white px-5 py-2.5 rounded-xl font-bold text-gray-600 hover:text-lpc-dark hover:border-lpc-dark transition-all">
                    <?= $en ? 'Preview FR' : 'Aperçu FR' ?>
                </button>
                <a href="?lang=<?= $lang ?>" class="text-sm font-bold text-gray-400 hover:text-gray-700 no-underline">
                    <?= $en ? 'Cancel' : 'Annuler' ?>
                </a>
                <span id="help-save-status" class="text-sm font-bold" role="status" aria-live="polite"></span>
            </div>

            <div id="help-preview" hidden class="bg-white rounded-2xl border border-lpc-border shadow-sm p-6">
                <p class="lpc-help-section-title"><?= $en ? 'Preview' : 'Aperçu' ?></p>
                <div class="lpc-help-prose" id="help-preview-body"></div>
            </div>
        </form>

    <?php endif; ?>

    </main>
</div>

<script src="<?= lpc_asset('/assets/js/modules/help-admin.js') ?>" defer></script>
</body>
</html>
