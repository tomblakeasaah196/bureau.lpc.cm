<?php
/**
 * modules/admin/data_wipe.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — clear test data, module by module (docs/DATA_WIPE.md).
 * For the WIPE_ADMIN_EMAIL account only, on its WIPE_URL_KEY address; anyone
 * else gets what a page that doesn't exist gets (DataWipeAccess). That account
 * alone has it in the sidebar, under Courrier; no search, no permission.
 *
 * Two tabs (owner, 3 Nov 2026), each with the same safeguards:
 *   · Tables entières: pick tables → see everything that goes with them, with
 *     row counts and the balances recalculated → a code emailed to that account
 *     + a reason → the wipe (copied first, kept 30 days) → the MD is told.
 *     Script: assets/js/modules/admin-data_wipe.js
 *   · Un par un: find documents, clients, suppliers, products or inactive staff
 *     accounts by number or name, choose for each account whether its records
 *     are handed over or wiped too, then the same plan, code, copy and notice.
 *     Script: assets/js/modules/admin-data_wipe-records.js
 * Past wipes below, with their copies while they are kept.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/DataWipe.php';
require_once __DIR__ . '/../../includes/classes/DataWipeAccess.php';

$db = Database::getInstance()->getConnection();
$user = DataWipeAccess::enforce($db);

$lang = lpc_i18n_current_lang();
$pageTitle = 'Effacer les données de test';
$history = DataWipe::history($db);
$md = DataWipeAccess::md($db);
$key = (string) ($_GET['k'] ?? '');
$e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$wiper = new DataWipe($db);
$data = [
    'api'     => '/api/v1/data_wipe_controller.php?k=' . rawurlencode($key),
    'csrf'    => Csrf::token(),
    'email'   => DataWipeAccess::maskedEmail(),
    'keep'    => DataWipe::KEEP_DAYS,
    'md'      => $md !== null,
    'kinds'   => $wiper->recordKinds(),
    'one'     => array_map(fn ($k) => $k[1], DataWipeMap::RECORDS),
    'targets' => (new DataWipeStaff($db, $wiper))->targets(),
];
?>
<!DOCTYPE html>
<html lang="<?= $e($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?= $e($pageTitle) ?> | Bureau LPC</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
.dw-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:1rem;align-items:start}
/* The preview follows the scroll on wide screens, and scrolls inside itself when taller than the window */
@media (min-width:1024px){.dw-grid{grid-template-columns:minmax(0,1.1fr) minmax(0,1fr)}
  .dw-side{position:sticky;top:1rem;max-height:calc(100vh - 2rem);overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;border-radius:.75rem}}
