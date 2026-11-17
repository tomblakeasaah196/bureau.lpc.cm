<?php
/**
 * modules/help/video.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — one training video of the Centre d'Aide (migration 126,
 * docs/HELP_VIDEOS.md).
 *
 *   /modules/help/video.php?slug=intro-1-premiers-pas&t=134
 *
 * The <video> plays the MP4 through api/v1/help_videos_controller.php (session
 * and audience checked, HTTP Range for seeking) with the French captions as a
 * track. Beside it, the transcript: every spoken line, clickable to seek, the
 * current one highlighted and kept in view while it plays; under it, the
 * chapters. `t` (seconds) starts the video there — the links from the search,
 * the AI chat and the page "?" button all carry it.
 *
 * A MISSING VIDEO AND A FORBIDDEN VIDEO RENDER THE SAME PAGE, as articles do
 * (modules/help/article.php). `help.manage` may preview any video, published
 * or not (HelpVideoLibrary::canWatch()).
 *
 * The page is built server-side; assets/js/modules/help-video-player.js only
 * wires the clicks, the highlight and the "copy the link at this moment" tool.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/help.php';
require_once __DIR__ . '/../../includes/classes/HelpVideoLibrary.php';

Rbac::requireAuth();

$lang = lpc_help_lang($_GET['lang'] ?? null);
$en   = $lang === 'en';
$slug = trim((string) ($_GET['slug'] ?? ''));

$video = ($slug !== '' && HelpVideoLibrary::ready()) ? HelpVideoLibrary::bySlug($slug) : null;
if ($video && !HelpVideoLibrary::canWatch($video)) {
    $video = null;   // the same page as "absent" — see the header
}
$start = isset($_GET['t']) && is_numeric($_GET['t']) ? max(0.0, (float) $_GET['t']) : 0.0;

$chapters = $cues = [];
$prev = $next = null;
if ($video) {
    $chapters = HelpVideoLibrary::chapters($video['id']);
    $cues     = HelpVideoLibrary::cues($video['id']);
    [$prev, $next] = HelpVideoLibrary::neighbours($video);
    if ($start * 1000 > $video['duration_ms']) {
        $start = 0.0;
    }
} else {
    http_response_code(404);
}

$chapterTitles = [];
foreach ($chapters as $c) {
    $chapterTitles[$c['n']] = $c['title'];
}

$seriesLine = '';
if ($video && $video['series'] !== '') {
    $seriesLine = $video['series'] . ($video['part'] !== null
        ? ' · ' . ($en ? 'Part ' : 'Partie ') . $video['part'] . ($video['parts'] !== null ? '/' . $video['parts'] : '')
        : '');
}

$pageTitle    = $video ? $video['title'] : ($en ? 'Video not found' : 'Vidéo introuvable');
$pageSubtitle = $en ? 'Training videos' : 'Vidéos de formation';

$strings = [
    'copied'   => $en ? 'Link copied: the video will open at {t}.' : 'Lien copié : la vidéo s\'ouvrira à {t}.',
    'copyFail' => $en ? 'Could not copy the link.' : 'Le lien n\'a pas pu être copié.',
    'paused'   => $en ? 'Auto-scroll paused.' : 'Suivi automatique en pause.',
    'resume'   => $en ? 'Follow the video' : 'Suivre la vidéo',
    'following'=> $en ? 'The transcript follows the video.' : 'La transcription suit la vidéo.',
    'noMatch'  => $en ? 'No line contains “{q}”.' : 'Aucune réplique ne contient « {q} ».',
];
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

    <?php if ($video && Rbac::hasPermission('help.manage')): ?>
    <div class="lpc-toolbar">
        <a href="/modules/admin/help_videos.php?lang=<?= $lang ?>"
           class="w-11 h-11 rounded-xl border border-lpc-border bg-white text-gray-500 hover:text-lpc-dark hover:border-lpc-dark flex items-center justify-center shrink-0 transition-all lpc-focusable no-underline"
           title="<?= $en ? 'Manage videos' : 'Gérer les vidéos' ?>"
           aria-label="<?= $en ? 'Manage videos' : 'Gérer les vidéos' ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
        </a>
    </div>
    <?php endif; ?>

    <main role="main" id="main" class="lpc-page">

        <nav class="lpc-help-crumbs" aria-label="<?= $en ? 'Breadcrumb' : "Fil d'Ariane" ?>">
            <a href="/modules/help/index.php?lang=<?= $lang ?>"><?= $en ? 'Help centre' : "Centre d'aide" ?></a>
            <span>›</span>
            <a href="/modules/help/index.php?lang=<?= $lang ?>#videos"><?= $en ? 'Training videos' : 'Vidéos de formation' ?></a>
            <?php if ($video): ?>
                <span>›</span>
                <span><?= htmlspecialchars($video['title']) ?></span>
            <?php endif; ?>
        </nav>

    <?php if (!$video): ?>

        <div class="lpc-help-empty">
            <p class="font-bold text-gray-900 mb-1"><?= $en ? 'This video is not available.' : "Cette vidéo n'est pas disponible." ?></p>
            <p><a class="lpc-help-link" href="/modules/help/index.php?lang=<?= $lang ?>#videos"><?= $en ? 'See the training videos' : 'Voir les vidéos de formation' ?> →</a></p>
        </div>

    <?php else: ?>

        <?php if (!$video['is_published']): ?>
            <div class="lpc-help-fallback">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <?= $en ? 'Draft: only help managers can see this video.' : "Brouillon : seuls les gestionnaires de l'aide voient cette vidéo." ?>
            </div>
        <?php endif; ?>

        <header class="lpc-vp-head">
            <?php if ($seriesLine !== ''): ?>
                <span class="lpc-vp-eyebrow"><?= htmlspecialchars($seriesLine) ?></span>
            <?php endif; ?>
            <h1><?= htmlspecialchars($video['title']) ?></h1>
            <?php if ($video['subtitle'] !== ''): ?>
                <p><?= htmlspecialchars($video['subtitle']) ?></p>
            <?php endif; ?>
            <div class="lpc-vp-meta">
                <span><?= htmlspecialchars($video['duration']) ?></span>
                <span><?= count($chapters) ?> <?= $en ? (count($chapters) > 1 ? 'chapters' : 'chapter') : (count($chapters) > 1 ? 'chapitres' : 'chapitre') ?></span>
                <?php if ($video['captions_url'] !== ''): ?>
                    <span><?= $en ? 'French captions' : 'Sous-titres en français' ?></span>
                <?php endif; ?>
            </div>
        </header>

        <div class="lpc-vp" id="vp" data-start="<?= htmlspecialchars((string) $start) ?>">

            <div class="lpc-vp-main min-w-0">
                <div class="lpc-vp-frame">
                    <video id="vp-video" controls preload="metadata" playsinline controlslist="nodownload"
                           <?php if ($video['poster_url'] !== ''): ?>poster="<?= htmlspecialchars($video['poster_url']) ?>"<?php endif; ?>>
                        <source src="<?= htmlspecialchars($video['video_url']) ?>" type="video/mp4">
                        <?php if ($video['captions_url'] !== ''): ?>
                            <track kind="captions" srclang="<?= htmlspecialchars($video['lang']) ?>"
                                   label="<?= $video['lang'] === 'fr' ? 'Français' : strtoupper(htmlspecialchars($video['lang'])) ?>"
                                   src="<?= htmlspecialchars($video['captions_url']) ?>" default>
                        <?php endif; ?>
                        <?= $en ? 'Your browser cannot play this video.' : 'Votre navigateur ne peut pas lire cette vidéo.' ?>
                    </video>
                </div>

                <div class="lpc-vp-tools">
                    <button type="button" class="lpc-vp-ghost" id="vp-copy">
                        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"/></svg>
                        <?= $en ? 'Copy the link at this moment' : 'Copier le lien à cet instant' ?>
                    </button>
                    <span class="lpc-vp-spacer"></span>
                    <a class="lpc-vp-ghost" href="/modules/help/index.php?lang=<?= $lang ?>#videos"><?= $en ? 'All videos' : 'Toutes les vidéos' ?></a>
                </div>

                <?php if ($chapters): ?>
                <section class="lpc-vp-box lpc-vp-chapters" aria-labelledby="vp-chapters-title">
                    <div class="lpc-vp-box-head">
                        <h2 class="lpc-vp-box-title" id="vp-chapters-title"><?= $en ? 'Chapters' : 'Chapitres' ?></h2>
                    </div>
                    <ol id="vp-chapters">
                        <?php foreach ($chapters as $c): ?>
                            <li>
                                <button type="button" class="lpc-vp-item" data-start="<?= (int) $c['start_ms'] ?>" data-end="<?= (int) $c['end_ms'] ?>">
                                    <span class="lpc-vp-chapter-n"><?= sprintf('%02d', $c['n']) ?></span>
                                    <span class="min-w-0 flex-1"><?= htmlspecialchars($c['title']) ?></span>
                                    <span class="lpc-vp-time"><?= htmlspecialchars($c['timecode']) ?></span>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
                <?php endif; ?>

                <?php if ($prev || $next): ?>
                <nav class="lpc-vp-parts" aria-label="<?= $en ? 'Other parts of this series' : 'Autres parties de cette série' ?>">
                    <?php if ($prev): ?>
                        <a class="lpc-vp-part" href="<?= htmlspecialchars($prev['url']) ?>&amp;lang=<?= $lang ?>">
                            <small>← <?= $en ? 'Previous part' : 'Partie précédente' ?></small>
                            <span><?= htmlspecialchars($prev['title']) ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if ($next): ?>
                        <a class="lpc-vp-part lpc-vp-part--next" href="<?= htmlspecialchars($next['url']) ?>&amp;lang=<?= $lang ?>">
                            <small><?= $en ? 'Next part' : 'Partie suivante' ?> →</small>
                            <span><?= htmlspecialchars($next['title']) ?></span>
                        </a>
                    <?php endif; ?>
                </nav>
                <?php endif; ?>
            </div>

            <aside class="lpc-vp-box lpc-vp-side" aria-labelledby="vp-transcript-title">
                <div class="lpc-vp-box-head">
                    <h2 class="lpc-vp-box-title" id="vp-transcript-title"><?= $en ? 'Transcript' : 'Transcription' ?></h2>
                    <input type="search" id="vp-filter" class="lpc-vp-filter" autocomplete="off"
                           placeholder="<?= $en ? 'Find in the transcript…' : 'Chercher dans la transcription…' ?>"
                           aria-label="<?= $en ? 'Find in the transcript' : 'Chercher dans la transcription' ?>">
                </div>
                <ol class="lpc-vp-transcript" id="vp-transcript">
                    <?php $lastChapter = -1; foreach ($cues as $c):
                        if ($c['chapter'] !== null && $c['chapter'] !== $lastChapter && isset($chapterTitles[$c['chapter']])):
                            $lastChapter = $c['chapter']; ?>
                            <li class="lpc-vp-chap-sep" aria-hidden="true"><?= sprintf('%02d', $c['chapter']) ?> · <?= htmlspecialchars($chapterTitles[$c['chapter']]) ?></li>
                        <?php endif; ?>
                        <li>
                            <button type="button" class="lpc-vp-item" data-start="<?= (int) $c['start_ms'] ?>" data-end="<?= (int) $c['end_ms'] ?>">
                                <span class="lpc-vp-time"><?= htmlspecialchars($c['timecode']) ?></span>
                                <span class="min-w-0 flex-1"><?= htmlspecialchars($c['text']) ?></span>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <p class="lpc-vp-follow" id="vp-follow" aria-live="polite"><?= htmlspecialchars($strings['following']) ?></p>
            </aside>
        </div>

        <script type="application/json" id="vp-data"><?= json_encode(
            ['slug' => $video['slug'], 'strings' => $strings],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        ) ?></script>

    <?php endif; ?>

    </main>
</div>

<?php if ($video): ?>
<script src="<?= lpc_asset('/assets/js/modules/help-video-player.js') ?>" defer></script>
<?php endif; ?>
</body>
</html>
