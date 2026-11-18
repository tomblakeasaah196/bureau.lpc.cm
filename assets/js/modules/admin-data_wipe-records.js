/**
 * assets/js/modules/admin-data_wipe-records.js
 * -----------------------------------------------------------------------------
 * The data wipe screen's tabs, and its "Un par un" tab (modules/admin/data_wipe.php):
 * find records by number or name → choose, for each staff account, whether its
 * records pass to another account or go too → live plan → code + reason → delete.
 * Talks to api/v1/data_wipe_controller.php (records_* actions) with the page's
 * key and CSRF token. The "Tables entières" tab keeps its own script.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var cfg = JSON.parse(document.getElementById('dw-data').textContent);
    var els = {
        tabs: Array.prototype.slice.call(document.querySelectorAll('.dw-tab')),
        kind: document.getElementById('dr-kind'),
        q: document.getElementById('dr-q'),
        results: document.getElementById('dr-results'),
        picked: document.getElementById('dr-picked'),
        none: document.getElementById('dr-none'),
        plan: document.getElementById('dr-plan'),
        send: document.getElementById('dr-send'),
        code: document.getElementById('dr-code'),
        reason: document.getElementById('dr-reason'),
        run: document.getElementById('dr-run'),
        msg: document.getElementById('dr-msg')
    };
    var state = { picked: [], plan: null, codeFor: null, busy: false, planTimer: null, findTimer: null, planSeq: 0, findSeq: 0 };

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

    // ---- Tabs ------------------------------------------------------------------
    function showTab(tab, focus) {
        els.tabs.forEach(function (t) {
            var on = t === tab;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
        });
        if (focus) { tab.focus(); }
        try { history.replaceState(null, '', tab.id === 'dw-tab-records' ? '#un-par-un' : tab.id === 'dw-tab-full' ? '#remise-a-zero' : location.pathname + location.search); } catch (e) { /* stays on the tab anyway */ }
    }
    els.tabs.forEach(function (tab, i) {
        tab.addEventListener('click', function () { showTab(tab, false); });
        tab.addEventListener('keydown', function (ev) {
            var to = ev.key === 'ArrowRight' ? i + 1 : ev.key === 'ArrowLeft' ? i - 1 : ev.key === 'Home' ? 0 : ev.key === 'End' ? els.tabs.length - 1 : null;
            if (to === null) { return; }
            ev.preventDefault();
            showTab(els.tabs[(to + els.tabs.length) % els.tabs.length], true);
        });
    });
    if (location.hash === '#un-par-un') { showTab(document.getElementById('dw-tab-records'), false); }
    if (location.hash === '#remise-a-zero') { showTab(document.getElementById('dw-tab-full'), false); }

    // ---- 1. Find and pick ----------------------------------------------------------
    function key(r) { return r.table + '#' + r.id; }
    function isPicked(r) { return state.picked.some(function (p) { return key(p) === key(r); }); }

    function find() {
        clearTimeout(state.findTimer);
        state.findTimer = setTimeout(function () {
            var seq = ++state.findSeq;
            els.results.innerHTML = '<li class="dw-sub" style="display:block;border:0;padding:0">Recherche…</li>';
            post({ action: 'records_search', table: els.kind.value, q: els.q.value }).then(function (r) {
                if (seq !== state.findSeq) { return; }
                if (r.status !== 'success') {
                    els.results.innerHTML = '<li class="dw-alert is-danger" style="display:block">' + esc(r.message || 'Erreur.') + '</li>';
                    return;
                }
                renderResults(r.results);
            });
        }, 250);
    }
    function renderResults(list) {
        state.results = list;
        if (!list.length) {
            els.results.innerHTML = '<li class="dw-sub" style="display:block;border:0;padding:0">Rien ne correspond. Essayez une partie du numéro ou du nom.</li>';
            return;
        }
        els.results.innerHTML = list.map(function (r, i) {
            var action = r.refused
                ? ''
                : isPicked(r) ? '<span class="dw-sub">Choisi</span>'
                    : '<button type="button" class="dr-btn" data-add="' + i + '" aria-label="Ajouter ' + esc(r.label) + '">Ajouter</button>';
            return '<li><span class="dr-name">' + esc(r.label)
                + (r.sub ? '<span class="dr-meta">' + esc(r.sub) + '</span>' : '')
                + (r.refused ? '<span class="dr-refused">' + esc(r.refused) + '</span>' : '')
                + '</span>' + action + '</li>';
        }).join('');
    }
    els.kind.addEventListener('change', find);
    els.q.addEventListener('input', find);
    els.results.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-add]');
        if (!b) { return; }
        var r = state.results[Number(b.dataset.add)];
        var item = { table: r.table, id: r.id, label: r.label, sub: r.sub, kind: cfg.one[r.table] || els.kind.options[els.kind.selectedIndex].text };
        if (r.table === 'users') { item.mode = 'handover'; item.to = 0; }
        state.picked.push(item);
        renderResults(state.results);
        renderPicked();
        schedulePlan();
    });

    function targetOptions(item) {
        var taken = {};
        state.picked.forEach(function (p) { if (p.table === 'users') { taken[p.id] = true; } });
        return '<option value="0">Choisissez le compte qui reprend…</option>' + cfg.targets.filter(function (t) {
            return !taken[t.id];
        }).map(function (t) {
            return '<option value="' + t.id + '"' + (Number(item.to) === Number(t.id) ? ' selected' : '') + '>'
                + esc(t.name) + (t.matricule ? ' (' + esc(t.matricule) + ')' : '') + '</option>';
        }).join('');
    }
    function renderPicked() {
        els.none.hidden = state.picked.length > 0;
        els.picked.innerHTML = state.picked.map(function (p, i) {
            var staff = '';
            if (p.table === 'users') {
                var name = 'dr-mode-' + p.id;
                staff = '<fieldset class="dr-staff"><legend>Ses enregistrements (documents créés, livraisons, encaissements saisis…)</legend>'
                    + '<label><input type="radio" name="' + name + '" value="handover" data-mode="' + i + '"' + (p.mode === 'handover' ? ' checked' : '') + '> Passer à un autre compte</label>'
                    + (p.mode === 'handover' ? '<select data-to="' + i + '" aria-label="Compte qui reprend les enregistrements de ' + esc(p.label) + '">' + targetOptions(p) + '</select>' : '')
                    + '<label><input type="radio" name="' + name + '" value="wipe" data-mode="' + i + '"' + (p.mode === 'wipe' ? ' checked' : '') + '> Les effacer aussi</label>'
                    + '</fieldset>';
            }
            return '<li class="dr-item"><span class="dr-name">' + esc(p.kind) + ' : ' + esc(p.label)
                + (p.sub ? '<span class="dr-meta">' + esc(p.sub) + '</span>' : '') + '</span>'
                + '<button type="button" class="dr-btn" data-remove="' + i + '" aria-label="Retirer ' + esc(p.label) + '">Retirer</button>' + staff + '</li>';
        }).join('');
    }
    els.picked.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-remove]');
        if (!b) { return; }
        state.picked.splice(Number(b.dataset.remove), 1);
        renderPicked();
        if (state.results) { renderResults(state.results); }
        schedulePlan();
    });
    els.picked.addEventListener('change', function (ev) {
        var t = ev.target;
        if (t.dataset.mode !== undefined) {
            state.picked[Number(t.dataset.mode)].mode = t.value;
            renderPicked();
        } else if (t.dataset.to !== undefined) {
            state.picked[Number(t.dataset.to)].to = Number(t.value);
        }
        schedulePlan();
    });

    // ---- 2. Plan -------------------------------------------------------------------
    function records() {
        return state.picked.map(function (p) {
            return p.table === 'users' ? { table: p.table, id: p.id, mode: p.mode, to: p.mode === 'handover' ? p.to : null } : { table: p.table, id: p.id };
        });
    }
    // What a code is tied to: the records and the choices made for accounts
    function pickKey() {
        return records().map(function (r) { return r.table + '#' + r.id + (r.mode ? ':' + r.mode + '>' + (r.to || '') : ''); }).sort().join(',');
    }
    function schedulePlan() {
        clearTimeout(state.planTimer);
        state.planTimer = setTimeout(loadPlan, 250);
    }
    function loadPlan() {
        var seq = ++state.planSeq;
        if (!state.picked.length) {
            state.plan = null;
            els.plan.innerHTML = '<p class="dw-sub">Choisissez au moins un enregistrement.</p>';
            updateButtons();
            return;
        }
        els.plan.innerHTML = '<p class="dw-sub">Calcul de tout ce qui en dépend…</p>';
        post({ action: 'records_plan', records: records() }).then(function (r) {
            if (seq !== state.planSeq) { return; }
            if (r.status !== 'success') {
                state.plan = null;
                els.plan.innerHTML = '<div class="dw-alert is-danger">' + esc(r.message || 'Erreur.') + '</div>';
            } else {
                state.plan = r.plan;
                renderPlan(r.plan);
            }
            if (state.codeFor && state.codeFor !== pickKey()) {
                say('info', 'Le choix a changé depuis l\'envoi du code : demandez-en un nouveau pour celui-ci.');
            }
            updateButtons();
        });
    }
    function list(title, items) {
        return items.length ? '<div class="dw-h3">' + title + '</div><ul class="dw-list">' + items.join('') + '</ul>' : '';
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
        html += list('Numérotation', p.numbering.map(function (n) {
            return '<li><span class="dr-note">' + esc(n.text) + '</span></li>';
        }));
        html += list('Passés à un autre compte', p.reassigned.map(function (r) {
            return '<li><span>' + esc(r.label) + ' <span class="dw-muted">(' + esc(r.column) + ' → ' + esc(r.to || 'compte à choisir') + ')</span></span><span class="dw-n">' + num(r.rows) + '</span></li>';
        }));
        html += list('Boîtes mail personnelles retirées de bureau', p.mailboxes.map(function (m) {
            return '<li><span>' + esc(m.email) + ' <span class="dw-muted">(' + esc(m.account) + ' : ' + num(m.messages)
                + ' message(s) copiés dans bureau, qui restent sur le serveur mail ; la copie de sécurité ne les garde pas)</span></span></li>';
        }));
        html += list('Gardés, mais détachés de ce qui part', p.unlinked.map(function (u) {
            return '<li><span>' + esc(u.label) + ' <span class="dw-muted">(' + esc(u.column) + ' vidé)</span></span><span class="dw-n">' + num(u.rows) + '</span></li>';
        }));
        var b = p.balances || {};
        var moves = function (title, rows, dec) {
            return list(title, (rows || []).map(function (x) {
                return '<li><span>' + esc(x.name) + '</span><span class="dw-n">' + num(x.before, dec) + ' → ' + num(x.after, dec) + '</span></li>';
            }));
        };
        html += moves('Trésorerie (solde avant → après)', b.treasury, 0);
        html += moves('Avoirs clients (avant → après)', b.wallets, 0);
        html += moves('Stock (quantité avant → après)', b.stock, 0);
        var other = [];
        if (b.invoices) { other.push(num(b.invoices) + ' facture(s) gardée(s) changent de statut de paiement'); }
        if (b.orders) { other.push(num(b.orders) + ' commande(s) : statut de paiement recalculé'); }
        if (b.empties) { other.push(num(b.empties) + ' compte(s) d\'emballages client recalculé(s)'); }
        if (b.cump) { other.push(num(b.cump) + ' produit(s) sans réception restante : CUMP remis à 0'); }
        html += list('Recalculé aussi', other.map(function (o) { return '<li>' + esc(o) + '</li>'; }));
        els.plan.innerHTML = html;
    }

    // ---- 3. Confirm ----------------------------------------------------------------
    function ready() {
        // No MD to tell (matricule LPC-MD-01 with an email): nothing can be sent or deleted
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
        var k = pickKey();
        post({ action: 'records_send_code', records: records() }).then(function (r) {
            state.busy = false;
            if (r.status === 'success') {
                state.codeFor = k;
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
        var names = state.picked.map(function (p) { return p.label; }).join(', ');
        if (!window.confirm('Effacer définitivement ' + names + ', avec ' + num(state.plan.total) + ' ligne(s) en tout ?\n\nUne copie est gardée ' + cfg.keep + ' jours, puis supprimée. Le DG sera prévenu.')) {
            return;
        }
        state.busy = true;
        updateButtons();
        say('info', 'Copie puis effacement en cours… Ne fermez pas la page.');
        post({ action: 'records_run', records: records(), code: els.code.value.trim(), reason: els.reason.value.trim() }).then(function (r) {
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
                + (w.numbering && w.numbering.length ? '<br>' + w.numbering.map(function (n) { return esc(n.text); }).join('<br>') : '')
                + (w.warnings && w.warnings.length ? '<br>À vérifier : ' + w.warnings.map(esc).join(' ; ') : '')
                + '<br><a href="#past" onclick="location.hash=\'un-par-un\';location.reload()">Voir l\'historique et la copie</a>');
            els.code.value = '';
            els.reason.value = '';
            state.codeFor = null;
            state.picked = [];
            renderPicked();
            loadPlan();
            find();
        });
    });

    renderPicked();
    if (els.kind.options.length) { find(); }
})();