.dw-list li > span:first-child{min-width:0;overflow-wrap:anywhere}
.dw-list li:only-child,.dw-list li:not(:has(.dw-n)){grid-template-columns:minmax(0,1fr)}
.dw-card{background:var(--lpc-surface);border:1px solid var(--lpc-border);border-radius:.75rem;padding:1rem 1.1rem}
.dw-card h2{font-size:.95rem;font-weight:700;color:var(--lpc-ink);margin:0 0 .6rem}
.dw-intro{color:var(--lpc-ink-soft);font-size:.85rem;line-height:1.5;margin:0}
.dw-mod{border-top:1px solid var(--lpc-border);padding:.55rem 0}
.dw-mod:first-of-type{border-top:0}
.dw-mod summary{display:flex;align-items:center;gap:.6rem;cursor:pointer;list-style:none;font-weight:600;font-size:.9rem;color:var(--lpc-ink);min-height:44px}
.dw-mod summary::-webkit-details-marker{display:none}
.dw-mod summary .dw-n{margin-left:auto;color:var(--lpc-ink-mute);font-weight:500;font-size:.8rem;font-variant-numeric:tabular-nums}
.dw-rows{display:grid;gap:.1rem;padding:.2rem 0 .3rem 1.9rem}
.dw-row{display:flex;align-items:center;gap:.6rem;font-size:.85rem;color:var(--lpc-ink);min-height:36px;cursor:pointer}
.dw-row .dw-n{margin-left:auto;color:var(--lpc-ink-mute);font-variant-numeric:tabular-nums}
.dw-row.is-empty{color:var(--lpc-ink-mute)}
.dw-check{width:1.05rem;height:1.05rem;accent-color:var(--lpc-status-danger-fg);flex:none}
.dw-total{font-size:1.5rem;font-weight:700;color:var(--lpc-ink);font-variant-numeric:tabular-nums}
.dw-sub{font-size:.8rem;color:var(--lpc-ink-soft)}
.dw-list{list-style:none;margin:.5rem 0 0;padding:0;display:grid;gap:.25rem}
.dw-list li{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.6rem;align-items:baseline;font-size:.84rem;color:var(--lpc-ink)}
.dw-list li .dw-n{font-variant-numeric:tabular-nums;font-weight:600;white-space:nowrap}
.dw-why{display:block;width:max-content;max-width:100%;margin-top:.1rem;font-size:.72rem;color:var(--lpc-status-warning-fg);background:var(--lpc-status-warning-tint);border-radius:999px;padding:.05rem .45rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dw-h3{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--lpc-ink-soft);margin:1rem 0 .3rem}
.dw-alert{border-radius:.6rem;padding:.6rem .8rem;font-size:.84rem;line-height:1.45;margin-top:.75rem}
.dw-alert.is-danger{background:var(--lpc-status-danger-tint);color:var(--lpc-status-danger-fg);border:1px solid var(--lpc-status-danger-bg)}
.dw-alert.is-ok{background:var(--lpc-status-success-tint);color:var(--lpc-status-success-fg);border:1px solid var(--lpc-status-success-bg)}
.dw-alert.is-info{background:var(--lpc-surface-sunken);color:var(--lpc-ink-soft);border:1px solid var(--lpc-border)}
.dw-field{display:grid;gap:.3rem;margin-top:.8rem}
.dw-field label{font-size:.8rem;font-weight:600;color:var(--lpc-ink)}
.dw-field input,.dw-field textarea{border:1px solid var(--lpc-border);border-radius:.5rem;padding:.55rem .7rem;font-size:.95rem;background:var(--lpc-surface);color:var(--lpc-ink);width:100%}
.dw-field input{max-width:12rem;letter-spacing:.3em;font-variant-numeric:tabular-nums}
.dw-actions{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:.9rem}
.dw-danger{background:var(--lpc-status-danger-fg);color:#fff;border:0;border-radius:.5rem;padding:.65rem 1rem;font-weight:700;font-size:.9rem;min-height:44px;cursor:pointer}
.dw-danger:disabled{opacity:.45;cursor:not-allowed}
.dw-table{width:100%;border-collapse:collapse;font-size:.82rem}
.dw-table th{text-align:left;font-weight:600;color:var(--lpc-ink-soft);padding:.4rem .5rem;border-bottom:1px solid var(--lpc-border);white-space:nowrap}
.dw-table td{padding:.5rem;border-bottom:1px solid var(--lpc-border);vertical-align:top;color:var(--lpc-ink)}
.dw-scroll{overflow-x:auto}
.dw-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.dw-muted{color:var(--lpc-ink-mute)}
/* Tabs, and the "one by one" tab */
.dw-tabs{display:flex;gap:.25rem;border-bottom:1px solid var(--lpc-border);margin-bottom:1rem;overflow-x:auto;overflow-y:hidden}
.dw-tab{background:none;border:0;border-bottom:2px solid transparent;margin-bottom:-1px;padding:.6rem .9rem;min-height:44px;font-size:.9rem;font-weight:600;color:var(--lpc-ink-soft);cursor:pointer;white-space:nowrap}
.dw-tab[aria-selected="true"]{color:var(--lpc-ink);border-bottom-color:var(--lpc-status-danger-fg)}
.dw-tab:focus-visible{outline:2px solid var(--lpc-status-danger-fg);outline-offset:-2px;border-radius:.35rem .35rem 0 0}
.dr-find{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,15rem),1fr));gap:.6rem .75rem}
.dr-find .dw-field{margin-top:0}
.dr-find select,.dr-find input,.dr-staff select{border:1px solid var(--lpc-border);border-radius:.5rem;padding:.55rem .7rem;font-size:.95rem;background:var(--lpc-surface);color:var(--lpc-ink);width:100%;max-width:none;letter-spacing:normal;min-height:44px}
.dr-results,.dr-picked{list-style:none;margin:.6rem 0 0;padding:0;display:grid;gap:.35rem}
.dr-results li,.dr-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.6rem;align-items:center;border:1px solid var(--lpc-border);border-radius:.6rem;padding:.5rem .7rem;background:var(--lpc-surface)}
.dr-name{font-size:.86rem;font-weight:600;color:var(--lpc-ink);overflow-wrap:anywhere}
.dr-meta{display:block;font-size:.76rem;color:var(--lpc-ink-soft);font-weight:400;margin-top:.1rem;overflow-wrap:anywhere}
.dr-refused{display:block;font-size:.76rem;color:var(--lpc-status-warning-fg);margin-top:.15rem}
.dr-btn{border:1px solid var(--lpc-border);background:var(--lpc-surface-sunken);color:var(--lpc-ink);border-radius:.5rem;padding:.4rem .75rem;min-height:40px;font-size:.82rem;font-weight:600;cursor:pointer;white-space:nowrap}
.dr-btn:focus-visible,.dr-staff input:focus-visible{outline:2px solid var(--lpc-status-danger-fg);outline-offset:2px}
.dr-staff{grid-column:1 / -1;border:0;border-top:1px solid var(--lpc-border);margin:.3rem 0 0;padding:.5rem 0 0;display:grid;gap:.35rem;min-width:0}
.dr-staff legend{font-size:.78rem;color:var(--lpc-ink-soft);padding:0;margin-bottom:.15rem}
.dr-staff label{display:flex;align-items:center;gap:.5rem;font-size:.85rem;color:var(--lpc-ink);min-height:36px;cursor:pointer}
.dr-staff select{margin-left:1.55rem;width:calc(100% - 1.55rem)}
.dr-note{font-size:.82rem;line-height:1.45;color:var(--lpc-ink);margin:.3rem 0 0}
</style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>
<?php
require __DIR__ . '/../../includes/components/sidebar.php';
require __DIR__ . '/../../includes/components/topbar.php';
?>
<div id="lpc-shell-main">
<main role="main" id="main" class="lpc-page">

