/**
 * assets/js/modules/inventory-rebate_config.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Ristourne ladder configuration (modules/inventory/rebate_config.php).
 *
 * Per (supplier, category) tiered ristourne rules — general capability, not
 * specific to any one supplier — plus the shared TVA/précompte rates the
 * calculation needs (api/v1/procurement_controller.php: rebate_ladder_*,
 * rebate_tier_*, rebate_tax_settings_*, rebate_categories_list).
 *
 * Confirmed rules this UI encodes:
 *   - A tier is (threshold_from EXCLUSIVE, threshold_to INCLUSIVE, rate).
 *     Leaving "from" blank means "starts at 0"; leaving "to" blank means
 *     "no upper bound" (the top/open tier).
 *   - Tiers are a CLIFF, not sliced: whichever tier a purchase's HT amount
 *     falls into, the WHOLE amount earns that one rate.
 *   - The tier is evaluated once, on the whole purchase order's category
 *     subtotal, at order creation — not per reception.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    let categories = []; // last fetch for the selected supplier
    let currentSupplierId = null;

    document.addEventListener('DOMContentLoaded', async () => {
        await Promise.all([loadSuppliers(), loadTaxSettings()]);
    });

    async function loadSuppliers() {
        try {
            const res = await fetch('/api/v1/procurement_controller.php?action=fetch_metadata');
            const result = await res.json();
            if (result.status !== 'success') return;
            const sel = document.getElementById('supplier_select');
            result.data.suppliers.forEach(s => {
                sel.innerHTML += `<option value="${s.id}">${escapeHtml(s.name)}</option>`;
            });
        } catch (e) { console.error(e); }
    }

    async function loadTaxSettings() {
        try {
            const res = await fetch('/api/v1/procurement_controller.php?action=rebate_tax_settings_get');
            const result = await res.json();
            if (result.status === 'success') {
                document.getElementById('tax_vat_rate').value = (result.data.vat_rate * 100).toFixed(2);
                document.getElementById('tax_precompte_rate').value = (result.data.precompte_rate * 100).toFixed(2);
            }
        } catch (e) { console.error(e); }
    }

    window.saveTaxSettings = async function () {
        const vat = document.getElementById('tax_vat_rate').value;
        const precompte = document.getElementById('tax_precompte_rate').value;
        try {
            const res = await fetch('/api/v1/procurement_controller.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'rebate_tax_settings_save', vat_rate_pct: vat, precompte_rate_pct: precompte })
            });
            const result = await res.json();
            if (result.status !== 'success') LPC.modal.alert('Erreur: ' + result.message);
        } catch (e) { LPC.modal.alert('Erreur système.'); }
    };

    window.onSupplierChange = async function (supplierId) {
        currentSupplierId = supplierId || null;
        const placeholder = document.getElementById('no-supplier-placeholder');
        const container = document.getElementById('categories-container');

        if (!currentSupplierId) {
            placeholder.classList.remove('hidden');
            container.classList.add('hidden');
            return;
        }
        placeholder.classList.add('hidden');
        container.classList.remove('hidden');
        await loadLadders();
    };

    async function loadLadders() {
        const container = document.getElementById('categories-container');
        container.innerHTML = '<p class="text-center text-gray-400 font-bold py-8 animate-pulse">Chargement...</p>';
        try {
            const res = await fetch(`/api/v1/procurement_controller.php?action=rebate_ladder_get&supplier_id=${currentSupplierId}`);
            const result = await res.json();
            if (result.status !== 'success') throw new Error(result.message);
            categories = result.data;
            renderCategories();
        } catch (e) {
            container.innerHTML = `<p class="text-center text-red-500 font-bold py-8">Erreur: ${escapeHtml(e.message)}</p>`;
        }
    }

    function fmtThreshold(v) {
        return (v === null || v === undefined) ? '' : Number(v).toLocaleString('fr-FR');
    }

    function renderCategories() {
        const container = document.getElementById('categories-container');
        container.innerHTML = categories.map(cat => `
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden" data-cat-id="${cat.id}">
                <div class="bg-gray-900 px-6 py-4 flex items-center justify-between">
                    <h4 class="text-sm font-black text-white uppercase tracking-widest">${escapeHtml(cat.name)}</h4>
                    <label class="flex items-center gap-2 text-xs font-bold text-gray-300 cursor-pointer">
                        <input type="checkbox" ${cat.is_active ? 'checked' : ''} onchange="toggleCategory(${cat.id}, this.checked)" class="w-4 h-4">
                        Actif
                    </label>
                </div>
                <div class="p-6">
                    <table class="w-full text-left mb-4">
                        <thead class="text-[10px] uppercase text-gray-400 font-black tracking-widest border-b border-gray-100">
                            <tr>
                                <th class="py-2 pr-2">Palier</th>
                                <th class="py-2 px-2 text-right">De (HT, exclusif)</th>
                                <th class="py-2 px-2 text-right">À (HT, inclusif)</th>
                                <th class="py-2 px-2 text-right">Taux</th>
                                <th class="py-2 pl-2 w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${cat.tiers.length ? cat.tiers.map((t, i) => `
                                <tr class="border-b border-gray-50">
                                    <td class="py-2 pr-2 font-bold text-gray-500">${i + 1}</td>
                                    <td class="py-2 px-2 text-right font-bold text-gray-700">${t.threshold_from === null ? '0' : fmtThreshold(t.threshold_from)}</td>
                                    <td class="py-2 px-2 text-right font-bold text-gray-700">${t.threshold_to === null ? '∞' : fmtThreshold(t.threshold_to)}</td>
                                    <td class="py-2 px-2 text-right font-black text-amber-600">${(t.rate * 100).toFixed(2)}%</td>
                                    <td class="py-2 pl-2 text-center">
                                        <button onclick="deleteTier(${t.id}, ${cat.id})" class="text-red-300 hover:text-red-500"><i class="fas fa-trash-alt"></i></button>
                                    </td>
                                </tr>
                            `).join('') : `<tr><td colspan="5" class="py-4 text-center text-gray-400 italic text-sm">Aucun palier — ce fournisseur ne gagne rien sur cette catégorie tant qu'aucun palier n'est ajouté.</td></tr>`}
                        </tbody>
                    </table>

                    <div class="flex items-end gap-2 bg-gray-50 border border-gray-200 rounded-xl p-3">
                        <div class="flex-1">
                            <label class="block text-[9px] font-black text-gray-400 uppercase tracking-widest mb-1">De (laisser vide = 0)</label>
                            <input type="number" min="0" class="tier-from w-full border border-gray-200 rounded-lg p-2 text-sm font-bold text-right outline-none focus:ring-2 focus:ring-lpc-light">
                        </div>
                        <div class="flex-1">
                            <label class="block text-[9px] font-black text-gray-400 uppercase tracking-widest mb-1">À (laisser vide = illimité)</label>
                            <input type="number" min="0" class="tier-to w-full border border-gray-200 rounded-lg p-2 text-sm font-bold text-right outline-none focus:ring-2 focus:ring-lpc-light">
                        </div>
                        <div class="w-28">
                            <label class="block text-[9px] font-black text-gray-400 uppercase tracking-widest mb-1">Taux %</label>
                            <input type="number" min="0" max="100" step="0.01" class="tier-rate w-full border border-gray-200 rounded-lg p-2 text-sm font-black text-right outline-none focus:ring-2 focus:ring-amber-400">
                        </div>
                        <button onclick="addTier(${cat.id}, this)" class="px-4 py-2 bg-lpc-dark hover:bg-green-800 text-white rounded-lg font-bold text-sm shadow-sm shrink-0"><i class="fas fa-plus"></i> Ajouter</button>
                    </div>
                </div>
            </div>
        `).join('');
    }

    window.toggleCategory = async function (categoryId, isActive) {
        try {
            const res = await fetch('/api/v1/procurement_controller.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'rebate_ladder_toggle', supplier_id: currentSupplierId, category_id: categoryId, is_active: isActive })
            });
            const result = await res.json();
            if (result.status !== 'success') LPC.modal.alert('Erreur: ' + result.message);
        } catch (e) { LPC.modal.alert('Erreur système.'); }
    };

    window.addTier = async function (categoryId, btn) {
        const card = btn.closest('[data-cat-id]');
        const rate = card.querySelector('.tier-rate').value;

        if (rate === '' || rate === null) return LPC.modal.alert('Le taux est requis.');

        const cat = categories.find(c => c.id === categoryId);
        const nextOrder = cat ? cat.tiers.length + 1 : 1;

        try {
            const res = await fetch('/api/v1/procurement_controller.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'rebate_tier_save',
                    supplier_id: currentSupplierId,
                    category_id: categoryId,
                    tier_order: nextOrder,
                    threshold_from: card.querySelector('.tier-from').value || null,
                    threshold_to: card.querySelector('.tier-to').value || null,
                    rate_pct: rate
                })
            });
            const result = await res.json();
            if (result.status === 'success') {
                await loadLadders();
            } else LPC.modal.alert('Erreur: ' + result.message);
        } catch (e) { LPC.modal.alert('Erreur système.'); }
    };

    window.deleteTier = async function (tierId) {
        if (!(await LPC.modal.confirm('Supprimer ce palier ?'))) return;
        const fd = new FormData();
        fd.append('action', 'rebate_tier_delete');
        fd.append('id', tierId);
        try {
            const res = await fetch('/api/v1/procurement_controller.php', { method: 'POST', body: fd });
            const result = await res.json();
            if (result.status === 'success') await loadLadders();
            else LPC.modal.alert('Erreur: ' + result.message);
        } catch (e) { LPC.modal.alert('Erreur système.'); }
    };

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = String(s ?? '');
        return d.innerHTML;
    }
})();
