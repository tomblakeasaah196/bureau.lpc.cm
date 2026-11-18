/**
 * assets/js/modules/admin-chart_of_accounts.js
 * -----------------------------------------------------------------------------
 * Plan Comptable (modules/admin/chart_of_accounts.php), rebuilt with the
 * owner's decision 14 (18/11/2026):
 *   · a tab per SYSCOHADA class, 1 to 9, and a search box over the whole chart;
 *   · a tree: each OHADA parent with its sub-parents and its sub-accounts
 *     folded under it (the parent's own posting row shows on the parent's line);
 *   · add a sub-account under any parent (the next free code is proposed),
 *     rename, deactivate, and delete only what nothing uses (no journal line,
 *     no client, supplier, treasury account, product, category, loan or
 *     setting: the API says why otherwise);
 *   · the expense categories panel, unchanged in what it does.
 *
 * Reads: accounting_controller.php?tab=chart_admin (the chart, each row's
 * posted balance, its line count and what links to it) and
 * expenses_controller.php?action=categories. Writes: accounting_controller.php
 * POST actions; the CSRF header is added by lpc-rbac.js.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    const API   = '/api/v1/accounting_controller.php';
    const $root = document.getElementById('coa-root');

    const CLASSES = {
        1: 'Ressources durables',
        2: 'Actif immobilisé',
        3: 'Stocks',
        4: 'Tiers',
        5: 'Trésorerie',
        6: 'Charges',
        7: 'Produits',
        8: 'Autres charges et produits',
        9: 'Analytique',
    };
    const TYPE_LABEL = {
        asset: 'Actif', liability: 'Passif', equity: 'Capitaux propres',
        revenue: 'Produit', expense: 'Charge', other: 'Autre',
    };

    let STATE = {
        ohada: [], coa: [], cats: [], cats_denied: false,
        can_edit: false, can_create: false, can_delete: false,
    };
    const UI = { cls: 1, q: '', inactive: false, open: new Set() };

    // ---------- utils ----------------------------------------------------
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));
    const fmt = (n) => Math.round(Math.abs(+n || 0)).toLocaleString('fr-FR');
    /** A balance as accountants read it: 519 320 D, 1 000 000 C. */
    const bal = (n) => {
        n = Math.round(+n || 0);
        if (!n) return '<span class="text-gray-300">0</span>';
        return `${fmt(n)} <span class="text-[10px] font-black ${n > 0 ? 'text-sky-700' : 'text-amber-700'}">${n > 0 ? 'D' : 'C'}</span>`;
    };
    const classOf = (number) => String(number || '').charAt(0);

    function toast(msg, type = 'success') {
        if (window.LPC?.toast) return LPC.toast(msg, type);
        alert(msg);
    }
    async function ask(msg) {
        if (window.LPC?.modal?.confirm) return LPC.modal.confirm(msg);
        return confirm(msg);
    }
    async function api(payload) {
        const res = await fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const json = await res.json().catch(() => ({ status: 'error', message: 'Réponse invalide du serveur.' }));
        if (json.status !== 'success') throw new Error(json.message || 'Erreur inconnue.');
        return json;
    }

    async function loadAll() {
        const [chartRes, catsRes] = await Promise.all([
            fetch(`${API}?tab=chart_admin`).then(r => r.json()),
            // Gated by accounting.expenses.view, which a chart-only role may not hold.
            fetch('/api/v1/expenses_controller.php?action=categories')
                .then(r => r.json().then(j => ({ ok: r.ok, body: j })))
                .catch(() => ({ ok: false, body: { status: 'error' } })),
        ]);
        if (chartRes.status !== 'success') throw new Error(chartRes.message || 'Chargement du plan comptable échoué.');
        const d = chartRes.data;
        STATE.ohada      = (d.ohada_masters || []).map(o => ({ ...o, id: +o.id }));
        STATE.coa        = (d.coa || []).map(c => ({ ...c, id: +c.id, ohada_account_id: +c.ohada_account_id, is_active: +c.is_active }));
        STATE.can_edit   = !!d.can_edit;
        STATE.can_create = !!d.can_create;
        STATE.can_delete = !!d.can_delete;

        const catBody = catsRes?.body;
        if (catBody?.status === 'success') {
            const cd = catBody.data;
            STATE.cats = Array.isArray(cd) ? cd : (Array.isArray(cd?.categories) ? cd.categories : []);
            STATE.cats_denied = false;
        } else {
            STATE.cats = [];
            STATE.cats_denied = true;
        }
        buildTree();
    }

    // ---------- the tree --------------------------------------------------
    // Each OHADA row's parent is the longest other OHADA number that starts it
    // (2441 under 244, 552 under 55); chart rows hang under their OHADA row.
    let NODES = new Map();   // ohada id → {o, parent, kids: [node], own: coa|null, subs: [coa]}
    let ROOTS = [];
    function buildTree() {
        NODES = new Map();
        const byNumber = new Map();
        STATE.ohada.forEach(o => {
            const n = { o, parent: null, kids: [], own: null, subs: [] };
            NODES.set(o.id, n);
            byNumber.set(String(o.account_number), n);
        });
        NODES.forEach(n => {
            const num = String(n.o.account_number);
            for (let len = num.length - 1; len >= 1; len--) {
                const p = byNumber.get(num.slice(0, len));
                if (p) { n.parent = p; p.kids.push(n); break; }
            }
        });
        STATE.coa.forEach(c => {
            const n = NODES.get(c.ohada_account_id);
            if (!n) return;
            if (String(c.code) === String(n.o.account_number)) n.own = c;
            else n.subs.push(c);
        });
        const byNum = (a, b) => String(a.o.account_number).localeCompare(String(b.o.account_number));
        NODES.forEach(n => {
            n.kids.sort(byNum);
            n.subs.sort((a, b) => String(a.code).localeCompare(String(b.code)));
        });
        ROOTS = [...NODES.values()].filter(n => !n.parent).sort(byNum);
    }

    const rowVisible = (c) => UI.inactive || c.is_active;
    const rowMatches = (c) => !UI.q || `${c.code} ${c.name}`.toLowerCase().includes(UI.q);
    const nodeSelfMatches = (n) => !UI.q || `${n.o.account_number} ${n.o.name}`.toLowerCase().includes(UI.q);

    /** Does the node, or anything under it, show for the current search? */
    function nodeShown(n) {
        if (nodeSelfMatches(n)) return true;
        if (n.own && rowVisible(n.own) && rowMatches(n.own)) return true;
        if (n.subs.some(c => rowVisible(c) && rowMatches(c))) return true;
        return n.kids.some(nodeShown);
    }
    /** The node's balance: its own row, its sub-accounts and its sub-parents. */
    function nodeBalance(n) {
        let s = 0;
        if (n.own) s += +n.own.balance || 0;
        n.subs.forEach(c => { s += +c.balance || 0; });
        n.kids.forEach(k => { s += nodeBalance(k); });
        return s;
    }
    function countInClass(cls) {
        return ROOTS.filter(n => classOf(n.o.account_number) === String(cls) && nodeShown(n)).length;
    }

    // ---------- rendering -------------------------------------------------
    function render() {
        const readonly = !STATE.can_edit;
        $root.innerHTML = `
            ${readonly ? `
              <div class="bg-amber-50 border border-amber-200 text-amber-900 px-4 py-3 rounded-xl text-sm font-bold">
                Mode lecture seule : modifier le plan comptable demande la permission <code class="text-xs">accounting.chart.edit</code>.
              </div>` : ''}

            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm">
                <header class="flex flex-wrap items-center gap-3 px-4 sm:px-6 py-4 border-b border-gray-100">
                    <div class="flex-1 min-w-[220px]">
                        <label for="coa-search" class="sr-only">Rechercher un compte</label>
                        <input type="search" id="coa-search" value="${esc(UI.q)}" placeholder="Rechercher un code ou un nom (ex. 5521, Smart, caisse)"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" autocomplete="off">
                    </div>
                    <label class="flex items-center gap-2 text-xs text-gray-600 font-bold cursor-pointer">
                        <input type="checkbox" id="show-inactive" ${UI.inactive ? 'checked' : ''}> Comptes désactivés
                    </label>
                    <button type="button" id="btn-fold" class="text-xs font-bold text-lpc-dark hover:underline">Tout replier</button>
                    ${STATE.can_create ? `
                    <button type="button" id="btn-add-ohada" class="bg-lpc-dark hover:bg-green-800 text-white px-4 py-2 rounded-xl font-bold text-xs shadow-sm">
                        + Compte OHADA
                    </button>` : ''}
                </header>
                <div role="tablist" aria-label="Classes du plan comptable" id="coa-tabs"
                     class="flex gap-1 overflow-x-auto px-2 sm:px-4 border-b border-gray-100"></div>
                <div id="coa-tree" role="tabpanel" class="divide-y divide-gray-100"></div>
            </section>

            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm">
                <header class="px-4 sm:px-6 py-4 border-b border-gray-100">
                    <h2 class="font-black text-lpc-dark tracking-wide">Catégories de dépense</h2>
                    <p class="text-xs text-gray-500 mt-0.5">Chaque catégorie poste sur un compte du plan comptable.</p>
                </header>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="text-left px-6 py-2 font-bold">Catégorie</th>
                                <th class="text-left px-6 py-2 font-bold">Compte</th>
                                <th class="text-right px-6 py-2 font-bold w-32"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody id="cats-body" class="divide-y divide-gray-100"></tbody>
                    </table>
                </div>
            </section>
        `;

        const $search = document.getElementById('coa-search');
        $search.addEventListener('input', () => {
            UI.q = $search.value.trim().toLowerCase();
            // Searching jumps to a class that has matches when the current one has none.
            if (UI.q && !countInClass(UI.cls)) {
                const first = Object.keys(CLASSES).find(k => countInClass(k));
                if (first) UI.cls = +first;
            }
            renderTabs(); renderTree();
        });
        document.getElementById('show-inactive').addEventListener('change', (e) => {
            UI.inactive = e.target.checked; renderTabs(); renderTree();
        });
        document.getElementById('btn-fold').addEventListener('click', () => { UI.open.clear(); renderTree(); });
        document.getElementById('btn-add-ohada')?.addEventListener('click', addOhadaModal);

        renderTabs();
        renderTree();
        renderCategories();
    }

    function renderTabs() {
        const $tabs = document.getElementById('coa-tabs');
        $tabs.innerHTML = Object.entries(CLASSES).map(([k, label]) => {
            const on = +k === UI.cls;
            const n = countInClass(k);
            return `<button type="button" role="tab" aria-selected="${on}" data-cls="${k}"
                        class="shrink-0 px-3 py-3 text-xs font-bold border-b-2 ${on ? 'border-lpc-dark text-lpc-dark' : 'border-transparent text-gray-500 hover:text-gray-800'}">
                        <span class="font-black">${k}</span> ${esc(label)}
                        <span class="ml-1 text-[10px] ${n ? 'text-gray-400' : 'text-gray-300'}">${n}</span>
                    </button>`;
        }).join('');
        $tabs.querySelectorAll('[data-cls]').forEach(b => b.addEventListener('click', () => {
            UI.cls = +b.dataset.cls; renderTabs(); renderTree();
        }));
    }

    function renderTree() {
        const $tree = document.getElementById('coa-tree');
        const roots = ROOTS.filter(n => classOf(n.o.account_number) === String(UI.cls) && nodeShown(n));
        if (!roots.length) {
            $tree.innerHTML = `<p class="px-6 py-8 text-center text-gray-400 text-sm">${UI.q ? 'Aucun compte ne correspond à la recherche dans cette classe.' : 'Aucun compte dans cette classe.'}</p>`;
            return;
        }
        $tree.innerHTML = roots.map(n => nodeHtml(n, 0)).join('');
        $tree.querySelectorAll('[data-toggle]').forEach(b => b.addEventListener('click', () => {
            const id = +b.dataset.toggle;
            UI.open.has(id) ? UI.open.delete(id) : UI.open.add(id);
            renderTree();
        }));
        $tree.querySelectorAll('[data-add-sub]').forEach(b => b.addEventListener('click', () => addSubModal(+b.dataset.addSub)));
        $tree.querySelectorAll('[data-rename-ohada]').forEach(b => b.addEventListener('click', () => renameOhadaModal(+b.dataset.renameOhada)));
        $tree.querySelectorAll('[data-rename-coa]').forEach(b => b.addEventListener('click', () => renameCoaModal(+b.dataset.renameCoa)));
        $tree.querySelectorAll('[data-toggle-coa]').forEach(b => b.addEventListener('click', () => toggleCoaActive(+b.dataset.toggleCoa, +b.dataset.targetState)));
        $tree.querySelectorAll('[data-delete-coa]').forEach(b => b.addEventListener('click', () => deleteCoa(+b.dataset.deleteCoa)));
    }

    function nodeHtml(n, depth) {
        const subs = n.subs.filter(c => rowVisible(c) && (rowMatches(c) || nodeSelfMatches(n)));
        const kids = n.kids.filter(nodeShown);
        const hasChildren = subs.length + kids.length > 0;
        // Searching opens what holds a match; otherwise the person decides.
        const open = hasChildren && (UI.open.has(n.o.id) || (UI.q && !nodeSelfMatches(n)) || (UI.q && subs.some(rowMatches) && UI.q.length > 1));
        const pad = 12 + depth * 20;
        const own = n.own && rowVisible(n.own) ? n.own : null;
        const count = n.subs.length;

        let html = `
            <div class="flex items-center gap-2 pr-3 sm:pr-6 py-2 ${depth ? 'bg-gray-50/40' : ''}" style="padding-left:${pad}px">
                ${hasChildren
                    ? `<button type="button" data-toggle="${n.o.id}" aria-expanded="${open}" aria-label="${open ? 'Replier' : 'Déplier'} ${esc(n.o.account_number)}"
                              class="w-6 h-6 shrink-0 rounded hover:bg-gray-100 text-gray-500 text-xs">${open ? '▾' : '▸'}</button>`
                    : '<span class="w-6 shrink-0"></span>'}
                <span class="font-mono font-black text-sm text-gray-900 w-16 shrink-0">${esc(n.o.account_number)}</span>
                <span class="flex-1 min-w-0">
                    <span class="font-bold text-gray-800 text-sm">${esc(n.o.name)}</span>
                    ${count ? `<span class="text-[10px] font-bold text-gray-500 bg-gray-100 rounded-full px-2 py-0.5 ml-1">${count} sous-compte${count > 1 ? 's' : ''}</span>` : ''}
                    ${own && !own.is_active ? '<span class="text-[10px] font-bold text-gray-500 ml-1">(compte propre inactif)</span>' : ''}
                </span>
                <span class="font-mono text-xs text-gray-700 text-right w-32 shrink-0" title="Solde des écritures validées">${bal(nodeBalance(n))}</span>
                <span class="flex items-center justify-end gap-3 w-72 shrink-0 text-xs font-bold whitespace-nowrap">
                    ${STATE.can_create ? `<button type="button" data-add-sub="${n.o.id}" class="text-lpc-dark hover:underline">+ Sous-compte</button>` : ''}
                    ${STATE.can_edit ? `<button type="button" data-rename-ohada="${n.o.id}" class="text-gray-600 hover:underline">Renommer</button>` : ''}
                    ${own && STATE.can_edit ? `<button type="button" data-toggle-coa="${own.id}" data-target-state="${own.is_active ? 0 : 1}"
                        class="${own.is_active ? 'text-rose-600' : 'text-emerald-700'} hover:underline" title="Le compte propre ${esc(own.code)}">${own.is_active ? 'Désactiver' : 'Réactiver'}</button>` : ''}
                </span>
            </div>`;
        if (open) {
            html += subs.map(c => subHtml(c, depth + 1)).join('');
            html += kids.map(k => nodeHtml(k, depth + 1)).join('');
        }
        return html;
    }

    function subHtml(c, depth) {
        const links = Object.entries(c.links || {}).map(([k, v]) => `${v} ${k}`);
        const used = (+c.lines_count ? [`${(+c.lines_count).toLocaleString('fr-FR')} ligne(s) d'écriture`] : []).concat(links);
        const pad = 12 + depth * 20 + 32;
        return `
            <div class="flex items-center gap-2 pr-3 sm:pr-6 py-1.5 text-sm ${c.is_active ? '' : 'opacity-60'}" style="padding-left:${pad}px">
                <span class="font-mono font-bold text-gray-700 w-20 shrink-0">${esc(c.code)}</span>
                <span class="flex-1 min-w-0 truncate">${esc(c.name)}
                    ${c.is_active ? '' : '<span class="text-[10px] font-bold bg-gray-100 text-gray-500 rounded-full px-2 py-0.5 ml-1">Inactif</span>'}
                    ${used.length ? `<span class="text-[10px] text-gray-400 ml-1">· ${esc(used.join(', '))}</span>` : '<span class="text-[10px] text-gray-300 ml-1">· inutilisé</span>'}
                </span>
                <span class="font-mono text-xs text-gray-700 text-right w-32 shrink-0">${bal(c.balance)}</span>
                <span class="flex items-center justify-end gap-3 w-72 shrink-0 text-xs font-bold whitespace-nowrap">
                    ${STATE.can_edit ? `
                        <button type="button" data-rename-coa="${c.id}" class="text-gray-600 hover:underline">Renommer</button>
                        <button type="button" data-toggle-coa="${c.id}" data-target-state="${c.is_active ? 0 : 1}"
                                class="${c.is_active ? 'text-rose-600' : 'text-emerald-700'} hover:underline">${c.is_active ? 'Désactiver' : 'Réactiver'}</button>` : ''}
                    ${STATE.can_delete && c.deletable ? `<button type="button" data-delete-coa="${c.id}" class="text-rose-700 hover:underline">Supprimer</button>` : ''}
                </span>
            </div>`;
    }

    function renderCategories() {
        const body = document.getElementById('cats-body');
        if (!STATE.cats.length) {
            body.innerHTML = `<tr><td colspan="3" class="px-6 py-6 text-center text-gray-400 text-sm">
                ${STATE.cats_denied
                    ? 'Catégories non accessibles : la permission <code class="text-xs">accounting.expenses.view</code> est requise pour les lire et les remapper.'
                    : 'Aucune catégorie de dépense enregistrée.'}
            </td></tr>`;
            return;
        }
        body.innerHTML = STATE.cats.map(c => {
            const coaLbl = c.coa_code
                ? `<span class="font-mono text-xs bg-gray-100 px-2 py-0.5 rounded">${esc(c.coa_code)}</span> <span class="text-gray-600">${esc(c.coa_name || '')}</span>`
                : `<span class="text-rose-600 font-bold text-xs">Sans compte : la dépense ne pourra pas être comptabilisée</span>`;
            return `
                <tr>
                    <td class="px-6 py-3">
                        <div class="font-bold text-gray-800">${esc(c.name)}</div>
                        <div class="text-[10px] text-gray-400 font-mono">${esc(c.slug || '')}</div>
                    </td>
                    <td class="px-6 py-3">${coaLbl}</td>
                    <td class="px-6 py-3 text-right">
                        ${STATE.can_edit ? `<button type="button" class="text-xs font-bold text-lpc-dark hover:underline" data-remap="${c.id}">Changer de compte</button>` : ''}
                    </td>
                </tr>`;
        }).join('');
        body.querySelectorAll('[data-remap]').forEach(btn => btn.addEventListener('click', () => remapCategoryModal(+btn.dataset.remap)));
    }

    // ---------- modal ------------------------------------------------------
    const $modal      = document.getElementById('coaModal');
    const $modalTitle = document.getElementById('coa-modal-title');
    const $modalBody  = document.getElementById('coa-modal-body');
    const $modalSave  = document.getElementById('coa-modal-save');
    let modalOnSave = null;
    let lastFocus = null;

    function openModal(title, bodyHtml, onSave, saveLabel = 'Enregistrer') {
        lastFocus = document.activeElement;
        $modalTitle.textContent = title;
        $modalBody.innerHTML    = bodyHtml;
        $modalSave.textContent  = saveLabel;
        modalOnSave             = onSave;
        $modal.classList.remove('hidden');
        $modalBody.querySelector('input, select')?.focus();
    }
    function closeModal() {
        $modal.classList.add('hidden');
        modalOnSave = null;
        lastFocus?.focus?.();
    }
    document.getElementById('coa-modal-close') .addEventListener('click', closeModal);
    document.getElementById('coa-modal-cancel').addEventListener('click', closeModal);
    $modal.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
    $modalSave.addEventListener('click', async () => {
        if (!modalOnSave) return;
        try {
            $modalSave.disabled = true;
            await modalOnSave();
        } catch (e) {
            toast(e.message || 'Erreur.', 'error');
        } finally {
            $modalSave.disabled = false;
        }
    });

    // ---------- actions ----------------------------------------------------
    /** The next free code under a parent: its number + 001, 002… (5211, 5212… for a bank under 521). */
    function suggestCode(number) {
        const taken = new Set(STATE.coa.map(c => String(c.code)));
        const width = ['521', '552', '571'].includes(String(number)) ? 1 : 3;
        for (let w = width; w <= 6; w++) {
            for (let i = 1; i < 10 ** w; i++) {
                const code = String(number) + String(i).padStart(w, '0');
                if (!taken.has(code)) return code;
            }
        }
        return '';
    }

    function addSubModal(ohadaId) {
        const n = NODES.get(ohadaId);
        if (!n) return;
        const code = suggestCode(n.o.account_number);
        openModal(`Nouveau sous-compte de ${n.o.account_number}`, `
            <p class="text-xs text-gray-500">Sous <span class="font-mono font-bold">${esc(n.o.account_number)}</span> ${esc(n.o.name)}.</p>
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Code</span>
                <input id="f-code" type="text" inputmode="numeric" maxlength="10" value="${esc(code)}"
                       class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg font-mono">
                <span class="text-[11px] text-gray-500">Commence par ${esc(n.o.account_number)}. Le code proposé est le prochain libre.</span>
            </label>
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Nom</span>
                <input id="f-name" type="text" maxlength="100" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg">
            </label>
        `, async () => {
            const r = await api({
                action: 'create_lpc_account',
                ohada_account_id: ohadaId,
                code: document.getElementById('f-code').value.trim(),
                name: document.getElementById('f-name').value.trim(),
            });
            toast(r.message || 'Sous-compte créé.');
            UI.open.add(ohadaId);
            closeModal();
            await reload();
        }, 'Créer le sous-compte');
    }

    function addOhadaModal() {
        openModal('Nouveau compte OHADA', `
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Numéro (2 à 6 chiffres)</span>
                <input id="f-number" type="text" inputmode="numeric" maxlength="6" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg font-mono">
            </label>
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Nom</span>
                <input id="f-name" type="text" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg">
            </label>
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Type</span>
                <select id="f-type" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg">
                    ${['asset', 'liability', 'equity', 'revenue', 'expense'].map(k =>
                        `<option value="${k}"${k === 'expense' ? ' selected' : ''}>${TYPE_LABEL[k]}</option>`).join('')}
                </select>
            </label>
            <p class="text-xs text-gray-500">Le compte reçoit son compte propre, utilisable aussitôt dans les écritures.</p>
        `, async () => {
            await api({
                action: 'create_ohada_account',
                account_number: document.getElementById('f-number').value.trim(),
                name: document.getElementById('f-name').value.trim(),
                type: document.getElementById('f-type').value,
            });
            toast('Compte OHADA créé.');
            closeModal();
            await reload();
        }, 'Créer');
    }

    function renameOhadaModal(id) {
        const n = NODES.get(id);
        if (!n) return;
        openModal(`Renommer ${n.o.account_number}`, `
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Nouveau nom</span>
                <input id="f-name" type="text" value="${esc(n.o.name)}" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg">
            </label>
            <p class="text-xs text-gray-500">Le numéro <span class="font-mono">${esc(n.o.account_number)}</span> ne change pas ; les écritures passées non plus.</p>
        `, async () => {
            await api({ action: 'update_ohada_account', id, name: document.getElementById('f-name').value.trim() });
            toast('Compte renommé.');
            closeModal();
            await reload();
        }, 'Renommer');
    }

    function renameCoaModal(id) {
        const row = STATE.coa.find(c => c.id === id);
        if (!row) return;
        openModal(`Renommer ${row.code}`, `
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Nouveau nom</span>
                <input id="f-name" type="text" maxlength="100" value="${esc(row.name)}" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg">
            </label>
            <p class="text-xs text-gray-500">Le code <span class="font-mono">${esc(row.code)}</span> ne change pas ; les écritures passées non plus.</p>
        `, async () => {
            await api({ action: 'update_lpc_account', id, name: document.getElementById('f-name').value.trim() });
            toast('Compte renommé.');
            closeModal();
            await reload();
        }, 'Renommer');
    }

    async function toggleCoaActive(id, targetState) {
        const row = STATE.coa.find(c => c.id === id);
        if (!row) return;
        const verb = targetState ? 'Réactiver' : 'Désactiver';
        if (!(await ask(`${verb} le compte ${row.code} ${row.name} ?${targetState ? '' : ' Il disparaît des listes de choix ; ses écritures restent.'}`))) return;
        try {
            await api({ action: 'set_lpc_account_active', id, is_active: targetState });
            toast(targetState ? 'Compte réactivé.' : 'Compte désactivé.');
            await reload();
        } catch (e) { toast(e.message, 'error'); }
    }

    async function deleteCoa(id) {
        const row = STATE.coa.find(c => c.id === id);
        if (!row) return;
        if (!(await ask(`Supprimer le compte ${row.code} ${row.name} ? Il n'a aucune écriture et rien ne l'utilise.`))) return;
        try {
            const r = await api({ action: 'delete_lpc_account', id });
            toast(r.message || 'Compte supprimé.');
            await reload();
        } catch (e) { toast(e.message, 'error'); }
    }

    function remapCategoryModal(catId) {
        const cat = STATE.cats.find(c => +c.id === catId);
        if (!cat) return;
        const opts = STATE.coa.filter(c => c.is_active && /^[2-6]/.test(String(c.ohada_number)))
            .map(c => `<option value="${c.id}"${c.id === +cat.coa_account_id ? ' selected' : ''}>${esc(c.code)} ${esc(c.name)}</option>`).join('');
        openModal(`Compte de « ${cat.name} »`, `
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Compte</span>
                <select id="f-coa" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg">${opts}</select>
            </label>
            <p class="text-xs text-gray-500">Les prochaines dépenses de cette catégorie passeront sur ce compte. Les écritures passées ne changent pas.</p>
        `, async () => {
            await api({ action: 'remap_expense_category', category_id: cat.id, coa_account_id: +document.getElementById('f-coa').value });
            toast('Catégorie mise à jour.');
            closeModal();
            await reload();
        });
    }

    async function reload() {
        try {
            await loadAll();
            render();
        } catch (e) {
            $root.innerHTML = `<div class="bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 rounded-xl">${esc(e.message)}</div>`;
        }
    }

    reload();
})();