<div class="dw-tabs" role="tablist" aria-label="Façon d'effacer">
    <button type="button" class="dw-tab" role="tab" id="dw-tab-tables" aria-controls="dw-panel-tables" aria-selected="true">Tables entières</button>
    <button type="button" class="dw-tab" role="tab" id="dw-tab-records" aria-controls="dw-panel-records" aria-selected="false" tabindex="-1">Un par un</button>
</div>

<div id="dw-panel-tables" role="tabpanel" aria-labelledby="dw-tab-tables">
<div class="dw-card" style="margin-bottom:1rem">
    <p class="dw-intro">
        Cochez ce qui doit disparaître. Tout ce qui en dépend est ajouté et compté avant que vous confirmiez.
        Les soldes de trésorerie, les avoirs clients et le stock sont recalculés sur ce qui reste, et la numérotation
        des documents vidés repart à 0001. Avant d'effacer, tout est copié dans un fichier privé, gardé
        <?= (int) DataWipe::KEEP_DAYS ?> jours. Le DG est prévenu à la fin.
        Ne sont jamais effacés : comptes et rôles du personnel, employés, plan comptable, paramètres, Courrier.
    </p>
    <?php if (!$md): ?>
        <div class="dw-alert is-danger"><?= $e(DataWipeAccess::mdMissing()) ?></div>
    <?php endif; ?>
</div>

