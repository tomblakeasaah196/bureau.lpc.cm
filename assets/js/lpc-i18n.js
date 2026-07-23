/**
 * assets/js/lpc-i18n.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — client-side translator.
 *
 * The server injects window.LPC.i18n = { lang, strings } BEFORE this file
 * loads (head_assets.php renders it inline). This helper exposes:
 *
 *   LPC.t(key, params?)          → translated string
 *   LPC.lang                     → active language ('fr' | 'en')
 *   LPC.setLang(lang)            → persist a new choice server-side and reload
 *   LPC.i18n.strings             → the raw dictionary (read-only)
 *
 * PARAM SUBSTITUTION:
 *   Any {name} token in a dictionary string is substituted from
 *   params[name] when supplied.
 *
 *     LPC.t('sales.orders.deleted_n', {n: 3})
 *     // dictionary: "sales.orders.deleted_n" → "{n} commandes supprimées"
 *     // returns:    "3 commandes supprimées"
 *
 * MISSING KEYS:
 *   If the key isn't in the current-lang dictionary, we fall back to the
 *   FR dictionary if we happen to have it in memory, otherwise we return
 *   the key literal — so a missing translation is visible in the UI
 *   instead of silent.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';
    if (!window.LPC) window.LPC = {};
    if (window.LPC.__i18nLoaded) return;
    window.LPC.__i18nLoaded = true;

    // Read the bootstrap payload from the inert JSON block (CSP-safe).
    // Falls back to window.LPC.i18n if the page was rendered from an older
    // template that still injects the data inline.
    let payload;
    try {
        const el = document.getElementById('lpc-bootstrap-data');
        if (el) {
            const parsed = JSON.parse(el.textContent || '{}');
            payload = { lang: parsed.lang || 'fr', strings: (parsed.i18n && parsed.i18n.strings) || {} };
        }
    } catch (e) { /* fall through */ }
    if (!payload) payload = window.LPC.i18n || { lang: 'fr', strings: {} };
    // Freeze the dictionary — callers should not mutate.
    if (Object.freeze) Object.freeze(payload.strings);

    window.LPC.lang = payload.lang || 'fr';

    function substitute(str, params) {
        if (!params) return str;
        return str.replace(/\{(\w+)\}/g, function (_, k) {
            return (params[k] === undefined || params[k] === null) ? '{' + k + '}' : String(params[k]);
        });
    }

    /**
     * Translate a dotted key.
     * @param {string} key
     * @param {object} [params]
     * @returns {string}
     */
    function t(key, params) {
        if (typeof key !== 'string' || !key) return '';
        const strs = payload.strings || {};
        let str = strs[key];
        if (str === undefined || str === null) {
            // No fallback dict on the client (payload contains one lang).
            // Return the key literal so gaps stand out.
            str = key;
        }
        return substitute(str, params);
    }

    /**
     * Persist a new UI language server-side and reload.
     * @param {string} lang
     */
    function setLang(lang) {
        if (lang !== 'fr' && lang !== 'en') return;
        // Bounce through the same URL with ?lang=… — the server updates
        // both $_SESSION['lang'] and the lpc_lang cookie.
        const u = new URL(window.location.href);
        u.searchParams.set('lang', lang);
        window.location.replace(u.toString());
    }

    window.LPC.t = t;
    window.LPC.setLang = setLang;
})();
