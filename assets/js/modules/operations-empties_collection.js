/**
 * assets/js/modules/operations-empties_collection.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Gestion des Consignes & Recyclage.
 *
 * Sprint 8 rewrite: the page shell now matches Ventes / Achats (README §5.5).
 * The mechanical differences from the previous version:
 *
 *   1. DATA-DRIVEN BOTTLE CARDS. The CRE and Recycling forms no longer bake in
 *      four hardcoded slots (20L cork / nocork, 10L cork / nocork). Both grids
 *      are painted from cre_controller::get_empty_products, so a Verre 1L
 *      product picked up by a client is bookable through the same UI as a 20L
 *      bonbonne. Element IDs are now `qty_${product_id}` and `rec_${product_id}`
 *      instead of `qty_20l_cork` / `rec_901` — nothing outside this file reads
 *      those, so the rename is safe.
 *
 *   2. PAGINATION ON HISTORY. get_history has always been Paginator-backed;
 *      the previous JS ignored the envelope and rendered the first page as if
 *      it were the whole thing (same class of bug Ventes fixed in Sprint 6).
 *      The history tab is now a table plumbed through lpc-paginator.js, with
 *      server-side search on top.
 *
 *   3. CANCEL A STUCK CRE. New endpoint cancel_cre — see the modal invocation
 *      below. Was previously impossible; a CRE sitting in en_transit with an
 *      unresponsive client would keep the ledger drifted forever.
 *
 *   4. PERIOD PICKER on the Revenus Recyclage tab. The revenue query is now
 *      scoped to ytd / current_month / all / custom, same options as Ventes.
 *      The picker is hidden on every other tab (a filter that doesn't move the
 *      numbers on screen is worse than none — it reads as if the page is
 *      mis-filtered).
 *
 *   5. RBAC-DRIVEN AFFORDANCES. Buttons carry data-perm so
 *      lpc-rbac.js hides the ones the user cannot use, rather than the
 *      previous mix of server-rendered ifs and unconditional buttons that
 *      500'd on submit.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    // ---------------------------------------------------------------------
    // MODULE STATE
    // ---------------------------------------------------------------------
    let globalClients        = [];
    let globalEmptyCatalog   = [];    // [{ id, name, base_price, bottle_size, has_cork, type_key }]
    let globalRecyclePrices  = {};    // id → base_price
    let priceOverrides       = {};    // id → { price, reason }  (transient, per sale)
    let priceModalProductId  = null;
    let currentShareLink     = '';
    let historyPager         = null;
    let currentTab           = 'owed';
    const domain             = window.location.origin;
    const canOverridePrice   = window.LPC_CAN_OVERRIDE_PRICE === true;
    const canCreateCre       = window.LPC_CAN_CREATE_CRE === true;
    const canSellRecycler    = window.LPC_CAN_SELL_RECYCLER === true;
    const canViewRevenue     = window.LPC_CAN_VIEW_REVENUE === true;

    const fmt = (num) => LPC.fmt.int(Number(num) || 0);
    const openModal  = (id) => document.getElementById(id).classList.remove('hidden');
    const closeModal = (id) => document.getElementById(id).classList.add('hidden');
    window.closeModal = closeModal;   // used by inline onclick handlers on modal chrome

    // ---------------------------------------------------------------------
    // BOOTSTRAP
    // ---------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        loadEmptyCatalog().then(function () {
            renderCreQtyGrid();
            renderRecyclingGrid();
        });
        loadClients();
        loadOwedEmpties();
        loadEmptiesKPIs();
        setupHistoryPager();
        // Toolbar déclutter (Sprint 9) — le sélecteur de période vit
        // maintenant dans l'onglet Revenus, donc plus rien à masquer/afficher
        // côté toolbar au chargement.

        if (window.LPC && LPC.deeplink && LPC.deeplink.hasFilter()) {
            arriveFromDeeplink();
        }
    });

    // ---------------------------------------------------------------------
    // TAB SWITCHING + PRIMARY ACTION (FAB)
    //
    // Sprint 9 déclutter :
    //   • Bouton primaire déplacé de la toolbar vers un FAB bottom-right
    //     (#fab-primary-action). Même logique per-tab, seul le DOM cible
    //     change.
    //   • L'onglet "history" a disparu — il est devenu une sous-vue de DUS
    //     via switchOwedView(). PRIMARY_ACTION.history est retiré.
    //   • Le sélecteur de période vit dans le contenu de l'onglet Revenus,
    //     donc handleTabUI() n'a plus rien à cacher/montrer.
    // ---------------------------------------------------------------------
    const PRIMARY_ACTION = {
        owed:      { label: 'ui.x.nouvelle_collecte',       icon: 'fa-plus',              fn: () => switchTab('new') },
        new:       { label: 'ui.x.generer_le_cre_code_qr',  icon: 'fa-qrcode',            fn: generateCRE },
        recycling: { label: 'ui.x.valider_la_vente_au_recycleur', icon: 'fa-hand-holding-usd', fn: submitRecycling },
        revenue:   { label: 'ui.x.nouvelle_collecte',       icon: 'fa-plus',              fn: () => switchTab('new') },
    };

    function switchTab(tab) {
        currentTab = tab;
        document.querySelectorAll('.tab-link').forEach(function (el) {
            el.classList.remove('text-lpc-dark', 'border-lpc-dark', 'font-black');
            el.classList.add('text-gray-400', 'border-transparent', 'font-bold');
        });
        const active = document.getElementById('tab-' + tab);
        if (active) {
            active.classList.remove('text-gray-400', 'border-transparent', 'font-bold');
            active.classList.add('text-lpc-dark', 'border-lpc-dark', 'font-black');
        }
        document.querySelectorAll('.tab-content').forEach(function (el) { el.classList.remove('active'); });
        const pane = document.getElementById('content-' + tab);
        if (pane) pane.classList.add('active');

        // FAB label + icon + handler.
        const cfg = PRIMARY_ACTION[tab] || PRIMARY_ACTION.owed;
        const fabTxt = document.getElementById('fab-primary-text');
        const fabIco = document.getElementById('fab-primary-icon');
        const fabBtn = document.getElementById('fab-primary-action');
        if (fabTxt) fabTxt.textContent = LPC.t ? LPC.t(cfg.label) : cfg.label;
        if (fabIco) fabIco.className   = 'fas ' + cfg.icon + ' text-xl leading-none';
        if (fabBtn) fabBtn.setAttribute('aria-label', LPC.t ? LPC.t(cfg.label) : cfg.label);

        if (tab === 'revenue' && canViewRevenue) loadRevenueData();
    }
    window.switchTab = switchTab;

    function onPrimaryAction() {
        const cfg = PRIMARY_ACTION[currentTab] || PRIMARY_ACTION.owed;
        cfg.fn();
    }
    window.onPrimaryAction = onPrimaryAction;

    // ---------------------------------------------------------------------
    // DUS sub-view toggle — "En cours" vs "Historique" (Sprint 9).
    // L'ancien onglet HISTORIQUE de la toolbar a été fusionné ici. Le
    // paginator et les IDs de champs (tbody-history, history-search,
    // history-pager) restent inchangés — on ne fait que basculer la
    // visibilité des deux sous-panneaux.
    // ---------------------------------------------------------------------
    let owedSubView = 'current';
    function switchOwedView(view) {
        owedSubView = view === 'history' ? 'history' : 'current';
        const cur  = document.getElementById('owed-view-current');
        const hist = document.getElementById('owed-view-history');
        const btnCur  = document.getElementById('owed-sub-current');
        const btnHist = document.getElementById('owed-sub-history');
        if (!cur || !hist) return;

        const activeCls   = ['bg-white', 'text-lpc-dark', 'shadow-sm', 'font-black'];
        const inactiveCls = ['text-gray-500', 'font-bold'];

        if (owedSubView === 'history') {
            cur.classList.add('hidden');
            hist.classList.remove('hidden');
            btnHist.classList.add(...activeCls);   btnHist.classList.remove(...inactiveCls);
            btnCur .classList.remove(...activeCls); btnCur .classList.add(...inactiveCls);
            loadHistory();
        } else {
            hist.classList.add('hidden');
            cur.classList.remove('hidden');
            btnCur .classList.add(...activeCls);   btnCur .classList.remove(...inactiveCls);
            btnHist.classList.remove(...activeCls); btnHist.classList.add(...inactiveCls);
        }
    }
    window.switchOwedView = switchOwedView;

    function handlePeriodUI() {
        const sel = document.getElementById('rev_period_type');
        const wrap = document.getElementById('custom_period_wrapper');
        if (!sel || !wrap) return;
        if (sel.value === 'custom') {
            wrap.classList.remove('hidden');
            wrap.classList.add('flex');
            // Sensible defaults: previous month → current month.
            const now = new Date();
            const prev = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            const cur  = new Date(now.getFullYear(), now.getMonth(), 1);
            const iso  = (d) => d.toISOString().slice(0, 7);
            const s = document.getElementById('rev_start_month');
            const e = document.getElementById('rev_end_month');
            if (s && !s.value) s.value = iso(prev);
            if (e && !e.value) e.value = iso(cur);
        } else {
            wrap.classList.add('hidden');
            wrap.classList.remove('flex');
        }
        if (currentTab === 'revenue') loadRevenueData();
    }
    window.handlePeriodUI = handlePeriodUI;

    // ---------------------------------------------------------------------
    // KPI RIBBON (top of page)
    //
    // Four cards, always visible. Same visual treatment as Ventes' ribbon so
    // an ops person switching modules sees the same layout of "what's
    // pending / late / already done" up top.
    // ---------------------------------------------------------------------
    async function loadEmptiesKPIs() {
        const box = document.getElementById('kpi-ribbon');
        if (!box) return;
        box.innerHTML = kpiSkeleton();
        try {
            const res = await fetch('/api/v1/cre_controller.php?action=get_empties_kpis');
            const r = await res.json();
            if (r.status !== 'success') return renderKpiError(box);
            const k = r.data || {};
            // Sprint 9 : chaque carte est cliquable et ouvre une modale de
             // drill-down (openKpiModal). Le type identifie le jeu de données
             // à charger côté client (voir openKpiModal ci-dessous).
            box.innerHTML = '';
            box.appendChild(kpiCard({
                type: 'owed',
                icon: 'fas fa-balance-scale',
                accent: 'rose',
                label: LPC.t ? LPC.t('ui.x.solde_du_total') : 'Solde dû total',
                value: fmt(k.total_owed) + ' u.',
                sub:   (k.clients_with_debt || 0) + ' ' + (LPC.t ? LPC.t('ui.x.clients_avec_dette') : 'clients avec dette'),
            }));
            /* Sprint 12 (migration 074) — the in-flight KPI. Sits between
               Solde dû (confirmed) and CRE en attente (paperwork open) so
               the eye reads left-to-right in decreasing confidence: sure →
               probable → maybe. Amber accent matches the DUS column so the
               two surfaces are visually linked. */
            box.appendChild(kpiCard({
                type: 'inflight',
                icon: 'fas fa-truck-loading',
                accent: 'amber',
                label: 'Vides en circulation',
                value: fmt(k.total_in_flight || 0) + ' u.',
                sub:   (k.clients_in_flight || 0) + ' client(s) · BL non signés',
            }));
            box.appendChild(kpiCard({
                type: 'pending',
                icon: 'fas fa-clock',
                accent: 'amber',
                label: LPC.t ? LPC.t('ui.x.cre_en_attente_signature') : 'CRE en attente de signature',
                value: fmt(k.cre_pending),
                sub:   LPC.t ? LPC.t('ui.x.a_relancer_si_bloque') : 'À relancer si bloqué',
            }));
            box.appendChild(kpiCard({
                type: 'signed30',
                icon: 'fas fa-check-double',
                accent: 'emerald',
                label: LPC.t ? LPC.t('ui.x.cre_signes_30j') : 'CRE signés (30 j)',
                value: fmt(k.cre_signed_30d),
                sub:   LPC.t ? LPC.t('ui.x.retours_confirmes') : 'Retours confirmés',
            }));
            box.appendChild(kpiCard({
                type: 'revenue30',
                icon: 'fas fa-recycle',
                accent: 'blue',
                label: LPC.t ? LPC.t('ui.x.revenu_recyclage_30j') : 'Revenu recyclage (30 j)',
                value: LPC.fmt.fcfa(Number(k.recycling_revenue_30d) || 0),
                sub:   fmt(k.recycling_sales_30d) + ' ' + (LPC.t ? LPC.t('ui.x.ventes') : 'ventes'),
            }));
        } catch (e) {
            renderKpiError(box);
        }
    }

    function kpiSkeleton() {
        return Array.from({ length: 5 })
            .map(() => '<div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-sm animate-pulse h-[110px]"></div>')
            .join('');
    }
    function renderKpiError(box) {
        box.innerHTML = '<div class="col-span-full bg-rose-50 border border-rose-200 rounded-xl p-4 text-center text-xs font-bold text-rose-700">'
                      + (LPC.t ? LPC.t('ui.x.kpis_indisponibles') : 'KPIs indisponibles.') + '</div>';
    }
    function kpiCard(spec) {
        // Bouton natif (pas <div>) pour la sémantique clavier + a11y.
        // spec.type sélectionne le drill-down (voir openKpiModal).
        const el = document.createElement('button');
        el.type = 'button';
        el.className = 'text-left bg-white border border-gray-200 rounded-2xl p-5 shadow-sm hover:shadow-md hover:border-lpc-dark hover:-translate-y-0.5 transition-all cursor-pointer lpc-focusable';
        if (spec.type) {
            el.dataset.kpiType = spec.type;
            el.setAttribute('aria-label', 'Détails — ' + spec.label);
        }
        el.innerHTML = ''
            + '<div class="flex items-start justify-between mb-2">'
            + '  <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">' + LPC.escapeHtml(spec.label) + '</p>'
            + '  <span class="w-8 h-8 rounded-lg bg-' + spec.accent + '-50 text-' + spec.accent + '-600 flex items-center justify-center">'
            + '    <i class="' + spec.icon + '"></i></span>'
            + '</div>'
            + '<p class="text-2xl font-black text-gray-900">' + LPC.escapeHtml(String(spec.value)) + '</p>'
            + '<div class="flex items-center justify-between mt-1">'
            + '  <p class="text-[10px] font-bold text-gray-400">' + LPC.escapeHtml(String(spec.sub || '')) + '</p>'
            + (spec.type ? '  <span class="text-[10px] font-black text-lpc-dark opacity-0 group-hover:opacity-100 transition-opacity">Détails →</span>' : '')
            + '</div>';
        if (spec.type) {
            el.addEventListener('click', function () { openKpiModal(spec.type, spec.label); });
        }
        return el;
    }

    // ---------------------------------------------------------------------
    // KPI DRILL-DOWN MODAL (Sprint 9)
    // Un seul <div id="modal-kpi-detail"> dans le PHP ; ici on choisit
    // quoi charger selon le type de KPI cliqué. Chaque type sait :
    //   • quel endpoint interroger
    //   • comment rendre les lignes (tableau)
    //   • où mène le bouton "Voir tout" (deep-link vers l'onglet concerné)
    // ---------------------------------------------------------------------
    async function openKpiModal(type, labelHint) {
        const modal   = document.getElementById('modal-kpi-detail');
        const eyebrow = document.getElementById('kpi-modal-eyebrow');
        const title   = document.getElementById('kpi-modal-title');
        const body    = document.getElementById('kpi-modal-body');
        const cta     = document.getElementById('kpi-modal-cta');
        if (!modal || !body) return;

        eyebrow.textContent = 'KPI';
        title.textContent   = labelHint || '';
        body.innerHTML      = '<p class="text-center py-8 text-gray-400 font-bold text-sm"><i class="fas fa-spinner fa-spin mr-2"></i> Chargement…</p>';
        cta.textContent     = 'Voir tout';
        cta.href            = '#';
        openModal('modal-kpi-detail');

        try {
            if (type === 'owed')      return renderOwedDrillDown(body, cta);
            /* Sprint 12 — in-flight drill-down. Uses the same owed-cache
               so no extra roundtrip; groups by client and sums
               quantity_in_flight. Reuses the KPI modal chrome — no new
               modal added. */
            if (type === 'inflight')  return renderInflightDrillDown(body, cta);
            if (type === 'pending')   return renderCreDrillDown(body, cta, 'en_transit', 'CRE en attente de signature');
            if (type === 'signed30') return renderCreDrillDown(body, cta, 'signed', 'CRE signés (30 j)');
            if (type === 'revenue30') return renderRevenueDrillDown(body, cta);
            body.innerHTML = '<p class="text-center py-6 text-gray-400 font-bold text-xs">Vue de détail non disponible.</p>';
        } catch (e) {
            console.error('KPI drill-down failed:', e);
            body.innerHTML = '<p class="text-center py-6 text-rose-600 font-bold text-xs">Impossible de charger les détails.</p>';
        }
    }
    window.openKpiModal = openKpiModal;

    async function renderOwedDrillDown(body, cta) {
        // Réutilise le cache si dispo, sinon fetch. Groupé par client
        // pour donner le "qui doit combien" en un coup d'œil.
        let rows = owedRowsCache;
        if (!rows || !rows.length) {
            const res = await fetch('/api/v1/cre_controller.php?action=get_owed_empties');
            const r = await res.json();
            rows = r.data || [];
        }
        const byClient = {};
        rows.forEach(function (r) {
            const key = r.client_name + (r.site_name ? ' · ' + r.site_name : '');
            if (!byClient[key]) byClient[key] = { client_id: r.client_id, site_id: r.site_id, name: key, total: 0, lines: [] };
            byClient[key].total += Number(r.quantity_owed) || 0;
            byClient[key].lines.push(r);
        });
        const groups = Object.values(byClient).sort(function (a, b) { return b.total - a.total; });
        if (!groups.length) {
            body.innerHTML = '<p class="text-center py-8 text-gray-400 font-bold text-sm">Aucun solde dû. 🎉</p>';
            cta.href = '#'; cta.classList.add('pointer-events-none', 'opacity-50');
            return;
        }
        let html = '<table class="w-full text-sm">'
                 + '<thead class="text-[10px] font-black text-gray-500 uppercase tracking-wider">'
                 + '  <tr><th class="text-left py-2">Client / Site</th><th class="text-right py-2">Solde dû (u.)</th><th class="text-right py-2">Action</th></tr>'
                 + '</thead><tbody class="divide-y divide-gray-100">';
        groups.forEach(function (g) {
            html += '<tr>'
                  + '  <td class="py-2 font-black text-gray-900">' + LPC.escapeHtml(g.name) + '</td>'
                  + '  <td class="py-2 text-right font-black text-rose-600">' + fmt(g.total) + '</td>'
                  + '  <td class="py-2 text-right"><button type="button" class="text-xs font-bold text-lpc-dark hover:text-black" data-kpi-collect="' + g.client_id + '" data-kpi-site="' + (g.site_id || '') + '"><i class="fas fa-hand-holding-water mr-1"></i>Collecter</button></td>'
                  + '</tr>';
        });
        html += '</tbody></table>';
        body.innerHTML = html;
        cta.textContent = 'Voir tout dans DUS';
        cta.href = '#';
        cta.classList.remove('pointer-events-none', 'opacity-50');
        cta.onclick = function (ev) { ev.preventDefault(); closeModal('modal-kpi-detail'); switchTab('owed'); switchOwedView('current'); };
        body.querySelectorAll('[data-kpi-collect]').forEach(function (b) {
            b.addEventListener('click', function () {
                closeModal('modal-kpi-detail');
                prefillCollection(b.dataset.kpiCollect, b.dataset.kpiSite || null);
            });
        });
    }

    /* Sprint 12 (migration 074) — in-flight drill-down. Same shape as
       renderOwedDrillDown but reads quantity_in_flight instead of
       quantity_owed. Reuses owedRowsCache (the get_owed_empties response
       returns both fields on the same row) so no extra network call. */
    async function renderInflightDrillDown(body, cta) {
        let rows = owedRowsCache;
        if (!rows || !rows.length) {
            const res = await fetch('/api/v1/cre_controller.php?action=get_owed_empties');
            const r = await res.json();
            rows = r.data || [];
        }
        const byClient = {};
        rows.forEach(function (r) {
            const qty = Number(r.quantity_in_flight) || 0;
            if (qty <= 0) return; // in-flight bucket only
            const key = r.client_name + (r.site_name ? ' · ' + r.site_name : '');
            if (!byClient[key]) byClient[key] = { client_id: r.client_id, site_id: r.site_id, name: key, total: 0, lines: [] };
            byClient[key].total += qty;
            byClient[key].lines.push(r);
        });
        const groups = Object.values(byClient).sort(function (a, b) { return b.total - a.total; });
        if (!groups.length) {
            body.innerHTML = '<p class="text-center py-8 text-gray-400 font-bold text-sm">Aucun vide en circulation. Tous les BL sont signés. 🎉</p>';
            cta.href = '#'; cta.classList.add('pointer-events-none', 'opacity-50');
            return;
        }
        let html = '<div class="text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2 mb-3">'
                 + '<i class="fas fa-info-circle mr-1"></i>'
                 + 'Ces vides ont été expédiés sur des BL <strong>non encore signés</strong>. Ils basculeront en "Solde dû" dès que le client signera.'
                 + '</div>';
        html += '<table class="w-full text-sm">'
              + '<thead class="text-[10px] font-black text-gray-500 uppercase tracking-wider">'
              + '  <tr><th class="text-left py-2">Client / Site</th><th class="text-right py-2">En circulation (u.)</th></tr>'
              + '</thead><tbody class="divide-y divide-gray-100">';
        groups.forEach(function (g) {
            html += '<tr>'
                  + '  <td class="py-2 font-black text-gray-900">' + LPC.escapeHtml(g.name) + '</td>'
                  + '  <td class="py-2 text-right font-black text-amber-700">' + fmt(g.total) + '</td>'
                  + '</tr>';
        });
        html += '</tbody></table>';
        body.innerHTML = html;
        cta.textContent = 'Voir dans DUS';
        cta.href = '#';
        cta.classList.remove('pointer-events-none', 'opacity-50');
        cta.onclick = function (ev) { ev.preventDefault(); closeModal('modal-kpi-detail'); switchTab('owed'); switchOwedView('current'); };
    }

    async function renderCreDrillDown(body, cta, statusFilter, label) {
        // get_history n'a pas de filtre `status` dédié : on utilise `q=` que
        // le Paginator applique en OR sur (reference, client_name, phone,
        // status). 'en_transit' / 'signed' ne collent que sur la colonne
        // status, donc c'est safe en pratique. Ensuite on filtre côté client
        // pour être strict (au cas où un client s'appellerait "Signed SARL").
        const res = await fetch('/api/v1/cre_controller.php?action=get_history&q=' + encodeURIComponent(statusFilter));
        const r = await res.json();
        const rows = ((r && r.data) || []).filter(function (x) { return x.status === statusFilter; }).slice(0, 10);
        if (!rows.length) {
            body.innerHTML = '<p class="text-center py-8 text-gray-400 font-bold text-sm">Aucun CRE ' + LPC.escapeHtml(label.toLowerCase()) + '.</p>';
            cta.href = '#'; cta.classList.add('pointer-events-none', 'opacity-50');
            return;
        }
        let html = '<table class="w-full text-sm">'
                 + '<thead class="text-[10px] font-black text-gray-500 uppercase tracking-wider">'
                 + '  <tr><th class="text-left py-2">Date · Réf.</th><th class="text-left py-2">Client</th><th class="text-center py-2">Statut</th></tr>'
                 + '</thead><tbody class="divide-y divide-gray-100">';
        rows.forEach(function (cre) {
            const d = new Date(cre.created_at || cre.date);
            const dateStr = isNaN(d.getTime()) ? '' : d.toLocaleDateString('fr-FR');
            const badge = statusFilter === 'en_transit'
                ? '<span class="bg-amber-100 text-amber-700 px-2 py-1 rounded text-[9px] font-black uppercase">En attente</span>'
                : '<span class="bg-green-100 text-green-700 px-2 py-1 rounded text-[9px] font-black uppercase">Signé</span>';
            html += '<tr>'
                  + '  <td class="py-2 text-xs">' + dateStr + '<br><span class="text-[10px] font-black text-lpc-dark">' + LPC.escapeHtml(cre.reference || '') + '</span></td>'
                  + '  <td class="py-2 font-black text-gray-900">' + LPC.escapeHtml(cre.client_name || '') + '</td>'
                  + '  <td class="py-2 text-center">' + badge + '</td>'
                  + '</tr>';
        });
        html += '</tbody></table>';
        body.innerHTML = html;
        cta.textContent = 'Voir tout l\'historique';
        cta.href = '#';
        cta.classList.remove('pointer-events-none', 'opacity-50');
        cta.onclick = function (ev) { ev.preventDefault(); closeModal('modal-kpi-detail'); switchTab('owed'); switchOwedView('history'); };
    }

    async function renderRevenueDrillDown(body, cta) {
        if (!canViewRevenue) {
            body.innerHTML = '<p class="text-center py-8 text-gray-400 font-bold text-sm">Vous n\'avez pas la permission de voir le revenu recyclage.</p>';
            cta.href = '#'; cta.classList.add('pointer-events-none', 'opacity-50');
            return;
        }
        const res = await fetch('/api/v1/cre_controller.php?action=get_recycling_revenue&period=current_month');
        const r = await res.json();
        const rows = (r && r.data && r.data.table) || [];
        const s = (r && r.data && r.data.stats) || {};
        if (!rows.length) {
            body.innerHTML = '<p class="text-center py-8 text-gray-400 font-bold text-sm">Aucune vente recyclage ce mois-ci.</p>';
            cta.href = '#'; cta.classList.add('pointer-events-none', 'opacity-50');
            return;
        }
        let html = '<div class="mb-3 text-xs font-bold text-gray-600">Ce mois-ci : <span class="text-emerald-700 font-black">' + LPC.fmt.fcfa(Number(s.total_revenue) || 0) + '</span></div>'
                 + '<table class="w-full text-sm">'
                 + '<thead class="text-[10px] font-black text-gray-500 uppercase tracking-wider">'
                 + '  <tr><th class="text-left py-2">Date · Réf.</th><th class="text-left py-2">Chauffeur</th><th class="text-right py-2">Montant</th></tr>'
                 + '</thead><tbody class="divide-y divide-gray-100">';
        rows.slice(0, 10).forEach(function (row) {
            const d = new Date(row.created_at);
            const dateStr = d.toLocaleDateString('fr-FR');
            html += '<tr>'
                  + '  <td class="py-2 text-xs">' + dateStr + '<br><span class="text-[10px] font-black text-amber-600">' + LPC.escapeHtml(row.reference || '') + '</span></td>'
                  + '  <td class="py-2 font-black text-gray-900">' + LPC.escapeHtml(row.driver_name || '') + '</td>'
                  + '  <td class="py-2 text-right font-black text-emerald-600">' + fmt(row.total_amount) + ' F</td>'
                  + '</tr>';
        });
        html += '</tbody></table>';
        body.innerHTML = html;
        cta.textContent = 'Voir tout dans Revenus';
        cta.href = '#';
        cta.classList.remove('pointer-events-none', 'opacity-50');
        cta.onclick = function (ev) { ev.preventDefault(); closeModal('modal-kpi-detail'); switchTab('revenue'); };
    }

    // ---------------------------------------------------------------------
    // TAB: DUS — outstanding empties balances per client
    // ---------------------------------------------------------------------
    let owedRowsCache = [];   // last-fetched rows, used by client-side filter

    async function loadOwedEmpties() {
        const tbody = document.getElementById('tbody-owed');
        try {
            /* Sprint 12 — honour the ?client_id= URL filter set by the
               order_detail deep-link. When present we pass it through so
               the backend returns only that client's rows; the "filtered
               view" chip is rendered elsewhere (see renderFilterChip
               below). Absent → default "everyone" behaviour, unchanged. */
            const cid = Number(window.LPC_EMPTIES_CLIENT_FILTER || 0);
            const url = cid > 0
                ? '/api/v1/cre_controller.php?action=get_owed_empties&client_id=' + encodeURIComponent(cid)
                : '/api/v1/cre_controller.php?action=get_owed_empties';
            const res = await fetch(url);
            const result = await res.json();
            owedRowsCache = (result.data || []);
            renderOwedRows(owedRowsCache);
            renderFilterChip(cid, owedRowsCache);
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-8 text-red-500 font-bold">'
                            + (LPC.t ? LPC.t('ui.x.erreur_reseau') : 'Erreur réseau.') + '</td></tr>';
        }
    }

    /* Sprint 12 — filtered-view chip. When the operator arrived here from
       an order_detail deep-link, tell them plainly that they're not looking
       at everyone, and give them one click to clear it. Rendered inline in
       the DUS panel header, above the table. */
    function renderFilterChip(cid, rows) {
        const anchor = document.getElementById('owed-view-current');
        if (!anchor) return;
        // Remove any previously-rendered chip so re-renders don't stack.
        const prev = anchor.querySelector('[data-client-filter-chip]');
        if (prev) prev.remove();
        if (!cid) return;
        const name = (rows[0] && rows[0].client_name) || 'ce client';
        const chip = document.createElement('div');
        chip.dataset.clientFilterChip = '1';
        chip.className = 'flex items-center justify-between gap-3 bg-blue-50 border-b border-blue-200 px-6 py-2 text-xs font-bold text-blue-900';
        chip.innerHTML =
              '<span><i class="fas fa-filter mr-1"></i>Vue filtrée sur <strong>' + LPC.escapeHtml(String(name)) + '</strong></span>'
            + '<a href="/modules/operations/empties_collection.php" class="underline hover:text-blue-700">Voir tous les clients</a>';
        anchor.insertBefore(chip, anchor.firstChild);
    }

    function renderOwedRows(rows) {
        const tbody = document.getElementById('tbody-owed');
        tbody.innerHTML = '';
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-8 text-gray-400 font-bold">'
                            + (LPC.t ? LPC.t('ui.x.aucun_solde_du') : 'Aucun solde dû.') + '</td></tr>';
            return;
        }
        rows.forEach(function (row) {
            const siteName = row.site_name
                ? LPC.raw(LPC.html`<span class="text-xs text-blue-600 block">${row.site_name}</span>`)
                : '';
            /* Sprint 12 — amber in-flight cell. Non-zero: bold amber to
               match the KPI accent. Zero / missing: muted em-dash so the
               reader distinguishes "field-known-to-be-zero" from "field
               not populated" (there is no third case, but the visual
               grammar helps a supervisor scanning the column). */
            const inFlight = Number(row.quantity_in_flight || 0);
            const inFlightCell = inFlight > 0
                ? LPC.raw(LPC.html`<span class="font-black text-amber-700">${inFlight}</span>`)
                : LPC.raw('<span class="text-gray-300">—</span>');
            tbody.insertAdjacentHTML('beforeend', LPC.html`
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="py-3 px-4 font-black text-gray-900">${row.client_name} ${siteName}</td>
                    <td class="py-3 px-4 text-xs font-bold text-gray-600">${row.product_name}</td>
                    <td class="py-3 px-4 text-center font-black text-blue-600">${row.total_out}</td>
                    <td class="py-3 px-4 text-center font-black text-green-600">${row.total_in}</td>
                    <td class="py-3 px-4 text-center font-black text-rose-600 bg-rose-50/50">${row.quantity_owed}</td>
                    <td class="py-3 px-4 text-center bg-amber-50/40">${inFlightCell}</td>
                    <td class="py-3 px-4 text-right">
                        <button type="button" onclick="prefillCollection(${row.client_id}, ${row.site_id || 'null'})"
                                data-perm="operations.empties.create_cre"
                                class="bg-gray-900 hover:bg-black text-white px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest shadow-sm">
                            <i class="fas fa-hand-holding-water mr-1"></i> ${LPC.t ? LPC.t('ui.x.collecter') : 'Collecter'}
                        </button>
                    </td>
                </tr>`);
        });
    }

    function filterOwedTable(q) {
        q = (q || '').toLowerCase();
        if (!q) return renderOwedRows(owedRowsCache);
        const filtered = owedRowsCache.filter(function (r) {
            return (r.client_name || '').toLowerCase().includes(q)
                || (r.site_name || '').toLowerCase().includes(q)
                || (r.product_name || '').toLowerCase().includes(q);
        });
        renderOwedRows(filtered);
    }
    window.filterOwedTable = filterOwedTable;

    function prefillCollection(clientId, siteId) {
        switchTab('new');
        const sel = document.getElementById('client_id');
        if (sel) sel.value = clientId;
        loadSites();
        setTimeout(function () {
            if (siteId) {
                const s = document.getElementById('site_id');
                if (s) s.value = siteId;
            }
        }, 100);
    }
    window.prefillCollection = prefillCollection;

    // ---------------------------------------------------------------------
    // TAB: COLLECTER — data-driven CRE form
    //
    // Builds one card per (bottle_size, has_cork) group. Any product master
    // row with is_empty=1 shows up here without a code change. Element IDs
    // become `qty_${product_id}` so the submit path can post {product_id, qty}
    // pairs directly.
    // ---------------------------------------------------------------------
    async function loadEmptyCatalog() {
        try {
            const res = await fetch('/api/v1/cre_controller.php?action=get_empty_products');
            const result = await res.json();
            if (result.status === 'success') {
                globalEmptyCatalog = result.data || [];
                globalEmptyCatalog.forEach(function (p) {
                    globalRecyclePrices[p.id] = parseFloat(p.base_price);
                });
            }
        } catch (e) {
            console.error('Could not load empty-product catalog', e);
        }
    }

    /**
     * Group the catalogue by bottle_size so the UI looks like "20L bottles"
     * with its two variants (cork / no cork) below, "10L bottles" with the
     * same, and any new size (1L, 5L…) as its own card.
     */
    function groupBySize(list) {
        const groups = {};
        list.forEach(function (p) {
            const key = p.bottle_size || (LPC.t ? LPC.t('ui.x.autre') : 'Autre');
            if (!groups[key]) groups[key] = [];
            groups[key].push(p);
        });
        // Preserve the "big bottles first" order the desk uses when
        // physically sorting: 20L, 10L, 5L, 1.5L, 1L, 0.5L, then anything
        // else alphabetically.
        const orderIndex = { '20L': 0, '10L': 1, '5L': 2, '1.5L': 3, '1L': 4, '0.5L': 5 };
        return Object.keys(groups).sort(function (a, b) {
            const ai = orderIndex[a] !== undefined ? orderIndex[a] : 99;
            const bi = orderIndex[b] !== undefined ? orderIndex[b] : 99;
            if (ai !== bi) return ai - bi;
            return a.localeCompare(b);
        }).map(function (k) { return { size: k, items: groups[k] }; });
    }

    function renderCreQtyGrid() {
        const grid = document.getElementById('cre_qty_grid');
        if (!grid) return;
        grid.innerHTML = '';
        if (!globalEmptyCatalog.length) {
            grid.innerHTML = '<p class="col-span-full text-center py-6 text-gray-400 font-bold text-xs">'
                           + (LPC.t ? LPC.t('ui.x.catalogue_emballages_vide') : 'Aucun emballage vide dans le catalogue. Ajoutez-en un dans Master Data → Produits.')
                           + '</p>';
            return;
        }
        groupBySize(globalEmptyCatalog).forEach(function (g) {
            const card = document.createElement('div');
            card.className = 'bg-gray-50 p-3 rounded-xl border border-gray-200';
            let inner = '<p class="text-xs font-black text-gray-800 text-center mb-2">'
                      + LPC.escapeHtml(g.size + ' — ' + (LPC.t ? LPC.t('ui.x.bouteilles') : 'bouteilles')) + '</p>'
                      + '<div class="space-y-3">';
            g.items.forEach(function (p) {
                const isNoCork = !p.has_cork;
                const labelKey = isNoCork ? 'ui.x.sans_bouchon' : 'ui.x.avec_bouchon';
                const label    = LPC.t ? LPC.t(labelKey) : (isNoCork ? 'Sans bouchon' : 'Avec bouchon');
                const bordCls  = isNoCork ? 'border-rose-300 bg-rose-50 text-rose-900' : 'border-gray-300';
                const lblCls   = isNoCork ? 'text-rose-500' : 'text-gray-500';
                inner += ''
                    + '<div>'
                    + '  <label for="qty_' + p.id + '" class="text-[10px] font-bold ' + lblCls + ' uppercase">' + LPC.escapeHtml(label) + '</label>'
                    + '  <input type="number" id="qty_' + p.id + '" data-empty-id="' + p.id + '" min="0" value="0"'
                    + '         class="w-full mt-1 border ' + bordCls + ' rounded-lg p-2 outline-none focus:border-lpc-dark focus:ring-1 focus:ring-lpc-dark">'
                    + '</div>';
            });
            inner += '</div>';
            card.innerHTML = inner;
            grid.appendChild(card);
        });
    }

    // ---------------------------------------------------------------------
    // COLLECTER — client list + sites
    // ---------------------------------------------------------------------
    async function loadClients() {
        try {
            const res = await fetch('/api/v1/cre_controller.php?action=get_clients');
            const result = await res.json();
            if (result.status !== 'success') return;
            globalClients = result.data;
            const sel = document.getElementById('client_id');
            globalClients.forEach(function (c) {
                sel.insertAdjacentHTML('beforeend', LPC.html`<option value="${c.id}">${c.name}</option>`);
            });
        } catch (e) { /* ignore */ }
    }

    function loadSites() {
        const clientId = document.getElementById('client_id').value;
        const siteSel = document.getElementById('site_id');
        const siteContainer = document.getElementById('site_container');
        siteSel.innerHTML = '<option value="">-- ' + (LPC.t ? LPC.t('ui.x.siege_principal') : 'Siège Principal') + ' --</option>';
        const phoneInput = document.getElementById('client_phone');
        if (phoneInput) phoneInput.value = '';

        if (!clientId) return siteContainer.classList.add('hidden');
        const client = globalClients.find(function (c) { return c.id == clientId; });
        if (client && client.phone && phoneInput) phoneInput.value = client.phone;

        if (client && client.sites && client.sites.length > 0) {
            siteContainer.classList.remove('hidden');
            client.sites.forEach(function (s) {
                siteSel.insertAdjacentHTML('beforeend', LPC.html`<option value="${s.id}" data-phone="${s.phone || ''}">${s.name}</option>`);
            });
        } else {
            siteContainer.classList.add('hidden');
        }
    }
    window.loadSites = loadSites;

    // ---------------------------------------------------------------------
    // GENERATE CRE
    // Payload shape moved from the old bottle_type buckets to a plain
    // {product_id, quantity} list — matches how the server now stores it and
    // lets any bottle size work with no server change.
    // ---------------------------------------------------------------------
    async function generateCRE() {
        if (!canCreateCre) return LPC.modal.alert(LPC.t ? LPC.t('ui.x.non_autorise') : 'Non autorisé.');

        const clientId = document.getElementById('client_id').value;
        if (!clientId) return LPC.modal.alert(LPC.t ? LPC.t('ui.x.veuillez_selectionner_un_client') : 'Veuillez sélectionner un client.');

        const items = [];
        globalEmptyCatalog.forEach(function (p) {
            const el = document.getElementById('qty_' + p.id);
            const q = el ? parseInt(el.value, 10) || 0 : 0;
            if (q > 0) items.push({ product_id: p.id, quantity: q });
        });
        if (items.length === 0) {
            return LPC.modal.alert(LPC.t ? LPC.t('ui.x.veuillez_saisir_au_moins_une_quantite') : 'Veuillez saisir au moins une quantité.');
        }

        const payload = {
            action:    'create_cre',
            client_id: clientId,
            site_id:   document.getElementById('site_id').value,
            items:     items,
        };

        try {
            const res = await fetch('/api/v1/cre_controller.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const result = await res.json();
            if (result.status === 'success') {
                document.getElementById('form-cre').reset();
                renderCreQtyGrid();   // reset the qty inputs
                loadSites();
                showShareModal(result.data.reference, result.data.token, document.getElementById('client_phone').value);
                loadHistory();
                loadEmptiesKPIs();
            } else {
                LPC.modal.alert(result.message);
            }
        } catch (e) { LPC.modal.alert('Erreur.'); }
    }
    window.generateCRE = generateCRE;

    // ---------------------------------------------------------------------
    // SHARE MODAL (WhatsApp / QR)
    // ---------------------------------------------------------------------
    let qrcode_instance = null;
    function showShareModal(ref, token, phone) {
        document.getElementById('share_ref').textContent = ref;
        if (phone) document.getElementById('client_phone').value = phone;
        currentShareLink = `${domain}/sign_cre.php?token=${token}`;
        const qrContainer = document.getElementById('qrcode');
        qrContainer.innerHTML = '';
        qrcode_instance = new QRCode(qrContainer, {
            text: currentShareLink, width: 192, height: 192,
            colorDark: '#0F172A', correctLevel: QRCode.CorrectLevel.H,
        });
        openModal('modal-share');
    }
    window.showShareModal = showShareModal;

    function shareWhatsApp() {
        let phone = document.getElementById('client_phone').value.trim().replace(/\s+/g, '');
        if (phone.length === 9) phone = '237' + phone;
        const msg = (LPC.t ? LPC.t('ui.x.wa_lien_secure_cre') : "Bonjour,\nLien sécurisé pour signer le Retour d'Emballages LPC :");
        const text = encodeURIComponent(msg + '\n' + currentShareLink);
        window.open(
            phone ? 'https://wa.me/' + phone + '?text=' + text
                  : 'https://api.whatsapp.com/send?text=' + text,
            '_blank');
    }
    window.shareWhatsApp = shareWhatsApp;

    // ---------------------------------------------------------------------
    // TAB: VENTE RECYCLAGE — data-driven grid + override handling
    // ---------------------------------------------------------------------
    function basePriceOf(productId)    { return parseFloat(globalRecyclePrices[productId]) || 0; }
    function effectivePriceOf(productId) {
        const o = priceOverrides[productId];
        return o ? o.price : basePriceOf(productId);
    }

    function renderRecyclingGrid() {
        const grid = document.getElementById('rec_qty_grid');
        if (!grid) return;
        grid.innerHTML = '';
        if (!globalEmptyCatalog.length) {
            grid.innerHTML = '<p class="col-span-full text-center py-6 text-gray-400 font-bold text-xs">'
                           + (LPC.t ? LPC.t('ui.x.catalogue_emballages_vide') : 'Aucun emballage vide dans le catalogue.') + '</p>';
            return;
        }
        groupBySize(globalEmptyCatalog).forEach(function (g) {
            const card = document.createElement('div');
            card.className = 'bg-gray-50 p-3 rounded-xl border border-gray-200';
            let inner = '<p class="text-xs font-black text-gray-800 text-center mb-2">'
                      + LPC.escapeHtml(g.size + ' — ' + (LPC.t ? LPC.t('ui.x.bouteilles') : 'bouteilles')) + '</p>'
                      + '<div class="space-y-3">';
            g.items.forEach(function (p) {
                const isNoCork = !p.has_cork;
                const labelKey = isNoCork ? 'ui.x.sans_bouchon' : 'ui.x.avec_bouchon';
                const label    = LPC.t ? LPC.t(labelKey) : (isNoCork ? 'Sans bouchon' : 'Avec bouchon');
                const bordCls  = isNoCork ? 'border-rose-300 bg-rose-50 text-rose-900' : 'border-gray-300';
                const lblCls   = isNoCork ? 'text-rose-500' : 'text-gray-500';
                inner += ''
                    + '<div>'
                    + '  <div class="flex justify-between items-center mb-1">'
                    + '    <label for="rec_' + p.id + '" class="text-[10px] font-bold ' + lblCls + ' uppercase">' + LPC.escapeHtml(label) + '</label>'
                    + '    <span class="flex items-center gap-1">'
                    + '      <span id="price_' + p.id + '" class="text-[10px] font-black text-amber-600">' + fmt(basePriceOf(p.id)) + ' F</span>'
                    + (canOverridePrice
                        ? '      <button type="button" class="js-edit-price text-gray-400 hover:text-amber-600 transition-colors p-0.5"'
                          + '              data-empty-id="' + p.id + '" title="' + (LPC.t ? LPC.t('ui.x.modifier_le_prix') : 'Modifier le prix')
                          + '" aria-label="' + (LPC.t ? LPC.t('ui.x.modifier_le_prix') : 'Modifier le prix') + '"><i class="fas fa-pen text-[9px]"></i></button>'
                        : '')
                    + '    </span>'
                    + '  </div>'
                    + '  <input type="number" id="rec_' + p.id + '" data-empty-id="' + p.id + '" min="0" value="0" oninput="calcRecycleTotal()"'
                    + '         class="w-full border ' + bordCls + ' rounded-lg p-2 outline-none focus:border-amber-500">'
                    + '</div>';
            });
            inner += '</div>';
            card.innerHTML = inner;
            grid.appendChild(card);
        });
        renderOverrideBanner();
    }

    function renderOverrideBanner() {
        const banner = document.getElementById('override_banner');
        const list   = document.getElementById('override_list');
        if (!banner || !list) return;
        const ids = Object.keys(priceOverrides);
        if (ids.length === 0) { banner.classList.add('hidden'); list.innerHTML = ''; return; }
        list.innerHTML = '';
        ids.forEach(function (pid) {
            const p  = globalEmptyCatalog.find(function (x) { return String(x.id) === String(pid); });
            const ov = priceOverrides[pid];
            if (!p) return;
            list.insertAdjacentHTML('beforeend', LPC.html`
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-[11px] font-black text-orange-900 leading-tight">${p.name}</p>
                        <p class="text-[10px] font-bold text-orange-700">${fmt(basePriceOf(p.id))} F → ${fmt(ov.price)} F</p>
                        <p class="text-[10px] text-orange-600 italic break-words">${ov.reason}</p>
                    </div>
                    <button type="button" class="js-clear-override shrink-0 text-orange-400 hover:text-rose-600 p-1" data-empty-id="${p.id}" title="Rétablir le prix catalogue" aria-label="Rétablir le prix catalogue">
                        <i class="fas fa-times-circle"></i>
                    </button>
                </div>`);
        });
        banner.classList.remove('hidden');
    }

    function refreshRecyclingPriceLabels() {
        globalEmptyCatalog.forEach(function (p) {
            const labelEl = document.getElementById('price_' + p.id);
            if (!labelEl) return;
            const base = basePriceOf(p.id);
            const ov   = priceOverrides[p.id];
            if (ov) {
                labelEl.className = 'text-[10px] font-black text-orange-600';
                labelEl.innerHTML = LPC.html`<span class="line-through text-gray-400 font-bold mr-1">${fmt(base)}</span>${fmt(ov.price)} F`;
                labelEl.title = 'Prix négocié — ' + ov.reason;
            } else {
                labelEl.className = 'text-[10px] font-black text-amber-600';
                labelEl.textContent = fmt(base) + ' F';
                labelEl.title = '';
            }
        });
        renderOverrideBanner();
    }

    function openPriceModal(productId) {
        if (!canOverridePrice) return;
        const p = globalEmptyCatalog.find(function (x) { return String(x.id) === String(productId); });
        if (!p) return LPC.modal.alert(LPC.t ? LPC.t('ui.x.catalogue_non_charge') : 'Catalogue non chargé. Réessayez.');
        priceModalProductId = p.id;
        const base = basePriceOf(p.id);
        const ov   = priceOverrides[p.id];
        document.getElementById('price_modal_product').textContent = p.name;
        document.getElementById('price_modal_base').textContent    = fmt(base);
        document.getElementById('price_modal_input').value         = ov ? ov.price : base;
        document.getElementById('price_modal_reason').value        = ov ? ov.reason : '';
        document.getElementById('price_modal_reset').classList.toggle('hidden', !ov);
        hidePriceModalError();
        updatePriceDelta();
        openModal('modal-price');
        setTimeout(function () {
            const input = document.getElementById('price_modal_input');
            input.focus(); input.select();
        }, 50);
    }

    function updatePriceDelta() {
        const el = document.getElementById('price_modal_delta');
        if (!el || priceModalProductId === null) return;
        const base = basePriceOf(priceModalProductId);
        const val  = parseFloat(document.getElementById('price_modal_input').value);
        if (isNaN(val)) { el.textContent = ''; return; }
        const delta = val - base;
        if (Math.abs(delta) < 0.005) {
            el.className = 'text-[11px] font-bold mt-1.5 h-4 text-gray-400';
            el.textContent = LPC.t ? LPC.t('ui.x.identique_au_prix_catalogue') : 'Identique au prix catalogue';
            return;
        }
        const pct = base > 0 ? ' (' + (delta > 0 ? '+' : '') + ((delta / base) * 100).toFixed(1) + '%)' : '';
        el.className = 'text-[11px] font-bold mt-1.5 h-4 ' + (delta > 0 ? 'text-emerald-600' : 'text-rose-600');
        el.textContent = (delta > 0 ? '+' : '') + fmt(delta) + ' F par unité' + pct;
    }

    function showPriceModalError(msg) {
        const el = document.getElementById('price_modal_error');
        el.textContent = msg;
        el.classList.remove('hidden');
    }
    function hidePriceModalError() {
        document.getElementById('price_modal_error').classList.add('hidden');
    }

    function applyPriceOverride() {
        if (priceModalProductId === null) return;
        const base   = basePriceOf(priceModalProductId);
        const raw    = document.getElementById('price_modal_input').value;
        const reason = document.getElementById('price_modal_reason').value.trim();
        const price  = parseFloat(raw);

        if (raw === '' || isNaN(price))  return showPriceModalError('Saisissez un prix valide.');
        if (price < 0)                   return showPriceModalError('Le prix ne peut pas être négatif.');
        if (price > 1000000)             return showPriceModalError('Prix irréaliste (max 1 000 000 FCFA/unité).');
        if (base > 0 && price > base * 10) {
            return showPriceModalError('Prix irréaliste : ' + fmt(price) + ' F contre ' + fmt(base) + ' F au catalogue. Vérifiez la saisie.');
        }
        if (Math.abs(price - base) < 0.005) {
            delete priceOverrides[priceModalProductId];
            closeModal('modal-price');
            refreshRecyclingPriceLabels();
            calcRecycleTotal();
            return;
        }
        if (reason.length < 5) return showPriceModalError('Motif requis (au moins 5 caractères) — il sera enregistré avec votre nom.');

        priceOverrides[priceModalProductId] = { price: Math.round(price * 100) / 100, reason: reason.slice(0, 500) };
        closeModal('modal-price');
        refreshRecyclingPriceLabels();
        calcRecycleTotal();
    }
    window.applyPriceOverride = applyPriceOverride;

    function resetPriceOverride() {
        if (priceModalProductId !== null) delete priceOverrides[priceModalProductId];
        closeModal('modal-price');
        refreshRecyclingPriceLabels();
        calcRecycleTotal();
    }
    window.resetPriceOverride = resetPriceOverride;

    function calcRecycleTotal() {
        let total = 0;
        globalEmptyCatalog.forEach(function (p) {
            const el = document.getElementById('rec_' + p.id);
            const qty = el ? parseInt(el.value, 10) || 0 : 0;
            total += qty * effectivePriceOf(p.id);
        });
        const dst = document.getElementById('recycle_total');
        if (dst) dst.textContent = fmt(total);
    }
    window.calcRecycleTotal = calcRecycleTotal;

    async function submitRecycling() {
        if (!canSellRecycler) return LPC.modal.alert(LPC.t ? LPC.t('ui.x.non_autorise') : 'Non autorisé.');
        const location = document.getElementById('recycler_location').value.trim();
        if (!location) return LPC.modal.alert('Veuillez saisir le lieu de recyclage.');

        const itemsToSell = [];
        let overridesInSale = 0;
        globalEmptyCatalog.forEach(function (p) {
            const el = document.getElementById('rec_' + p.id);
            const qty = el ? parseInt(el.value, 10) || 0 : 0;
            if (qty <= 0) return;
            const item = { product_id: p.id, quantity: qty };
            const ov = priceOverrides[p.id];
            if (ov) { item.unit_price = ov.price; item.price_reason = ov.reason; overridesInSale++; }
            itemsToSell.push(item);
        });
        if (itemsToSell.length === 0) return LPC.modal.alert('Veuillez saisir au moins une quantité à vendre.');

        if (overridesInSale > 0) {
            let rows = '';
            itemsToSell.filter(function (i) { return i.unit_price !== undefined; }).forEach(function (i) {
                const p = globalEmptyCatalog.find(function (x) { return x.id === i.product_id; });
                rows += LPC.html`
                    <li style="margin-bottom:.5rem">
                        <strong>${p ? p.name : i.product_id}</strong><br>
                        ${fmt(basePriceOf(i.product_id))} F → <strong>${fmt(i.unit_price)} F</strong><br>
                        <em style="opacity:.7">${priceOverrides[i.product_id].reason}</em>
                    </li>`;
            });
            const ok = await LPC.modal.custom({
                level: 'warn',
                title: 'Confirmer les prix négociés',
                bodyHtml:
                    '<p><strong>' + overridesInSale + '</strong> prix hors catalogue sur cette vente :</p>' +
                    '<ul style="margin:.75rem 0;padding-left:1.1rem">' + rows + '</ul>' +
                    '<p>Votre nom et le motif seront enregistrés de façon permanente.</p>',
                buttons: [
                    { label: 'Annuler',            value: false, variant: 'secondary' },
                    { label: 'Confirmer la vente', value: true,  variant: 'primary', primary: true },
                ],
            });
            if (!ok) return;
        }

        const btn = document.getElementById('btn-submit-recycling');
        const oldHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin text-lg"></i> Validation...';
        btn.disabled = true;

        try {
            const res = await fetch('/api/v1/cre_controller.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'sell_to_recycler', location: location, items: itemsToSell }),
            });
            const result = await res.json();
            if (result.status === 'success') {
                const note = result.data.override_count > 0
                    ? ' — ' + result.data.override_count + ' prix négocié(s) enregistré(s) à votre nom.'
                    : '';
                LPC.modal.alert('Vente validée ! Montant attendu en caisse : ' + fmt(result.data.total_amount) + ' FCFA' + note);
                document.getElementById('form-recycle').reset();
                priceOverrides = {};
                renderRecyclingGrid();
                calcRecycleTotal();
                loadEmptiesKPIs();
            } else {
                LPC.modal.alert('Erreur: ' + result.message);
            }
        } catch (e) {
            LPC.modal.alert('Erreur réseau.');
        } finally {
            btn.innerHTML = oldHtml;
            btn.disabled = false;
        }
    }
    window.submitRecycling = submitRecycling;

    // ---------------------------------------------------------------------
    // TAB: HISTORIQUE — table + pagination + search
    // ---------------------------------------------------------------------
    function setupHistoryPager() {
        if (!window.LPC || !LPC.paginator) return;
        const tbody = document.getElementById('tbody-history');
        if (!tbody) return;
        historyPager = LPC.paginator.attach(tbody, {
            url:         '/api/v1/cre_controller.php?action=get_history',
            dataPath:    'data',
            envelopePath:'pagination',
            searchInput: document.getElementById('history-search'),
            hashKey:     'cre_hist',
            emptyMsg:    LPC.t ? LPC.t('ui.x.aucun_historique') : 'Aucun historique.',
            colspan:     5,
            renderRow:   renderHistoryRow,
        });
    }

    async function loadHistory() {
        if (historyPager) return historyPager.reload();
        // Fallback path (paginator unavailable) — best-effort first page.
        try {
            const res = await fetch('/api/v1/cre_controller.php?action=get_history');
            const result = await res.json();
            const tbody = document.getElementById('tbody-history');
            if (!tbody) return;
            const rows = result.data || [];
            tbody.innerHTML = '';
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-8 text-gray-400 font-bold">'
                                + (LPC.t ? LPC.t('ui.x.aucun_historique') : 'Aucun historique.') + '</td></tr>';
                return;
            }
            rows.forEach(function (r) { tbody.insertAdjacentHTML('beforeend', renderHistoryRow(r)); });
        } catch (e) {
            const tbody = document.getElementById('tbody-history');
            if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-center py-8 text-red-500 font-bold">Erreur réseau.</td></tr>';
        }
    }
    window.loadHistory = loadHistory;

    /**
     * Render one CRE row. Returns an HTML string (paginator expects that
     * shape from renderRow, and the fallback path uses insertAdjacentHTML).
     */
    function renderHistoryRow(cre) {
        const d = new Date(cre.created_at || cre.date);
        const dateStr = isNaN(d.getTime()) ? '' : d.toLocaleDateString('fr-FR');
        const detail  = '~' + ((cre.qty_20c || 0) + (cre.qty_20n || 0) + (cre.qty_10c || 0) + (cre.qty_10n || 0)) + ' u.';
        const statusBadge = LPC.raw(
            cre.status === 'en_transit'   ? '<span class="bg-amber-100 text-amber-700 px-2 py-1 rounded text-[9px] font-black uppercase"><i class="fas fa-clock"></i> En Attente</span>' :
            cre.status === 'signed'       ? '<span class="bg-green-100 text-green-700 px-2 py-1 rounded text-[9px] font-black uppercase"><i class="fas fa-check-double"></i> Signé</span>' :
            cre.status === 'cancelled'    ? '<span class="bg-gray-200 text-gray-700 px-2 py-1 rounded text-[9px] font-black uppercase"><i class="fas fa-ban"></i> Annulé</span>' :
                                            '<span class="bg-rose-100 text-rose-700 px-2 py-1 rounded text-[9px] font-black uppercase"><i class="fas fa-times"></i> Refusé</span>'
        );

        let actionBtn = LPC.raw('');
        if (cre.status === 'en_transit') {
            actionBtn = LPC.raw(LPC.html`
                <button type="button" class="js-cre-reshare text-lpc-dark font-black text-xs bg-green-50 hover:bg-green-100 px-3 py-1.5 rounded-lg border border-green-200"
                        data-ref="${cre.reference}" data-token="${cre.token}" data-phone="${cre.client_phone || ''}">
                    <i class="fas fa-share-alt mr-1"></i> Renvoyer
                </button>
                <button type="button" class="js-cre-cancel text-red-700 font-black text-xs bg-red-50 hover:bg-red-100 px-3 py-1.5 rounded-lg border border-red-200 ml-2"
                        data-id="${cre.id}" data-ref="${cre.reference}">
                    <i class="fas fa-ban mr-1"></i> Annuler
                </button>`);
        } else if (cre.status === 'signed') {
            actionBtn = LPC.raw(LPC.html`
                <a href="/print_cre.php?token=${cre.token}" target="_blank" class="text-gray-700 font-black text-xs bg-gray-100 hover:bg-gray-200 px-3 py-1.5 rounded-lg border border-gray-300">
                    <i class="fas fa-file-pdf text-red-500 mr-1"></i> PDF
                </a>`);
        }

        return LPC.html`
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="py-3 px-4 text-xs font-bold text-gray-500">${dateStr}<br><span class="text-[10px] font-black text-lpc-dark">${cre.reference}</span></td>
                <td class="py-3 px-4 font-black text-gray-900">${cre.client_name}<br><span class="text-[10px] font-bold text-gray-400">${cre.client_phone || ''}</span></td>
                <td class="py-3 px-4 text-center text-xs font-bold text-gray-600">${detail}</td>
                <td class="py-3 px-4 text-center">${statusBadge}</td>
                <td class="py-3 px-4 text-right whitespace-nowrap">${actionBtn}</td>
            </tr>`;
    }

    // Delegated listeners on the history table (rows are re-painted on each
    // page change, so per-element bindings would go stale).
    document.addEventListener('click', function (ev) {
        const reshare = ev.target.closest('.js-cre-reshare');
        if (reshare) {
            showShareModal(reshare.dataset.ref, reshare.dataset.token, reshare.dataset.phone);
            return;
        }
        const cancel = ev.target.closest('.js-cre-cancel');
        if (cancel) {
            document.getElementById('cancel_cre_id').value  = cancel.dataset.id;
            document.getElementById('cancel_cre_ref').textContent = cancel.dataset.ref;
            document.getElementById('cancel_cre_reason').value = '';
            openModal('modal-cancel-cre');
            return;
        }
        const editBtn = ev.target.closest('.js-edit-price');
        if (editBtn) { openPriceModal(editBtn.dataset.emptyId); return; }
        const clearBtn = ev.target.closest('.js-clear-override');
        if (clearBtn) {
            delete priceOverrides[clearBtn.dataset.emptyId];
            refreshRecyclingPriceLabels();
            calcRecycleTotal();
        }
    });

    document.addEventListener('input', function (ev) {
        if (ev.target && ev.target.id === 'price_modal_input') updatePriceDelta();
    });

    async function submitCancelCRE() {
        const cid = document.getElementById('cancel_cre_id').value;
        const reason = document.getElementById('cancel_cre_reason').value.trim();
        if (!reason) return LPC.modal.alert(LPC.t ? LPC.t('ui.x.motif_requis') : 'Motif requis.');
        try {
            const res = await fetch('/api/v1/cre_controller.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'cancel_cre', cre_id: cid, reason: reason }),
            });
            const result = await res.json();
            if (result.status === 'success') {
                closeModal('modal-cancel-cre');
                loadHistory();
                loadEmptiesKPIs();
                LPC.modal.alert(LPC.t ? LPC.t('ui.x.cre_annule') : 'CRE annulé.');
            } else {
                LPC.modal.alert(result.message || 'Erreur.');
            }
        } catch (e) {
            LPC.modal.alert('Erreur réseau.');
        }
    }
    window.submitCancelCRE = submitCancelCRE;

    // ---------------------------------------------------------------------
    // TAB: REVENUS RECYCLAGE — period-scoped KPIs + sales log + overrides
    // ---------------------------------------------------------------------
    function currentPeriodParams() {
        const type = (document.getElementById('rev_period_type') || {}).value || 'ytd';
        const params = new URLSearchParams();
        params.set('period', type);
        if (type === 'custom') {
            const s = (document.getElementById('rev_start_month') || {}).value || '';
            const e = (document.getElementById('rev_end_month')   || {}).value || '';
            if (s) params.set('start', s + '-01');
            if (e) {
                // End of the picked month.
                const [y, m] = e.split('-').map(Number);
                const eom = new Date(y, m, 0);   // day 0 of next month = last day of this month
                params.set('end', y + '-' + String(m).padStart(2, '0') + '-' + String(eom.getDate()).padStart(2, '0'));
            }
        }
        return params;
    }

    async function loadRevenueData() {
        if (!canViewRevenue) return;
        const tbody = document.getElementById('tbody-revenue');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-8 text-gray-400 font-bold"><i class="fas fa-spinner fa-spin"></i> Chargement...</td></tr>';
        try {
            const res = await fetch('/api/v1/cre_controller.php?action=get_recycling_revenue&' + currentPeriodParams().toString());
            const result = await res.json();
            if (result.status !== 'success') {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center py-8 text-red-500">' + LPC.escapeHtml(result.message || 'Erreur') + '</td></tr>';
                return;
            }
            const s = result.data.stats || {};
            document.getElementById('kpi_rev_total').textContent = LPC.fmt.fcfa(Number(s.total_revenue) || 0);
            const lbl = document.getElementById('kpi_rev_period_label');
            if (lbl) lbl.textContent = s.period_label || '';
            document.getElementById('stat_20c').textContent = s.qty_20c || 0;
            document.getElementById('stat_20n').textContent = s.qty_20n || 0;
            document.getElementById('stat_10c').textContent = s.qty_10c || 0;
            document.getElementById('stat_10n').textContent = s.qty_10n || 0;

            tbody.innerHTML = '';
            if (!result.data.table.length) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center py-8 text-gray-400">Aucune vente sur la période.</td></tr>';
            } else {
                result.data.table.forEach(function (r) {
                    const d = new Date(r.created_at);
                    const dateStr = d.toLocaleDateString('fr-FR');
                    const badge = Number(r.override_count) > 0
                        ? LPC.raw(LPC.html`<span class="ml-1 inline-block bg-orange-100 text-orange-700 px-1.5 py-0.5 rounded text-[9px] font-black uppercase" title="Prix négocié(s)"><i class="fas fa-tag"></i> ${r.override_count} prix négocié</span>`)
                        : '';
                    tbody.insertAdjacentHTML('beforeend', LPC.html`
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="py-3 px-4 text-xs font-bold text-gray-500">${dateStr}<br><span class="text-[10px] font-black text-amber-600">${r.reference}</span></td>
                            <td class="py-3 px-4 font-black text-gray-900">${r.driver_name}</td>
                            <td class="py-3 px-4">
                                <p class="text-[10px] text-gray-400 font-black uppercase mb-1">${r.recycler_location}</p>
                                <p class="text-xs font-bold text-blue-600 italic">${r.details}</p>
                                ${badge}
                            </td>
                            <td class="py-3 px-4 text-right font-black text-emerald-600 text-lg">${fmt(r.total_amount)} F</td>
                        </tr>`);
                });
            }
            loadPriceOverrides();
        } catch (e) { console.error(e); }
    }
    window.loadRevenueData = loadRevenueData;

    async function loadPriceOverrides() {
        const box = document.getElementById('override_log');
        if (!box) return;
        try {
            const res = await fetch('/api/v1/cre_controller.php?action=get_price_overrides&' + currentPeriodParams().toString());
            const result = await res.json();
            const rows = (result && result.data) || [];
            if (rows.length === 0) {
                box.innerHTML = '<p class="text-center py-6 text-gray-400 font-bold text-xs">Aucun prix négocié enregistré.</p>';
                return;
            }
            box.innerHTML = '';
            rows.forEach(function (o) {
                const when   = new Date(o.created_at).toLocaleString('fr-FR');
                const up     = Number(o.delta_amount) > 0;
                const pct    = (o.delta_pct === null || o.delta_pct === undefined)
                    ? '' : ' (' + (Number(o.delta_pct) > 0 ? '+' : '') + Number(o.delta_pct).toFixed(1) + '%)';
                const impact = Number(o.total_impact);
                box.insertAdjacentHTML('beforeend', LPC.html`
                    <div class="p-3 border-b border-gray-100 last:border-0">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-black text-gray-900">${o.product_name}
                                    <span class="text-[10px] font-bold text-gray-400 ml-1">${o.sale_reference || ''}</span>
                                </p>
                                <p class="text-[11px] font-bold text-gray-600 mt-0.5">
                                    ${fmt(o.base_price)} F → <span class="${up ? 'text-emerald-600' : 'text-rose-600'}">${fmt(o.override_price)} F</span>${pct}
                                    <span class="text-gray-400">· ${o.quantity} u.</span>
                                </p>
                                <p class="text-[11px] text-gray-500 italic mt-1 break-words">« ${o.reason} »</p>
                                <p class="text-[10px] font-bold text-gray-400 mt-1">
                                    <i class="fas fa-user mr-1"></i>${o.user_name || 'Utilisateur supprimé'} · ${when}
                                    ${o.recycler_location ? LPC.raw(LPC.html` · <span>${o.recycler_location}</span>`) : ''}
                                </p>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="text-[9px] font-black uppercase text-gray-400">Impact</p>
                                <p class="text-sm font-black ${impact > 0 ? 'text-emerald-600' : 'text-rose-600'}">${impact > 0 ? '+' : ''}${fmt(impact)} F</p>
                            </div>
                        </div>
                    </div>`);
            });
        } catch (e) {
            box.innerHTML = '<p class="text-center py-6 text-gray-400 font-bold text-xs">Registre indisponible.</p>';
        }
    }

    // ---------------------------------------------------------------------
    // DEEPLINK — arriving from CRM "Dette Emballages" drill-down.
    // Narrow the Dus table + pre-select the client in the collect form.
    // ---------------------------------------------------------------------
    function arriveFromDeeplink() {
        if (!window.LPC || !LPC.deeplink) return;
        LPC.deeplink.arrive(['#tbody-owed']);
        let tries = 0;
        const timer = setInterval(function () {
            const sel = document.getElementById('client_id');
            if (sel && sel.querySelector('option[value="' + LPC.deeplink.clientId + '"]')) {
                sel.value = String(LPC.deeplink.clientId);
                loadSites();
                clearInterval(timer);
            } else if (++tries > 40) {
                clearInterval(timer);
            }
        }, 100);
    }
})();
