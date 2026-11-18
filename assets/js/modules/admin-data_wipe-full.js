/**
 * assets/js/modules/admin-data_wipe-full.js
 * -----------------------------------------------------------------------------
 * « Remise à zéro avant démarrage » (modules/admin/data_wipe.php, third tab;
 * the owner's decisions 16 and 17, 18/11/2026): two choices (keep the products,
 * keep the suppliers) → the plan of everything that goes → code + reason → wipe.
 * Same safeguards as the other tabs: the code is tied to the choices shown.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var cfg = JSON.parse(document.getElementById('dw-data').textContent);
    var els = {
        tab: document.getElementById('dw-tab-full'),
        products: document.getElementById('df-keep-products'),
        suppliers: document.getElementById('df-keep-suppliers'),
        plan: document.getElementById('df-plan'),
        send: document.getElementById('df-send'),
        code: document.getElementById('df-code'),
        reason: document.getElementById('df-reason'),
        run: document.getElementById('df-run'),
        msg: document.getElementById('df-msg')
    };
    if (!els.tab) { return; }
    var state = { plan: null, codeFor: null, busy: false, seq: 0, loaded: false };

    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function num(n) { return Number(n || 0).toLocaleString('fr-FR'); }
    function post(body) {
        return fetch(cfg.api, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return { status: 'error', message: 'Réponse illisible du serveur (' + r.status + ').' }; });
        });
    }
    function say(kind, html) {
        els.msg.innerHTML = html ? '<div class="dw-alert is-' + kind + '">' + html + '</div>' : '';
    }
    function choices() {
        return { keep_products: els.products.checked, keep_suppliers: els.suppliers.checked };
    }
    function key() { return (els.products.checked ? 'P' : 'p') + (els.suppliers.checked ? 'S' : 's'); }

    function loadPlan() {
        var seq = ++state.seq;
        els.plan.innerHTML = '<p class="dw-sub">Calcul de tout ce qui part…</p>';
        post(Object.assign({ action: 'full_plan' }, choices())).then(function (r) {
            if (seq !== state.seq) { return; }
            if (r.status !== 'success') {
                state.plan = null;
                els.plan.innerHTML = '<div class="dw-alert is-danger">' + esc(r.message || 'Erreur.') + '</div>';
            } else {
                state.plan = r.plan;
                render(r.plan);
            }
            if (state.codeFor && state.codeFor !== key()) {
                say('info', 'Les choix ont changé depuis l\'envoi du code : demandez-en un nouveau.');
            }
            update();
        });
    }
    function render(p) {
        var html = '<div class="dw-total">' + num(p.total) + ' ligne(s)</div>'
            + '<div class="dw-sub">dans ' + p.tables.length + ' table(s), copiées avant d\'être effacées</div>';
        if (p.blocked.length) {
            html += '<div class="dw-alert is-danger"><strong>Impossible tel quel.</strong><br>' + p.blocked.map(esc).join('<br>') + '</div>';
        }
        html += '<ul class="dw-list">' + p.tables.map(function (t) {
            return '<li><span>' + esc(t.label) + '</span><span class="dw-n">' + num(t.rows) + '</span></li>';
        }).join('') + '</ul>';
        if (p.unlinked.length) {
            html += '<div class="dw-h3">Gardés, mais détachés de ce qui part</div><ul class="dw-list">' + p.unlinked.map(function (u) {
                return '<li><span>' + esc(u.label) + ' <span class="dw-muted">(' + esc(u.column) + ' vidé)</span></span><span class="dw-n">' + num(u.rows) + '</span></li>';
            }).join('') + '</ul>';
        }
        html += '<div class="dw-h3">Ensuite</div><ul class="dw-list"><li>Chaque numérotation (devis, commandes, BL, factures, achats, dépenses, emprunts…) repart à 00001.</li>'
            + '<li>Les comptes de trésorerie sont à recréer, avec leur solde réel : chacun reçoit son sous-compte.</li></ul>';
        els.plan.innerHTML = html;
    }

    function ready() { return cfg.md && state.plan && state.plan.total > 0 && !state.plan.blocked.length && !state.busy; }
    function update() {
        els.send.disabled = !ready();
        var codeOk = /^\d{6}$/.test(els.code.value.trim());
        var reasonOk = els.reason.value.trim().length >= 10;
        els.run.disabled = !(ready() && codeOk && reasonOk && state.codeFor === key());
        els.run.textContent = state.plan && state.plan.total ? 'Tout effacer : ' + num(state.plan.total) + ' ligne(s)' : 'Tout effacer';
    }
    els.code.addEventListener('input', update);
    els.reason.addEventListener('input', update);
    els.products.addEventListener('change', loadPlan);
    els.suppliers.addEventListener('change', loadPlan);
    // The plan is read when the tab is opened, not on every visit to the page
    function onShow() {
        if (!els.tab.getAttribute('aria-selected') || els.tab.getAttribute('aria-selected') !== 'true' || state.loaded) { return; }
        state.loaded = true;
        loadPlan();
    }
    new MutationObserver(onShow).observe(els.tab, { attributes: true, attributeFilter: ['aria-selected'] });
    onShow();

    els.send.addEventListener('click', function () {
        if (!ready()) { return; }
        state.busy = true;
        update();
        var k = key();
        post(Object.assign({ action: 'full_send_code' }, choices())).then(function (r) {
            state.busy = false;
            if (r.status === 'success') {
                state.codeFor = k;
                say('ok', esc(r.message));
                els.code.focus();
            } else {
                say('danger', esc(r.message || 'Erreur.'));
            }
            update();
        });
    });

    els.run.addEventListener('click', function () {
        if (els.run.disabled) { return; }
        var total = state.plan.total;
        LPC.modal.confirm('Effacer définitivement ' + num(total) + ' ligne(s) : toutes les données de test, sauf ce qui est gardé ?\n\n'
            + 'Une copie est gardée ' + cfg.keep + ' jours, puis supprimée. Le DG sera prévenu.', { danger: true, confirmLabel: 'Tout effacer' })
        .then(function (ok) {
            if (!ok) { return; }
            state.busy = true;
            update();
            say('info', 'Copie puis effacement en cours… Ne fermez pas la page.');
            post(Object.assign({ action: 'full_run', code: els.code.value.trim(), reason: els.reason.value.trim() }, choices())).then(function (r) {
                state.busy = false;
                if (r.status !== 'success') {
                    say('danger', esc(r.message || 'Erreur : rien n\'a été effacé.'));
                    update();
                    return;
                }
                var w = r.wipe;
                var md = w.md && w.md.notified
                    ? 'Le DG (' + esc(w.md.name) + ') a été prévenu' + (w.md.emailed ? ' par email et dans ses notifications.' : ' dans ses notifications (l\'email n\'est pas parti).')
                    : 'Le DG n\'a pas pu être prévenu' + (w.md && w.md.error ? ' : ' + esc(w.md.error) : '.');
                say('ok', '<strong>Remise à zéro faite : ' + num(w.total) + ' ligne(s) effacée(s).</strong> La copie est gardée jusqu\'au '
                    + esc(new Date(w.expires.replace(' ', 'T')).toLocaleDateString('fr-FR')) + '. ' + md
                    + (w.warnings && w.warnings.length ? '<br>À vérifier : ' + w.warnings.map(esc).join(' ; ') : '')
                    + '<br><a href="#past" onclick="location.reload()">Voir l\'historique et la copie</a>');
                els.code.value = '';
                els.reason.value = '';
                state.codeFor = null;
                loadPlan();
            });
        });
    });
})();
