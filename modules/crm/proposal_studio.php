<?php
/**
 * modules/crm/proposal_studio.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7E · Proposal Studio.
 *
 * The editor for every string, logo and share-message on the 4-page commercial
 * proposal served by public/documents/quote.php. Values live in
 * proposal_template_settings (migration 030); this page is the only supported
 * way to change them.
 *
 * Shell contract: see README §5.5. Toolbar → tabs → main, no page-specific
 * chrome in the topbar, no hand-rolled control bar.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/proposal_template.php';

Rbac::requirePermission('crm.proposals.template');

$lang   = isset($_GET['lang']) && $_GET['lang'] === 'en' ? 'en' : 'fr';
$groups = lpc_proposal_studio_payload();
$labels = lpc_proposal_group_labels();
$order  = ['brand', 'p1', 'p2', 'logos', 'p3', 'p4', 'share', 'ui'];

// If migration 030 hasn't run yet the payload is empty. Say so plainly rather
// than rendering an empty editor that silently saves nothing.
$needsMigration = ($groups === []);

// Preview needs a real proposal to render against — the template only supplies
// the wrapper text; client name, line items and totals come from a record.
// Most recent quote is the best available stand-in.
$previewToken = '';
try {
    $st = Database::getInstance()->getConnection()->query(
        "SELECT token FROM proposals
          WHERE token IS NOT NULL AND token <> ''
          ORDER BY id DESC LIMIT 1"
    );
    $previewToken = (string) ($st->fetchColumn() ?: '');
} catch (Throwable $e) {
    error_log('proposal_studio: preview token lookup failed: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Studio Proposition | LPC ERP</title>

    <style>
        .pt-field        { border-bottom: 1px solid var(--lpc-border, #e5e7eb); padding: 1rem 0; }
        .pt-field:last-child { border-bottom: 0; }
        .pt-grid         { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 900px) { .pt-grid { grid-template-columns: 1fr; } }
        .pt-input        { width: 100%; border: 1px solid #d1d5db; border-radius: .5rem;
                           padding: .55rem .75rem; font-size: .875rem; color: #111827;
                           background: #fff; transition: border-color .15s, box-shadow .15s; }
        .pt-input:focus  { outline: none; border-color: #005A2B; box-shadow: 0 0 0 3px rgba(0,90,43,.12); }
        .pt-input.dirty  { border-color: #b45309; background: #fffbeb; }
        textarea.pt-input { min-height: 5.5rem; line-height: 1.5; resize: vertical; }
        .pt-lang         { font-size: .625rem; font-weight: 800; letter-spacing: .08em;
                           text-transform: uppercase; color: #9ca3af; margin-bottom: .3rem; display: block; }
        .pt-badge        { font-size: .625rem; font-weight: 700; padding: .1rem .4rem;
                           border-radius: .25rem; background: #fef3c7; color: #92400e; }
        .pt-logo-prev    { height: 2.5rem; width: auto; max-width: 9rem; object-fit: contain;
                           background: #f9fafb; border: 1px solid #e5e7eb; border-radius: .375rem; padding: .25rem; }
        .pt-panel[hidden] { display: none; }
        .pt-savebar      { position: sticky; bottom: 0; z-index: 20; }

        /* Character counter. Three states, because "you are over" is only
           useful if "you are near" came first. */
        .pt-count        { display: block; text-align: right; font-size: .6875rem;
                           font-variant-numeric: tabular-nums; color: #9ca3af;
                           margin-top: .25rem; }
        .pt-count.warn   { color: #b45309; font-weight: 700; }
        .pt-count.over   { color: #b91c1c; font-weight: 800; }
        .pt-count.under  { color: #6b7280; }
        .pt-input.over   { border-color: #b91c1c; background: #fef2f2; }

        /* Image row. These widths were Tailwind arbitrary values
           (min-w-[16rem]); those are compiled at build time and were never in
           assets/css/tailwind.css, so they silently did nothing and the row
           collapsed. Scoped CSS instead — see README §5.5. */
        .pt-imgrow       { display: flex; align-items: flex-end; gap: 1rem; flex-wrap: wrap; }
        .pt-imgrow-path  { flex: 1 1 18rem; min-width: 14rem; }
        .pt-imgrow-alt   { flex: 1 1 10rem; min-width: 9rem; }
        .pt-upload-btn   { display: inline-flex; align-items: center; gap: .45rem;
                           cursor: pointer; white-space: nowrap;
                           padding: .55rem 1rem; border-radius: .5rem;
                           border: 1px solid #005A2B; color: #005A2B;
                           background: #fff; font-size: .8125rem; font-weight: 700;
                           transition: background .15s, color .15s; }
        .pt-upload-btn:hover { background: #005A2B; color: #fff; }
        .pt-upload-btn:focus-within { outline: 2px solid #005A2B; outline-offset: 2px; }
        .pt-upload-name  { font-size: .6875rem; color: #6b7280; margin-top: .3rem; }
    </style>

    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

<?php
$pageTitle    = 'Studio Proposition';
$pageSubtitle = 'Modèle de proposition commerciale';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>

<div id="lpc-shell-main">

    <div class="lpc-toolbar">
        <a href="/modules/crm/clients.php"
           class="lpc-toolbar-lead flex items-center gap-2 text-sm font-bold text-gray-600 hover:text-lpc-dark transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Base Clients
        </a>

        <span class="lpc-toolbar-sep"></span>

        <button type="button" id="pt-save"
                class="bg-lpc-dark hover:bg-[#004722] text-white px-5 py-2.5 rounded-xl font-bold shadow-md transition-all flex items-center gap-2 disabled:opacity-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            Enregistrer
        </button>

        <span id="pt-dirty-count" class="text-xs font-bold text-amber-700"></span>

        <span class="lpc-toolbar-sep"></span>

        <a href="<?= $previewToken !== ''
                     ? '/quote.php?token=' . htmlspecialchars($previewToken, ENT_QUOTES, 'UTF-8')
                     : '#' ?>"
           id="pt-preview" target="_blank" rel="noopener"
           <?= $previewToken === '' ? 'aria-disabled="true" title="Aucun devis existant à prévisualiser"' : '' ?>
           class="text-sm font-bold <?= $previewToken === '' ? 'text-gray-300 pointer-events-none' : 'text-lpc-dark hover:underline' ?> flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            Aperçu
        </a>

        <button type="button" id="pt-reset-all"
                class="text-sm font-bold text-red-600 hover:text-red-700 transition-colors">
            Tout réinitialiser
        </button>

        <?php
        // Help for this page. Empty string until migration 033 seeds the
        // Studio articles, so this line is safe to ship first.
        echo lpc_help_link('crm.proposal_studio', $lang);
        ?>
    </div>

    <nav class="lpc-tabs" role="tablist" aria-label="Sections du modèle">
        <?php $first = true; foreach ($order as $g):
            if (!isset($groups[$g])) continue; ?>
            <button type="button" role="tab"
                    class="pt-tab px-4 py-2 text-sm font-bold border-b-2 <?= $first ? 'text-lpc-dark' : 'border-transparent text-gray-500 hover:text-gray-800' ?>"
                    data-tab="<?= htmlspecialchars($g, ENT_QUOTES, 'UTF-8') ?>"
                    aria-selected="<?= $first ? 'true' : 'false' ?>"
                    aria-controls="panel-<?= htmlspecialchars($g, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($labels[$g][$lang] ?? $g, ENT_QUOTES, 'UTF-8') ?>
                <span class="ml-1 text-[10px] font-normal text-gray-400">(<?= count($groups[$g]) ?>)</span>
            </button>
        <?php $first = false; endforeach; ?>
    </nav>

    <main role="main" id="main" class="lpc-page">

        <?php if ($needsMigration): ?>
            <div role="alert" class="bg-amber-50 border border-amber-300 text-amber-900 rounded-xl p-5 mb-6">
                <p class="font-bold mb-1">Modèle non initialisé</p>
                <p class="text-sm">La table <code>proposal_template_settings</code> est vide ou absente.
                   Exécutez <code>migrations/030_proposal_template.sql</code> via
                   <code>scripts/deploy.sh</code>. En attendant, la proposition
                   continue de s'afficher avec ses valeurs d'origine.</p>
            </div>
        <?php endif; ?>

        <div class="bg-white border border-lpc-border rounded-2xl shadow-sm p-5 mb-6">
            <p class="text-sm text-gray-600 leading-relaxed">
                Ces valeurs alimentent la proposition commerciale envoyée aux clients
                (<code>quote.php</code>) ainsi que son export PDF. Les deux langues sont
                affichées côte à côte : un champ modifié dans une langue doit l'être dans
                l'autre. Les champs jamais modifiés conservent leur valeur d'origine.
            </p>
        </div>

        <?php $first = true; foreach ($order as $g):
            if (!isset($groups[$g])) continue; ?>
            <section class="pt-panel bg-white border border-lpc-border rounded-2xl shadow-sm p-6"
                     id="panel-<?= htmlspecialchars($g, ENT_QUOTES, 'UTF-8') ?>"
                     role="tabpanel" <?= $first ? '' : 'hidden' ?>>

                <h2 class="text-lg font-black text-gray-900 mb-1">
                    <?= htmlspecialchars($labels[$g][$lang] ?? $g, ENT_QUOTES, 'UTF-8') ?>
                </h2>
                <p class="text-xs text-gray-400 mb-4">
                    <?= count($groups[$g]) ?> champ(s) · les modifications ne sont
                    appliquées qu'après « Enregistrer »
                </p>

                <?php foreach ($groups[$g] as $f):
                    $k    = $f['key'];
                    $kind = $f['kind'];
                    $id   = 'f_' . preg_replace('/[^a-z0-9_]/', '', $k);
                ?>
                <div class="pt-field" data-key="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="flex items-start justify-between gap-4 mb-2">
                        <div>
                            <label for="<?= $id ?>_fr" class="text-sm font-bold text-gray-900">
                                <?= htmlspecialchars($f['label_fr'], ENT_QUOTES, 'UTF-8') ?>
                            </label>
                            <?php if (!$f['is_default']): ?>
                                <span class="pt-badge ml-2">modifié</span>
                            <?php endif; ?>
                            <?php if (!empty($f['help_fr'])): ?>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    <?= htmlspecialchars($f['help_fr'], ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <button type="button"
                                class="pt-reset text-[11px] font-bold text-gray-400 hover:text-red-600 shrink-0 transition-colors"
                                data-key="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>"
                                title="Restaurer la valeur d'origine">Réinitialiser</button>
                    </div>

                    <?php if ($kind === 'image'): ?>
                        <div class="pt-imgrow">
                            <img class="pt-logo-prev" id="<?= $id ?>_prev"
                                 src="<?= htmlspecialchars($f['value_fr'] ?: '/assets/img/full_logo.svg', ENT_QUOTES, 'UTF-8') ?>"
                                 alt="">
                            <div class="pt-imgrow-path">
                                <label class="pt-lang" for="<?= $id ?>_fr">Chemin de l'image</label>
                                <input type="text" class="pt-input pt-val" id="<?= $id ?>_fr" data-lang="fr"
                                       maxlength="<?= (int) $f['max_fr'] ?>"
                                       data-min="<?= (int) $f['min_fr'] ?>" data-max="<?= (int) $f['max_fr'] ?>"
                                       value="<?= htmlspecialchars($f['value_fr'], ENT_QUOTES, 'UTF-8') ?>">
                                <p class="text-[11px] text-gray-400 mt-1">
                                    Vider ce champ masque l'élément.
                                </p>
                            </div>
                            <div class="pt-imgrow-alt">
                                <label class="pt-lang" for="<?= $id ?>_en">Texte alternatif</label>
                                <input type="text" class="pt-input pt-val" id="<?= $id ?>_en" data-lang="en"
                                       maxlength="<?= (int) $f['max_en'] ?>"
                                       data-min="<?= (int) $f['min_en'] ?>" data-max="<?= (int) $f['max_en'] ?>"
                                       value="<?= htmlspecialchars($f['value_en'], ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div>
                                <label class="pt-upload-btn">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4"/>
                                    </svg>
                                    Choisir une image…
                                    <input type="file" class="pt-upload" accept="image/png,image/jpeg,image/webp"
                                           style="position:absolute; width:1px; height:1px; opacity:0; overflow:hidden;"
                                           data-key="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>">
                                </label>
                                <p class="pt-upload-name" id="<?= $id ?>_fname">PNG, JPEG ou WebP — 2 Mo max</p>
                            </div>
                        </div>

                    <?php else: $tag = $kind === 'textarea' ? 'textarea' : 'input'; ?>
                        <div class="pt-grid">
                            <div>
                                <label class="pt-lang" for="<?= $id ?>_fr">Français</label>
                                <?php if ($tag === 'textarea'): ?>
                                    <textarea class="pt-input pt-val" id="<?= $id ?>_fr" data-lang="fr" rows="4"
                                              maxlength="<?= (int) $f['max_fr'] ?>"
                                              data-min="<?= (int) $f['min_fr'] ?>" data-max="<?= (int) $f['max_fr'] ?>"><?= htmlspecialchars($f['value_fr'], ENT_QUOTES, 'UTF-8') ?></textarea>
                                <?php else: ?>
                                    <input type="text" class="pt-input pt-val" id="<?= $id ?>_fr" data-lang="fr"
                                           maxlength="<?= (int) $f['max_fr'] ?>"
                                           data-min="<?= (int) $f['min_fr'] ?>" data-max="<?= (int) $f['max_fr'] ?>"
                                           value="<?= htmlspecialchars($f['value_fr'], ENT_QUOTES, 'UTF-8') ?>">
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="pt-lang" for="<?= $id ?>_en">English</label>
                                <?php if ($tag === 'textarea'): ?>
                                    <textarea class="pt-input pt-val" id="<?= $id ?>_en" data-lang="en" rows="4"
                                              maxlength="<?= (int) $f['max_en'] ?>"
                                              data-min="<?= (int) $f['min_en'] ?>" data-max="<?= (int) $f['max_en'] ?>"><?= htmlspecialchars($f['value_en'], ENT_QUOTES, 'UTF-8') ?></textarea>
                                <?php else: ?>
                                    <input type="text" class="pt-input pt-val" id="<?= $id ?>_en" data-lang="en"
                                           maxlength="<?= (int) $f['max_en'] ?>"
                                           data-min="<?= (int) $f['min_en'] ?>" data-max="<?= (int) $f['max_en'] ?>"
                                           value="<?= htmlspecialchars($f['value_en'], ENT_QUOTES, 'UTF-8') ?>">
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </section>
        <?php $first = false; endforeach; ?>

    </main>
</div>

<script type="application/json" id="lpc-page-data">
<?= json_encode([
    'csrf'      => Csrf::token(),
    'csrfField' => '_csrf',
    'endpoint'  => '/api/v1/proposal_template_controller.php',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
 | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
</script>
<script src="<?= lpc_asset('/assets/js/modules/crm-proposal_studio.js') ?>" defer></script>
</body>
</html>
