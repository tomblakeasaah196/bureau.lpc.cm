<?php
/**
 * modules/mail/signature.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Courrier, Ma signature (PR 3; docs/MAIL.md §1 decision 12).
 *
 * One LPC company design for everyone, built from the HR record (name, job
 * title, service), the person's numbers, the LPC logo and the company's details.
 * A person sees their signature as recipients will (full and short, live as
 * they type) and edits only their own numbers; what comes from HR is shown
 * read-only with who to ask. Admins (mail.shared.manage) also edit the company
 * part for everyone, over the company profile's values. Saves through
 * api/v1/mail/compose_signature.php (assets/js/modules/mail-compose-signature.js).
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/mail/autoload.php';
Rbac::requireAuth();

use LPC\Mail\Compose\Signatures;
use LPC\Mail\MailAccess;
use LPC\Mail\People;
use LPC\Mail\View;

$userId = (int) ($_SESSION['user_id'] ?? 0);
$e      = [View::class, 'e'];

$ready = true;
try {
    $canUse   = MailAccess::canUse($userId);
    $isAdmin  = $canUse && MailAccess::canAddShared($userId);
    $person   = Signatures::person($userId);
    $numbers  = Signatures::numbers($userId);
    $company  = Signatures::company();
    $defaults = Signatures::companyDefaults();
    $accounts = MailAccess::accounts($userId, 'send');
} catch (Throwable $ex) {
    // migration 320 not applied yet on this database
    error_log('mail/signature: ' . $ex->getMessage());
    $ready = false;
    $canUse = false;
}
$email   = $ready ? (($accounts[0]['email'] ?? '') ?: (People::find($userId)['email'] ?? '')) : '';
$phone   = $ready ? ($numbers['saved'] ? (string) $numbers['phone'] : '') : '';
$mobile  = $ready ? ($numbers['saved'] ? (string) $numbers['mobile'] : (string) $person['hr_phone']) : '';

/** A read-only line from the HR record */
$hrLine = function (string $label, ?string $value, string $empty) use ($e): void {
    ?>
    <div class="sg-hr-row">
        <dt><?= $e($label) ?></dt>
        <dd><?= $value !== null && $value !== '' ? $e($value) : '<span class="sg-empty">' . $e($empty) . '</span>' ?></dd>
    </div>
    <?php
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ma signature | Courrier | Bureau LPC</title>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-mail.css') ?>">
<link rel="stylesheet" href="<?= lpc_asset('/assets/css/lpc-mail-compose.css') ?>">
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>
<?php
$pageTitle    = 'Ma signature';
$pageSubtitle = 'Courrier';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>
<div id="lpc-shell-main">
    <div class="lpc-toolbar">
        <p class="lpc-toolbar-lead mb-toolbar-lead">Ma signature</p>
        <a href="/modules/mail/index.php" class="mb-btn mb-btn--ghost">Ouvrir Courrier</a>
    </div>
    <main role="main" id="main" class="lpc-page mb-page sg-page">
    <?php if (!$canUse): ?>
        <section class="mb-card mb-empty">
            <p class="mb-empty-title">Courrier n’est pas encore prêt</p>
            <p class="mb-muted">La signature arrive avec la mise à jour de Courrier. Réessayez dans quelques minutes ; si ce message reste, prévenez l’administrateur.</p>
        </section>
    <?php else: ?>
        <section class="mb-card sg-preview-card" aria-labelledby="sg-preview-h">
            <div class="sg-preview-head">
                <div>
                    <h2 class="mb-h2" id="sg-preview-h">Votre signature</h2>
                    <p class="mb-muted">Telle que vos destinataires la verront. Bureau l’ajoute sous chaque message : complète sur un nouveau message, courte sur une réponse.</p>
                </div>
                <div class="sg-tabs" role="group" aria-label="Version affichée">
                    <button type="button" class="sg-tab" aria-pressed="true" data-style="full">Complète</button>
                    <button type="button" class="sg-tab" aria-pressed="false" data-style="slim">Courte</button>
                    <button type="button" class="sg-tab" aria-pressed="false" data-style="text">Texte seul</button>
                </div>
            </div>
            <div class="sg-mail" id="sg-panel" aria-live="polite">
                <div class="sg-mail-head">
                    <span class="sg-mail-dot" aria-hidden="true"></span>
                    <span class="sg-mail-from">
                        <?php if (count($accounts) > 1): ?>
                            <label class="mc-sr" for="sg-account">Envoyé depuis</label>
                            <select id="sg-account" class="sg-account" data-sg-account>
                                <?php foreach ($accounts as $a): ?>
                                    <option value="<?= (int) $a['id'] ?>"><?= $e($a['display_name'] . ' <' . $a['email'] . '>') ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            De : <?= $e(($accounts[0]['display_name'] ?? $person['name']) . ' <' . $email . '>') ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="sg-mail-body">
                    <p>Bonjour,</p>
                    <p>Merci pour votre message. Je vous envoie le bon de livraison dès sa signature.</p>
                    <p>Bien cordialement,</p>
                    <div class="sg-out" data-sg-out><?= Signatures::render($person, $company, $email, 'full') ?></div>
                    <pre class="sg-text" data-sg-text hidden><?= $e(Signatures::renderText($person, $company, $email, 'full')) ?></pre>
                </div>
            </div>
            <p class="mb-hint sg-hint">Le logo s’affiche dès que le destinataire autorise les images ; sinon, le nom de l’entreprise le remplace, à la même place.</p>
        </section>

        <div class="sg-grid">
            <section class="mb-card" aria-labelledby="sg-hr-h">
                <h2 class="mb-h2" id="sg-hr-h">Repris de votre fiche du personnel</h2>
                <dl class="sg-hr">
                    <?php $hrLine('Nom', $person['name'], 'Non renseigné'); ?>
                    <?php $hrLine('Poste', $person['title'], 'Non renseigné : la ligne n’apparaît pas'); ?>
                    <?php $hrLine('Service', $person['department'], 'Non renseigné : la ligne n’apparaît pas'); ?>
                </dl>
                <p class="mb-hint">Une erreur ? Demandez aux ressources humaines de corriger votre fiche : votre signature suit d’elle-même.</p>
            </section>

            <section class="mb-card" aria-labelledby="sg-num-h">
                <h2 class="mb-h2" id="sg-num-h">Mes numéros</h2>
                <form class="sg-form" data-sg-numbers novalidate>
                    <div class="mb-field">
                        <label for="sg-phone">Téléphone de bureau</label>
                        <input id="sg-phone" name="phone" type="tel" inputmode="tel" autocomplete="off" maxlength="30" value="<?= $e($phone) ?>" aria-describedby="sg-phone-hint">
                        <p class="mb-hint" id="sg-phone-hint">Par exemple +237 233 42 02 81. Laissez vide pour ne pas l’afficher.</p>
                        <p class="mb-field-error" data-error-for="phone" hidden></p>
                    </div>
                    <div class="mb-field">
                        <label for="sg-mobile">Mobile</label>
                        <input id="sg-mobile" name="mobile" type="tel" inputmode="tel" autocomplete="off" maxlength="30" value="<?= $e($mobile) ?>" aria-describedby="sg-mobile-hint">
                        <p class="mb-hint" id="sg-mobile-hint"><?= !$numbers['saved'] && $person['hr_phone'] ? 'Repris de votre fiche du personnel. Effacez-le pour ne pas l’afficher.' : 'Laissez vide pour ne pas l’afficher.' ?></p>
                        <p class="mb-field-error" data-error-for="mobile" hidden></p>
                    </div>
                    <p class="mb-form-error" role="alert" hidden></p>
                    <div class="sg-actions">
                        <button type="submit" class="mb-btn mb-btn--primary">Enregistrer mes numéros</button>
                    </div>
                </form>
            </section>
        </div>

        <?php if ($isAdmin): ?>
        <section class="mb-card" aria-labelledby="sg-co-h">
            <div class="mb-card-head">
                <div>
                    <h2 class="mb-h2" id="sg-co-h">Partie entreprise <span class="mb-pill mb-pill--neutral">Administrateurs</span></h2>
                    <p class="mb-muted">Elle s’applique à la signature de tout le monde. Par défaut, bureau reprend la fiche Entreprise (Administration).</p>
                </div>
            </div>
            <form class="sg-form sg-company" data-sg-company novalidate>
                <div class="sg-cols">
                    <div class="mb-field">
                        <label for="sg-co-name">Nom de l’entreprise</label>
                        <input id="sg-co-name" name="name" type="text" maxlength="80" required value="<?= $e($company['name']) ?>">
                        <p class="mb-field-error" data-error-for="name" hidden></p>
                    </div>
                    <div class="mb-field">
                        <label for="sg-co-tagline">Activité</label>
                        <input id="sg-co-tagline" name="tagline" type="text" maxlength="120" value="<?= $e($company['tagline']) ?>" aria-describedby="sg-co-tagline-hint">
                        <p class="mb-hint" id="sg-co-tagline-hint">Quelques mots après le nom, par exemple « <?= $e($defaults['tagline']) ?> ».</p>
                    </div>
                    <div class="mb-field sg-span">
                        <label for="sg-co-address">Adresse</label>
                        <input id="sg-co-address" name="address" type="text" maxlength="160" value="<?= $e($company['address']) ?>">
                    </div>
                    <div class="mb-field">
                        <label for="sg-co-phone">Téléphone de l’entreprise</label>
                        <input id="sg-co-phone" name="phone" type="tel" inputmode="tel" maxlength="30" value="<?= $e($company['phone']) ?>" aria-describedby="sg-co-phone-hint">
                        <p class="mb-hint" id="sg-co-phone-hint">Affiché pour les personnes qui n’ont aucun numéro.</p>
                        <p class="mb-field-error" data-error-for="phone" hidden></p>
                    </div>
                    <div class="mb-field">
                        <label for="sg-co-website">Site web</label>
                        <input id="sg-co-website" name="website" type="text" inputmode="url" maxlength="120" value="<?= $e($company['website']) ?>" spellcheck="false">
                        <p class="mb-field-error" data-error-for="website" hidden></p>
                    </div>
                </div>
                <fieldset class="sg-switches">
                    <legend class="mc-sr">Options</legend>
                    <label class="mc-check"><input type="checkbox" name="show_logo" value="1"<?= $company['show_logo'] ? ' checked' : '' ?>> Afficher le logo LPC</label>
                    <label class="mc-check"><input type="checkbox" name="show_legal" value="1"<?= $company['show_legal'] ? ' checked' : '' ?><?= $company['legal'] === '' ? ' disabled' : '' ?>> Afficher les mentions légales<?= $company['legal'] !== '' ? ' (' . $e($company['legal']) . ')' : ' (à saisir dans la fiche Entreprise)' ?></label>
                </fieldset>
                <details class="mb-details">
                    <summary>Logo personnalisé</summary>
                    <p class="mb-hint">Laissez vide pour le logo LPC fourni par bureau. Sinon, l’adresse complète d’une image PNG ou JPG en https://, carrée, d’au moins 160 pixels.</p>
                    <div class="mb-field">
                        <label for="sg-co-logo">Adresse du logo</label>
                        <input id="sg-co-logo" name="logo_url" type="text" inputmode="url" maxlength="300" value="<?= $e($company['custom'] && $company['logo_url'] !== $defaults['logo_url'] ? $company['logo_url'] : '') ?>" spellcheck="false">
                        <p class="mb-field-error" data-error-for="logo_url" hidden></p>
                    </div>
                </details>
                <p class="mb-form-error" role="alert" hidden></p>
                <div class="sg-actions">
                    <?php if ($company['custom']): ?>
                        <button type="button" class="mb-btn mb-btn--ghost" data-sg-reset>Reprendre la fiche Entreprise</button>
                    <?php endif; ?>
                    <button type="submit" class="mb-btn mb-btn--primary">Enregistrer pour tout le monde</button>
                </div>
            </form>
        </section>
        <?php endif; ?>
    <?php endif; ?>
    </main>
</div>

<?php if ($canUse): ?>
<script type="application/json" id="mail-signature-data"><?= json_encode([
    'api'      => '/api/v1/mail/compose_signature.php',
    'isAdmin'  => $isAdmin,
    'defaultLogo' => $defaults['logo_url'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/mail-compose-signature.js') ?>" defer></script>
<?php endif; ?>
</body>
</html>