<div class="dw-grid">
    <section class="dw-card" aria-labelledby="dw-pick-title">
        <h2 id="dw-pick-title">1. Ce que vous effacez</h2>
        <div id="dw-modules"><p class="dw-sub">Chargement…</p></div>
    </section>

    <div class="dw-side">
        <section class="dw-card" aria-labelledby="dw-plan-title" aria-live="polite">
            <h2 id="dw-plan-title">2. Ce qui sera effacé</h2>
            <div id="dw-plan"><p class="dw-sub">Cochez au moins une table.</p></div>
        </section>

        <section class="dw-card" style="margin-top:1rem" aria-labelledby="dw-confirm-title">
            <h2 id="dw-confirm-title">3. Confirmer</h2>
            <p class="dw-sub">Un code est envoyé à <strong><?= $e(DataWipeAccess::maskedEmail()) ?></strong>, valable <?= (int) DataWipeAccess::CODE_MINUTES ?> minutes et seulement pour la sélection affichée.</p>
            <?php if ($md): ?>
                <p class="dw-sub" style="margin-top:.4rem">Une fois l'effacement fait, le DG est prévenu par email et dans ses notifications :
                    <strong><?= $e($md['name']) ?></strong> &lt;<?= $e($md['email']) ?>&gt; (matricule <?= $e(DataWipeAccess::MD_MATRICULE) ?>).</p>
            <?php else: ?>
                <div class="dw-alert is-danger"><?= $e(DataWipeAccess::mdMissing()) ?></div>
            <?php endif; ?>
            <div class="dw-actions">
                <button type="button" class="btn btn-secondary" id="dw-send" disabled>Envoyer le code</button>
            </div>
            <div class="dw-field">
                <label for="dw-code">Code reçu par email</label>
                <input id="dw-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" placeholder="000000">
            </div>
            <div class="dw-field">
                <label for="dw-reason">Motif (envoyé au DG)</label>
                <textarea id="dw-reason" rows="3" maxlength="1000" placeholder="Ex. : fin des essais, début de la saisie réelle le 3 novembre"></textarea>
            </div>
            <div class="dw-actions">
                <button type="button" class="dw-danger" id="dw-run" disabled>Effacer définitivement</button>
            </div>
            <div id="dw-msg" role="status"></div>
        </section>
    </div>
</div>
</div>

<div id="dw-panel-records" role="tabpanel" aria-labelledby="dw-tab-records" hidden>
<div class="dw-card" style="margin-bottom:1rem">
    <p class="dw-intro">
        Cherchez par numéro ou par nom : un document (facture, devis, commande, BL, bon de commande, encaissement, dépense, CRE,
        inventaire, vente au recycleur), un client, un fournisseur, un produit, ou un compte du personnel inactif. Tout ce qui en
        dépend part avec lui, compté avant que vous confirmiez, et les soldes sont recalculés. Si un document portait le dernier
        numéro de sa série, la numérotation revient en arrière ; sinon il reste un trou, et le plan le dit. Pour un compte du
        personnel, sa fiche employé part avec lui, et vous choisissez si ses enregistrements passent à un autre compte ou partent
        aussi. Comme pour les tables entières : copie privée gardée <?= (int) DataWipe::KEEP_DAYS ?> jours, code par email, DG prévenu.
    </p>
    <?php if (!$md): ?>
        <div class="dw-alert is-danger"><?= $e(DataWipeAccess::mdMissing()) ?></div>
    <?php endif; ?>
</div>

