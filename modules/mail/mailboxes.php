<?php
/**
 * modules/mail/mailboxes.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, Boîtes mail (PR 1; docs/MAIL.md §6.1).
 *
 * Connect your own mailbox, and (admins) add shared ones; for each shared
 * mailbox, its people and their level (Lecture, Lecture et envoi,
 * Gestionnaire), its password, its name, removing it. Every staff login can
 * open this page (owner decision); each action checks the mailbox's level in
 * api/v1/mail/mailboxes.php. ?box=<id> brings that mailbox forward (links from
 * the bell), and opens its password form when it is waiting for one;
 * ?connect=1 (Courrier's empty page) opens the connect form when the person
 * has no mailbox of their own yet.
 *
 * The list is drawn here in PHP (no loading state); the forms are native
 * <dialog>s driven by assets/js/modules/mail-mailboxes.js, which reloads the
 * page after a change. Passwords go straight from the form to the API and are
 * never put back into the page.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/mail/autoload.php';
Rbac::requireAuth();

use LPC\Mail\Autodiscover;
use LPC\Mail\MailAccess;
use LPC\Mail\People;
use LPC\Mail\Store;
use LPC\Mail\View;

$userId = (int) ($_SESSION['user_id'] ?? 0);
$lang   = 'fr';   // Courrier is French only (owner decision)
$e      = [View::class, 'e'];

$ready = true;
try {
    $accounts = MailAccess::accounts($userId);
} catch (Throwable $ex) {
    // migration 300 not applied yet on this database
    error_log('mail/mailboxes: ' . $ex->getMessage());
    $accounts = [];
    $ready = false;
}
$canUse    = $ready && MailAccess::canUse($userId);
$canAdd    = $canUse && MailAccess::canAddShared($userId);
$me        = $ready ? People::find($userId) : null;
$personal  = null;
$shared    = [];
foreach ($accounts as $a) {
    if (Store::isShared($a)) {
        $shared[] = View::mailboxRow($a, $userId);
    } elseif ($personal === null) {
        $personal = View::mailboxRow($a, $userId);
    }
}
$focusBox  = (int) ($_GET['box'] ?? 0);
$myEmail   = (string) ($me['email'] ?? '');
// The bureau login can be any address (a Gmail one, say): the connect form starts on mail.lpc.cm, and the
// script swaps the server settings when the person types an address on another domain
$defaults  = Autodiscover::for('prenom.nom@lpc.cm');
$openConnect = $canUse && $personal === null && isset($_GET['connect']);

/** One mailbox row */
$row = function (array $b, bool $isShared) use ($e, $focusBox): void {
    $tone = $b['status_tone'];
    ?>
    <article class="mb-row<?= $b['id'] === $focusBox ? ' mb-row--focus' : '' ?>" id="box-<?= (int) $b['id'] ?>" data-box="<?= (int) $b['id'] ?>">
        <div class="mb-row-main">
            <span class="mb-avatar<?= $isShared ? ' mb-avatar--shared' : '' ?>" aria-hidden="true"><?= $e($b['initials']) ?></span>
            <div class="mb-row-text">
                <p class="mb-row-title">
                    <span class="mb-truncate"><?= $e($b['display_name']) ?></span>
                    <?php if ($isShared && $b['level_label'] !== ''): ?>
                        <span class="mb-pill mb-pill--level"><?= $e($b['level_label']) ?></span>
                    <?php endif; ?>
                </p>
                <p class="mb-row-sub mb-truncate"><?= $e($b['email']) ?></p>
            </div>
        </div>
        <div class="mb-row-meta">
            <span class="mb-pill mb-pill--<?= $e($tone) ?>"><?= $e($b['status_label']) ?></span>
            <?php if ($b['unread'] > 0): ?>
                <span class="mb-meta-item"><?= (int) $b['unread'] ?> non lu<?= $b['unread'] > 1 ? 's' : '' ?></span>
            <?php endif; ?>
            <?php if ($isShared): ?>
                <span class="mb-meta-item"><?= (int) $b['members'] ?> personne<?= $b['members'] > 1 ? 's' : '' ?></span>
            <?php endif; ?>
        </div>
        <?php if ($b['last_error'] && $b['status'] !== 'active'): ?>
            <p class="mb-row-alert" role="status">Le serveur a refusé le mot de passe. Saisissez le mot de passe actuel de la boîte pour qu’elle se remette à jour.</p>
        <?php endif; ?>
        <div class="mb-row-actions">
            <a class="mb-btn mb-btn--ghost" href="/modules/mail/index.php?box=<?= (int) $b['id'] ?>">Ouvrir</a>
            <?php if ($isShared): ?>
                <button type="button" class="mb-btn mb-btn--ghost" data-action="people"><?= $b['can_manage'] ? 'Gérer les personnes' : 'Voir les personnes' ?></button>
            <?php endif; ?>
            <?php if ($b['can_manage']): ?>
                <button type="button" class="mb-btn <?= $b['status'] === 'needs_reconnect' ? 'mb-btn--primary' : 'mb-btn--ghost' ?>" data-action="password">Changer le mot de passe</button>
                <button type="button" class="mb-btn mb-btn--ghost" data-action="rename">Renommer</button>
            <?php endif; ?>
            <?php if ($b['status'] === 'active'): ?>
                <button type="button" class="mb-btn mb-btn--ghost" data-action="sync">Actualiser</button>
            <?php endif; ?>
            <?php if ($b['can_manage']): ?>
                <button type="button" class="mb-btn mb-btn--danger-ghost" data-action="remove">Retirer</button>
            <?php endif; ?>
        </div>
    </article>
    <?php
};

