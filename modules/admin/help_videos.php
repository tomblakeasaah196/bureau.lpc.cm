<?php
/**
 * modules/admin/help_videos.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the training videos of the Centre d'Aide: upload, replace,
 * publish, delete (migration 126, docs/HELP_VIDEOS.md).
 *
 * A sibling of help_articles.php, behind the same permission (help.manage) and
 * reached from its tabs and from the Centre d'Aide's toolbar.
 *
 * UPLOAD
 *   The filming pipeline (scripts/video/) writes, per video, an .mp4, its
 *   .manifest.json, its .fr.vtt captions and a poster. The manager drops the
 *   four files here; assets/js/modules/help-videos-admin.js reads the manifest
 *   in the browser to check the set, sends the MP4 in 5 MB pieces (the live
 *   upload_max_filesize is 20M; a video is 20-60 MB) and then publishes it with
 *   the manifest, captions and poster. A manifest whose slug is already in the
 *   library replaces that video: same link, new files and transcript.
 *   The CLI does the same without a browser: scripts/help_videos/import.php.
 *
 * WHO SEES EACH VIDEO is shown per row, from the manifest's pages and the menu
 * catalogue, with a warning when no page is in the menu (then only managers
 * can see it until the manifest says audience "all").
 *
 * Shell contract: README §5.5.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/help.php';
require_once __DIR__ . '/../../includes/classes/HelpVideoLibrary.php';

Rbac::requirePermission('help.manage');

$lang = lpc_help_lang($_GET['lang'] ?? null);
$en   = $lang === 'en';

$ready  = HelpVideoLibrary::ready();
$videos = $ready ? HelpVideoLibrary::all() : [];

$storageError = null;
if ($ready) {
    try {
        HelpVideoStore::root();
    } catch (Throwable $e) {
        $storageError = $e->getMessage();
    }
}

$pages = lpc_nav_page_catalogue();
$articleCount = count(lpc_help_all_articles());

/** "45,2 Mo" */
function vadm_size(int $bytes, bool $en): string
{
    $mb = $bytes / 1048576;
    return $en ? number_format($mb, 1) . ' MB' : number_format($mb, 1, ',', ' ') . ' Mo';
}

