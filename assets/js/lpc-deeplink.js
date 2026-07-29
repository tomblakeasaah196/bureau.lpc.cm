/**
 * assets/js/lpc-deeplink.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7F · cross-page deep links and the return chip.
 *
 * A page reached from somewhere else carries three query params:
 *
 *   ?client_id=12&client=Societe%20Generale&from=crm_clients
 *
 *   · client_id / client — what to filter to. The name travels alongside the id
 *     so a page can seed a text filter without a second round-trip just to
 *     resolve a name it is about to display anyway.
 *   · from — where to offer to go back to.
 *
 * Two things this deliberately does NOT do:
 *
 *   1. `from` is a KEY into a whitelist, never a URL. Accepting a URL here —
 *      even a relative one — turns every page into an open redirector: send
 *      someone a link with ?from=//evil.example and the app itself renders the
 *      button that takes them there. The whitelist means an unknown key simply
 *      renders no chip.
 *   2. It does not filter anything itself. Each page knows how its own list is
 *      built; this file just exposes what was asked for and lets the page
 *      apply it. Trying to filter generically from here would mean this file
 *      knowing the internals of every page that uses it.
 *
 * Exposes: window.LPC.deeplink = { clientId, clientName, from, hasFilter() }
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    window.LPC = window.LPC || {};

    var params = new URLSearchParams(window.location.search);

    // Whitelist of return destinations. Add a page here to make it linkable-back-to.
    var ORIGINS = {
        crm_clients: { href: '/modules/crm/clients.php', label: 'Base Clients' },
        crm_studio:  { href: '/modules/crm/proposal_studio.php', label: 'Studio Proposition' },
        sales:       { href: '/modules/sales/orders.php', label: 'Ventes & Commandes' },
        dash_sales:  { href: '/modules/dashboard/views/sales_dashboard.php', label: 'Tableau de Bord Ventes' },
        invoices:    { href: '/modules/accounting/invoices.php', label: 'Facturation & AR' },
        empties:     { href: '/modules/operations/empties_collection.php', label: 'Gestion des Vides' }
    };

    var rawId = params.get('client_id');
    var clientId = /^\d+$/.test(rawId || '') ? parseInt(rawId, 10) : null;

    var deeplink = {
        clientId:   clientId,
        clientName: params.get('client') || '',
        from:       params.get('from') || '',
        hasFilter:  function () { return clientId !== null; }
    };

    window.LPC.deeplink = deeplink;

    /**
     * Render the return chip into the page's toolbar.
     *
     * The chip is prepended and marked .lpc-toolbar-lead so it sits at the far
     * left, away from the page's own action buttons — a navigation control and
     * a destructive action should never end up adjacent by accident.
     */
    function renderChip() {
        var origin = ORIGINS[deeplink.from];
        if (!origin) return;                       // unknown or absent → no chip

        var toolbar = document.querySelector('.lpc-toolbar');
        if (!toolbar || toolbar.querySelector('.lpc-back-chip')) return;

        var a = document.createElement('a');
        a.className = 'lpc-back-chip lpc-toolbar-lead lpc-focusable';
        a.href = origin.href;
        a.setAttribute('aria-label', 'Retour à ' + origin.label);

        a.innerHTML =
            '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
            'stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" ' +
            'd="M15 19l-7-7 7-7"/></svg><span></span>';
        // Label via textContent — origin.label is from our own whitelist, but
        // building it into the innerHTML string above would set the precedent.
        a.querySelector('span').textContent = 'Retour à ' + origin.label;

        toolbar.insertBefore(a, toolbar.firstChild);
    }

    /**
     * Render the active client filter as a removable chip.
     * `onClear` lets the page undo its own filtering; the URL is rewritten with
     * replaceState so a refresh doesn't silently reapply a filter the person
     * just dismissed.
     */
    function renderFilterChip(onClear) {
        if (!deeplink.hasFilter()) return;

        var toolbar = document.querySelector('.lpc-toolbar');
        if (!toolbar || toolbar.querySelector('.lpc-filter-chip')) return;

        var wrap = document.createElement('span');
        wrap.className = 'lpc-filter-chip';

        var label = document.createElement('span');
        label.textContent = 'Client : ' + (deeplink.clientName || ('#' + deeplink.clientId));
        wrap.appendChild(label);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Retirer le filtre client');
        btn.textContent = '×';
        btn.addEventListener('click', function () {
            deeplink.clientId = null;
            deeplink.clientName = '';
            wrap.remove();

            var url = new URL(window.location.href);
            url.searchParams.delete('client_id');
            url.searchParams.delete('client');
            window.history.replaceState({}, '', url);

            if (typeof onClear === 'function') onClear();
        });
        wrap.appendChild(btn);

        var back = toolbar.querySelector('.lpc-back-chip');
        if (back && back.nextSibling) toolbar.insertBefore(wrap, back.nextSibling);
        else toolbar.insertBefore(wrap, toolbar.firstChild);
    }

    deeplink.renderFilterChip = renderFilterChip;

    /**
     * Hide rows in a tbody that don't mention the deep-linked client.
     *
     * Uses a MutationObserver rather than "filter once after load" because
     * every list on these pages is fetched asynchronously and several reload
     * themselves after an action. Filtering once would work on first paint and
     * then silently stop applying — the user would think the filter had been
     * cleared. The observer re-applies on every re-render, and disconnects
     * when the filter is dismissed.
     *
     * Matching is on visible row text. That is deliberately loose: these
     * tables render the client name but not always its id, and a substring
     * match on the name is what the pages' own search boxes already do.
     *
     * @returns {function} teardown — call it when the filter chip is cleared.
     */
    function filterRowsByClient(tbodySelector) {
        var needle = (deeplink.clientName || '').trim().toLowerCase();
        if (!needle) return function () {};

        var tbody = document.querySelector(tbodySelector);
        if (!tbody) return function () {};

        var applying = false;

        function apply() {
            if (applying) return;          // our own style writes re-trigger the observer
            applying = true;
            Array.prototype.forEach.call(tbody.rows, function (tr) {
                var hit = tr.innerText.toLowerCase().indexOf(needle) !== -1;
                tr.style.display = hit ? '' : 'none';
            });
            applying = false;
        }

        var observer = new MutationObserver(apply);
        observer.observe(tbody, { childList: true, subtree: true });
        apply();

        return function teardown() {
            observer.disconnect();
            Array.prototype.forEach.call(tbody.rows, function (tr) { tr.style.display = ''; });
        };
    }

    deeplink.filterRowsByClient = filterRowsByClient;

    /**
     * The whole arrival experience for a destination page, in one call:
     * render the chips and filter the listed tables.
     *
     *   LPC.deeplink.arrive(['#table-body-invoices', '#table-body-payments']);
     */
    deeplink.arrive = function (tbodySelectors) {
        if (!deeplink.hasFilter()) return;

        var teardowns = (tbodySelectors || []).map(filterRowsByClient);
        renderFilterChip(function () {
            teardowns.forEach(function (fn) { fn(); });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderChip);
    } else {
        renderChip();
    }
})();
