<?php
/**
 * modules/mail/index.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, the Mail page (PR 2, reading; docs/MAIL.md §2, §6).
 *
 * ?box=<account id>&folder=<role or folder id>&thread=<thread id> (links from
 * the bell use it: MailNotices::inboxUrl), plus q= (search), show= (filter) and
 * page=. Three panes: mailboxes and folders with counts, the conversation list
 * (badges, filters, pages, search, older mail), the reader (the whole
 * conversation, safe HTML in a sandboxed frame, pictures held back, cid:
 * pictures, folded quotes, attachments, "Ouvert par"). On a phone, the list or
 * the reader. Every screen is drawn here first (no loading state); then
 * assets/js/modules/mail-read.js swaps the list and the reader with pieces from
 * api/v1/mail/read.php, raises and follows the browser events of §6.3, and runs
 * the keyboard shortcuts. Changing mailbox loads the page again, so the mounts
 * always have the right mailbox.
 *
 * Mount points of §6.2, where PR 1's placeholder had them: ask_mail in the
 * toolbar; thread_team (shared mailboxes) and thread_summary above the messages
 * (hidden in Indésirables); composer once, outside the list and the reader
 * (PR 3 puts compose_ai inside it).
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/mail/autoload.php';
Rbac::requireAuth();

use LPC\Mail\Imap\ImapException;
use LPC\Mail\MailAccess;
use LPC\Mail\MailError;
use LPC\Mail\Mount;
use LPC\Mail\Read\Format;
use LPC\Mail\Read\Inbox;
use LPC\Mail\Read\Screen;
use LPC\Mail\Read\ThreadList;
use LPC\Mail\Store;
use LPC\Mail\View;

$userId = (int) ($_SESSION['user_id'] ?? 0);
$e      = [View::class, 'e'];

$ready = true;
try {
    $inbox = Inbox::open($userId, $_GET);
} catch (Throwable $ex) {
    // migration 300 not applied yet on this database
    error_log('mail/index: ' . $ex->getMessage());
    $inbox = new Inbox();
    $inbox->userId = $userId;
    $ready = false;
}
$canUse  = $ready && MailAccess::canUse($userId);
$account = $canUse ? $inbox->account : null;
$folder  = $inbox->folder;
$q       = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 200);
$filter  = (string) ($_GET['show'] ?? 'all');
$page    = max(1, (int) ($_GET['page'] ?? 1));
$shared  = $inbox->shared();
$role    = $folder['role'] ?? null;

$list = null;
$reader = null;
$counts = null;
if ($account) {
    try {
        if ($inbox->thread) {
            try {
                $reader = Screen::thread($inbox, $inbox->thread, null, [], $q !== '');
            } catch (MailError $ex) {
                $inbox->notice = $ex->getMessage();
                $inbox->thread = null;
            }
        }
        $list = Screen::list($inbox, $q, $filter, $page, (int) ($inbox->thread['id'] ?? 0));
        $counts = $inbox->counts();
    } catch (ImapException $ex) {
        error_log('mail/index: server: ' . $ex->reason . ' ' . $ex->getMessage());
        $inbox->notice = 'Le serveur de messagerie n’a pas répondu. Réessayez dans une minute.';
    }
}
$filters   = $account ? ThreadList::filtersFor($account) : [];
$filter    = in_array($filter, $filters, true) ? $filter : 'all';
$boxUnread = $account ? $inbox->boxUnread() : [];
$level     = $account['access_level'] ?? null;
$canSend   = $inbox->can('send');
$folderKey = $folder ? $inbox->key($folder) : 'inbox';
$mount = [
    'user_id'    => $userId,
    'account_id' => $account['id'] ?? null,
    'folder'     => $folderKey,
    'thread_id'  => $reader ? (int) $inbox->thread['id'] : null,
    'shared'     => $shared,
];
$folderIcon = ['inbox' => 'inbox', 'drafts' => 'file-pen', 'sent' => 'paper-plane', 'archive' => 'box-archive', 'spam' => 'shield-halved', 'trash' => 'trash-can'];
$folderHref = fn (array $f) => '?box=' . (int) $account['id'] . '&folder=' . rawurlencode($inbox->key($f));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Courrier | Bureau LPC</title>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-mail.css') ?>">
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-mail-read.css') ?>">
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

<?php
$pageTitle    = 'Courrier';
$pageSubtitle = $account ? $account['email'] : 'Vos boîtes mail';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>

<div id="lpc-shell-main">
    <div class="lpc-toolbar mr-toolbar-card">
        <p class="lpc-toolbar-lead mb-toolbar-lead mr-lead">
            <?php if ($account): ?>
                <span class="mr-lead-name"><?= $e($account['display_name']) ?></span>
                <?php if ($level): ?><span class="mb-pill mb-pill--level"><?= $e(MailAccess::LEVEL_LABELS[$level] ?? '') ?></span><?php endif; ?>
            <?php else: ?>
                Courrier
            <?php endif; ?>
        </p>
        <?php if ($account): ?>
            <div class="mr-mount-inline" data-mount-wrap="ask_mail"><?php Mount::render('ask_mail', $mount); ?></div>
            <button type="button" class="mr-icon-btn mr-hide-phone" data-action="help" aria-label="Raccourcis clavier" title="Raccourcis clavier (?)"><?= Format::icon('keyboard') ?></button>
            <button type="button" class="mr-icon-btn" data-action="refresh" aria-label="Actualiser" title="Actualiser : relever le courrier maintenant"><?= Format::icon('rotate-right') ?></button>
            <a class="mr-icon-btn" href="/modules/mail/signature.php" aria-label="Ma signature" title="Ma signature"><?= Format::icon('signature') ?></a>
        <?php endif; ?>
        <a class="mr-icon-btn" href="/modules/mail/mailboxes.php<?= $account ? '?box=' . (int) $account['id'] : '' ?>" aria-label="Boîtes mail" title="Boîtes mail"><?= Format::icon('gear') ?></a>
        <?php if ($canSend): ?>
            <button type="button" class="mb-btn mb-btn--primary mr-new" data-compose="new" title="Nouveau message (c)"><?= Format::icon('pen-to-square') ?><span class="mr-new-label">Nouveau message</span></button>
        <?php endif; ?>
    </div>

    <main role="main" id="main" class="lpc-page mr-page">
    <?php if (!$ready): ?>
        <section class="mb-card mb-empty">
            <h2 class="mb-h2">Courrier n’est pas encore installé</h2>
            <p class="mb-muted">La base de données attend sa mise à jour (migration 300). Elle se fait d’elle-même au prochain déploiement.</p>
        </section>
    <?php elseif (!$canUse): ?>
        <section class="mb-card mb-empty">
            <h2 class="mb-h2">Courrier n’est pas ouvert à ce compte</h2>
            <p class="mb-muted">Seuls les comptes actifs du personnel utilisent Courrier. Si c’est une erreur, prévenez l’administrateur.</p>
        </section>
    <?php elseif (!$account): ?>
        <section class="mb-card mb-empty">
            <span class="mb-empty-icon" aria-hidden="true"><?= Format::icon('envelope') ?></span>
            <p class="mb-empty-title">Pas encore de boîte mail dans Courrier</p>
            <p class="mb-muted">Connectez votre adresse @lpc.cm pour lire et envoyer votre courrier ici. Les boîtes partagées apparaissent quand un gestionnaire vous y donne accès.</p>
            <a class="mb-btn mb-btn--primary" href="/modules/mail/mailboxes.php">Connecter ma boîte mail</a>
        </section>
    <?php else: ?>
        <?php if ($inbox->notice): ?>
            <p class="mr-notice" role="status"><?= Format::icon('circle-info') ?> <?= $e($inbox->notice) ?></p>
        <?php endif; ?>
        <div class="mr-shell">
            <div class="mr-panes<?= $reader ? ' has-reader' : '' ?>" data-panes>
                <nav class="mr-nav" aria-label="Boîtes mail et dossiers">
                    <?php if (count($inbox->accounts) > 1): ?>
                        <h2 class="mr-nav-title">Boîtes mail</h2>
                        <ul>
                            <?php foreach ($inbox->accounts as $a): $isBox = $a['id'] === $account['id']; $n = $boxUnread[$a['id']] ?? 0; ?>
                                <li>
                                    <a class="mr-nav-btn<?= $isBox ? ' is-active' : '' ?>" href="?box=<?= (int) $a['id'] ?>"<?= $isBox ? ' aria-current="true"' : '' ?>>
                                        <?= Format::icon(Store::isShared($a) ? 'users' : 'user') ?>
                                        <span class="mr-nav-name"><?= $e(Store::isShared($a) ? $a['display_name'] : 'Ma boîte') ?><span class="mr-nav-email"><?= $e($a['email']) ?></span></span>
                                        <span class="mr-nav-count" data-box-count="<?= (int) $a['id'] ?>"<?= $n > 0 ? '' : ' hidden' ?>><?= $n ?><span class="mr-sr"> non lus</span></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <h2 class="mr-nav-title">Dossiers</h2>
                    <ul data-folder-list>
                        <?php foreach ($inbox->folders as $f):
                            $isHere = $folder && $f['id'] === $folder['id'];
                            $n = $f['role'] === 'drafts' ? $f['total_count'] : $f['unread_count'];
                            ?>
                            <li style="--mr-depth: <?= $inbox->depth($f) ?>">
                                <a class="mr-nav-btn<?= $isHere ? ' is-active' : '' ?>" href="<?= $e($folderHref($f)) ?>" data-folder-key="<?= $e($inbox->key($f)) ?>" data-folder-id="<?= (int) $f['id'] ?>" data-role="<?= $e($f['role']) ?>" data-label="<?= $e(Store::folderLabel($f)) ?>"<?= $isHere ? ' aria-current="true"' : '' ?>>
                                    <?= Format::icon($folderIcon[$f['role']] ?? 'folder') ?>
                                    <span class="mr-nav-name"><?= $e(Store::folderLabel($f)) ?></span>
                                    <span class="mr-nav-count" data-folder-count="<?= (int) $f['id'] ?>"<?= $n > 0 ? '' : ' hidden' ?>><?= (int) $n ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        <?php if (!$inbox->folders): ?>
                            <li class="mr-nav-wait">bureau lit les dossiers de cette boîte…</li>
                        <?php endif; ?>
                    </ul>
                </nav>

                <section class="mr-list" aria-label="Conversations">
                    <div class="mr-list-head">
                        <div class="mr-pickers">
                            <?php if (count($inbox->accounts) > 1): ?>
                                <label class="mr-sr" for="mr-box-pick">Boîte mail</label>
                                <select id="mr-box-pick" class="mb-select" data-box-pick>
                                    <?php foreach ($inbox->accounts as $a): $n = $boxUnread[$a['id']] ?? 0; ?>
                                        <option value="<?= (int) $a['id'] ?>"<?= $a['id'] === $account['id'] ? ' selected' : '' ?>><?= $e($a['email'] . ($n > 0 ? " ({$n})" : '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                            <label class="mr-sr" for="mr-folder-pick">Dossier</label>
                            <select id="mr-folder-pick" class="mb-select" data-folder-pick>
                                <?php foreach ($inbox->folders as $f): $n = $f['role'] === 'drafts' ? $f['total_count'] : $f['unread_count']; ?>
                                    <option value="<?= $e($inbox->key($f)) ?>" data-folder-id="<?= (int) $f['id'] ?>" data-label="<?= $e(str_repeat("\u{00A0}\u{00A0}", $inbox->depth($f)) . Store::folderLabel($f)) ?>"<?= $folder && $f['id'] === $folder['id'] ? ' selected' : '' ?>><?= $e(str_repeat("\u{00A0}\u{00A0}", $inbox->depth($f)) . Store::folderLabel($f) . ($n > 0 ? " ({$n})" : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($account['status'] === 'needs_reconnect'): ?>
                            <p class="mr-alert mr-alert--danger" role="status">
                                <?= Format::icon('circle-exclamation') ?>
                                <span>Cette boîte ne se met plus à jour : le serveur a refusé son mot de passe.
                                <?php if ($inbox->can('manage')): ?>
                                    <a href="/modules/mail/mailboxes.php?box=<?= (int) $account['id'] ?>">Saisir le mot de passe</a>
                                <?php else: ?>
                                    Prévenez un gestionnaire de la boîte.
                                <?php endif; ?></span>
                            </p>
                        <?php endif; ?>

                        <form class="mr-search" role="search" data-search action="" method="get">
                            <input type="hidden" name="box" value="<?= (int) $account['id'] ?>">
                            <input type="hidden" name="folder" value="<?= $e($folderKey) ?>" data-search-folder>
                            <label class="mr-sr" for="mr-search">Chercher dans le courrier</label>
                            <span class="mr-search-icon"><?= Format::icon('magnifying-glass') ?></span>
                            <input id="mr-search" name="q" type="search" value="<?= $e($q) ?>" placeholder="Chercher dans cette boîte" autocomplete="off" spellcheck="false" enterkeyhint="search">
                            <button type="button" class="mr-icon-btn mr-icon-btn--sm mr-search-clear" data-action="clear-search" aria-label="Effacer la recherche"<?= $q === '' ? ' hidden' : '' ?>><?= Format::icon('xmark') ?></button>
                        </form>

                        <?php if (count($filters) > 1): ?>
                            <div class="mr-filters" role="group" aria-label="Afficher">
                                <?php foreach ($filters as $key): ?>
                                    <button type="button" class="mr-filter<?= $filter === $key ? ' is-active' : '' ?>" data-filter="<?= $e($key) ?>" aria-pressed="<?= $filter === $key ? 'true' : 'false' ?>"><?= $e(ThreadList::FILTER_LABELS[$key]) ?></button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mr-list-body" data-list aria-busy="false"><?= $list['html'] ?? '' ?></div>
                </section>

                <section class="mr-reader" data-reader aria-label="Lecture">
                    <div data-reader-head><?= $reader['head'] ?? '' ?></div>
                    <div class="mr-mounts" data-reader-mounts<?= $reader ? '' : ' hidden' ?>>
                        <?php if ($shared): ?>
                            <div data-mount-wrap="thread_team"><?php Mount::render('thread_team', $mount); ?></div>
                        <?php endif; ?>
                        <div data-mount-wrap="thread_summary"<?= $role === 'spam' ? ' hidden' : '' ?>><?php Mount::render('thread_summary', $mount); ?></div>
                    </div>
                    <div class="mr-messages" data-reader-messages><?= $reader['messages'] ?? '' ?></div>
                    <div class="mr-reader-empty" data-reader-empty<?= $reader ? ' hidden' : '' ?>>
                        <div>
                            <span class="mr-reader-empty-icon" aria-hidden="true"><?= Format::icon('envelope-open') ?></span>
                            <p>Choisissez une conversation pour la lire.</p>
                            <p class="mb-muted mr-hide-phone">Appuyez sur <kbd class="mr-kbd">?</kbd> pour les raccourcis clavier.</p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    <?php endif; ?>
    </main>
</div>

<?php if ($account): ?>
    <div data-mount-wrap="composer"><?php Mount::render('composer', $mount); ?></div>

    <div class="mr-snackbar" data-snackbar hidden>
        <span class="mr-snackbar-text" data-snackbar-text></span>
        <button type="button" class="mr-snackbar-btn" data-snackbar-undo hidden>Annuler</button>
        <button type="button" class="mr-icon-btn mr-icon-btn--sm" data-snackbar-close aria-label="Fermer le message"><?= Format::icon('xmark') ?></button>
    </div>

    <dialog class="mb-dialog" id="mr-keys" aria-labelledby="mr-keys-h">
        <div class="mb-form">
            <h2 class="mb-dialog-title" id="mr-keys-h">Raccourcis clavier</h2>
            <p class="mb-muted">Ils marchent quand vous n’écrivez pas dans un champ.</p>
            <dl class="mr-keys">
                <dt><kbd class="mr-kbd">j</kbd> <kbd class="mr-kbd">k</kbd></dt><dd>Conversation suivante, précédente</dd>
                <dt><kbd class="mr-kbd">u</kbd> <kbd class="mr-kbd">Échap</kbd></dt><dd>Retour à la liste</dd>
                <dt><kbd class="mr-kbd">/</kbd></dt><dd>Chercher</dd>
                <dt><kbd class="mr-kbd">c</kbd></dt><dd>Nouveau message</dd>
                <dt><kbd class="mr-kbd">r</kbd> <kbd class="mr-kbd">a</kbd> <kbd class="mr-kbd">f</kbd></dt><dd>Répondre, répondre à tous, transférer</dd>
                <dt><kbd class="mr-kbd">e</kbd></dt><dd>Archiver</dd>
                <dt><kbd class="mr-kbd">#</kbd></dt><dd>Placer dans la Corbeille (dans la Corbeille : supprimer définitivement)</dd>
                <dt><kbd class="mr-kbd">!</kbd></dt><dd>Placer dans Indésirables</dd>
                <dt><kbd class="mr-kbd">s</kbd></dt><dd>Marquer pour suivi, ou retirer le suivi</dd>
                <dt><kbd class="mr-kbd">Maj</kbd> <kbd class="mr-kbd">u</kbd></dt><dd>Marquer comme non lue</dd>
                <dt><kbd class="mr-kbd">?</kbd></dt><dd>Afficher ces raccourcis</dd>
            </dl>
            <div class="mb-dialog-actions"><button type="button" class="mb-btn mb-btn--ghost" data-close>Fermer</button></div>
        </div>
    </dialog>

    <dialog class="mb-dialog" id="mr-confirm" aria-labelledby="mr-confirm-h">
        <form method="dialog" class="mb-form">
            <h2 class="mb-dialog-title" id="mr-confirm-h" data-confirm-title></h2>
            <p class="mb-muted" data-confirm-text></p>
            <div class="mb-dialog-actions">
                <button type="button" class="mb-btn mb-btn--ghost" data-close data-confirm-keep></button>
                <button type="submit" class="mb-btn mb-btn--danger" data-confirm-go></button>
            </div>
        </form>
    </dialog>

    <script type="application/json" id="mail-page-data"><?= json_encode([
        'api'       => '/api/v1/mail/read.php',
        'nav'       => '/api/v1/mail/nav.php',
        'accountId' => (int) $account['id'],
        'shared'    => $shared,
        'canSend'   => $canSend,
        'organise'  => $inbox->can('organise'),
        'folder'    => $folder ? ['id' => $folder['id'], 'key' => $folderKey, 'role' => $folder['role'], 'label' => Store::folderLabel($folder)] : null,
        'q'         => $q,
        'filter'    => $filter,
        'page'      => $list['page'] ?? 1,
        'thread'    => $reader['thread'] ?? null,
        'stamp'     => $counts['stamp'] ?? null,
        'poll'      => 60,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
    <script src="<?= lpc_asset('/assets/js/modules/mail-read.js') ?>" defer></script>
<?php endif; ?>
</body>
</html>