$pageTitle    = $en ? 'Help Centre (videos)' : "Centre d'Aide (vidéos)";
$pageSubtitle = $en ? 'Help centre · training videos' : "Centre d'aide · vidéos de formation";
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
        <?= lpc_help_link('admin.help_videos', $lang) ?>
        <a href="/modules/help/index.php?lang=<?= $lang ?>#videos"
           class="text-sm font-bold text-gray-500 hover:text-lpc-dark no-underline">
            <?= $en ? 'View the training videos →' : 'Voir les vidéos de formation →' ?>
        </a>
    </div>

    <nav class="lpc-tabs" role="tablist">
        <a role="tab" href="/modules/admin/help_articles.php?lang=<?= $lang ?>"
           class="px-4 py-2 text-sm font-bold border-transparent text-gray-400">
            <?= $en ? 'All articles' : 'Tous les articles' ?> (<?= $articleCount ?>)
        </a>
        <a role="tab" href="/modules/admin/help_articles.php?new=1&lang=<?= $lang ?>"
           class="px-4 py-2 text-sm font-bold border-transparent text-gray-400">
            <?= $en ? 'Editor' : 'Éditeur' ?>
        </a>
        <a role="tab" aria-selected="true" href="?lang=<?= $lang ?>" class="px-4 py-2 text-sm font-bold text-lpc-dark">
            <?= $en ? 'Videos' : 'Vidéos' ?> (<?= count($videos) ?>)
        </a>
    </nav>

    <main role="main" id="main" class="lpc-page space-y-5">

    <?php if (!$ready): ?>
        <div class="lpc-help-empty">
            <p class="font-bold text-gray-900 mb-1"><?= $en ? 'Video library not installed' : 'Bibliothèque vidéo non installée' ?></p>
            <p><?= $en ? 'Run migrations/126_help_videos.sql (php scripts/migrate.php).' : 'Exécutez migrations/126_help_videos.sql (php scripts/migrate.php).' ?></p>
        </div>
    <?php else: ?>

        <?php if ($storageError): ?>
            <div class="lpc-help-callout lpc-help-callout--danger"><?= htmlspecialchars($storageError) ?></div>
        <?php endif; ?>

        <!-- ============================== UPLOAD ============================== -->
        <section class="bg-white rounded-2xl border border-lpc-border shadow-sm p-5" id="vadm-upload"
                 aria-labelledby="vadm-upload-title"
                 data-slugs="<?= htmlspecialchars(json_encode(array_column($videos, 'title', 'slug'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
            <?= Csrf::field() ?>
            <h2 id="vadm-upload-title" class="text-base font-extrabold text-gray-900"><?= $en ? 'Add or replace a video' : 'Ajouter ou remplacer une vidéo' ?></h2>
            <p class="text-sm text-gray-500 mt-1 mb-4">
                <?= $en
                    ? 'Pick the files the filming produced: the .mp4, its .manifest.json, its .fr.vtt captions and its poster. A video whose slug already exists is replaced (same link).'
                    : 'Choisissez les fichiers produits par le tournage : la vidéo .mp4, son .manifest.json, ses sous-titres .fr.vtt et son affiche. Une vidéo dont le slug existe déjà est remplacée (même lien).' ?>
            </p>

            <label class="lpc-vadm-drop" id="vadm-drop">
                <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                <strong><?= $en ? 'Drop the video files here' : 'Déposez les fichiers de la vidéo ici' ?></strong>
                <span><?= $en ? 'or click to choose them (.mp4, .manifest.json, .vtt, poster)' : 'ou cliquez pour les choisir (.mp4, .manifest.json, .vtt, affiche)' ?></span>
                <input type="file" id="vadm-files" multiple
                       accept=".mp4,.m4v,.json,.vtt,.jpg,.jpeg,.png,.webp,video/mp4,application/json,text/vtt,image/jpeg,image/png,image/webp">
            </label>

            <ul class="lpc-vadm-files" id="vadm-summary" hidden></ul>

            <div id="vadm-progress-wrap" class="mt-4" hidden>
                <div class="lpc-vadm-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="vadm-progress"><span></span></div>
                <p class="text-xs text-gray-500 mt-1" id="vadm-progress-text"></p>
            </div>

            <div class="flex flex-wrap items-center gap-3 mt-4">
                <label class="flex items-center gap-2 whitespace-nowrap">
                    <input type="checkbox" id="vadm-published" checked class="w-4 h-4 rounded border-lpc-border text-lpc-dark">
                    <span class="text-sm font-bold text-gray-700"><?= $en ? 'Publish now' : 'Publier dès l\'envoi' ?></span>
                </label>
                <button type="button" id="vadm-send" disabled
                        class="bg-lpc-dark hover:bg-[#004722] text-white px-6 py-2.5 rounded-xl font-bold shadow-md transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                    <?= $en ? 'Upload the video' : 'Envoyer la vidéo' ?>
                </button>
                <button type="button" id="vadm-cancel" hidden
                        class="border border-lpc-border bg-white px-5 py-2.5 rounded-xl font-bold text-gray-600 hover:text-lpc-dark hover:border-lpc-dark transition-all">
                    <?= $en ? 'Cancel' : 'Annuler' ?>
                </button>
                <span id="vadm-status" class="text-sm font-bold" role="status" aria-live="polite"></span>
            </div>

            <p class="text-xs text-gray-400 mt-4">
                <?= $en ? 'From the filming computer, without the browser:' : 'Depuis le poste de tournage, sans navigateur :' ?>
                <code class="font-mono">php scripts/help_videos/import.php &lt;video&gt;.manifest.json</code>
            </p>
        </section>

        <!-- =============================== LIST =============================== -->
        <?php if (!$videos): ?>
            <div class="lpc-help-empty"><?= $en ? 'No videos yet.' : 'Aucune vidéo pour le moment.' ?></div>
        <?php else: ?>
        <div class="bg-white rounded-2xl border border-lpc-border shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 text-left text-[11px] uppercase tracking-wider text-gray-500">
                        <th class="px-4 py-3 font-extrabold"><?= $en ? 'Video' : 'Vidéo' ?></th>
                        <th class="px-4 py-3 font-extrabold"><?= $en ? 'Series' : 'Série' ?></th>
                        <th class="px-4 py-3 font-extrabold"><?= $en ? 'Content' : 'Contenu' ?></th>
                        <th class="px-4 py-3 font-extrabold"><?= $en ? 'Seen by who opens' : 'Vue par qui ouvre' ?></th>
                        <th class="px-4 py-3 font-extrabold text-right"><?= $en ? 'Size' : 'Taille' ?></th>
                        <th class="px-4 py-3 font-extrabold text-right"><?= $en ? 'Views' : 'Vues' ?></th>
                        <th class="px-4 py-3 font-extrabold"><?= $en ? 'State' : 'État' ?></th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($videos as $v):
                    $gates = HelpVideoLibrary::pageGates($v['pages']);
                    $gated = array_filter($gates);
                ?>
                    <tr class="border-t border-lpc-border hover:bg-gray-50/60 align-top" data-id="<?= (int) $v['id'] ?>">
                        <?php /* The cell is as wide as its poster and title: a long title (« Proposition
                                 commerciale ») ran into the « Série » column (found filming the admin videos). */ ?>
                        <td class="px-4 py-3" style="min-width: 22rem">
                            <div class="flex gap-3">
                                <?php if ($v['poster_url'] !== ''): ?>
                                    <img class="lpc-vadm-thumb" src="<?= htmlspecialchars($v['poster_url']) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <span class="lpc-vadm-thumb" aria-hidden="true"></span>
                                <?php endif; ?>
                                <div style="min-width: 12rem">
                                    <span class="font-bold text-gray-900"><?= htmlspecialchars($v['title']) ?></span>
                                    <span class="block text-[11px] text-gray-400 font-mono"><?= htmlspecialchars($v['slug']) ?></span>
                                    <span class="block text-[11px] text-gray-400"><?= $en ? 'Updated' : 'Mise à jour le' ?> <?= htmlspecialchars(date($en ? 'Y-m-d H:i' : 'd/m/Y H:i', strtotime((string) $v['updated_at']) ?: time())) ?></span>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">
                            <?= htmlspecialchars($v['series']) ?><?php if ($v['part'] !== null): ?> · <?= (int) $v['part'] ?><?= $v['parts'] !== null ? '/' . (int) $v['parts'] : '' ?><?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-gray-600 whitespace-nowrap">
                            <?= htmlspecialchars($v['duration']) ?>
                            <span class="block text-[11px] text-gray-400">
                                <?= (int) $v['chapters_count'] ?> <?= $en ? 'chapters' : 'chapitres' ?> · <?= (int) $v['cues_count'] ?> <?= $en ? 'lines' : 'répliques' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($v['audience'] === 'all'): ?>
                                <span class="lpc-help-badge lpc-help-badge--on"><?= $en ? 'everyone' : 'tout le monde' ?></span>
                            <?php else: ?>
                                <?php foreach ($gated as $path => $perms):
                                    $label = $en ? ($pages[$path]['label_en'] ?? $path) : ($pages[$path]['label_fr'] ?? $path); ?>
                                    <?php /* Whoever may open this page; the permission code moved to the tooltip: the
                                             badges made the list wider than the page (« Supprimer » cut off). */ ?>
                                    <span class="block text-xs text-gray-700" title="<?= htmlspecialchars(implode(', ', $perms)) ?>">« <?= htmlspecialchars($label) ?> »</span>
                                <?php endforeach; ?>
                                <?php if (!$gated): ?>
                                    <span class="lpc-vadm-warn"><?= $en
                                        ? 'No page of this video is in the menu: only help managers see it. Set audience "all" in the manifest, or film a menu page.'
                                        : "Aucune page de cette vidéo n'est dans le menu : seuls les gestionnaires de l'aide la voient. Mettez audience « all » dans le manifeste, ou filmez une page du menu." ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-500 whitespace-nowrap"><?= htmlspecialchars(vadm_size($v['file_size'], $en)) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-500"><?= (int) $v['view_count'] ?></td>
                        <td class="px-4 py-3">
                            <button type="button" class="lpc-help-badge <?= $v['is_published'] ? 'lpc-help-badge--on' : 'lpc-help-badge--off' ?>"
                                    data-vadm-toggle="<?= (int) $v['id'] ?>"
                                    title="<?= $en ? 'Publish / unpublish' : 'Publier / retirer' ?>">
                                <?= $v['is_published'] ? ($en ? 'Published' : 'Publiée') : ($en ? 'Draft' : 'Brouillon') ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a class="text-lpc-dark font-bold no-underline hover:underline"
                               href="<?= htmlspecialchars($v['url']) ?>&amp;lang=<?= $lang ?>"><?= $en ? 'Watch' : 'Voir' ?></a>
                            <button type="button" class="ml-3 text-red-600 font-bold"
                                    data-vadm-delete="<?= (int) $v['id'] ?>"
                                    data-title="<?= htmlspecialchars($v['title'], ENT_QUOTES) ?>"><?= $en ? 'Delete' : 'Supprimer' ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>

    </main>
</div>

<script src="<?= lpc_asset('/assets/js/modules/help-videos-admin.js') ?>" defer></script>
</body>
</html>
