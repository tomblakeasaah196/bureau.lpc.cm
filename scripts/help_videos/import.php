<?php
/**
 * scripts/help_videos/import.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — register training videos from their manifests, without the
 * browser (docs/HELP_VIDEOS.md).
 *
 *   php scripts/help_videos/import.php <video>.manifest.json [more.manifest.json …]
 *       [--draft]     import unpublished (readers do not see it yet)
 *       [--publish]   publish, even a video that was unpublished
 *       [--dry]       check the manifests and files, change nothing
 *
 * The MP4, the captions (.vtt) and the poster named by each manifest are read
 * from the manifest's own folder (where scripts/video/ writes them), COPIED
 * into the private video folder (HELP_VIDEO_PATH) and registered in the
 * database of this checkout's .env. A manifest whose slug is already in the
 * library replaces that video in place: same link, new files, new transcript.
 * Without --draft/--publish a replaced video keeps its published state and a
 * new one is published.
 *
 * Exit codes: 0 all imported · 1 one or more failed · 2 usage.
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/HelpVideoLibrary.php';

$args = array_slice($argv, 1);
$flags = array_filter($args, static fn($a) => str_starts_with($a, '--'));
$paths = array_values(array_filter($args, static fn($a) => !str_starts_with($a, '--')));
$unknown = array_diff($flags, ['--draft', '--publish', '--dry', '--help']);

if (!$paths || in_array('--help', $flags, true) || $unknown || (in_array('--draft', $flags, true) && in_array('--publish', $flags, true))) {
    fwrite(STDERR, "Usage: php scripts/help_videos/import.php <video>.manifest.json [...] [--draft|--publish] [--dry]\n");
    exit(2);
}
$dry = in_array('--dry', $flags, true);
$published = in_array('--draft', $flags, true) ? false : (in_array('--publish', $flags, true) ? true : null);

if (!HelpVideoLibrary::ready()) {
    fwrite(STDERR, "La bibliothèque vidéo n'est pas installée dans " . DB_NAME . " : lancez php scripts/migrate.php (migration 126).\n");
    exit(1);
}

$failed = 0;
foreach ($paths as $manifestPath) {
    try {
        if (!is_file($manifestPath)) {
            throw new InvalidArgumentException("Manifeste introuvable : $manifestPath");
        }
        $json = (string) file_get_contents($manifestPath);
        $m = HelpVideoLibrary::parseManifest($json);
        $dir = dirname(realpath($manifestPath) ?: $manifestPath);

        $need = static function (string $name, string $what) use ($dir): ?string {
            if ($name === '') {
                return null;
            }
            $p = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($p)) {
                throw new InvalidArgumentException("$what introuvable à côté du manifeste : $p");
            }
            return $p;
        };
        $video    = $need($m['file'], 'Vidéo');
        $captions = $need($m['captions'], 'Sous-titres');
        $poster   = $need($m['poster'], 'Affiche');

        if ($dry) {
            if (!HelpVideoStore::looksLikeMp4($video)) {
                throw new InvalidArgumentException("{$m['file']} n'est pas un MP4.");
            }
            printf("OK (dry)  %s — %s : %d chapitre(s), %d réplique(s), %d page(s), %s\n",
                $m['slug'], $m['title'], count($m['chapters']), count($m['cues']), count($m['pages']),
                $m['audience'] === 'all' ? 'tout le monde' : 'selon les pages');
            continue;
        }

        $res = HelpVideoLibrary::import($m, $video, $captions, $poster, [
            'move' => false, 'published' => $published, 'manifest_json' => $json,
        ]);
        $v = HelpVideoLibrary::bySlug($res['slug']);
        printf("%s  %s — %s : %d chapitre(s), %d réplique(s), %d page(s)%s\n",
            $res['replaced'] ? 'REMPLACÉE' : 'AJOUTÉE ', $res['slug'], $m['title'],
            $res['chapters'], $res['cues'], $res['pages'], ($v && !$v['is_published']) ? ' [brouillon]' : '');
        if ($m['audience'] !== 'all' && $v && !array_filter(HelpVideoLibrary::pageGates($v['pages']))) {
            printf("  ATTENTION : aucune page de cette vidéo n'est dans le menu (includes/config/nav.php) et\n"
                 . "  audience n'est pas \"all\" : seuls les gestionnaires de l'aide pourront la voir.\n");
        }
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "ÉCHEC  $manifestPath : " . $e->getMessage() . "\n");
    }
}
exit($failed ? 1 : 0);
