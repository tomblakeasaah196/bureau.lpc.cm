/**
 * assets/js/modules/admin-data_wipe.js
 * -----------------------------------------------------------------------------
 * The data wipe screen (modules/admin/data_wipe.php): ticks → live plan of
 * everything that goes and the balances after → code + reason → wipe.
 * Talks to api/v1/data_wipe_controller.php with the page's key and CSRF token.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var cfg = JSON.parse(document.getElementById('dw-data').textContent);
    var els = {
        modules: document.getElementById('dw-modules'),
        plan: document.getElementById('dw-plan'),
        send: document.getElementById('dw-send'),
        code: document.getElementById('dw-code'),
        reason: document.getElementById('dw-reason'),
        run: document.getElementById('dw-run'),
        msg: document.getElementById('dw-msg')
    };
    var state = { plan: null, codeFor: null, busy: false, timer: null, seq: 0 };

    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function num(n, dec) {
        return Number(n || 0).toLocaleString('fr-FR', { minimumFractionDigits: dec || 0, maximumFractionDigits: dec || 0 });
    }
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
    function picked() {
        return Array.prototype.map.call(els.modules.querySelectorAll('input[data-table]:checked'), function (i) { return i.dataset.table; }).sort();
    }
    function pickKey() { return picked().join(','); }

    // ---- 1. Modules ----------------------------------------------------------
    function renderModules(modules) {
        var html = '';
        Object.keys(modules).forEach(function (key) {
            var m = modules[key];
            var total = 0;
            var rows = Object.keys(m.tables).map(function (t) {
                var info = m.tables[t];
                total += info.rows;
                return '<label class="dw-row' + (info.rows ? '' : ' is-empty') + '">'
                    + '<input type="checkbox" class="dw-check" data-table="' + esc(t) + '" data-module="' + esc(key) + '">'
                    + '<span>' + esc(info.label) + '</span><span class="dw-n">' + num(info.rows) + '</span></label>';
            }).join('');
            html += '<details class="dw-mod"' + (total ? ' open' : '') + '><summary>'
                + '<input type="checkbox" class="dw-check" data-all="' + esc(key) + '" aria-label="Tout ' + esc(m.label) + '">'
                + '<span>' + esc(m.label) + '</span><span class="dw-n">' + num(total) + ' ligne(s)</span></summary>'
                + '<div class="dw-rows">' + rows + '</div></details>';
        });
        els.modules.innerHTML = html || '<p class="dw-sub">Aucune table à effacer.</p>';
    }

    els.modules.addEventListener('click', function (ev) {
        // The module's own box ticks without opening or closing the module
        if (ev.target.matches('input[data-all]')) { ev.stopPropagation(); }
    });
    els.modules.addEventListener('change', function (ev) {
        var t = ev.target;
        if (t.dataset.all) {
            els.modules.querySelectorAll('input[data-module="' + t.dataset.all + '"]').forEach(function (i) { i.checked = t.checked; });
        }
        syncModuleBoxes();
        schedulePlan();
    });
    function syncModuleBoxes() {
        els.modules.querySelectorAll('input[data-all]').forEach(function (box) {
            var all = els.modules.querySelectorAll('input[data-module="' + box.dataset.all + '"]');
            var on = Array.prototype.filter.call(all, function (i) { return i.checked; }).length;
            box.checked = on > 0 && on === all.length;
            box.indeterminate = on > 0 && on < all.length;
        });
    }

    // ---- 2. Plan -------------------------------------------------------------
    function schedulePlan() {
        clearTimeout(state.timer);
        state.timer = setTimeout(loadPlan, 250);
    }
    function loadPlan() {
        var tables = picked();
        var seq = ++state.seq;
        if (!tables.length) {
            state.plan = null;
            els.plan.innerHTML = '<p class="dw-sub">Cochez au moins une table.</p>';
            updateButtons();
            return;
        }
        els.plan.innerHTML = '<p class="dw-sub">Calcul de tout ce qui en dépend…</p>';
        post({ action: 'plan', tables: tables }).then(function (r) {
            if (seq !== state.seq) { return; }
            if (r.status !== 'success') {
                state.plan = null;
                els.plan.innerHTML = '<div class="dw-alert is-danger">' + esc(r.message || 'Erreur.') + '</div>';
            } else {
                state.plan = r.plan;
                renderPlan(r.plan);
            }
            if (state.codeFor && state.codeFor !== pickKey()) {
                say('info', 'La sélection a changé depuis l\'envoi du code : demandez-en un nouveau pour celle-ci.');
            }
            updateButtons();
        });
    }
    function renderPlan(p) {
        var html = '<div class="dw-total">' + num(p.total) + ' ligne(s)</div>'
            + '<div class="dw-sub">dans ' + p.tables.length + ' table(s), copiées avant d\'être effacées</div>';
        if (p.blocked.length) {
            html += '<div class="dw-alert is-danger"><strong>Impossible tel quel.</strong><br>' + p.blocked.map(esc).join('<br>') + '</div>';
        }
        html += '<ul class="dw-list">' + p.tables.map(function (t) {
            return '<li><span>' + esc(t.label)
                + (t.picked ? '' : '<span class="dw-why" title="' + esc(t.why) + '">ajouté : ' + esc(t.why) + '</span>')
                + '</span><span class="dw-n">' + num(t.rows) + '</span></li>';
        }).join('') + '</ul>';
        if (p.unlinked.length) {
            html += '<div class="dw-h3">Gardés, mais détachés de ce qui part</div><ul class="dw-list">' + p.unlinked.map(function (u) {
                return '<li><span>' + esc(u.label) + ' <span class="dw-muted">(' + esc(u.column) + ' vidé)</span></span><span class="dw-n">' + num(u.rows) + '</span></li>';
            }).join('') + '</ul>';
        }
        var b = p.balances || {};
        var moves = function (title, list, dec) {
            if (!list || !list.length) { return ''; }
            return '<div class="dw-h3">' + title + '</div><ul class="dw-list">' + list.map(function (x) {
                return '<li><span>' + esc(x.name) + '</span><span class="dw-n">' + num(x.before, dec) + ' → ' + num(x.after, dec) + '</span></li>';
            }).join('') + '</ul>';
        };
        html += moves('Trésorerie (solde avant → après)', b.treasury, 0);
        html += moves('Avoirs clients (avant → après)', b.wallets, 0);
        html += moves('Stock (quantité avant → après)', b.stock, 0);
        var other = [];
        if (b.invoices) { other.push(num(b.invoices) + ' facture(s) gardée(s) changent de statut de paiement'); }
        if (b.orders) { other.push(num(b.orders) + ' commande(s) : statut de paiement recalculé'); }
        if (b.empties) { other.push(num(b.empties) + ' compte(s) d\'emballages client recalculé(s)'); }
        if (b.cump) { other.push(num(b.cump) + ' produit(s) sans réception restante : CUMP remis à 0'); }
        if (other.length) {
            html += '<div class="dw-h3">Recalculé aussi</div><ul class="dw-list">' + other.map(function (o) { return '<li>' + esc(o) + '</li>'; }).join('') + '</ul>';
        }
        els.plan.innerHTML = html;
    }

    // ---- 3. Confirm ----------------------------------------------------------
    function ready() {
        // No MD to tell (matricule LPC-MD-01 with an email): nothing can be sent or wiped
        return cfg.md && state.plan && state.plan.total > 0 && !state.plan.blocked.length && !state.busy;
    }
    function updateButtons() {
        els.send.disabled = !ready();
        var codeOk = /^\d{6}$/.test(els.code.value.trim());
        var reasonOk = els.reason.value.trim().length >= 10;
        els.run.disabled = !(ready() && codeOk && reasonOk && state.codeFor === pickKey());
        els.run.textContent = state.plan && state.plan.total
            ? 'Effacer définitivement ' + num(state.plan.total) + ' ligne(s)' : 'Effacer définitivement';
    }
    els.code.addEventListener('input', updateButtons);
    els.reason.addEventListener('input', updateButtons);

    els.send.addEventListener('click', function () {
        if (!ready()) { return; }
        state.busy = true;
        updateButtons();
        var key = pickKey();
        post({ action: 'send_code', tables: picked() }).then(function (r) {
            state.busy = false;
            if (r.status === 'success') {
                state.codeFor = key;
                say('ok', esc(r.message));
                els.code.focus();
            } else {
                say('danger', esc(r.message || 'Erreur.'));
            }
            updateButtons();
        });
    });

    els.run.addEventListener('click', function () {
        if (els.run.disabled) { return; }
        var total = state.plan.total;
        if (!window.confirm('Effacer définitivement ' + num(total) + ' ligne(s) ?\n\nUne copie est gardée ' + cfg.keep + ' jours, puis supprimée. Le DG sera prévenu.')) {
            return;
        }
        state.busy = true;
        updateButtons();
        say('info', 'Copie puis effacement en cours… Ne fermez pas la page.');
        post({ action: 'run', tables: picked(), code: els.code.value.trim(), reason: els.reason.value.trim() }).then(function (r) {
            state.busy = false;
            if (r.status !== 'success') {
                say('danger', esc(r.message || 'Erreur : rien n\'a été effacé.'));
                updateButtons();
                return;
            }
            var w = r.wipe;
            var md = w.md && w.md.notified
                ? 'Le DG (' + esc(w.md.name) + ') a été prévenu' + (w.md.emailed ? ' par email et dans ses notifications.' : ' dans ses notifications (l\'email n\'est pas parti).')
                : 'Le DG n\'a pas pu être prévenu' + (w.md && w.md.error ? ' : ' + esc(w.md.error) : '.');
            say('ok', '<strong>' + num(w.total) + ' ligne(s) effacée(s).</strong> La copie est gardée jusqu\'au '
                + esc(new Date(w.expires.replace(' ', 'T')).toLocaleDateString('fr-FR')) + '. ' + md
                + (w.warnings && w.warnings.length ? '<br>À vérifier : ' + w.warnings.map(esc).join(' ; ') : '')
                + '<br><a href="#past" onclick="location.reload()">Voir l\'historique et la copie</a>');
            els.code.value = '';
            els.reason.value = '';
            state.codeFor = null;
            loadOverview();
        });
    });

    function loadOverview() {
        post({ action: 'overview' }).then(function (r) {
            if (r.status !== 'success') {
                els.modules.innerHTML = '<div class="dw-alert is-danger">' + esc(r.message || 'Erreur.') + '</div>';
                return;
            }
            renderModules(r.modules);
            state.plan = null;
            els.plan.innerHTML = '<p class="dw-sub">Cochez au moins une table.</p>';
            updateButtons();
        });
    }
    loadOverview();
})();
