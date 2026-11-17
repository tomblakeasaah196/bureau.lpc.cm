<?php
/**
 * scripts/tests/help_videos/library.test.php
 * -----------------------------------------------------------------------------
 * The training-video library's data (includes/classes/HelpVideoLibrary.php,
 * migration 126):
 *
 *   · a manifest is checked against the contract and stored in milliseconds,
 *     cues in time order, the pages of the cues added to the pages;
 *   · importing the same slug again replaces the video in place (same id, new
 *     revision folder, the old one deleted, chapters and cues replaced) and
 *     keeps its published state; delete removes rows and files;
 *   · the gate: audience "all" is every signed-in user, otherwise whoever can
 *     open one of its pages per the menu catalogue; a page outside the menu
 *     grants nothing; drafts are hidden; nobody signed out sees anything;
 *   · the transcript search is accent-insensitive, ranks the line with every
 *     word first and never returns a moment of a video the reader may not see;
 *   · the page "?" button offers the video at the first line spoken on that
 *     page, and no longer says « Aide (à venir) ».
 *
 * Run: HELPVID_TEST_DB_PORT=3306 php scripts/tests/help_videos/library.test.php
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/support/bootstrap.php';
$db = hv_test_db();
require_once $GLOBALS['__hv_root'] . '/includes/classes/HelpRetrieval.php';

// =============================================================================
section('the manifest contract');

$m = HelpVideoLibrary::normalize(hv_manifest());
check('times become integer milliseconds (431.6 s → 431600)', $m['duration_ms'] === 431600);
check('a cue keeps its exact millisecond (134.25 s → 134250)', $m['cues'][2]['start_ms'] === 134250);
check('cues are numbered in time order', array_column($m['cues'], 'seq') === [1, 2, 3, 4]);
check('audience "all" is kept', $m['audience'] === 'all');
check('audience omitted means "by pages"', HelpVideoLibrary::normalize(hv_manifest(['audience' => null]))['audience'] === 'pages');
$shuffled = hv_manifest(['cues' => array_reverse(hv_manifest()['cues'])]);
check('cues given out of order are sorted', HelpVideoLibrary::normalize($shuffled)['cues'][0]['text'] === 'Bienvenue sur LPC ERP.');
$extra = hv_manifest(['pages' => ['/index.php'], 'cues' => [['start' => 1, 'end' => 2, 'chapter' => 1, 'text' => 'x', 'page' => '/modules/crm/clients.php?tab=1']]]);
check("a cue's page joins the pages, without its query string",
    HelpVideoLibrary::normalize($extra)['pages'] === ['/index.php', '/modules/crm/clients.php']);
check('the page "/" is the login page /index.php', HelpVideoLibrary::normalizePage('/') === '/index.php');

$bad = [
    'schema 2'                   => ['schema' => 2],
    'slug with capitals'         => ['slug' => 'Intro 1'],
    'no title'                   => ['title' => ''],
    'negative duration'          => ['duration' => -3],
    'audience "staff"'           => ['audience' => 'staff'],
    'a page outside the app'     => ['pages' => ['https://evil.example/x.php']],
    'a path going up'            => ['pages' => ['/modules/../.env']],
    'file in a folder'           => ['file' => '../video.mp4'],
    'file not an mp4'            => ['file' => 'video.exe'],
    'a cue ending before it starts' => ['cues' => [['start' => 5, 'end' => 2, 'text' => 'x']]],
    'two chapters numbered 1'    => ['chapters' => [['n' => 1, 'title' => 'a', 'start' => 0, 'end' => 1], ['n' => 1, 'title' => 'b', 'start' => 1, 'end' => 2]]],
];
foreach ($bad as $what => $over) {
    $e = throws(fn() => HelpVideoLibrary::normalize(hv_manifest($over)));
    check("refused: $what", $e instanceof InvalidArgumentException && str_starts_with($e->getMessage(), 'Manifeste invalide'),
        $e ? $e->getMessage() : 'accepted');
}
check('not JSON is refused', throws(fn() => HelpVideoLibrary::parseManifest('{nope')) instanceof InvalidArgumentException);

// =============================================================================
section('import, then upsert by slug');

hv_as('admin');
$r1 = hv_import(hv_manifest(), ['user_id' => 1]);
check('a new video is added', $r1['replaced'] === false && $r1['cues'] === 4 && $r1['chapters'] === 3);
$v1 = HelpVideoLibrary::bySlug('intro-1-premiers-pas');
check('it is published by default', $v1 && $v1['is_published'] === true);
$dir1 = HelpVideoStore::absolute($r1['storage_dir']);
check('its files are in a revision folder of the private folder',
    is_file("$dir1/video.mp4") && is_file("$dir1/captions.fr.vtt") && is_file("$dir1/poster.png") && is_file("$dir1/manifest.json"));
check('the stored size and SHA-256 are the MP4\'s',
    $v1['file_size'] === filesize(hv_mp4()) && $db->query("SELECT file_sha256 FROM help_videos WHERE id = {$v1['id']}")->fetchColumn() === hash_file('sha256', hv_mp4()));
check('pages are stored', $v1['pages'] === ['/index.php', '/modules/dashboard/views/md_dashboard.php']);
check('a cue is stored in ms with its chapter and page',
    (bool) $db->query("SELECT 1 FROM help_video_cues WHERE video_id = {$v1['id']} AND start_ms = 134250 AND end_ms = 138000 AND chapter_n = 3 AND page_path = '/modules/dashboard/views/md_dashboard.php'")->fetchColumn());

HelpVideoLibrary::setPublished($v1['id'], false, 1);
usleep(1100000);   // a new revision folder name
$new = hv_manifest(['title' => 'Premiers pas (v2)', 'cues' => [
    ['start' => 2.0, 'end' => 3.0, 'chapter' => 1, 'text' => 'Nouvelle version.', 'page' => '/index.php'],
], 'chapters' => [['n' => 1, 'title' => 'Tout', 'start' => 0, 'end' => 431.6]]]);
$r2 = hv_import($new);
$v2 = HelpVideoLibrary::bySlug('intro-1-premiers-pas');
check('the same slug replaces the video', $r2['replaced'] === true && $v2['title'] === 'Premiers pas (v2)');
check('…in place: same id, so links and counters stay', $v2['id'] === $v1['id']);
check('…with the new transcript and chapters only', $v2['cues_count'] === 1 && $v2['chapters_count'] === 1);
check('…in a new revision folder, the old one deleted', $r2['storage_dir'] !== $r1['storage_dir'] && !is_dir($dir1)
    && is_file(HelpVideoStore::absolute($r2['storage_dir'] . '/video.mp4')));
check('…keeping its unpublished state', $v2['is_published'] === false);
$r3 = hv_import(hv_manifest(), ['published' => true]);
check('…unless the import says publish', HelpVideoLibrary::bySlug('intro-1-premiers-pas')['is_published'] === true);
check('one row for the slug', (int) $db->query("SELECT COUNT(*) FROM help_videos WHERE slug = 'intro-1-premiers-pas'")->fetchColumn() === 1);

$bogus = hv_tmp() . '/media/not-a-video.mp4';
file_put_contents($bogus, str_repeat('x', 5000));
$before = glob(hv_tmp() . '/videos/videos/intro-1-premiers-pas/*') ?: [];
$e = throws(fn() => HelpVideoLibrary::import(HelpVideoLibrary::normalize(hv_manifest()), $bogus));
check('a file that is not an MP4 is refused', $e instanceof InvalidArgumentException, $e ? $e->getMessage() : 'accepted');
check('…and the video in place is untouched', (glob(hv_tmp() . '/videos/videos/intro-1-premiers-pas/*') ?: []) === $before);

// =============================================================================
section('who sees what');

hv_import(hv_manifest(['slug' => 'paie-1-bulletins', 'title' => 'Les bulletins de paie', 'series' => 'Paie', 'part' => 1,
    'audience' => null, 'pages' => ['/modules/hr/payroll_finance.php'], 'poster' => '',
    'cues' => [['start' => 12.3, 'end' => 18, 'chapter' => 1, 'text' => 'Vérifiez le bulletin, puis appuyez sur Valider la paie.', 'page' => '/modules/hr/payroll_finance.php']],
    'chapters' => [['n' => 1, 'title' => 'Valider', 'start' => 0, 'end' => 30]]]));
hv_import(hv_manifest(['slug' => 'crm-1-clients', 'title' => 'La base clients', 'series' => 'Commerce', 'part' => 1,
    'audience' => null, 'pages' => ['/modules/crm/clients.php'],
    'cues' => [
        ['start' => 3.0, 'end' => 5.0, 'chapter' => 1, 'text' => 'Voici la base clients.', 'page' => null],
        ['start' => 6.4, 'end' => 11.0, 'chapter' => 1, 'text' => 'Tapez le nom du client dans la recherche.', 'page' => '/modules/crm/clients.php'],
        ['start' => 11.5, 'end' => 30.0, 'chapter' => 1, 'text' => 'Cliquez sur Nouveau devis pour accéder au formulaire.', 'page' => '/modules/crm/clients.php'],
    ],
    'chapters' => [['n' => 1, 'title' => 'Rechercher un client', 'start' => 0, 'end' => 30]]]));
hv_import(hv_manifest(['slug' => 'login-only', 'title' => 'Écran de connexion', 'series' => '', 'audience' => null,
    'pages' => ['/index.php'], 'cues' => [['start' => 1, 'end' => 2, 'text' => 'Accéder au Bureau depuis la connexion.', 'page' => '/index.php']],
    'chapters' => []]));

$slugs = fn() => array_column(HelpVideoLibrary::listVisible(), 'slug');

hv_as('sales');
check('sales: the "all" video and the clients video, not payroll',
    $slugs() === ['intro-1-premiers-pas', 'crm-1-clients'], implode(',', $slugs()));
hv_as('accountant');
check('accountant: "all" and payroll (hr.payroll.view), not clients',
    $slugs() === ['intro-1-premiers-pas', 'paie-1-bulletins'], implode(',', $slugs()));
hv_as('admin');
check('admin: everything except the video filming only the login page',
    !in_array('login-only', $slugs(), true) && count($slugs()) === 3, implode(',', $slugs()));
check('…a page outside the menu grants nothing (/index.php has no catalogue entry)',
    HelpVideoLibrary::audienceAllows('pages', ['/index.php']) === false);
check('…but help.manage may still watch it (preview)', HelpVideoLibrary::canWatch(HelpVideoLibrary::bySlug('login-only')));
check('the catalogue maps a page to its permission', lpc_nav_permissions_for_path('/modules/crm/clients.php') === ['crm.clients.view']);
check('…a page several menu entries share carries all their permissions',
    count(array_intersect(lpc_nav_permissions_for_path('/modules/settings/index.php'), ['admin.roles.view', 'admin.settings.view'])) === 2);
hv_as('sales');
check('sales cannot watch the payroll video, even by its slug', !HelpVideoLibrary::canWatch(HelpVideoLibrary::bySlug('paie-1-bulletins')));
HelpVideoLibrary::setPublished(HelpVideoLibrary::bySlug('crm-1-clients')['id'], false);
check('an unpublished video is hidden from readers', !in_array('crm-1-clients', $slugs(), true));
HelpVideoLibrary::setPublished(HelpVideoLibrary::bySlug('crm-1-clients')['id'], true);
hv_as('');
check('signed out: nothing, not even an "all" video', $slugs() === []);

// =============================================================================
section('transcript search');

hv_as('sales');
$s = HelpVideoLibrary::searchMoments('acceder bureau', 'fr');
check('« acceder bureau » (no accent) finds « Accéder au Bureau »', ($s[0]['text'] ?? '') === 'Appuyez sur Accéder au Bureau.',
    json_encode(array_column($s, 'text'), JSON_UNESCAPED_UNICODE));
check('…the line with both words first, at its second (134 s)', ($s[0]['t'] ?? null) === 134 && ($s[0]['timecode'] ?? '') === '02:14');
check('…labelled « Premiers pas · Chapitre 3 · 02:14 »', ($s[0]['label'] ?? '') === 'Premiers pas · Chapitre 3 · 02:14');
check('…linking to the player at that second', ($s[0]['url'] ?? '') === '/modules/help/video.php?slug=intro-1-premiers-pas&t=134');
check('…and a line with one of the words only is not padded in', count($s) === 1, json_encode(array_column($s, 'text'), JSON_UNESCAPED_UNICODE));
$s = HelpVideoLibrary::searchMoments('ACCÉDER', 'fr');
check('« ACCÉDER » in capitals with an accent finds the same lines', in_array('Appuyez sur Accéder au Bureau.', array_column($s, 'text'), true));
check('…and the clients video\'s line', in_array('crm-1-clients', array_column($s, 'slug'), true));
check('…never the login-only video (sales may not see it)', !in_array('login-only', array_column($s, 'slug'), true));
$s = HelpVideoLibrary::searchMoments('bulletin paie', 'fr');
check('sales finds nothing in the payroll video', $s === []);
hv_as('accountant');
$s = HelpVideoLibrary::searchMoments('bulletin paie', 'fr');
check('the accountant does', ($s[0]['slug'] ?? '') === 'paie-1-bulletins');
check('a one-letter query finds nothing', HelpVideoLibrary::searchMoments('a', 'fr') === []);
check('an English reader gets « Chapter »', str_contains(HelpVideoLibrary::searchMoments('acceder bureau', 'en')[0]['label'] ?? '', 'Chapter 3'));

// The LIKE path (no FULLTEXT index, or words under its minimum length) is accent-insensitive too.
$db->exec('ALTER TABLE help_video_cues DROP INDEX ft_help_video_cue');
hv_as('sales');
$s = HelpVideoLibrary::searchMoments('acceder bureau', 'fr');
check('without the FULLTEXT index the LIKE fallback still finds « Accéder au Bureau »', ($s[0]['t'] ?? null) === 134);
hv_sql_file($db, $GLOBALS['__hv_root'] . '/migrations/126_help_videos.sql');   // puts the index back
check('re-running 126 restores the index', (bool) $db->query("SHOW INDEX FROM help_video_cues WHERE Key_name = 'ft_help_video_cue'")->fetch());

$c = HelpVideoLibrary::chatMoments('Comment accéder au bureau ?', 'fr');
check('the chat question « Comment accéder au bureau ? » cites 02:14', ($c[0]['t'] ?? null) === 134 && !empty($c[0]['context']));
check('HelpRetrieval::videoMoments() is the same search', (HelpRetrieval::videoMoments('Comment accéder au bureau ?', 'fr')[0]['t'] ?? null) === 134);

// =============================================================================
section('the page "?" button');

hv_as('sales');
$p = HelpVideoLibrary::pageMoments('/modules/crm/clients.php', 'fr');
check('the clients page offers the clients video at the first line spoken on it (6.4 s)',
    ($p[0]['slug'] ?? '') === 'crm-1-clients' && ($p[0]['t'] ?? null) === 6 && ($p[0]['text'] ?? '') === 'Tapez le nom du client dans la recherche.');
check('on the dashboard page, sales are offered only the "all" video',
    array_column(HelpVideoLibrary::pageMoments('/modules/dashboard/views/md_dashboard.php', 'fr'), 'slug') === ['intro-1-premiers-pas']);
hv_as('accountant');
check('the clients page offers nothing to the accountant', HelpVideoLibrary::pageMoments('/modules/crm/clients.php', 'fr') === []);

hv_as('sales');
$html = lpc_help_link('crm.test_anchor', 'fr', ['page' => '/modules/crm/clients.php']);
check('no article but a video: the button opens the drawer with the video moments',
    str_contains($html, '<button') && str_contains($html, 'data-lpc-help=""') && str_contains($html, 'data-lpc-help-videos=')
    && str_contains($html, 'crm-1-clients&amp;t=6'), $html);
check('…its label is « Aide », like every page', str_contains($html, '>Aide</span>'));
hv_as('accountant');
$html = lpc_help_link('crm.test_anchor', 'fr', ['page' => '/modules/crm/clients.php']);
check('nothing for this reader: a plain « Aide » link to the Centre d\'Aide',
    str_contains($html, '<a href="/modules/help/index.php?lang=fr"') && str_contains($html, '>Aide</span>')
    && str_contains($html, 'data-lpc-help-empty="1"'), $html);
check('…never the old « Aide (à venir) » placeholder', !str_contains($html, 'venir') && !str_contains($html, 'lpc-help-btn-empty'));
check('…and never a video the reader may not see', !str_contains($html, 'data-lpc-help-videos'));
check('lpc_help_link() uses the running script\'s path by default', str_contains(hv_code('includes/functions/help.php'), "\$_SERVER['SCRIPT_NAME']"));

// =============================================================================
section('unpublish and delete');

hv_as('admin');
$v = HelpVideoLibrary::bySlug('crm-1-clients');
$dir = HelpVideoStore::absolute($v['storage_dir']);
check('delete removes the video', HelpVideoLibrary::delete($v['id']) && HelpVideoLibrary::bySlug('crm-1-clients') === null);
check('…its chapters, cues and pages (cascade)',
    (int) $db->query("SELECT (SELECT COUNT(*) FROM help_video_cues WHERE video_id = {$v['id']}) + (SELECT COUNT(*) FROM help_video_chapters WHERE video_id = {$v['id']}) + (SELECT COUNT(*) FROM help_video_pages WHERE video_id = {$v['id']})")->fetchColumn() === 0);
check('…and its files', !is_dir($dir));
check('deleting it again says so', HelpVideoLibrary::delete($v['id']) === false);

hv_test_done();
