/**
 * assets/js/modules/admin-chart_of_accounts.js
 * -----------------------------------------------------------------------------
 * Plan Comptable admin — one page, three panels:
 *   1. Expense Categories (rename target CoA per category)
 *   2. OHADA parents      (add + rename)
 *   3. CoA rows           (add child, rename, deactivate/reactivate)
 *
 * All mutations go through /api/v1/accounting_controller.php POST actions.
 * Reads use ?tab=chart_admin which returns everything the page needs in one
 * shot — the list is a few hundred rows at most, so pagination is overkill.
 *
 * Migration 117 introduced the accounting.chart.edit permission and the
 * SYSCOHADA account fixes (6022 Combustibles, 6225 Entretien matériel de
 * transport, 6252 renamed to Assurances mat. de transport, 6054 Fournitures
 * de bureau). The page has no special handling for those — they are just
 * rows like any other, editable through the same three actions.
 *
 * Missing accounting.chart.edit shows Read-only banner + all mutation
 * buttons hidden. Missing accounting.chart.view means the page RBAC on
 * chart_of_accounts.php already rejected the request; this file never
 * loads.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    const API   = '/api/v1/accounting_controller.php';
    const $root = document.getElementById('coa-root');

    // Live state — reloaded on every mutation.
    let STATE = {
        ohada: [],         // [{id, account_number, name, type}]
        coa:   [],         // [{id, code, name, type, is_active, ohada_*, lines_count, categories_count}]
        cats:  [],         // [{id, name, slug, coa_account_id, coa_code, coa_name}] filled by loadAll
        cats_denied: false,// true when the categories endpoint refused us (403)
        can_edit: false,
        can_create: false,
    };

    const TYPE_LABEL = {
        asset:     'Actif',
        liability: 'Passif',
        equity:    'Capitaux propres',
        revenue:   'Produit',
        expense:   'Charge',
    };

    // ---------- utils ----------------------------------------------------
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => (
        { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]
    ));

    function toast(msg, type = 'success') {
        if (window.LPC?.toast) return LPC.toast(type, msg);
        alert(msg);
    }

    async function api(payload) {
        const res = await fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const json = await res.json().catch(() => ({
            status: 'error', message: 'Réponse invalide du serveur.',
        }));
        if (json.status !== 'success') {
            throw new Error(json.message || 'Erreur inconnue.');
        }
        return json;
    }

    async function loadAll() {
        // The chart tab and a small side call to expenses_controller for the
        // categories list. Both are cheap; parallel keeps the page snappy.
        const [chartRes, catsRes] = await Promise.all([
            fetch(`${API}?tab=chart_admin`).then(r => r.json()),
            // This endpoint is gated by accounting.expenses.view, which a
            // chart-only role may not hold. A 403 still returns valid JSON,
            // so we read the status rather than treating it as a hard error
            // and record WHY the panel is empty.
            fetch('/api/v1/expenses_controller.php?action=categories')
                .then(r => r.json().then(j => ({ ok: r.ok, body: j })))
                .catch(() => ({ ok: false, body: { status: 'error' } })),
        ]);
        if (chartRes.status !== 'success') {
            throw new Error(chartRes.message || 'Chargement du plan comptable échoué.');
        }
        STATE.ohada      = chartRes.data.ohada_masters || [];
        STATE.coa        = chartRes.data.coa           || [];
        STATE.can_edit   = !!chartRes.data.can_edit;
        STATE.can_create = !!chartRes.data.can_create;

        // expenses_controller answers `categories` with the row list as the
        // data payload itself — jr('success','',$rows) — not wrapped under a
        // `categories` key. Reading data.categories therefore always yielded
        // undefined and the panel rendered permanently empty. Accept either
        // shape so this keeps working if the endpoint is ever wrapped.
        const catBody = catsRes?.body;
        if (catBody?.status === 'success') {
            const d = catBody.data;
            STATE.cats = Array.isArray(d) ? d : (Array.isArray(d?.categories) ? d.categories : []);
            STATE.cats_denied = false;
        } else {
            STATE.cats = [];
            STATE.cats_denied = true;
        }
    }

    // ---------- modal ----------------------------------------------------
    const $modal      = document.getElementById('coaModal');
    const $modalTitle = document.getElementById('coa-modal-title');
    const $modalBody  = document.getElementById('coa-modal-body');
    const $modalSave  = document.getElementById('coa-modal-save');

    let modalOnSave = null;

    function openModal(title, bodyHtml, onSave, saveLabel = 'Enregistrer') {
        $modalTitle.textContent = title;
        $modalBody.innerHTML    = bodyHtml;
        $modalSave.textContent  = saveLabel;
        modalOnSave             = onSave;
        $modal.classList.remove('hidden');
    }
    function closeModal() {
        $modal.classList.add('hidden');
        modalOnSave = null;
    }
    document.getElementById('coa-modal-close') .addEventListener('click', closeModal);
    document.getElementById('coa-modal-cancel').addEventListener('click', closeModal);
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

    // ---------- renderers ------------------------------------------------
    function render() {
        const readonly = !STATE.can_edit;
        $root.innerHTML = `
            ${readonly ? `
              <div class="bg-amber-50 border border-amber-200 text-amber-900 px-4 py-3 rounded-xl text-sm font-bold">
                Mode lecture seule — la permission <code class="text-xs">accounting.chart.edit</code> est requise pour modifier le plan comptable.
              </div>` : ''}

            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm">
                <header class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <div>
                        <h2 class="font-black text-lpc-dark tracking-wide">Catégories de dépense</h2>
                        <p class="text-xs text-gray-500 mt-0.5">Chaque catégorie poste sur un compte du plan comptable. Cliquez pour changer.</p>
                    </div>
                </header>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="text-left px-6 py-2 font-bold">Catégorie</th>
                                <th class="text-left px-6 py-2 font-bold">Compte OHADA</th>
                                <th class="text-right px-6 py-2 font-bold w-32"></th>
                            </tr>
                        </thead>
                        <tbody id="cats-body" class="divide-y divide-gray-100"></tbody>
                    </table>
                </div>
            </section>

            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm">
                <header class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <div>
                        <h2 class="font-black text-lpc-dark tracking-wide">Comptes OHADA (parents)</h2>
                        <p class="text-xs text-gray-500 mt-0.5">Racines SYSCOHADA. Chaque parent porte automatiquement un compte du plan.</p>
                    </div>
                    ${STATE.can_create ? `
                    <button type="button" id="btn-add-ohada" class="bg-lpc-dark hover:bg-green-800 text-white px-4 py-2 rounded-xl font-bold text-xs shadow-sm">
                        + Compte OHADA
                    </button>` : ''}
                </header>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="text-left px-6 py-2 font-bold w-24">N°</th>
                                <th class="text-left px-6 py-2 font-bold">Nom</th>
                                <th class="text-left px-6 py-2 font-bold w-40">Type</th>
                                <th class="text-right px-6 py-2 font-bold w-32"></th>
                            </tr>
                        </thead>
                        <tbody id="ohada-body" class="divide-y divide-gray-100"></tbody>
                    </table>
                </div>
            </section>

            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm">
                <header class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <div>
                        <h2 class="font-black text-lpc-dark tracking-wide">Comptes auxiliaires (LPC)</h2>
                        <p class="text-xs text-gray-500 mt-0.5">Comptes exposés aux écritures et aux catégories. Ceux inactifs sont masqués dans les sélecteurs.</p>
                    </div>
                    ${STATE.can_create ? `
                    <button type="button" id="btn-add-coa" class="bg-lpc-dark hover:bg-green-800 text-white px-4 py-2 rounded-xl font-bold text-xs shadow-sm">
                        + Compte auxiliaire
                    </button>` : ''}
                </header>
                <div class="px-6 py-3 border-b border-gray-100 flex items-center gap-3 text-xs">
                    <label class="flex items-center gap-2 text-gray-600 font-bold cursor-pointer">
                        <input type="checkbox" id="show-inactive"> Afficher les comptes désactivés
                    </label>
                    <input type="text" id="coa-search" placeholder="Rechercher code ou nom..."
                           class="flex-1 max-w-xs px-3 py-1.5 border border-gray-200 rounded-lg text-sm">
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="text-left px-6 py-2 font-bold w-28">Code</th>
                                <th class="text-left px-6 py-2 font-bold">Nom</th>
                                <th class="text-left px-6 py-2 font-bold w-40">Parent OHADA</th>
                                <th class="text-right px-6 py-2 font-bold w-24">Écritures</th>
                                <th class="text-right px-6 py-2 font-bold w-24">Statut</th>
                                <th class="text-right px-6 py-2 font-bold w-40"></th>
                            </tr>
                        </thead>
                        <tbody id="coa-body" class="divide-y divide-gray-100"></tbody>
                    </table>
                </div>
            </section>
        `;

        renderCategories();
        renderOhada();
        renderCoa();

        if (STATE.can_create) {
            document.getElementById('btn-add-ohada')?.addEventListener('click', addOhadaModal);
            document.getElementById('btn-add-coa')  ?.addEventListener('click', addCoaModal);
        }
        document.getElementById('show-inactive').addEventListener('change', renderCoa);
        document.getElementById('coa-search')   .addEventListener('input',  renderCoa);
    }

    function renderCategories() {
        const body = document.getElementById('cats-body');
        if (!STATE.cats.length) {
            body.innerHTML = `<tr><td colspan="3" class="px-6 py-6 text-center text-gray-400 text-sm">
                ${STATE.cats_denied
                    ? 'Catégories non accessibles — la permission <code class="text-xs">accounting.expenses.view</code> est requise pour les lire et les remapper.'
                    : 'Aucune catégorie de dépense enregistrée.'}
            </td></tr>`;
            return;
        }
        body.innerHTML = STATE.cats.map(c => {
            const coaLbl = c.coa_code
                ? `<span class="font-mono text-xs bg-gray-100 px-2 py-0.5 rounded">${esc(c.coa_code)}</span> <span class="text-gray-600">${esc(c.coa_name || '')}</span>`
                : `<span class="text-rose-600 font-bold text-xs">Non mappée — postExpense va refuser</span>`;
            return `
                <tr>
                    <td class="px-6 py-3">
                        <div class="font-bold text-gray-800">${esc(c.name)}</div>
                        <div class="text-[10px] text-gray-400 font-mono">${esc(c.slug || '')}</div>
                    </td>
                    <td class="px-6 py-3">${coaLbl}</td>
                    <td class="px-6 py-3 text-right">
                        ${STATE.can_edit ? `
                        <button type="button" class="text-xs font-bold text-lpc-dark hover:underline"
                                data-remap="${c.id}">Remapper</button>` : ''}
                    </td>
                </tr>
            `;
        }).join('');
        body.querySelectorAll('[data-remap]').forEach(btn => {
            btn.addEventListener('click', () => remapCategoryModal(+btn.dataset.remap));
        });
    }

    function renderOhada() {
        const body = document.getElementById('ohada-body');
        body.innerHTML = STATE.ohada.map(o => `
            <tr>
                <td class="px-6 py-2 font-mono font-bold">${esc(o.account_number)}</td>
                <td class="px-6 py-2">${esc(o.name)}</td>
                <td class="px-6 py-2 text-xs text-gray-600">${esc(TYPE_LABEL[o.type] || o.type)}</td>
                <td class="px-6 py-2 text-right">
                    ${STATE.can_edit ? `
                    <button type="button" class="text-xs font-bold text-lpc-dark hover:underline"
                            data-rename-ohada="${o.id}">Renommer</button>` : ''}
                </td>
            </tr>
        `).join('');
        body.querySelectorAll('[data-rename-ohada]').forEach(btn => {
            btn.addEventListener('click', () => renameOhadaModal(+btn.dataset.renameOhada));
        });
    }

    function renderCoa() {
        const body = document.getElementById('coa-body');
        const showInactive = document.getElementById('show-inactive').checked;
        const q = document.getElementById('coa-search').value.trim().toLowerCase();

        const rows = STATE.coa.filter(c => {
            if (!showInactive && !c.is_active) return false;
            if (q) {
                const hay = (c.code + ' ' + c.name + ' ' + c.ohada_number + ' ' + c.ohada_name).toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });

        if (!rows.length) {
            body.innerHTML = `<tr><td colspan="6" class="px-6 py-6 text-center text-gray-400 text-sm">Aucun compte.</td></tr>`;
            return;
        }
        body.innerHTML = rows.map(c => {
            const statusBadge = c.is_active
                ? `<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700">Actif</span>`
                : `<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-gray-100 text-gray-500">Inactif</span>`;
            const toggleLbl = c.is_active ? 'Désactiver' : 'Réactiver';
            const linesLbl  = (+c.lines_count).toLocaleString('fr-FR');
            return `
                <tr class="${c.is_active ? '' : 'bg-gray-50/50'}">
                    <td class="px-6 py-2 font-mono font-bold">${esc(c.code)}</td>
                    <td class="px-6 py-2">${esc(c.name)}</td>
                    <td class="px-6 py-2 text-xs text-gray-500">
                        <span class="font-mono">${esc(c.ohada_number)}</span> ${esc(c.ohada_name)}
                    </td>
                    <td class="px-6 py-2 text-right font-mono text-xs text-gray-600">${linesLbl}</td>
                    <td class="px-6 py-2 text-right">${statusBadge}</td>
                    <td class="px-6 py-2 text-right space-x-3">
                        ${STATE.can_edit ? `
                        <button type="button" class="text-xs font-bold text-lpc-dark hover:underline"
                                data-rename-coa="${c.id}">Renommer</button>
                        <button type="button" class="text-xs font-bold ${c.is_active ? 'text-rose-600' : 'text-emerald-700'} hover:underline"
                                data-toggle-coa="${c.id}" data-target-state="${c.is_active ? 0 : 1}">${toggleLbl}</button>
                        ` : ''}
                    </td>
                </tr>
            `;
        }).join('');
        body.querySelectorAll('[data-rename-coa]').forEach(btn => {
            btn.addEventListener('click', () => renameCoaModal(+btn.dataset.renameCoa));
        });
        body.querySelectorAll('[data-toggle-coa]').forEach(btn => {
            btn.addEventListener('click', () => toggleCoaActive(+btn.dataset.toggleCoa, +btn.dataset.targetState));
        });
    }

    // ---------- actions --------------------------------------------------
    function addOhadaModal() {
        openModal('Nouveau compte OHADA', `
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Numéro (2 à 6 chiffres)</span>
                <input id="f-number" type="text" inputmode="numeric" pattern="[0-9]{2,6}" maxlength="6"
                       class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg font-mono">
            </label>
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Nom</span>
                <input id="f-name" type="text" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg">
            </label>
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Type</span>
                <select id="f-type" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg">
                    ${Object.entries(TYPE_LABEL).map(([k, v]) =>
                        `<option value="${k}"${k === 'expense' ? ' selected' : ''}>${v}</option>`).join('')}
                </select>
            </label>
            <p class="text-xs text-gray-500">
                Le compte est créé dans <code>ohada_accounts</code> et un compte auxiliaire miroir
                est ajouté au plan comptable pour qu'il soit immédiatement utilisable dans les catégories et les écritures.
            </p>
        `, async () => {
            await api({
                action: 'create_ohada_account',
                account_number: document.getElementById('f-number').value.trim(),
                name:           document.getElementById('f-name').value.trim(),
                type:           document.getElementById('f-type').value,
            });
            toast('Compte OHADA créé.');
            closeModal();
            await reload();
        });
    }

    function renameOhadaModal(id) {
        const row = STATE.ohada.find(o => o.id === id);
        if (!row) return;
        openModal(`Renommer OHADA ${row.account_number}`, `
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Nouveau nom</span>
                <input id="f-name" type="text" value="${esc(row.name)}"
                       class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg">
            </label>
            <p class="text-xs text-gray-500">
                Le code <code class="font-mono">${esc(row.account_number)}</code> ne change pas.
                Les écritures postées et les mappings de budget référencent le compte par code — elles restent inchangées.
            </p>
        `, async () => {
            await api({
                action: 'update_ohada_account',
                id,
                name: document.getElementById('f-name').value.trim(),
            });
            toast('Renommé.');
            closeModal();
            await reload();
        });
    }

    function addCoaModal() {
        const opts = STATE.ohada.map(o =>
            `<option value="${o.id}">${esc(o.account_number)} — ${esc(o.name)}</option>`).join('');
        openModal('Nouveau compte auxiliaire', `
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Compte OHADA parent</span>
                <select id="f-ohada" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg">${opts}</select>
            </label>
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Code (6 chiffres)</span>
                <input id="f-code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                       class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg font-mono">
            </label>
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Nom</span>
                <input id="f-name" type="text" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg">
            </label>
        `, async () => {
            await api({
                action: 'create_lpc_account',
                ohada_account_id: +document.getElementById('f-ohada').value,
                code:             document.getElementById('f-code').value.trim(),
                name:             document.getElementById('f-name').value.trim(),
            });
            toast('Compte créé.');
            closeModal();
            await reload();
        });
    }

    function renameCoaModal(id) {
        const row = STATE.coa.find(c => c.id === id);
        if (!row) return;
        openModal(`Renommer ${row.code}`, `
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Nouveau nom</span>
                <input id="f-name" type="text" value="${esc(row.name)}"
                       class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg">
            </label>
            <p class="text-xs text-gray-500">
                Parent : <span class="font-mono">${esc(row.ohada_number)}</span> ${esc(row.ohada_name)}.
                Le code ne change pas.
            </p>
        `, async () => {
            await api({
                action: 'update_lpc_account',
                id,
                name: document.getElementById('f-name').value.trim(),
            });
            toast('Renommé.');
            closeModal();
            await reload();
        });
    }

    async function toggleCoaActive(id, targetState) {
        const row = STATE.coa.find(c => c.id === id);
        if (!row) return;
        const verb = targetState ? 'réactiver' : 'désactiver';
        if (!confirm(`Vraiment ${verb} le compte ${row.code} — ${row.name} ?`)) return;
        try {
            await api({ action: 'set_lpc_account_active', id, is_active: targetState });
            toast(targetState ? 'Réactivé.' : 'Désactivé.');
            await reload();
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    function remapCategoryModal(catId) {
        const cat = STATE.cats.find(c => c.id === catId);
        if (!cat) return;
        const opts = STATE.coa
            .filter(c => c.is_active)
            .map(c => `<option value="${c.id}"${c.id === cat.coa_account_id ? ' selected' : ''}>
                        ${esc(c.code)} — ${esc(c.name)}
                       </option>`).join('');
        openModal(`Remapper « ${cat.name} »`, `
            <label class="block">
                <span class="text-xs font-bold text-gray-600">Compte de destination</span>
                <select id="f-coa" class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg">${opts}</select>
            </label>
            <p class="text-xs text-gray-500">
                Les futures dépenses de cette catégorie posteront sur le compte choisi.
                L'historique n'est pas modifié.
            </p>
        `, async () => {
            await api({
                action:           'remap_expense_category',
                category_id:      cat.id,
                coa_account_id:   +document.getElementById('f-coa').value,
            });
            toast('Catégorie remappée.');
            closeModal();
            await reload();
        });
    }

    async function reload() {
        try {
            await loadAll();
            render();
        } catch (e) {
            $root.innerHTML = `<div class="bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 rounded-xl">
                ${esc(e.message)}
            </div>`;
        }
    }

    // ---------- boot -----------------------------------------------------
    reload();
})();
