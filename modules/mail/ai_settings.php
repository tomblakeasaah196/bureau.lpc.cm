<?php
/**
 * modules/mail/ai_settings.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, Praxis AI settings (PR 5; docs/MAIL.md §6.1, §7.6).
 *
 * Admins only (People::isAdmin, as for shared mailboxes): the owner enters the
 * Gemini key here, and may change the model, test the key or remove it. The key
 * is stored encrypted (mail_settings ai.gemini_key, \Crypto / APP_ENCRYPTION_KEY)
 * and never shown again: only its masked tail. The field stays blank; saving it
 * blank keeps the stored key. Without a key, every Praxis AI button in Courrier
 * is hidden, and this page says so.
 *
 * Also says what Praxis AI reads and never reads, and the limits per person.
 * Forms go to api/v1/mail/ai_settings.php through
 * assets/js/modules/mail-ai-settings.js, which reloads the page after a change.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/mail/autoload.php';
Rbac::requireAuth();

use LPC\Mail\Ai\Limits;
use LPC\Mail\Ai\Settings;
use LPC\Mail\View;

$userId = (int) ($_SESSION['user_id'] ?? 0);
$e      = [View::class, 'e'];

$ready = true;
try {
    $canManage = Settings::canManage($userId);
    $s = $canManage ? Settings::masked() : null;
} catch (Throwable $ex) {
    // migration 300 not applied yet on this database
    error_log('mail/ai_settings: ' . $ex->getMessage());
    $ready = false;
    $canManage = false;
    $s = null;
}
if ($ready && !$canManage) {
    http_response_code(403);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Praxis AI | Courrier | Bureau LPC</title>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-mail.css') ?>">
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-mail-ai.css') ?>">
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

<?php
$pageTitle    = 'Praxis AI';
$pageSubtitle = 'Courrier';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>

<div id="lpc-shell-main">
    <div class="lpc-toolbar">
        <p class="lpc-toolbar-lead mb-toolbar-lead">Praxis AI dans Courrier</p>
        <a href="/modules/mail/index.php" class="mb-btn mb-btn--ghost">Ouvrir Courrier</a>
    </div>

    <main role="main" id="main" class="lpc-page mb-page mai-settings">
    <?php if (!$ready): ?>
        <section class="mb-card mb-empty">
            <h2 class="mb-h2">Courrier n’est pas encore installé</h2>
            <p class="mb-muted">La base de données attend sa mise à jour (migration 300). Elle se fait d’elle-même au prochain déploiement.</p>
        </section>
    <?php elseif (!$canManage): ?>
        <section class="mb-card mb-empty">
            <h2 class="mb-h2">Réservé aux administrateurs</h2>
            <p class="mb-muted">Les réglages de Praxis AI sont gérés par les administrateurs de bureau. Si un bouton de Praxis AI vous manque dans Courrier, demandez-leur.</p>
            <a class="mb-btn mb-btn--primary" href="/modules/mail/index.php">Ouvrir Courrier</a>
        </section>
    <?php else: ?>

        <section class="mb-card" aria-labelledby="mai-key-h">
            <header class="mb-card-head">
                <div>
                    <h2 class="mb-h2" id="mai-key-h">Clé Gemini</h2>
                    <p class="mb-muted">Praxis AI aide dans Courrier : <em>Écrire avec l’IA</em>, <em>Résumer</em> une conversation et <em>Demander à votre courrier</em>. Il passe uniquement par Gemini, de Google, avec la clé de LPC saisie ici.</p>
                </div>
                <span class="mb-pill <?= $s['configured'] ? 'mb-pill--success' : 'mb-pill--neutral' ?> mb-head-pill"><?= $s['configured'] ? 'Activé' : 'Désactivé' ?></span>
            </header>

            <?php if (!$s['encryption_ready']): ?>
                <p class="mb-form-error mai-banner">bureau ne peut pas encore enregistrer de clé en sécurité : la clé de chiffrement du serveur (APP_ENCRYPTION_KEY) est absente. Prévenez l’administrateur du serveur.</p>
            <?php elseif ($s['unreadable']): ?>
                <p class="mb-form-error mai-banner">Une clé est enregistrée, mais bureau ne peut plus la lire (la clé de chiffrement du serveur a changé). Saisissez la clé Gemini de nouveau.</p>
            <?php elseif ($s['configured']): ?>
                <p class="mai-status">Clé enregistrée <span class="mai-mask"><?= $e($s['key_masked']) ?></span><?= $s['updated_by'] ? ' · par ' . $e($s['updated_by']) : '' ?><?= $s['updated_ago'] ? ', ' . $e($s['updated_ago']) : '' ?>.</p>
            <?php else: ?>
                <p class="mai-status mai-status--off">Aucune clé pour l’instant : les boutons de Praxis AI restent cachés dans Courrier, pour tout le monde. Ils apparaissent dès que la clé est enregistrée.</p>
            <?php endif; ?>

            <div class="mai-settings-form" data-mai-settings-form>
                <div class="mb-field">
                    <label for="mai-key"><?= $s['configured'] ? 'Nouvelle clé API Gemini' : 'Clé API Gemini' ?></label>
                    <input id="mai-key" name="gemini_key" type="password" autocomplete="off" spellcheck="false" autocapitalize="off" aria-describedby="mai-key-hint"<?= $s['encryption_ready'] ? '' : ' disabled' ?>>
                    <p class="mb-field-error" data-error-for="gemini_key" hidden></p>
                    <p class="mb-hint" id="mai-key-hint">Créez-la dans Google AI Studio (aistudio.google.com, « Get API key »), puis collez-la ici. <?= $s['configured'] ? 'Laissez vide pour garder la clé actuelle. ' : '' ?>Elle est chiffrée et ne s’affiche plus jamais.</p>
                </div>
                <div class="mb-field mai-model">
                    <label for="mai-model">Modèle</label>
                    <input id="mai-model" name="model" type="text" autocomplete="off" spellcheck="false" autocapitalize="off" value="<?= $e($s['model']) ?>" aria-describedby="mai-model-hint">
                    <p class="mb-field-error" data-error-for="model" hidden></p>
                    <p class="mb-hint" id="mai-model-hint">Un modèle Gemini « flash », rapide. Gardez <?= $e($s['default_model']) ?> sauf avis contraire.</p>
                </div>
                <p class="mb-form-error" data-mai-settings-error role="alert" hidden></p>
                <div class="mai-settings-actions">
                    <button type="button" class="mb-btn mb-btn--primary" data-mai-save<?= $s['encryption_ready'] ? '' : ' disabled' ?>>Enregistrer</button>
                    <?php if ($s['configured']): ?>
                        <button type="button" class="mb-btn" data-mai-test>Tester la clé</button>
                        <button type="button" class="mb-btn mb-btn--danger-ghost mai-remove" data-mai-remove>Retirer la clé</button>
                    <?php endif; ?>
                </div>
                <p class="mai-test-result" data-mai-test-result role="status" hidden></p>
            </div>
        </section>

        <section class="mb-card" aria-labelledby="mai-rules-h">
            <h2 class="mb-h2" id="mai-rules-h">Ce que Praxis AI lit, et ce qu’il ne lit jamais</h2>
            <ul class="mai-rules">
                <li><strong>Seulement quand une personne appuie sur un bouton</strong>, jamais de lui-même. Il n’envoie jamais rien : la personne relit et choisit.</li>
                <li><strong>Seulement le courrier que cette personne peut lire</strong> dans bureau : sa propre boîte et les boîtes partagées où elle a accès.</li>
                <li><strong>Au plus les 30 derniers messages</strong> d’une conversation, sans le texte cité des réponses précédentes.</li>
                <li><strong>Jamais les pièces jointes</strong>, jamais les Indésirables, la Corbeille ni les Brouillons.</li>
                <li><strong>Rien du courrier dans les journaux</strong> de bureau : ni question, ni brouillon, ni résumé.</li>
                <li><strong>Par personne et par heure</strong> : <?= Limits::limit('write') ?> brouillons, <?= Limits::limit('summary') ?> résumés et <?= Limits::limit('ask') ?> questions au plus.</li>
            </ul>
            <p class="mb-muted mb-small">Un résumé reste affiché sur la conversation pour tous ceux qui la lisent, jusqu’à l’arrivée d’un nouveau message.</p>
        </section>

        <dialog class="mb-dialog" id="mai-remove-dlg" aria-labelledby="mai-remove-h">
            <div class="mb-form">
                <h2 class="mb-dialog-title" id="mai-remove-h">Retirer la clé Gemini ?</h2>
                <p class="mb-muted">Les boutons de Praxis AI disparaissent de Courrier pour tout le monde. Vous pourrez saisir une clé plus tard.</p>
                <p class="mb-form-error" role="alert" hidden></p>
                <div class="mb-dialog-actions">
                    <button type="button" class="mb-btn mb-btn--ghost" data-close>Garder la clé</button>
                    <button type="button" class="mb-btn mb-btn--danger" data-mai-remove-confirm>Retirer la clé</button>
                </div>
            </div>
        </dialog>

        <script src="<?= lpc_asset('/assets/js/modules/mail-ai-settings.js') ?>" defer></script>
    <?php endif; ?>
    </main>
</div>
</body>
</html>