<div class="dw-grid">
    <section class="dw-card" aria-labelledby="dr-pick-title">
        <h2 id="dr-pick-title">1. Ce que vous effacez</h2>
        <div class="dr-find">
            <div class="dw-field">
                <label for="dr-kind">Quoi</label>
                <select id="dr-kind">
                    <?php foreach ($data['kinds'] as $t => $label): ?>
                        <option value="<?= $e($t) ?>"><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="dw-field">
                <label for="dr-q">Numéro ou nom</label>
                <input id="dr-q" type="search" autocomplete="off" spellcheck="false" placeholder="Ex. : FAC-2611-0003">
            </div>
        </div>
        <ul class="dr-results" id="dr-results" aria-label="Résultats" aria-live="polite"></ul>
        <div class="dw-h3">Choisis</div>
        <ul class="dr-picked" id="dr-picked"></ul>
        <p class="dw-sub" id="dr-none">Rien de choisi pour l'instant.</p>
    </section>

    <div class="dw-side">
        <section class="dw-card" aria-labelledby="dr-plan-title" aria-live="polite">
            <h2 id="dr-plan-title">2. Ce qui sera effacé</h2>
            <div id="dr-plan"><p class="dw-sub">Choisissez au moins un enregistrement.</p></div>
        </section>

        <section class="dw-card" style="margin-top:1rem" aria-labelledby="dr-confirm-title">
            <h2 id="dr-confirm-title">3. Confirmer</h2>
            <p class="dw-sub">Un code est envoyé à <strong><?= $e(DataWipeAccess::maskedEmail()) ?></strong>, valable <?= (int) DataWipeAccess::CODE_MINUTES ?> minutes et seulement pour ce qui est choisi, avec les choix faits pour les comptes.</p>
            <?php if ($md): ?>
                <p class="dw-sub" style="margin-top:.4rem">Une fois l'effacement fait, le DG est prévenu par email et dans ses notifications :
                    <strong><?= $e($md['name']) ?></strong> &lt;<?= $e($md['email']) ?>&gt; (matricule <?= $e(DataWipeAccess::MD_MATRICULE) ?>).</p>
            <?php else: ?>
                <div class="dw-alert is-danger"><?= $e(DataWipeAccess::mdMissing()) ?></div>
            <?php endif; ?>
            <div class="dw-actions">
                <button type="button" class="btn btn-secondary" id="dr-send" disabled>Envoyer le code</button>
            </div>
            <div class="dw-field">
                <label for="dr-code">Code reçu par email</label>
                <input id="dr-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" placeholder="000000">
            </div>
            <div class="dw-field">
                <label for="dr-reason">Motif (envoyé au DG)</label>
                <textarea id="dr-reason" rows="3" maxlength="1000" placeholder="Ex. : facture saisie deux fois par erreur"></textarea>
            </div>
            <div class="dw-actions">
                <button type="button" class="dw-danger" id="dr-run" disabled>Effacer définitivement</button>
            </div>
            <div id="dr-msg" role="status"></div>
        </section>
    </div>
</div>
</div>

<section class="dw-card" style="margin-top:1rem" aria-labelledby="dw-past-title" id="past">
    <h2 id="dw-past-title">Effacements passés</h2>
    <?php if (!$history): ?>
        <p class="dw-sub">Aucun effacement pour l'instant.</p>
    <?php else: ?>
    <div class="dw-scroll">
    <table class="dw-table">
        <thead><tr><th>Date</th><th>Par</th><th>Ce qui a été choisi</th><th class="dw-num">Lignes</th><th>Motif</th><th>DG</th><th>Copie</th></tr></thead>
        <tbody>
        <?php foreach ($history as $w):
            $copy = DataWipe::copyPath($w);
        ?>
            <tr>
                <td style="white-space:nowrap"><?= $e(date('d/m/Y H:i', strtotime((string) $w['created_at']))) ?></td>
                <td><?= $e($w['user_name']) ?></td>
                <td><?= $e(DataWipe::pickedText((string) $w['picked'])) ?></td>
                <td class="dw-num"><?= number_format((int) $w['total'], 0, ',', ' ') ?></td>
                <td><?= $e($w['reason']) ?></td>
                <td><?= $w['md_notified_at'] ? ($w['md_emailed'] ? 'Prévenu (email)' : 'Prévenu (notification)') : '<span class="dw-muted">Non</span>' ?></td>
                <td style="white-space:nowrap">
                    <?php if ($copy): ?>
                        <a class="btn btn-secondary" href="<?= $e($data['api'] . '&action=download&id=' . (int) $w['id']) ?>">Télécharger</a>
                        <div class="dw-sub">jusqu'au <?= $e(date('d/m/Y', strtotime((string) $w['copy_expires_at']))) ?></div>
                    <?php else: ?>
                        <span class="dw-muted">Supprimée</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

</main>
</div>

<script type="application/json" id="dw-data"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/admin-data_wipe.js') ?>" defer></script>
<script src="<?= lpc_asset('/assets/js/modules/admin-data_wipe-records.js') ?>" defer></script>
<?= Rbac::jsBootstrap() ?>
<script src="<?= lpc_asset('/assets/js/lpc-rbac.js') ?>" defer></script>
</body>
</html>