/** The server settings block, prefilled */
$serverFields = function (string $prefix, array $s) use ($e): void {
    // No "none": a connection without TLS is refused on live (LPC\Mail\HostPolicy); only local tests switch it on
    $enc = ['ssl' => 'SSL/TLS', 'starttls' => 'STARTTLS'] + (\LPC\Mail\Config::get('allow_plain', false) ? ['none' => 'Aucune'] : []);
    ?>
    <details class="mb-details">
        <summary>Paramètres du serveur</summary>
        <p class="mb-hint">Pour une adresse @lpc.cm, rien à changer : mail.lpc.cm, IMAP 993 et SMTP 465 en SSL/TLS, identifiant = l’adresse complète.</p>
        <div class="mb-grid">
            <div class="mb-field">
                <label for="<?= $prefix ?>-imap-host">Serveur entrant (IMAP)</label>
                <input id="<?= $prefix ?>-imap-host" name="imap_host" type="text" autocomplete="off" spellcheck="false" value="<?= $e($s['imap_host']) ?>">
                <p class="mb-field-error" data-error-for="imap_host" hidden></p>
            </div>
            <div class="mb-field mb-field--narrow">
                <label for="<?= $prefix ?>-imap-port">Port</label>
                <input id="<?= $prefix ?>-imap-port" name="imap_port" type="number" inputmode="numeric" value="<?= (int) $s['imap_port'] ?>">
            </div>
            <div class="mb-field mb-field--narrow">
                <label for="<?= $prefix ?>-imap-enc">Sécurité</label>
                <select id="<?= $prefix ?>-imap-enc" name="imap_encryption">
                    <?php foreach ($enc as $k => $label): ?><option value="<?= $k ?>"<?= $s['imap_encryption'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="mb-field">
                <label for="<?= $prefix ?>-smtp-host">Serveur sortant (SMTP)</label>
                <input id="<?= $prefix ?>-smtp-host" name="smtp_host" type="text" autocomplete="off" spellcheck="false" value="<?= $e($s['smtp_host']) ?>">
                <p class="mb-field-error" data-error-for="smtp_host" hidden></p>
            </div>
            <div class="mb-field mb-field--narrow">
                <label for="<?= $prefix ?>-smtp-port">Port</label>
                <input id="<?= $prefix ?>-smtp-port" name="smtp_port" type="number" inputmode="numeric" value="<?= (int) $s['smtp_port'] ?>">
            </div>
            <div class="mb-field mb-field--narrow">
                <label for="<?= $prefix ?>-smtp-enc">Sécurité</label>
                <select id="<?= $prefix ?>-smtp-enc" name="smtp_encryption">
                    <?php foreach ($enc as $k => $label): ?><option value="<?= $k ?>"<?= $s['smtp_encryption'] === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
    </details>
    <?php
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Boîtes mail | Courrier | Bureau LPC</title>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-mail.css') ?>">
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

<?php
$pageTitle    = 'Boîtes mail';
$pageSubtitle = 'Courrier';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>

<div id="lpc-shell-main">
    <div class="lpc-toolbar">
        <p class="lpc-toolbar-lead mb-toolbar-lead">Vos boîtes mail dans bureau</p>
        <a href="/modules/mail/index.php" class="mb-btn mb-btn--ghost">Ouvrir Courrier</a>
    </div>

    <main role="main" id="main" class="lpc-page mb-page">
    <?php if (!$ready): ?>
        <section class="mb-card mb-empty">
            <h2 class="mb-h2">Courrier n’est pas encore installé</h2>
            <p class="mb-muted">La base de données attend sa mise à jour (migration 300). Elle se fait d’elle-même au prochain déploiement.</p>
        </section>
    <?php else: ?>

        <section class="mb-card" aria-labelledby="mb-personal-h">
            <header class="mb-card-head">
                <div>
                    <h2 class="mb-h2" id="mb-personal-h">Ma boîte mail</h2>
                    <p class="mb-muted">Votre adresse personnelle. Vous seul la voyez, même les administrateurs n’y ont pas accès.</p>
                </div>
            </header>
            <?php if ($personal): ?>
                <div class="mb-list"><?php $row($personal, false); ?></div>
            <?php else: ?>
                <div class="mb-empty">
                    <span class="mb-empty-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                    </span>
                    <p class="mb-empty-title">Lisez et envoyez votre courrier sans quitter bureau</p>
                    <p class="mb-muted">Connectez votre adresse, par exemple <?= $e(str_ends_with($myEmail, '@lpc.cm') ? $myEmail : 'prenom.nom@lpc.cm') ?>, avec le mot de passe de la boîte mail.</p>
                    <button type="button" class="mb-btn mb-btn--primary" data-open="dlg-connect">Connecter ma boîte mail</button>
                </div>
            <?php endif; ?>
        </section>

        <section class="mb-card" aria-labelledby="mb-shared-h">
            <header class="mb-card-head">
                <div>
                    <h2 class="mb-h2" id="mb-shared-h">Boîtes partagées</h2>
                    <p class="mb-muted">Les adresses d’équipe, comme contact@lpc.cm. Chacun y a son niveau : Lecture, Lecture et envoi ou Gestionnaire.</p>
                </div>
                <?php if ($canAdd): ?>
                    <button type="button" class="mb-btn mb-btn--primary mb-head-btn" data-open="dlg-shared">Ajouter une boîte partagée</button>
                <?php endif; ?>
            </header>
            <?php if ($shared): ?>
                <div class="mb-list"><?php foreach ($shared as $b) { $row($b, true); } ?></div>
            <?php else: ?>
                <div class="mb-empty mb-empty--quiet">
                    <p class="mb-muted"><?= $canAdd
                        ? 'Aucune boîte partagée pour l’instant. Ajoutez-en une, puis donnez l’accès aux personnes qui en ont besoin.'
                        : 'Aucune boîte partagée pour vous pour l’instant. Un administrateur ou le gestionnaire d’une boîte peut vous y donner accès.' ?></p>
                </div>
            <?php endif; ?>
        </section>

        <section class="mb-card mb-card--note" aria-labelledby="mb-levels-h">
            <h2 class="mb-h3" id="mb-levels-h">Les niveaux d’accès</h2>
            <dl class="mb-levels">
                <div><dt>Lecture</dt><dd>voit tout le courrier de la boîte.</dd></div>
                <div><dt>Lecture et envoi</dt><dd>répond, écrit, classe et supprime aussi.</dd></div>
                <div><dt>Gestionnaire</dt><dd>gère aussi les personnes, le mot de passe et le nom de la boîte.</dd></div>
            </dl>
            <p class="mb-muted mb-small">Les mots de passe des boîtes sont chiffrés dans bureau et ne sont jamais réaffichés. Le courrier reste sur le serveur mail.lpc.cm ; bureau garde une copie des 12 derniers mois pour aller vite.</p>
        </section>
    <?php endif; ?>
    </main>
</div>

<?php if ($canUse): ?>
<!-- ===================================================================== dialogs -->
<dialog class="mb-dialog" id="dlg-connect" aria-labelledby="dlg-connect-h">
    <form method="dialog" class="mb-form" data-api="connect_personal" novalidate>
        <h2 class="mb-dialog-title" id="dlg-connect-h">Connecter ma boîte mail</h2>
        <div class="mb-field">
            <label for="cn-email">Adresse</label>
            <input id="cn-email" name="email" type="email" autocomplete="email" required value="<?= $e(str_ends_with($myEmail, '@lpc.cm') ? $myEmail : '') ?>" placeholder="prenom.nom@lpc.cm">
            <p class="mb-field-error" data-error-for="email" hidden></p>
        </div>
        <div class="mb-field">
            <label for="cn-password">Mot de passe de la boîte mail</label>
            <?php /* not "current-password": the browser would fill in the saved bureau sign-in, not the mailbox's */ ?>
            <input id="cn-password" name="password" type="password" autocomplete="new-password" required>
            <p class="mb-hint">Celui de l’adresse dans cPanel ou webmail, pas votre mot de passe bureau.</p>
            <p class="mb-field-error" data-error-for="password" hidden></p>
        </div>
        <div class="mb-field">
            <label for="cn-name">Nom affiché aux destinataires</label>
            <input id="cn-name" name="display_name" type="text" maxlength="120" value="<?= $e($me['name'] ?? '') ?>">
        </div>
        <?php $serverFields('cn', $defaults); ?>
        <p class="mb-form-error" role="alert" hidden></p>
        <div class="mb-dialog-actions">
            <button type="button" class="mb-btn mb-btn--ghost" data-close>Annuler</button>
            <button type="submit" class="mb-btn mb-btn--primary">Connecter</button>
        </div>
    </form>
</dialog>

<?php if ($canAdd): ?>
<dialog class="mb-dialog" id="dlg-shared" aria-labelledby="dlg-shared-h">
    <form method="dialog" class="mb-form" data-api="add_shared" novalidate>
        <h2 class="mb-dialog-title" id="dlg-shared-h">Ajouter une boîte partagée</h2>
        <p class="mb-muted">Vous en devenez le premier gestionnaire. Ensuite, donnez l’accès aux personnes qui en ont besoin.</p>
        <div class="mb-field">
            <label for="sh-email">Adresse</label>
            <input id="sh-email" name="email" type="email" autocomplete="off" required placeholder="contact@lpc.cm">
            <p class="mb-field-error" data-error-for="email" hidden></p>
        </div>
        <div class="mb-field">
            <label for="sh-name">Nom affiché aux destinataires</label>
            <input id="sh-name" name="display_name" type="text" maxlength="120" required placeholder="LPC Service client">
            <p class="mb-field-error" data-error-for="display_name" hidden></p>
        </div>
        <div class="mb-field">
            <label for="sh-password">Mot de passe de la boîte mail</label>
            <input id="sh-password" name="password" type="password" autocomplete="new-password" required>
            <p class="mb-field-error" data-error-for="password" hidden></p>
        </div>
        <?php $serverFields('sh', Autodiscover::for('contact@lpc.cm')); ?>
        <p class="mb-form-error" role="alert" hidden></p>
        <div class="mb-dialog-actions">
            <button type="button" class="mb-btn mb-btn--ghost" data-close>Annuler</button>
            <button type="submit" class="mb-btn mb-btn--primary">Ajouter la boîte</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<dialog class="mb-dialog" id="dlg-password" aria-labelledby="dlg-password-h">
    <form method="dialog" class="mb-form" data-api="update_password" novalidate>
        <h2 class="mb-dialog-title" id="dlg-password-h">Mettre à jour le mot de passe</h2>
        <p class="mb-muted" data-fill="email"></p>
        <div class="mb-field">
            <label for="pw-password">Nouveau mot de passe de la boîte</label>
            <input id="pw-password" name="password" type="password" autocomplete="new-password" required>
            <p class="mb-hint">Bureau le vérifie auprès du serveur avant de l’enregistrer.</p>
            <p class="mb-field-error" data-error-for="password" hidden></p>
        </div>
        <?php $serverFields('pw', $defaults); ?>
        <p class="mb-form-error" role="alert" hidden></p>
        <div class="mb-dialog-actions">
            <button type="button" class="mb-btn mb-btn--ghost" data-close>Annuler</button>
            <button type="submit" class="mb-btn mb-btn--primary">Enregistrer le mot de passe</button>
        </div>
    </form>
</dialog>

<dialog class="mb-dialog" id="dlg-rename" aria-labelledby="dlg-rename-h">
    <form method="dialog" class="mb-form" data-api="rename" novalidate>
        <h2 class="mb-dialog-title" id="dlg-rename-h">Renommer la boîte</h2>
        <p class="mb-muted" data-fill="email"></p>
        <div class="mb-field">
            <label for="rn-name">Nom affiché aux destinataires</label>
            <input id="rn-name" name="display_name" type="text" maxlength="120" required>
            <p class="mb-field-error" data-error-for="display_name" hidden></p>
        </div>
        <p class="mb-form-error" role="alert" hidden></p>
        <div class="mb-dialog-actions">
            <button type="button" class="mb-btn mb-btn--ghost" data-close>Annuler</button>
            <button type="submit" class="mb-btn mb-btn--primary">Renommer</button>
        </div>
    </form>
</dialog>

<dialog class="mb-dialog" id="dlg-remove" aria-labelledby="dlg-remove-h">
    <form method="dialog" class="mb-form" data-api="remove" novalidate>
        <h2 class="mb-dialog-title" id="dlg-remove-h">Retirer <span data-fill="email"></span> ?</h2>
        <p class="mb-muted">Bureau oublie sa copie, ses personnes et le travail d’équipe sur ses conversations. Le courrier reste sur le serveur : vous pourrez la reconnecter.</p>
        <p class="mb-form-error" role="alert" hidden></p>
        <div class="mb-dialog-actions">
            <button type="button" class="mb-btn mb-btn--ghost" data-close>Garder la boîte</button>
            <button type="submit" class="mb-btn mb-btn--danger">Retirer la boîte</button>
        </div>
    </form>
</dialog>

<dialog class="mb-dialog mb-dialog--wide" id="dlg-people" aria-labelledby="dlg-people-h">
    <div class="mb-form">
        <h2 class="mb-dialog-title" id="dlg-people-h">Personnes sur <span data-fill="email"></span></h2>
        <ul class="mb-people" data-people aria-live="polite"></ul>
        <form class="mb-invite" data-invite hidden novalidate>
            <h3 class="mb-h3">Donner l’accès</h3>
            <div class="mb-invite-row">
                <div class="mb-field">
                    <label for="pp-user">Personne</label>
                    <select id="pp-user" name="user_id" required></select>
                    <p class="mb-field-error" data-error-for="invite_user" hidden></p>
                </div>
                <div class="mb-field mb-field--level">
                    <label for="pp-level">Niveau</label>
                    <select id="pp-level" name="level">
                        <option value="read">Lecture</option>
                        <option value="send" selected>Lecture et envoi</option>
                        <option value="manage">Gestionnaire</option>
                    </select>
                </div>
                <button type="submit" class="mb-btn mb-btn--primary mb-invite-btn">Donner l’accès</button>
            </div>
        </form>
        <p class="mb-form-error" role="alert" hidden></p>
        <div class="mb-dialog-actions">
            <button type="button" class="mb-btn mb-btn--ghost" data-close>Fermer</button>
        </div>
    </div>
</dialog>

<script type="application/json" id="mail-page-data"><?= json_encode([
    'api'      => '/api/v1/mail/mailboxes.php',
    'focusBox' => $focusBox,
    'openConnect' => $openConnect,
    'boxes'    => array_map(fn ($b) => [
        'id' => $b['id'], 'email' => $b['email'], 'display_name' => $b['display_name'], 'kind' => $b['kind'],
        'status' => $b['status'], 'can_manage' => $b['can_manage'], 'settings' => $b['settings'],
    ], array_values(array_filter(array_merge([$personal], $shared)))),
    'levels'   => MailAccess::LEVEL_LABELS,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/mail-mailboxes.js') ?>" defer></script>
<?php endif; ?>
</body>
</html>
