<?php
/**
 * modules/mail/compose_outbox.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, Programmés et non envoyés (PR 3; docs/MAIL.md §12).
 *
 * The person's own messages that wait for their time, that did not go (the
 * "Message non envoyé" bell links here), and their unfinished drafts, across
 * every mailbox they can send from. Each opens in the composer (mounted here
 * too); a scheduled one goes back to being a draft while it is changed. Drawn in
 * PHP; assets/js/modules/mail-compose-outbox.js raises mail:compose and reloads
 * the list when the composer closes or sends.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/mail/autoload.php';
Rbac::requireAuth();

use LPC\Mail\Clock;
use LPC\Mail\Compose\MessageBuilder;
use LPC\Mail\Compose\Outbox;
use LPC\Mail\MailAccess;
use LPC\Mail\Mount;
use LPC\Mail\Store;
use LPC\Mail\View;

$userId = (int) ($_SESSION['user_id'] ?? 0);
$e      = [View::class, 'e'];

try {
    $canUse = MailAccess::canUse($userId);
    $groups = $canUse ? Outbox::make()->listFor($userId) : ['scheduled' => [], 'failed' => [], 'drafts' => []];
    $boxes  = [];
    foreach (MailAccess::accounts($userId, 'send') as $a) {
        $boxes[$a['id']] = $a;
    }
} catch (Throwable $ex) {
    error_log('mail/compose_outbox: ' . $ex->getMessage());
    $canUse = false;
    $groups = ['scheduled' => [], 'failed' => [], 'drafts' => []];
    $boxes  = [];
}
$total = count($groups['scheduled']) + count($groups['failed']) + count($groups['drafts']);

/** One message row */
$item = function (array $row, string $group) use ($e, $boxes): void {
    $p = $row['payload'];
    $who = array_map(fn ($a) => $a['name'] ?: $a['email'], array_merge($p['to'] ?? [], $p['cc'] ?? [], $p['bcc'] ?? []));
    $to = $who ? 'À ' . implode(', ', array_slice($who, 0, 3)) . (count($who) > 3 ? ' et ' . (count($who) - 3) . ' autre(s)' : '') : 'Sans destinataire';
    $box = $boxes[$row['account_id']]['email'] ?? '';
    $when = match ($group) {
        'scheduled' => 'Part le ' . MessageBuilder::when(Clock::parse($row['send_at'])),
        'failed' => 'Non envoyé',
        default => 'Modifié ' . View::ago($row['updated_at']),
    };
    ?>
    <li class="ob-item">
        <div class="ob-main">
            <p class="ob-subject"><?= $e(trim((string) ($p['subject'] ?? '')) ?: '(sans objet)') ?></p>
            <p class="ob-meta"><?= $e($when) ?> · <?= $e($to) ?><?= $box !== '' ? ' · depuis ' . $e($box) : '' ?></p>
            <?php if ($group === 'failed' && $row['last_error']): ?>
                <p class="ob-error"><?= $e($row['last_error']) ?></p>
            <?php endif; ?>
        </div>
        <div class="ob-actions">
            <button type="button" class="mb-btn <?= $group === 'failed' ? 'mb-btn--primary' : 'mb-btn--ghost' ?> mb-btn--sm" data-ob-open="<?= $e($row['id']) ?>" data-account="<?= (int) $row['account_id'] ?>">
                <?= $group === 'scheduled' ? 'Modifier' : ($group === 'failed' ? 'Ouvrir et renvoyer' : 'Continuer') ?>
            </button>
            <button type="button" class="mb-btn mb-btn--danger-ghost mb-btn--sm" data-ob-discard="<?= $e($row['id']) ?>">
                <?= $group === 'scheduled' ? 'Annuler l’envoi' : 'Supprimer' ?>
            </button>
        </div>
    </li>
    <?php
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Programmés et non envoyés | Courrier | Bureau LPC</title>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-mail.css') ?>">
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-mail-compose.css') ?>">
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>
<?php
$pageTitle    = 'Programmés et non envoyés';
$pageSubtitle = 'Courrier';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>
<div id="lpc-shell-main">
    <div class="lpc-toolbar">
        <p class="lpc-toolbar-lead mb-toolbar-lead">Programmés et non envoyés</p>
        <a href="/modules/mail/index.php" class="mb-btn mb-btn--ghost">Ouvrir Courrier</a>
    </div>
    <main role="main" id="main" class="lpc-page mb-page">
    <?php if (!$canUse || !$boxes): ?>
        <section class="mb-card mb-empty">
            <p class="mb-empty-title">Aucune boîte mail depuis laquelle écrire</p>
            <p class="mb-muted">Connectez votre adresse @lpc.cm, ou demandez le niveau « Lecture et envoi » sur une boîte partagée.</p>
            <a class="mb-btn mb-btn--primary" href="/modules/mail/mailboxes.php">Ouvrir Boîtes mail</a>
        </section>
    <?php elseif ($total === 0): ?>
        <section class="mb-card mb-empty">
            <p class="mb-empty-title">Rien en attente</p>
            <p class="mb-muted">Les messages programmés, ceux qui ne sont pas partis et vos brouillons apparaissent ici.</p>
            <button type="button" class="mb-btn mb-btn--primary" data-ob-new>Nouveau message</button>
        </section>
    <?php else: ?>
        <?php foreach (['failed' => ['Non envoyés', 'Ouvrez-les pour corriger ce qui bloque, puis renvoyez.'],
                        'scheduled' => ['Programmés', 'Ils partent tout seuls au moment choisi.'],
                        'drafts' => ['Brouillons', 'Gardés dans bureau et dans les Brouillons du serveur.']] as $group => [$title, $hint]): ?>
            <?php if (!$groups[$group]) continue; ?>
            <section class="mb-card ob-group" aria-labelledby="ob-<?= $group ?>">
                <div>
                    <h2 class="mb-h2" id="ob-<?= $group ?>"><?= $e($title) ?> <span class="mb-pill mb-pill--<?= $group === 'failed' ? 'danger' : 'neutral' ?>"><?= count($groups[$group]) ?></span></h2>
                    <p class="mb-muted"><?= $e($hint) ?></p>
                </div>
                <ul class="ob-list">
                    <?php foreach ($groups[$group] as $row) $item($row, $group); ?>
                </ul>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
    </main>
</div>

<?php if ($canUse && $boxes) Mount::render('composer', ['user_id' => $userId, 'account_id' => array_key_first($boxes), 'folder' => 'drafts', 'thread_id' => null, 'shared' => false]); ?>
<script src="<?= lpc_asset('/assets/js/modules/mail-compose-outbox.js') ?>" defer></script>
</body>
</html>
