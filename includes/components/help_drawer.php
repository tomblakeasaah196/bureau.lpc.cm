<?php
/**
 * includes/components/help_drawer.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7G · the slide-over help panel.
 *
 * An EMPTY shell. The markup here is chrome only; the article itself is fetched
 * from api/v1/help_controller.php when the user actually asks for help, so the
 * ~40 KB of prose that documents a page is not paid for by every page load of
 * that page.
 *
 * WHY A DRAWER AND NOT A NAVIGATION
 * ---------------------------------
 * The pages that most need help are the ones with long forms — a new client, a
 * devis. Sending the user to /modules/help/article.php to read how a field
 * works would discard everything they had typed. The drawer keeps the work on
 * screen behind it, and offers "open full article" for people who want the
 * whole thing.
 *
 * Included once by topbar.php, so every shell page has it and no page has two.
 * Requires bootstrap + includes/functions/help.php.
 * -----------------------------------------------------------------------------
 */
if (!defined('LPC_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../bootstrap.php';
}
require_once __DIR__ . '/../functions/help.php';

// Before migration 032 there is nothing to show. Emit nothing at all rather
// than a drawer that can only ever report an error.
if (!lpc_help_ready()) { return; }

$__hLang = lpc_help_lang($lang ?? null);
$__hEn   = $__hLang === 'en';

$__hStrings = [
    'eyebrow'   => $__hEn ? 'Help centre'          : "Centre d'aide",
    'loading'   => $__hEn ? 'Loading…'             : 'Chargement…',
    'close'     => $__hEn ? 'Close'                : 'Fermer',
    'full'      => $__hEn ? 'Open full article'    : "Ouvrir l'article complet",
    'centre'    => $__hEn ? 'Browse all help'      : "Parcourir toute l'aide",
    'notfound'  => $__hEn ? 'This article is not available.'
                          : "Cet article n'est pas disponible.",
    'error'     => $__hEn ? 'Help could not be loaded. Please try again.'
                          : "L'aide n'a pas pu être chargée. Réessayez.",
    'fallback'  => $__hEn ? 'English version coming soon — showing the French article.'
                          : 'Version française affichée.',
    'seealso'   => $__hEn ? 'See also'             : 'Voir aussi',
];
?>
<div id="lpc-help-drawer" hidden role="dialog" aria-modal="true"
     aria-labelledby="lpc-help-drawer-title">
    <div class="lpc-help-panel" role="document">

        <div class="lpc-help-panel-head">
            <div class="min-w-0 flex-1">
                <p class="lpc-help-panel-eyebrow" id="lpc-help-drawer-eyebrow"><?= htmlspecialchars($__hStrings['eyebrow']) ?></p>
                <h2 class="lpc-help-panel-title" id="lpc-help-drawer-title"><?= htmlspecialchars($__hStrings['loading']) ?></h2>
            </div>
            <button type="button" class="lpc-help-panel-close lpc-focusable"
                    id="lpc-help-drawer-close"
                    title="<?= htmlspecialchars($__hStrings['close']) ?>"
                    aria-label="<?= htmlspecialchars($__hStrings['close']) ?>">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="lpc-help-panel-body" id="lpc-help-drawer-body">
            <div class="lpc-help-skeleton"></div>
            <div class="lpc-help-skeleton"></div>
            <div class="lpc-help-skeleton"></div>
        </div>

        <div class="lpc-help-panel-foot">
            <a href="/modules/help/index.php?lang=<?= $__hLang ?>"><?= htmlspecialchars($__hStrings['centre']) ?></a>
            <a id="lpc-help-drawer-full" href="/modules/help/index.php?lang=<?= $__hLang ?>"><?= htmlspecialchars($__hStrings['full']) ?> →</a>
        </div>
    </div>
</div>

<script type="application/json" id="lpc-help-data"><?= json_encode(
    ['lang' => $__hLang, 'strings' => $__hStrings],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?></script>
<script src="<?= lpc_asset('/assets/js/lpc-help.js') ?>" defer></script>
<?php
// Unset only this component's own temporaries — an include shares the caller's
// scope, and a generic name here would clobber the including page's variable.
unset($__hLang, $__hEn, $__hStrings);
?>
