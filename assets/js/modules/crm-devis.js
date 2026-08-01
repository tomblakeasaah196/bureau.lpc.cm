/**
 * assets/js/modules/crm-devis.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the "Devis" tab on modules/crm/clients.php.
 *
 * Backs the quote history that, until this file, existed nowhere in the UI:
 * create_proposal.php handed you a link once and that was the only time you
 * ever saw it. Reads /api/v1/fetch_proposals.php (paged, 25/rows, filters
 * applied in SQL) and /api/v1/proposal_detail.php (one devis + signature).
 *
 * Loaded AFTER crm-clients.js on purpose: openProposalModal(), addProposalRow()
 * and closeModal() live there, and the "duplicate a signed devis" flow calls
 * straight into the existing create modal rather than reimplementing it.
 *
 * ── The one rule that matters ─────────────────────────────────────────────
 * A signed devis is never editable. That is enforced by update_proposal.php
 * inside its transaction; everything this file does with `editable` is
 * presentation. Do not "fix" a locked modal by flipping the flag here — the
 * server will refuse the save and the user will have retyped a devis for
 * nothing.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    // A user without crm.proposals.view never receives the tab markup at all
    // (clients.php renders it inside a permission check), so this file is a
    // no-op rather than an error on their page.
    if (!document.getElementById('tab-panel-devis')) return;

    var H = window.LPC.html;
    var F = window.LPC.fmt;

    // -------------------------------------------------------------------------
    // State. Filters live here rather than being re-read from the DOM on every
    // request so the pager and the filters can't disagree about what page 3 of
    // what query means.
    // -------------------------------------------------------------------------
    var state = {
        search: '',
        status: 'all',
        from:   '',
        to:     '',
        rep:    '',
        sort:   'date',
        dir:    'desc',
        page:   1,
        pages:  1,
        loaded: false      // first load is lazy — see switchCrmTab()
    };

    var currentDevis = null;   // the devis open in the modal, as returned by detail

    // =========================================================================
    // TABS
    // =========================================================================

    /**
     * Switch between the client base and the devis répertoire.
     *
     * The devis list is loaded LAZILY, on first switch, not at DOMContentLoaded.
     * Most visits to this page are about clients; firing a second paginated
     * query plus a KPI aggregate on every one of them would slow the page for
     * everybody to save one click for some.
     */
    window.switchCrmTab = function (tab) {
        var isDevis = tab === 'devis';

        var pc = document.getElementById('tab-panel-clients');
        var pd = document.getElementById('tab-panel-devis');
        var bc = document.getElementById('tab-btn-clients');
        var bd = document.getElementById('tab-btn-devis');
        if (!pc || !pd) return;

        pc.classList.toggle('hidden', isDevis);
        pd.classList.toggle('hidden', !isDevis);
        if (bc) bc.setAttribute('aria-selected', String(!isDevis));
        if (bd) bd.setAttribute('aria-selected', String(isDevis));

        // Deep-linkable and back-button friendly: reload on #devis and you are
        // still on devis. replaceState, not pushState — switching tabs is not
        // a navigation the user expects Back to undo one step at a time.
        try {
            history.replaceState(null, '', isDevis ? '#devis' : location.pathname);
        } catch (e) { /* file:// or a locked-down browser — cosmetic only */ }

        if (isDevis && !state.loaded) {
            state.loaded = true;
            loadReps();
            loadDevis();
        }
    };

    // =========================================================================
    // LIST
    // =========================================================================

    function query() {
        var p = new URLSearchParams();
        if (state.search) p.set('search', state.search);
        if (state.status !== 'all') p.set('status', state.status);
        if (state.from) p.set('from', state.from);
        if (state.to)   p.set('to',   state.to);
        if (state.rep)  p.set('rep',  state.rep);
        p.set('sort', state.sort);
        p.set('dir',  state.dir);
        p.set('page', String(state.page));
        return p.toString();
    }

    async function loadDevis() {
        var tbody = document.getElementById('devis-tbody');
        if (!tbody) return;

        try {
            var res  = await fetch('/api/v1/fetch_proposals.php?' + query());
            var json = await res.json();

            if (json.status !== 'success') {
                tbody.innerHTML = H`<tr><td colspan="7" class="py-10 text-center text-red-500 text-sm">${json.message || 'Erreur de chargement.'}</td></tr>`;
                return;
            }

            state.page  = json.page;
            state.pages = json.pages;

            renderKpis(json.kpis);
            renderRows(json.data);
            renderPager(json);

            var count = document.getElementById('tab-devis-count');
            if (count) count.textContent = json.total;

        } catch (e) {
            console.error('loadDevis', e);
            tbody.innerHTML = H`<tr><td colspan="7" class="py-10 text-center text-red-500 text-sm">Impossible de charger les devis.</td></tr>`;
        }
    }

    /** Sales-rep filter options. Fire-and-forget: a failure leaves "Tous". */
    async function loadReps() {
        try {
            var res  = await fetch('/api/v1/fetch_proposals.php?action=reps');
            var json = await res.json();
            if (json.status !== 'success' || !json.data.length) return;

            var sel = document.getElementById('devis-rep');
            if (!sel) return;
            json.data.forEach(function (name) {
                var o = document.createElement('option');
                o.value = name;
                o.textContent = name;
                sel.appendChild(o);
            });
        } catch (e) { /* filter simply stays at "Tous les commerciaux" */ }
    }

    function renderKpis(k) {
        setText('kpi-devis-sent', F.int(k.total_count));
        setText('kpi-devis-sent-sub',
            k.expired_count ? (k.expired_count + ' expiré' + (k.expired_count > 1 ? 's' : '')) : ' ');

        setText('kpi-devis-signed', F.int(k.signed_count));
        // The rate is the number a sales review actually asks for; the raw
        // count alone says nothing without the denominator.
        setText('kpi-devis-signed-sub',
            k.total_count ? (k.signed_rate + ' % · ' + F.fcfa(k.signed_value)) : ' ');

        setText('kpi-devis-pipeline', F.fcfa(k.pipeline_value));
        setText('kpi-devis-pipeline-sub',
            k.pipeline_count ? (k.pipeline_count + ' devis en cours') : 'Aucun devis en cours');
    }

    function setText(id, s) {
        var n = document.getElementById(id);
        if (n) n.textContent = s;
    }

    /**
     * Status badge.
     *
     * "Signé" means the CLIENT signed — that is the commercial event. An
     * internal LPC attestation is shown as a separate "Visé LPC" marker
     * beside the status, never as "Signé": a devis nobody outside the
     * building has agreed to is still in the pipeline, and colouring it green
     * would tell a sales review it had been won.
     *
     * See docs/SIGNATURES.md for the two-party model.
     */
    function badge(row) {
        var visa = row.lpc_signed
            ? H`<span class="devis-badge devis-badge-visa" title="Signé en interne par La Petite Cour — le client n'a pas encore signé">Visé LPC</span>`
            : '';

        if (row.derived_status === 'signed') {
            // Client signed. The internal visa is implied context at that
            // point, so it is not repeated.
            return H`<span class="devis-badge devis-badge-signed">Signé</span>`;
        }
        if (row.derived_status === 'expired') {
            return H`<span class="devis-badge devis-badge-expired">Expiré</span>${visa}`;
        }
        return H`<span class="devis-badge devis-badge-sent">Envoyé</span>${visa}`;
    }

    /**
     * The "Suivi" cell — one column, two meanings, chosen per row.
     *
     * A signed devis is a document you cite: who signed it, when. An unsigned
     * one is a document you chase: whose it is, and how long is left. Giving
     * each its own column would leave whichever half doesn't apply blank on
     * every single row, which is how the answer to "who signed this?" ends up
     * buried three columns to the right of a sea of dashes.
     */
    function followCell(row) {
        if (row.derived_status === 'signed') {
            // signer_name is COALESCE(signatory_name, signer_name) server-side,
            // so this is the client's typed name on an external signature.
            return H`<div class="text-sm font-bold text-gray-800">${row.signer_name || '—'}</div>
                     <div class="text-xs text-gray-500">${row.signed_at ? 'le ' + F.date(row.signed_at) : ''}</div>`;
        }
        var expiry = row.expires_on ? F.date(row.expires_on) : '—';
        var late   = row.derived_status === 'expired';
        // Not client-signed: the rep chases it. If LPC has already visaed it
        // internally, say so here — otherwise "Visé LPC" in the status column
        // has no explanation anywhere on the row.
        var visaLine = row.lpc_signed
            ? H`<div class="text-[11px] text-gray-400 mt-0.5">visé par ${row.lpc_signer_name || 'LPC'}${row.lpc_signed_at ? ' le ' + F.date(row.lpc_signed_at) : ''}</div>`
            : '';
        return H`<div class="text-sm text-gray-700">${row.sales_rep_name || '—'}</div>
                 <div class="text-xs ${late ? 'text-red-600 font-bold' : 'text-gray-500'}">
                    ${late ? 'expiré le ' : "valable jusqu'au "}${expiry}
                 </div>`;
    }

    function renderRows(rows) {
        var tbody = document.getElementById('devis-tbody');
        if (!rows.length) {
            tbody.innerHTML = H`<tr><td colspan="7" class="py-12 text-center text-gray-400 text-sm italic">Aucun devis ne correspond à ces critères.</td></tr>`;
            return;
        }

        tbody.innerHTML = rows.map(function (r) {
            return H`
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="py-4 px-6">
                        <div class="font-extrabold text-gray-900">${r.reference}</div>
                    </td>
                    <td class="py-4 px-6">
                        <div class="text-sm font-bold text-gray-800">${r.client_name}</div>
                        <div class="text-xs text-gray-500">${r.client_contact || ''}</div>
                    </td>
                    <td class="py-4 px-6 text-sm text-gray-600">${F.date(r.date)}</td>
                    <td class="py-4 px-6 text-right text-sm font-bold text-gray-900">${F.fcfa(r.total_amount)}</td>
                    <td class="py-4 px-6">${LPC.raw(badge(r))}</td>
                    <td class="py-4 px-6">${LPC.raw(followCell(r))}</td>
                    <td class="py-4 px-6 text-right">
                        <button type="button" data-devis-open="${r.id}"
                                class="bg-lpc-light hover:bg-green-600 text-white px-3 py-1.5 rounded-lg text-xs font-bold shadow-sm transition-colors">
                            Détails
                        </button>
                    </td>
                </tr>`;
        }).join('');
    }

    function renderPager(json) {
        var label = document.getElementById('devis-pager-label');
        var prev  = document.getElementById('devis-prev');
        var next  = document.getElementById('devis-next');

        if (label) {
            var first = json.total ? ((json.page - 1) * json.per_page + 1) : 0;
            var last  = Math.min(json.page * json.per_page, json.total);
            label.textContent = json.total
                ? (first + '–' + last + ' sur ' + json.total + ' devis')
                : 'Aucun devis';
        }
        if (prev) prev.disabled = json.page <= 1;
        if (next) next.disabled = json.page >= json.pages;
    }

    window.devisPage = function (delta) {
        var target = state.page + delta;
        if (target < 1 || target > state.pages) return;
        state.page = target;
        loadDevis();
        // The table can be taller than the viewport; without this the user
        // lands on page 2 already scrolled past its first rows.
        document.getElementById('tab-panel-devis').scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    window.resetDevisFilters = function () {
        state.search = ''; state.status = 'all'; state.from = ''; state.to = '';
        state.rep = ''; state.sort = 'date'; state.dir = 'desc'; state.page = 1;

        val('devis-search', ''); val('devis-from', ''); val('devis-to', '');
        val('devis-rep', ''); val('devis-sort', 'date:desc');
        chips('all');
        loadDevis();
    };

    function val(id, v) { var n = document.getElementById(id); if (n) n.value = v; }

    function chips(active) {
        document.querySelectorAll('#devis-status-chips .devis-chip').forEach(function (b) {
            b.classList.toggle('is-active', b.dataset.status === active);
        });
    }

    // =========================================================================
    // DETAIL MODAL
    // =========================================================================

    async function openDevis(id) {
        var body    = document.getElementById('devis-modal-body');
        var actions = document.getElementById('devis-modal-actions');
        var share   = document.getElementById('devis-modal-share');

        body.innerHTML  = H`<p class="text-sm text-gray-400 text-center py-10">Chargement…</p>`;
        actions.innerHTML = '';
        share.innerHTML   = '';
        openModal('devisModal');

        try {
            var res  = await fetch('/api/v1/proposal_detail.php?id=' + encodeURIComponent(id));
            var json = await res.json();

            if (json.status !== 'success') {
                body.innerHTML = H`<p class="text-sm text-red-500 text-center py-10">${json.message || 'Devis introuvable.'}</p>`;
                return;
            }

            currentDevis = json;
            renderDetail(json);

        } catch (e) {
            console.error('openDevis', e);
            body.innerHTML = H`<p class="text-sm text-red-500 text-center py-10">Erreur de chargement.</p>`;
        }
    }

    function renderDetail(d) {
        var p = d.data;

        setText('devis-modal-ref', p.reference);
        setText('devis-modal-sub',
            p.client_name + (p.client_contact ? ' · ' + p.client_contact : '') +
            ' · ' + F.date(p.date));

        // Stale gets its own badge rather than reusing "Signé": the signature
        // exists but no longer matches the figures, which is a discrepancy to
        // investigate, not a clean sign-off.
        var badgeEl = document.getElementById('devis-modal-badge');
        badgeEl.innerHTML = d.stale
            ? H`<span class="devis-badge devis-badge-stale">Signé · modifié depuis</span>`
            : badge({ derived_status: d.derived_status });

        document.getElementById('devis-modal-body').innerHTML =
            (d.signature ? signatureBlock(d) : '') +
            (d.editable ? editableTerms(p) : readonlyTerms(p)) +
            linesBlock(d);

        if (d.editable) {
            mountLineEditors(d);
        }

        renderFooter(d);
    }

    /**
     * Signature proof. The verify URL is the public, token-based one that the
     * printed PDF's QR code points at — same evidence a client holding the
     * document can check, which is the point: an internal screen that showed
     * different proof from the external one would be worth nothing in a
     * dispute.
     */
    function signatureBlock(d) {
        var s = d.signature;
        return H`
            <div class="mb-6 rounded-xl border ${d.stale ? 'border-amber-200 bg-amber-50' : 'border-green-200 bg-green-50'} p-4">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <p class="text-xs font-black uppercase tracking-wider ${d.stale ? 'text-amber-700' : 'text-green-700'}">
                            ${d.stale ? 'Signature ne correspondant plus au document' : 'Devis signé'}
                        </p>
                        <p class="text-sm font-bold text-gray-900 mt-1">${s.signer_name}</p>
                        <p class="text-xs text-gray-600">${s.signer_role} · ${F.dateTime(s.signed_at)}</p>
                        ${d.stale ? LPC.raw(H`<p class="text-xs text-amber-800 mt-2 max-w-md">
                            Ce devis a été modifié après signature. La signature reste enregistrée mais ne
                            certifie plus les chiffres affichés. Créez un nouveau devis, ou révoquez la
                            signature depuis la page du devis.
                        </p>`) : ''}
                    </div>
                    <div class="text-right">
                        <a href="${s.verify_url}" target="_blank" rel="noopener"
                           class="text-xs font-bold text-lpc-dark hover:underline">Vérifier ↗</a>
                        <p class="text-[10px] text-gray-400 font-mono mt-1">${s.hash_prefix}…</p>
                    </div>
                </div>
            </div>`;
    }

    function termRow(label, value) {
        return H`<div>
            <p class="text-[10px] font-black text-gray-400 uppercase tracking-wider">${label}</p>
            <p class="text-sm font-bold text-gray-800 mt-0.5">${value || '—'}</p>
        </div>`;
    }

    function readonlyTerms(p) {
        return H`
            <div class="mb-6">
                <h4 class="text-xs font-black text-gray-400 uppercase tracking-widest mb-3 border-b border-gray-200 pb-2">Conditions</h4>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                    ${LPC.raw(termRow('Validité', p.validity_days + ' jours'))}
                    ${LPC.raw(termRow('Expire le', F.date(p.expires_on)))}
                    ${LPC.raw(termRow('Livraison', p.delivery_frequency))}
                    ${LPC.raw(termRow('Stock tampon', p.buffer_stock_weeks + ' semaines'))}
                    ${LPC.raw(termRow('Paiement', p.payment_terms))}
                    ${LPC.raw(termRow('Emballages', p.empties_policy))}
                    ${LPC.raw(termRow('Commercial', p.sales_rep_name))}
                    ${LPC.raw(termRow('Langue', p.language === 'en' ? 'English' : 'Français'))}
                    ${LPC.raw(termRow('Modifié le', p.updated_at ? F.dateTime(p.updated_at) : 'jamais'))}
                </div>
            </div>`;
    }

    function editableTerms(p) {
        var sel = function (id, value, opts) {
            return H`<select id="${id}" class="w-full bg-white border border-gray-300 rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none font-medium">
                ${LPC.raw(opts.map(function (o) {
                    return H`<option value="${o}" ${String(o) === String(value) ? LPC.raw('selected') : ''}>${o}</option>`;
                }).join(''))}
            </select>`;
        };

        return H`
            <div class="mb-6">
                <h4 class="text-xs font-black text-gray-400 uppercase tracking-widest mb-3 border-b border-gray-200 pb-2">Conditions</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Validité (jours)</label>
                        ${LPC.raw(sel('edit_validity', p.validity_days, [15, 30, 60]))}
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Fréquence de livraison</label>
                        ${LPC.raw(sel('edit_frequency', p.delivery_frequency, ['Hebdomadaire', 'Bi-Mensuel', 'Mensuel', 'Sur Demande']))}
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Stock tampon (semaines)</label>
                        <input type="number" id="edit_buffer" min="0" value="${p.buffer_stock_weeks}"
                               class="w-full bg-white border border-gray-300 rounded-xl px-3 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Conditions de paiement</label>
                        ${LPC.raw(sel('edit_payment', p.payment_terms, ['Paiement à la Livraison', 'Net 15 Jours', 'Net 30 Jours']))}
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Politique des emballages</label>
                        ${LPC.raw(sel('edit_empties', p.empties_policy, [
                            'Échange 1-pour-1 (100%)',
                            'Retour Minimum Exigé (90%)',
                            'Retour Minimum Exigé (80%)',
                            'Retour Minimum Exigé (70%)',
                            'Consigne Payée (Facturée)'
                        ]))}
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Langue du document</label>
                        ${LPC.raw(sel('edit_language', p.language === 'en' ? 'English (EN)' : 'Français (FR)',
                                      ['Français (FR)', 'English (EN)']))}
                    </div>
                </div>
                <!-- The client is NOT editable. proposals stores a snapshot of
                     who the devis was addressed to, and rewriting it would
                     change a document already in someone's inbox. Re-addressing
                     means a new devis. -->
                <p class="text-[11px] text-gray-400 mt-3">
                    Le client (${p.client_name}) et la référence ne sont pas modifiables — créez un nouveau devis pour les changer.
                </p>
            </div>`;
    }

    /**
     * Lines.
     *
     * Editable devis get a picker per line, stamped from the SAME
     * #proposal-row-template the create modal uses, so the two paths cannot
     * drift. Lines with no product_id (devis written before migration 056)
     * cannot be preselected in a picker and are rendered as the frozen text
     * they are — kept, removable, but not re-priceable.
     */
    function linesBlock(d) {
        var editable = d.editable;

        var rows = d.items.map(function (it, i) {
            if (!editable) {
                return H`<tr>
                    <td class="py-2.5 px-4">
                        <div class="text-sm font-bold text-gray-800">${it.product_description}</div>
                        <div class="text-xs text-gray-500">${[it.product_format, it.pack_note].filter(Boolean).join(' · ')}</div>
                    </td>
                    <td class="py-2.5 px-4 text-center text-sm font-bold">${F.int(it.quantity)}</td>
                    <td class="py-2.5 px-4 text-right text-sm">${F.fcfa(it.unit_price)}</td>
                    <td class="py-2.5 px-4 text-right text-sm font-bold">${F.fcfa(it.total_price)}</td>
                </tr>`;
            }

            if (it.locked) {
                return H`<tr class="devis-line-locked item-row" data-locked="1"
                             data-desc="${it.product_description}"
                             data-format="${it.product_format || ''}"
                             data-pack="${it.pack_note || ''}">
                    <td class="py-2.5 px-4">
                        <div class="text-sm font-bold">${it.product_description}</div>
                        <div class="text-[11px] italic">Devis antérieur — produit non modifiable</div>
                    </td>
                    <td class="p-2">
                        <input type="number" class="line-qty w-full border border-gray-300 rounded-lg p-2 text-sm text-center font-bold" value="${it.quantity}" min="1">
                    </td>
                    <td class="p-2">
                        <input type="number" class="line-price w-full border border-gray-300 rounded-lg p-2 text-sm text-right font-bold" value="${it.unit_price}" min="0">
                    </td>
                    <td class="p-2 text-center">
                        <button type="button" data-devis-rmline class="text-red-300 hover:text-red-500 p-1" aria-label="Supprimer la ligne">✕</button>
                    </td>
                </tr>`;
            }

            return H`<tr class="item-row" data-product="${it.product_id}">
                <td class="p-2 align-top"><select class="line-prod" data-lpc-product-picker="sell" aria-label="Produit"></select></td>
                <td class="p-2 align-top">
                    <input type="number" class="line-qty w-full border border-gray-300 rounded-lg p-2 text-sm text-center font-bold" value="${it.quantity}" min="1">
                </td>
                <td class="p-2 align-top">
                    <input type="number" class="line-price w-full border border-gray-300 rounded-lg p-2 text-sm text-right font-bold" value="${it.unit_price}" min="0">
                </td>
                <td class="p-2 text-center align-top">
                    <button type="button" data-devis-rmline class="text-red-300 hover:text-red-500 p-1" aria-label="Supprimer la ligne">✕</button>
                </td>
            </tr>`;
        }).join('');

        return H`
            <div>
                <div class="flex justify-between items-end mb-3 border-b border-gray-200 pb-2">
                    <h4 class="text-xs font-black text-gray-400 uppercase tracking-widest">Offre commerciale</h4>
                    ${editable ? LPC.raw(H`<button type="button" data-devis-addline
                        class="text-lpc-light hover:text-green-600 text-xs font-bold uppercase tracking-wide transition-colors">+ Ajouter une ligne</button>`) : ''}
                </div>
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm">
                    <table class="w-full text-left">
                        <thead class="bg-gray-50 border-b border-gray-200 text-xs uppercase text-gray-500 font-bold">
                            <tr>
                                <th class="py-3 px-4 w-1/2">Produit</th>
                                <th class="py-3 px-4 w-24 text-center">Qté</th>
                                <th class="py-3 px-4 text-right">Prix unitaire</th>
                                <th class="py-3 px-4 ${editable ? 'w-10' : 'text-right'}">${editable ? '' : 'Total'}</th>
                            </tr>
                        </thead>
                        <tbody id="devis-lines" class="divide-y divide-gray-100">${LPC.raw(rows)}</tbody>
                        ${editable ? '' : LPC.raw(H`<tfoot class="bg-gray-50 border-t border-gray-200">
                            <tr>
                                <td colspan="3" class="py-3 px-4 text-right text-xs font-black uppercase text-gray-500">Total</td>
                                <td class="py-3 px-4 text-right text-base font-black text-gray-900">${F.fcfa(d.data.total_amount)}</td>
                            </tr>
                        </tfoot>`)}
                    </table>
                </div>
                ${editable ? LPC.raw(H`<p class="text-right text-sm font-black text-gray-900 mt-3">
                    Total : <span id="devis-edit-total">${F.fcfa(d.data.total_amount)}</span>
                </p>`) : ''}
            </div>`;
    }

    /**
     * Preselect the picker on every editable line.
     *
     * The <select data-lpc-product-picker> is upgraded asynchronously by
     * lpc-product-picker.js's MutationObserver, so __lpcPicker does not exist
     * the instant the HTML lands. setValue() is safe to call before the
     * catalogue has loaded (it stashes the id in _pending), but the PICKER
     * object still has to exist first — hence the deferred pass rather than a
     * synchronous one.
     */
    function mountLineEditors(d) {
        requestAnimationFrame(function () {
            document.querySelectorAll('#devis-lines .item-row[data-product]').forEach(function (row) {
                var sel = row.querySelector('.line-prod');
                var id  = row.dataset.product;
                if (!sel || !id) return;
                var picker = sel.__lpcPicker;
                if (picker) {
                    picker.setValue(id, { silent: true });
                } else {
                    // Observer hasn't reached it yet. One retry on the next
                    // frame is enough in practice; a spin loop here would burn
                    // CPU on a devis whose product was deleted.
                    requestAnimationFrame(function () {
                        if (sel.__lpcPicker) sel.__lpcPicker.setValue(id, { silent: true });
                    });
                }
            });
            recalcTotal();
        });
    }

    function recalcTotal() {
        var total = 0;
        document.querySelectorAll('#devis-lines .item-row').forEach(function (row) {
            var q = Number(row.querySelector('.line-qty') ? row.querySelector('.line-qty').value : 0);
            var p = Number(row.querySelector('.line-price') ? row.querySelector('.line-price').value : 0);
            if (q > 0 && p >= 0) total += q * p;
        });
        var el = document.getElementById('devis-edit-total');
        if (el) el.textContent = F.fcfa(total);
    }

    // =========================================================================
    // FOOTER — the two modes
    // =========================================================================

    function renderFooter(d) {
        var actions = document.getElementById('devis-modal-actions');
        var share   = document.getElementById('devis-modal-share');

        // Re-share is offered in BOTH modes. A signed devis is the one you are
        // most likely to need to send again.
        share.innerHTML = H`
            <button type="button" data-devis-copy
                    class="px-3 py-2 rounded-xl text-xs font-bold text-gray-600 border border-gray-300 hover:bg-gray-100 transition-colors">
                Copier le lien
            </button>
            <a href="${waLink(d)}" target="_blank" rel="noopener"
               class="px-3 py-2 rounded-xl text-xs font-bold text-green-700 border border-green-300 hover:bg-green-50 transition-colors">
                WhatsApp
            </a>
            <a href="${mailLink(d)}"
               class="px-3 py-2 rounded-xl text-xs font-bold text-blue-700 border border-blue-300 hover:bg-blue-50 transition-colors">
                Email
            </a>
            <a href="${d.quote_url}" target="_blank" rel="noopener"
               class="px-3 py-2 rounded-xl text-xs font-bold text-gray-600 border border-gray-300 hover:bg-gray-100 transition-colors">
                Ouvrir ↗
            </a>`;

        if (d.editable) {
            actions.innerHTML = H`
                <button type="button" onclick="closeModal('devisModal')"
                        class="px-5 py-2.5 rounded-xl text-sm font-bold text-gray-600 hover:bg-gray-100 transition-colors">Fermer</button>
                <button type="button" data-devis-save
                        class="px-6 py-2.5 rounded-xl text-sm font-extrabold text-white bg-lpc-light hover:bg-green-600 shadow-lg transition-all">
                    Enregistrer les modifications
                </button>`;
        } else {
            // Locked. The only forward path is a new devis — spelled out,
            // because "why can't I edit this" is the first question a signed
            // devis provokes.
            //
            // The duplicate button is gated on crm.proposals.create, not on
            // the lock: an accountant holds crm.proposals.view and nothing
            // else, so offering them a button that opens a create form
            // create_proposal.php would then refuse is worse than not
            // offering it. Cosmetic gate only — the server still checks.
            var canCreate = window.LPC && typeof LPC.can === 'function' && LPC.can('crm.proposals.create');

            actions.innerHTML = H`
                <span class="text-[11px] text-gray-400 mr-2 hidden md:inline">
                    ${d.signed ? 'Signé — non modifiable' : 'Lecture seule'}
                </span>
                <button type="button" onclick="closeModal('devisModal')"
                        class="px-5 py-2.5 rounded-xl text-sm font-bold text-gray-600 hover:bg-gray-100 transition-colors">Fermer</button>
                ${canCreate ? LPC.raw(H`<button type="button" data-devis-duplicate
                        class="px-6 py-2.5 rounded-xl text-sm font-extrabold text-white bg-lpc-dark hover:bg-[#004722] shadow-lg transition-all">
                    Nouveau devis à partir de celui-ci
                </button>`) : ''}`;
        }
    }

    // Share templates are edited in Studio Proposition and rendered server-side
    // on the quote page; replicating the full {contact}/{reference}/{link}
    // substitution here would mean a second copy of that logic drifting out of
    // sync. The link is what matters — the rep writes their own covering line.
    function waLink(d) {
        var phone = (d.data.client_phone || '').replace(/[^0-9]/g, '');
        var text  = 'Bonjour ' + (d.data.client_contact || '') +
                    ',\n\nVoici le lien vers la proposition commerciale de La Petite Cour (' +
                    d.data.reference + ') :\n' + d.quote_url;
        return 'https://wa.me/' + phone + '?text=' + encodeURIComponent(text);
    }

    function mailLink(d) {
        var subject = 'Proposition Commerciale - La Petite Cour (' + d.data.reference + ')';
        var body    = 'Bonjour ' + (d.data.client_contact || '') +
                      ',\n\nVeuillez trouver notre proposition commerciale via le lien sécurisé ci-dessous :\n\n' +
                      d.quote_url + '\n\nCordialement,\n' + (d.data.sales_rep_name || '');
        return 'mailto:' + (d.data.client_email || '') +
               '?subject=' + encodeURIComponent(subject) +
               '&body='    + encodeURIComponent(body);
    }

    // =========================================================================
    // SAVE / DUPLICATE
    // =========================================================================

    async function saveDevis() {
        if (!currentDevis || !currentDevis.editable) return;

        var items = [];
        document.querySelectorAll('#devis-lines .item-row').forEach(function (row) {
            var qty   = Number(row.querySelector('.line-qty').value);
            var price = Number(row.querySelector('.line-price').value);
            if (!(qty > 0)) return;

            if (row.dataset.locked) {
                // Pre-056 line: no product to send, so the snapshot text goes
                // back as-is. update_proposal.php preserves it verbatim rather
                // than dropping the line.
                items.push({
                    product_id: null,
                    product_description: row.dataset.desc,
                    product_format: row.dataset.format || null,
                    pack_note: row.dataset.pack || null,
                    quantity: qty,
                    unit_price: price
                });
                return;
            }

            var sel = row.querySelector('.line-prod');
            var pid = sel ? (sel.__lpcPicker ? sel.__lpcPicker.getValue() : sel.value) : '';
            // A picker clears its id the moment the typed text stops matching,
            // so a half-typed line is a real possibility. Dropped rather than
            // sent, same as submitProposal() in crm-clients.js.
            if (!pid) return;

            items.push({ product_id: pid, quantity: qty, unit_price: price });
        });

        if (!items.length) {
            LPC.modal.alert('Un devis doit comporter au moins une ligne valide.');
            return;
        }

        var langSel = document.getElementById('edit_language');
        var payload = {
            id: currentDevis.data.id,
            validity_days: document.getElementById('edit_validity').value,
            delivery_frequency: document.getElementById('edit_frequency').value,
            buffer_stock_weeks: document.getElementById('edit_buffer').value,
            payment_terms: document.getElementById('edit_payment').value,
            empties_policy: document.getElementById('edit_empties').value,
            language: (langSel && langSel.value.indexOf('EN') !== -1) ? 'en' : 'fr',
            items: items
        };

        // The link the client holds does not change, and that is worth saying
        // out loud before the save rather than after: anyone who already opened
        // the devis will see the new figures on the same URL.
        var ok = await LPC.modal.confirm(
            'Le lien déjà partagé (' + currentDevis.data.reference + ') affichera immédiatement ces nouveaux chiffres. Enregistrer ?',
            { confirmLabel: 'Enregistrer' }
        );
        if (!ok) return;

        try {
            var res = await fetch('/api/v1/update_proposal.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            var json = await res.json();

            if (json.status === 'success') {
                closeModal('devisModal');
                LPC.toast.success('Devis ' + json.reference + ' mis à jour.');
                loadDevis();
                return;
            }

            // 409: someone signed it between opening the modal and saving.
            // Worth a distinct message — "erreur serveur" would send the user
            // hunting for a bug that isn't one.
            if (json.locked) {
                await LPC.modal.alert(json.message, { title: 'Devis signé' });
                openDevis(payload.id);   // reopen, now correctly read-only
                return;
            }

            LPC.modal.alert(json.message || 'Enregistrement impossible.');

        } catch (e) {
            console.error('saveDevis', e);
            LPC.modal.alert('Erreur réseau. Le devis n\'a pas été modifié.');
        }
    }

    /**
     * Duplicate — the sanctioned way to "change" a signed devis.
     *
     * Reuses the create modal from crm-clients.js rather than posting a copy
     * server-side, so the new devis goes through exactly the same validation,
     * pricing warnings and product picker as any other. The user sees the
     * prefilled form and confirms it; nothing is written until they do.
     */
    function duplicateDevis() {
        var d = currentDevis;
        if (!d) return;

        if (!d.data.client_id) {
            LPC.modal.alert(
                'Le client « ' + d.data.client_name + ' » n\'existe plus dans la base ' +
                '(renommé ou supprimé). Créez le devis depuis la fiche client.'
            );
            return;
        }

        closeModal('devisModal');
        openProposalModal(d.data.client_id, d.data.client_name);

        // Terms carry over; the reference, the date and the token do not — this
        // is a new document, not a revision.
        setSel('prop_validity', d.data.validity_days);
        setSel('prop_frequency', d.data.delivery_frequency);
        setSel('prop_payment', d.data.payment_terms);
        setSel('prop_empties', d.data.empties_policy);
        setSel('prop_lang', d.data.language);
        var buf = document.getElementById('prop_buffer');
        if (buf) buf.value = d.data.buffer_stock_weeks;

        // openProposalModal() has already stamped one blank row; clear and
        // restamp so line 1 goes through the same path as the rest.
        var tbody = document.getElementById('proposal-items-tbody');
        tbody.innerHTML = '';

        var copied = 0;
        d.items.forEach(function (it) {
            if (!it.product_id) return;   // no id, nothing to preselect
            var row = addProposalRow();
            if (!row) return;
            copied++;
            row.querySelector('.prod-qty').value   = it.quantity;
            row.querySelector('.prod-price').value = it.unit_price;

            var sel = row.querySelector('.prod-id');
            requestAnimationFrame(function () {
                if (sel.__lpcPicker) sel.__lpcPicker.setValue(it.product_id, { silent: true });
            });
        });

        if (!copied) {
            addProposalRow();
            LPC.toast('Les lignes de ce devis sont antérieures au suivi des produits — à ressaisir.', 'info');
        }
    }

    function setSel(id, value) {
        var n = document.getElementById(id);
        if (!n || value === null || value === undefined) return;
        n.value = value;
    }

    // =========================================================================
    // EVENTS
    //
    // Delegated from the two containers rather than bound per row: the table
    // and the modal body are both replaced wholesale on every render, and
    // per-element handlers would leak on each one.
    // =========================================================================

    document.addEventListener('click', function (e) {
        // e.target is a Text node or the document itself for some synthetic
        // events; closest() only exists on Element.
        if (!e.target || typeof e.target.closest !== 'function') return;

        var open = e.target.closest('[data-devis-open]');
        if (open) { openDevis(open.dataset.devisOpen); return; }

        if (e.target.closest('[data-devis-save]'))      { saveDevis(); return; }
        if (e.target.closest('[data-devis-duplicate]')) { duplicateDevis(); return; }

        if (e.target.closest('[data-devis-copy]')) {
            navigator.clipboard.writeText(currentDevis.quote_url)
                .then(function () { LPC.toast.success('Lien copié.'); })
                .catch(function () { LPC.modal.alert(currentDevis.quote_url, { title: 'Lien du devis' }); });
            return;
        }

        if (e.target.closest('[data-devis-addline]')) {
            var tbody = document.getElementById('devis-lines');
            var tr = document.createElement('tr');
            tr.className = 'item-row';
            tr.innerHTML = H`
                <td class="p-2 align-top"><select class="line-prod" data-lpc-product-picker="sell" aria-label="Produit"></select></td>
                <td class="p-2 align-top"><input type="number" class="line-qty w-full border border-gray-300 rounded-lg p-2 text-sm text-center font-bold" value="1" min="1"></td>
                <td class="p-2 align-top"><input type="number" class="line-price w-full border border-gray-300 rounded-lg p-2 text-sm text-right font-bold" value="0" min="0"></td>
                <td class="p-2 text-center align-top"><button type="button" data-devis-rmline class="text-red-300 hover:text-red-500 p-1" aria-label="Supprimer la ligne">✕</button></td>`;
            tbody.appendChild(tr);
            return;
        }

        var rm = e.target.closest('[data-devis-rmline]');
        if (rm) {
            var row = rm.closest('tr');
            var picker = row.querySelector('.line-prod');
            // The picker renders its dropdown into a detached popup element;
            // removing the row alone would orphan it. destroy() is idempotent.
            if (picker && picker.__lpcPicker) picker.__lpcPicker.destroy();
            row.remove();
            recalcTotal();
            return;
        }

        var chip = e.target.closest('#devis-status-chips .devis-chip');
        if (chip) {
            state.status = chip.dataset.status;
            state.page = 1;
            chips(state.status);
            loadDevis();
        }
    });

    document.addEventListener('input', function (e) {
        if (e.target && typeof e.target.closest === 'function' && e.target.closest('#devis-lines')) {
            recalcTotal();
        }
    });

    // Search is debounced: one request when the user stops typing, not one per
    // keystroke against a paginated SQL query.
    var timer = null;
    document.addEventListener('input', function (e) {
        if (e.target.id !== 'devis-search') return;
        clearTimeout(timer);
        timer = setTimeout(function () {
            state.search = e.target.value.trim();
            state.page = 1;
            loadDevis();
        }, 300);
    });

    document.addEventListener('change', function (e) {
        var id = e.target.id;
        if (id === 'devis-from') { state.from = e.target.value; state.page = 1; loadDevis(); }
        if (id === 'devis-to')   { state.to   = e.target.value; state.page = 1; loadDevis(); }
        if (id === 'devis-rep')  { state.rep  = e.target.value; state.page = 1; loadDevis(); }
        if (id === 'devis-sort') {
            var parts = e.target.value.split(':');
            state.sort = parts[0];
            state.dir  = parts[1] || 'desc';
            state.page = 1;
            loadDevis();
        }
    });

    // Deep link: /modules/crm/clients.php#devis opens straight on the tab.
    document.addEventListener('DOMContentLoaded', function () {
        if (location.hash === '#devis') switchCrmTab('devis');
    });
})();
