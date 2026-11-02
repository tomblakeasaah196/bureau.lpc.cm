<?php
/**
 * modules/mail/index.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, the Mail page. PLACEHOLDER from PR 1: PR 2
 * (reading) replaces this whole file with the real inbox (docs/MAIL.md §2, §6).
 *
 * What PR 1 puts here, so PRs 3-5 can build and try their parts before PR 2:
 *   · the URL contract: ?box=<account id>&folder=<role or folder id>&thread=<thread id>
 *     (links from the bell always use it; PR 2 must keep it);
 *   · every mount point of §6.2, in the place PR 2's page keeps them;
 *   · a plain list of the latest conversations of the chosen mailbox: clicking
 *     one raises the browser event mail:thread-opened (for PR 4 and PR 5),
 *     "Nouveau message" raises mail:compose (for PR 3). No reading here.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/mail/autoload.php';
Rbac::requireAuth();

use LPC\Mail\Db;
use LPC\Mail\MailAccess;
use LPC\Mail\Mount;
use LPC\Mail\Store;
use LPC\Mail\View;

$userId = (int) ($_SESSION['user_id'] ?? 0);
$e      = [View::class, 'e'];

try {
    $accounts = MailAccess::accounts($userId);
} catch (Throwable $ex) {
    error_log('mail/index: ' . $ex->getMessage());
    $accounts = [];
}
$boxId   = (int) ($_GET['box'] ?? 0);
$account = null;
foreach ($accounts as $a) {
    if ($a['id'] === $boxId || ($account === null && $boxId === 0)) {
        $account = $a;
    }
}
$threadId = (int) ($_GET['thread'] ?? 0) ?: null;
$threads  = [];
if ($account) {
    $threads = array_map([Store::class, 'threadRow'], Db::all(
        'SELECT * FROM mail_threads WHERE account_id = ? AND message_count > 0 ORDER BY last_message_at DESC LIMIT 20',
        [$account['id']],
    ));
}
$mount = [
    'user_id'    => $userId,
    'account_id' => $account['id'] ?? null,
    'folder'     => (string) ($_GET['folder'] ?? 'inbox'),
    'thread_id'  => $threadId,
    'shared'     => $account ? Store::isShared($account) : false,
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Courrier | Bureau LPC</title>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-mail.css') ?>">
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
    <div class="lpc-toolbar">
        <p class="lpc-toolbar-lead mb-toolbar-lead"><?= $account ? $e($account['display_name']) : 'Courrier' ?></p>
        <?php if ($account): ?>
            <?php Mount::render('ask_mail', $mount); ?>
            <button type="button" class="mb-btn mb-btn--primary" data-compose-new>Nouveau message</button>
        <?php endif; ?>
        <a href="/modules/mail/mailboxes.php" class="mb-btn mb-btn--ghost">Boîtes mail</a>
    </div>

    <main role="main" id="main" class="lpc-page mb-page" data-account="<?= (int) ($account['id'] ?? 0) ?>">
    <?php if (!$accounts): ?>
        <section class="mb-card mb-empty">
            <p class="mb-empty-title">Pas encore de boîte mail dans Courrier</p>
            <p class="mb-muted">Connectez votre adresse @lpc.cm pour lire et envoyer votre courrier ici. Les boîtes partagées apparaissent quand un gestionnaire vous y donne accès.</p>
            <a class="mb-btn mb-btn--primary" href="/modules/mail/mailboxes.php">Connecter ma boîte mail</a>
        </section>
    <?php else: ?>
        <section class="mb-card mb-placeholder">
            <h2 class="mb-h2">La lecture du courrier arrive bientôt</h2>
            <p class="mb-muted">Vos boîtes sont connectées et bureau en garde déjà une copie à jour. L’écran de lecture complet arrive avec la prochaine mise à jour de Courrier.</p>
            <?php if (count($accounts) > 1): ?>
                <nav aria-label="Boîtes mail" class="mb-switcher">
                    <?php foreach ($accounts as $a): ?>
                        <a class="mb-btn <?= $account && $a['id'] === $account['id'] ? 'mb-btn--primary' : 'mb-btn--ghost' ?>" href="?box=<?= (int) $a['id'] ?>"><?= $e($a['email']) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <div class="mb-dev">
                <h3 class="mb-h3">Dernières conversations</h3>
                <?php if ($threads): ?>
                    <ul class="mb-dev-list">
                        <?php foreach ($threads as $t): $from = $t['participants'][0] ?? null; ?>
                            <li><button type="button" class="mb-btn mb-btn--ghost" data-thread="<?= (int) $t['id'] ?>"<?= $t['id'] === $threadId ? ' aria-current="true"' : '' ?>>
                                <span class="mb-truncate"><?= $e($t['subject'] ?: '(sans objet)') ?> · <?= $e($from['name'] ?? $from['email'] ?? '') ?> · <?= $e(View::ago($t['last_message_at'])) ?></span>
                            </button></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="mb-muted">Rien pour l’instant : la première copie peut prendre quelques minutes après la connexion.</p>
                <?php endif; ?>
            </div>
        </section>

        <?php /* The reader area: PR 2 keeps these two mounts above the messages of the open conversation */ ?>
        <section class="mb-card" aria-label="Conversation">
            <?php Mount::render('thread_summary', $mount); ?>
            <?php if ($mount['shared']) Mount::render('thread_team', $mount); ?>
            <p class="mb-muted" data-reader-empty>Choisissez une conversation.</p>
        </section>
    <?php endif; ?>
    </main>
</div>

<?php if ($account) Mount::render('composer', $mount); ?>

<script type="application/json" id="mail-page-data"><?= json_encode([
    'accountId' => $account['id'] ?? null,
    'threadId'  => $threadId,
    'shared'    => $mount['shared'],
    'folder'    => $mount['folder'],
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/mail-inbox-placeholder.js') ?>" defer></script>
</body>
</html>
