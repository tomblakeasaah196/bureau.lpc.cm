/**
 * assets/js/modules/accounting-fixed_assets.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Immobilisations & Amortissements front-end.
 *
 * Sprint 6 D2 extracted the original block from fixed_assets.php.
 * Aug 2026 rewrite (SYSCOHADA hardening):
 *   · uses the /list_accounts `buckets` response (2xx / 28x / 68x / 5xx / 81x / 82x)
 *     instead of best-effort startsWith() over `LPC…`-prefixed COA codes;
 *   · adds a dotations preview (dry-run of the JE) before posting;
 *   · adds a register status filter (actif / totalement amorti / sorti);
 *   · adds TVA input on cession and pre-selects the 81/82 company defaults;
 *   · surfaces a period-closed warning before the user runs a dotation batch.
 * -----------------------------------------------------------------------------
 */

// D2 hoister prelude: populate window.PAGE_DATA from the inert JSON block.
(function(){
    if (window.PAGE_DATA) return;
    var el = document.getElementById('lpc-page-data');
    if (!el) { window.PAGE_DATA = {}; return; }
    try { window.PAGE_DATA = JSON.parse(el.textContent || '{}'); }
    catch (e) { console.warn('PAGE_DATA parse failed:', e); window.PAGE_DATA = {}; }
})();

let currentTab = 'queue';
let registerFilter = 'active';   // 'all' | 'active' | 'depreciated' | 'disposed'
let globalData = {
    accounts: { lpc: [], buckets: { assets:[], accumulated:[], charge:[], cash:[], plus_value:[], moins_value:[] }, defaults: {} },
    queue: [],
    assets: []
};
const CSRF_TOKEN = window.PAGE_DATA.v1;

const fmt = (num) => LPC.fmt.int(Math.round(Number(num) || 0));

// Deep-link support: /modules/accounting/fixed_assets.php#register
const FA_VALID_TABS = ['queue', 'register', 'dotations'];

window.onload = () => {
    document.getElementById('dotation_month').value = new Date().toISOString().slice(0, 7);
    document.getElementById('cap_date').valueAsDate = new Date();
    document.getElementById('cap_service_start').valueAsDate = new Date();
    document.getElementById('ces_date').valueAsDate = new Date();
    const hash = (window.location.hash || '').replace('#', '');
    switchTab(FA_VALID_TABS.includes(hash) ? hash : 'queue');
};

async function jsonPost(payload) {
    const res = await fetch('/api/v1/fixed_assets_controller.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify(payload)
    });
    return res.json();
}

function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

async function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.tab-link').forEach(el => {
        el.classList.remove('border-asset-highlight', 'text-asset-dark', 'font-black');
        el.classList.add('border-transparent', 'text-gray-400', 'font-bold');
    });
    document.getElementById(`tab-${tab}`).classList.remove('border-transparent', 'text-gray-400', 'font-bold');
    document.getElementById(`tab-${tab}`).classList.add('border-asset-highlight', 'text-asset-dark', 'font-black');

    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.getElementById(`content-${tab}`).classList.add('active');

    await fetchTabData(tab);
}

async function fetchTabData(tab) {
    try {
        if (tab === 'queue') {
            const [qRes, aRes] = await Promise.all([
                fetch('/api/v1/fixed_assets_controller.php?action=list_queue').then(r => r.json()),
                fetch('/api/v1/fixed_assets_controller.php?action=list_accounts').then(r => r.json()),
            ]);
            if (qRes.status === 'success') globalData.queue = qRes.data.queue || [];
            if (aRes.status === 'success') absorbAccounts(aRes.data);
            renderQueue();
            populateAccountDropdowns();
        } else if (tab === 'register') {
            const rRes = await fetch(`/api/v1/fixed_assets_controller.php?action=list_register&status=${encodeURIComponent(registerFilter)}`).then(r => r.json());
            if (rRes.status === 'success') globalData.assets = rRes.data.assets || [];
            renderAssets();
        } else if (tab === 'dotations') {
            if (!globalData.accounts.buckets.cash.length) {
                const aRes = await fetch('/api/v1/fixed_assets_controller.php?action=list_accounts').then(r => r.json());
                if (aRes.status === 'success') absorbAccounts(aRes.data);
            }
            populateCashAccountDropdown();
        }
    } catch(e) { console.error("Fetch error", e); }
}

function absorbAccounts(data) {
    globalData.accounts.lpc      = data.lpc      || [];
    globalData.accounts.buckets  = data.buckets  || { assets:[], accumulated:[], charge:[], cash:[], plus_value:[], moins_value:[] };
    globalData.accounts.defaults = data.defaults || {};
}

function populateCashAccountDropdown() {
    const sel = document.getElementById('ces_cash_account');
    if (!sel) return;
    sel.innerHTML = '<option value="">-- Compte trésorerie --</option>';
    (globalData.accounts.buckets.cash || []).forEach(a => {
        sel.innerHTML += LPC.html`<option value="${a.id}">[${a.ohada_code || a.code}] ${a.name}</option>`;
    });
}

// ================= RENDERS ================= //

function renderQueue() {
    const tbody = document.getElementById('table-body-queue');
    tbody.innerHTML = '';

    if(globalData.queue.length === 0) {
        tbody.innerHTML = LPC.html`<tr><td colspan="4" class="py-12 text-center text-gray-400 font-bold italic"><i class="fas fa-check-circle text-3xl mb-2 text-green-200 block"></i>Aucun véhicule en attente de capitalisation.</td></tr>`;
        document.getElementById('badge-queue').classList.add('hidden');
        return;
    }

    globalData.queue.forEach(v => {
        tbody.innerHTML += LPC.html`
            <tr class="hover:bg-gray-50 border-b border-gray-100 transition-colors">
                <td class="py-4 px-6 font-black text-gray-900">${v.plate_number}</td>
                <td class="py-4 px-6 text-xs text-gray-600 font-bold uppercase">${v.type} - ${v.make_model || 'N/A'}</td>
                <td class="py-4 px-6 text-center text-xs text-gray-500">${fmt(v.current_odometer)} Km</td>
                <td class="py-4 px-6 text-right">
                    <button onclick="openCapitalizeModal(${v.id}, '${v.plate_number}', '${v.make_model || ''}')" class="bg-asset-highlight hover:bg-asset-dark text-white px-4 py-2 rounded-lg text-[10px] font-black uppercase shadow-md transition-all">
                        Capitaliser
                    </button>
                </td>
            </tr>`;
    });

    document.getElementById('badge-queue').innerText = globalData.queue.length;
    document.getElementById('badge-queue').classList.remove('hidden');
}

function renderAssets() {
    const tbody = document.getElementById('table-body-register');
    tbody.innerHTML = '';

    if(globalData.assets.length === 0) return tbody.innerHTML = LPC.html`<tr><td colspan="6" class="py-8 text-center text-gray-400 italic">Aucun actif à afficher avec ce filtre.</td></tr>`;

    globalData.assets.forEach(a => {
        const vnc = a.acquisition_cost - a.accumulated_depr;
        const isZero = (vnc <= 0 || a.status === 'disposed');
        const rowClass = isZero ? 'vnc-zero border-b border-gray-50' : 'hover:bg-gray-50 border-b border-gray-100';

        let statusHtml = '';
        if(a.status === 'disposed') statusHtml = `<br><span class="text-[9px] bg-rose-100 text-rose-800 px-1.5 py-0.5 rounded font-black uppercase tracking-widest mt-1 inline-block">Sorti</span>`;
        else if(vnc <= 0) statusHtml = `<br><span class="text-[9px] bg-gray-200 text-gray-600 px-1.5 py-0.5 rounded font-black uppercase tracking-widest mt-1 inline-block">Totalement Amorti</span>`;

        tbody.innerHTML += LPC.html`
            <tr class="${rowClass}">
                <td class="py-3 px-6"><span class="text-xs font-black">${a.name}</span>${statusHtml}</td>
                <td class="py-3 px-6 text-center text-xs font-bold">${a.acquisition_date}</td>
                <td class="py-3 px-6 text-right font-black">${fmt(a.acquisition_cost)}</td>
                <td class="py-3 px-6 text-right text-asset-highlight font-bold">-${fmt(a.accumulated_depr)}</td>
                <td class="py-3 px-6 text-right font-black bg-gray-50/50">${fmt(vnc)}</td>
                <td class="py-3 px-6 text-right">
                    ${LPC.raw(!isZero ? `<button onclick="openDisposalWizard(${a.id}, '${LPC.escapeHtml(a.name)}', ${vnc})" class="text-rose-500 hover:text-rose-700 bg-rose-50 px-2 py-1 rounded text-xs font-bold border border-rose-200" title="Céder ou Rebut" aria-label="Céder ou Rebut">Céder</button>` : '-')}
                </td>
            </tr>`;
    });
}

function setRegisterFilter(val) {
    registerFilter = val;
    document.querySelectorAll('[data-filter-btn]').forEach(el => {
        el.classList.toggle('bg-asset-dark', el.dataset.filterBtn === val);
        el.classList.toggle('text-white',    el.dataset.filterBtn === val);
        el.classList.toggle('bg-gray-100',   el.dataset.filterBtn !== val);
        el.classList.toggle('text-gray-500', el.dataset.filterBtn !== val);
    });
    fetchTabData('register');
}

function filterTable(tbodyId, query) {
    const q = query.toLowerCase();
    const rows = document.getElementById(tbodyId).getElementsByTagName('tr');
    for (let i = 0; i < rows.length; i++) {
        if(rows[i].innerText.toLowerCase().includes(q)) rows[i].style.display = '';
        else rows[i].style.display = 'none';
    }
}

// ================= WIZARDS & MODALS ================= //

function populateAccountDropdowns() {
    const selAss = document.getElementById('cap_acc_asset');
    const selDep = document.getElementById('cap_acc_depr');
    const selExp = document.getElementById('cap_acc_exp');

    selAss.innerHTML = '<option value="">-- Actif (2xx) --</option>';
    selDep.innerHTML = '<option value="">-- Amortissement (28x) --</option>';
    selExp.innerHTML = '<option value="">-- Dotation (68x) --</option>';

    const b = globalData.accounts.buckets;
    (b.assets      || []).forEach(a => selAss.innerHTML += LPC.html`<option value="${a.id}">[${a.ohada_code || a.code}] ${a.name}</option>`);
    (b.accumulated || []).forEach(a => selDep.innerHTML += LPC.html`<option value="${a.id}">[${a.ohada_code || a.code}] ${a.name}</option>`);
    (b.charge      || []).forEach(a => selExp.innerHTML += LPC.html`<option value="${a.id}">[${a.ohada_code || a.code}] ${a.name}</option>`);

    populateCashAccountDropdown();
    // Empty-state hint — if the buckets are still empty after 082 didn't run,
    // tell the user rather than leaving three silent empty dropdowns.
    if (!b.assets?.length || !b.accumulated?.length || !b.charge?.length) {
        [selAss, selDep, selExp].forEach(s => {
            if (!s.options.length || s.options.length === 1) {
                s.insertAdjacentHTML('beforeend', '<option value="" disabled>Aucun compte — exécutez la migration 084.</option>');
            }
        });
    }
}

function openCapitalizeModal(vehId, plate, model) {
    document.getElementById('form-capitalize').reset();
    document.getElementById('cap_vehicle_id').value = vehId;
    document.getElementById('cap_name').value = `Véhicule ${plate} ${model}`.trim();
    document.getElementById('cap_monthly_display').innerText = '0 F';
    // A camion / véhicule automobile — pre-select 2451 / 28451 / 6813 when
    // present in the buckets, but let the user override.
    autoSelectAccount('cap_acc_asset', '2451');
    autoSelectAccount('cap_acc_depr',  '28451');
    autoSelectAccount('cap_acc_exp',   '6813');
    openModal('modal-capitalize');
}

function openManualAssetModal() {
    document.getElementById('form-capitalize').reset();
    document.getElementById('cap_vehicle_id').value = '';
    document.getElementById('cap_monthly_display').innerText = '0 F';
    openModal('modal-capitalize');
}

function autoSelectAccount(selectId, ohadaCode) {
    const sel = document.getElementById(selectId);
    if (!sel) return;
    for (let i = 0; i < sel.options.length; i++) {
        if (sel.options[i].text.startsWith(`[${ohadaCode}]`)) {
            sel.selectedIndex = i;
            return;
        }
    }
}

function calculateDotation() {
    const cost    = parseFloat(document.getElementById('cap_cost').value)    || 0;
    const salvage = parseFloat(document.getElementById('cap_salvage').value) || 0;
    const months  = parseInt(document.getElementById('cap_lifespan').value)  || 1;
    const method  = document.getElementById('cap_method').value || 'linear';
    const base    = Math.max(0, cost - salvage);
    let dot = base / months;
    // Show 1st-month dégressif figure for a hint; the exact per-month figure
    // during the run comes from Depreciation::monthly() server-side.
    if (method === 'declining' && months > 0) {
        const years = Math.max(1, Math.round(months / 12));
        const coef  = years <= 2 ? 1.0 : years <= 4 ? 1.5 : years <= 6 ? 2.0 : 2.5;
        dot = (base * (coef / years)) / 12;
    }
    document.getElementById('cap_monthly_display').innerText = LPC.fmt.fcfa(dot);
}

async function submitCapitalization() {
    const form = document.getElementById('form-capitalize');
    if(!form.checkValidity()) return form.reportValidity();

    // Sanity — service start not before acquisition.
    const acq   = document.getElementById('cap_date').value;
    const start = document.getElementById('cap_service_start').value;
    if (acq && start && start < acq) {
        return LPC.modal.alert("La date de mise en service ne peut pas précéder l'acquisition.");
    }

    const payload = {
        action: 'capitalize_asset',
        vehicle_id: document.getElementById('cap_vehicle_id').value || null,
        name: document.getElementById('cap_name').value,
        cost: document.getElementById('cap_cost').value,
        salvage_value: document.getElementById('cap_salvage').value || 0,
        acquisition_date: acq,
        service_start_date: start,
        useful_life_months: document.getElementById('cap_lifespan').value,
        depreciation_method: document.getElementById('cap_method').value,
        asset_account_id:   document.getElementById('cap_acc_asset').value,
        depr_account_id:    document.getElementById('cap_acc_depr').value,
        expense_account_id: document.getElementById('cap_acc_exp').value,
    };

    const btn = document.getElementById('btn-submit-cap');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    try {
        const result = await jsonPost(payload);
        if(result.status === 'success') {
            closeModal('modal-capitalize');
            LPC.modal.alert(result.message || "Immobilisation capitalisée.");
            fetchTabData('queue');
        } else {
            LPC.modal.alert(result.message || 'Erreur');
        }
    } catch(e) { LPC.modal.alert("Erreur serveur"); }

    btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Enregistrer l\'Actif';
}

// -- CESSION --
let currentDisposalBookValue = 0;

function openDisposalWizard(id, name, vnc) {
    currentDisposalBookValue = Number(vnc) || 0;
    document.getElementById('ces_asset_id').value = id;
    document.getElementById('ces_asset_name').innerText = name;
    document.getElementById('ces_asset_vnc').innerText = LPC.fmt.fcfa(vnc);
    document.getElementById('ces_type').value = 'sale';
    document.getElementById('ces_amount').value = '';
    const vatEl = document.getElementById('ces_vat');
    if (vatEl) vatEl.value = '';
    populateCashAccountDropdown();
    toggleCessionAmount();
    previewCession();
    openModal('modal-cession');
}

function toggleCessionAmount() {
    const type = document.getElementById('ces_type').value;
    const div = document.getElementById('ces_amount_div');
    const cashDiv = document.getElementById('ces_cash_div');
    const vatDiv  = document.getElementById('ces_vat_div');
    const amt = document.getElementById('ces_amount');
    const cash = document.getElementById('ces_cash_account');
    if(type === 'scrap') {
        div.classList.add('hidden');     amt.required = false; amt.value = 0;
        cashDiv.classList.add('hidden'); cash.required = false;
        if (vatDiv) vatDiv.classList.add('hidden');
    } else {
        div.classList.remove('hidden');     amt.required = true;
        cashDiv.classList.remove('hidden'); cash.required = true;
        if (vatDiv) vatDiv.classList.remove('hidden');
    }
    previewCession();
}

function previewCession() {
    const sale = parseFloat(document.getElementById('ces_amount').value) || 0;
    const vatEl = document.getElementById('ces_vat');
    const vat = parseFloat(vatEl && vatEl.value) || 0;
    const saleHt = Math.max(0, sale - vat);
    const bv = currentDisposalBookValue;
    const plus  = Math.max(0, saleHt - bv);
    const moins = Math.max(0, bv - saleHt);
    document.getElementById('ces_preview').classList.remove('hidden');
    document.getElementById('ces_preview_book').innerText  = LPC.fmt.fcfa(bv);
    document.getElementById('ces_preview_plus').innerText  = LPC.fmt.fcfa(plus);
    document.getElementById('ces_preview_moins').innerText = LPC.fmt.fcfa(moins);
    const htEl = document.getElementById('ces_preview_ht');
    if (htEl) htEl.innerText = LPC.fmt.fcfa(saleHt);
}

async function submitCession() {
    const amt = document.getElementById('ces_amount');
    const cash = document.getElementById('ces_cash_account');
    const vatEl = document.getElementById('ces_vat');
    if(amt.required && !amt.value) return LPC.modal.alert("Le prix de vente est obligatoire.");
    if(cash.required && !cash.value) return LPC.modal.alert("Sélectionnez un compte de trésorerie.");
    const vat = parseFloat(vatEl && vatEl.value) || 0;
    if (vat < 0) return LPC.modal.alert("Le montant de TVA ne peut pas être négatif.");
    if (vat > (parseFloat(amt.value) || 0)) return LPC.modal.alert("La TVA ne peut pas dépasser le prix TTC.");

    const payload = {
        action: 'dispose_asset',
        asset_id: document.getElementById('ces_asset_id').value,
        amount: amt.value || 0,
        vat_amount: vat,
        cash_account_id: cash.value || 0,
        date: document.getElementById('ces_date').value,
    };

    try {
        const result = await jsonPost(payload);
        if(result.status === 'success') {
            closeModal('modal-cession');
            LPC.modal.alert("Cession validée. JE #" + result.data.journal_entry_id + " postée.");
            fetchTabData('register');
        } else {
            LPC.modal.alert(result.message || 'Erreur');
        }
    } catch(e) { LPC.modal.alert("Erreur serveur"); }
}

// -- DOTATIONS MENSUELLES --
async function previewDotations() {
    const monthStr = document.getElementById('dotation_month').value;
    if(!monthStr) return LPC.modal.alert("Veuillez choisir un mois.");
    const [y, m] = monthStr.split('-').map(Number);

    const box = document.getElementById('dotation_preview');
    box.classList.remove('hidden');
    box.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Calcul…';
    try {
        const res = await fetch(`/api/v1/fixed_assets_controller.php?action=preview_dotations&year=${y}&month=${m}`).then(r => r.json());
        if (res.status !== 'success') { box.innerHTML = res.message || 'Erreur'; return; }
        const d = res.data;
        if (d.is_closed) {
            box.innerHTML = `<div class="p-4 bg-rose-50 border border-rose-200 rounded-xl text-rose-800 font-bold text-xs">Exercice ${y} clôturé — impossible de poster une dotation.</div>`;
            return;
        }
        if (!d.lines.length) {
            box.innerHTML = `<div class="p-4 bg-gray-50 border border-gray-200 rounded-xl text-gray-500 font-bold text-xs">Aucune dotation à générer pour ${d.period}. ${d.already_done} actif(s) déjà traité(s).</div>`;
            return;
        }
        let html = `<div class="p-4 bg-white border border-gray-200 rounded-xl">
            <div class="flex justify-between mb-3">
              <div class="font-black text-gray-800 text-sm uppercase tracking-widest">Aperçu — ${d.period}</div>
              <div class="text-[10px] text-gray-500 font-bold">${d.count} actif(s) · ${d.already_done} déjà passé(s)</div>
            </div>
            <div class="overflow-auto max-h-60 border border-gray-100 rounded"><table class="min-w-full text-xs">
                <thead class="bg-gray-50"><tr>
                    <th class="py-2 px-3 text-left font-black text-gray-500">Actif</th>
                    <th class="py-2 px-3 text-center font-black text-gray-500">Méthode</th>
                    <th class="py-2 px-3 text-center font-black text-gray-500">Prorata</th>
                    <th class="py-2 px-3 text-right font-black text-gray-500">Montant</th>
                </tr></thead><tbody class="divide-y divide-gray-100">`;
        d.lines.forEach(l => {
            html += `<tr><td class="py-2 px-3 font-bold">${LPC.escapeHtml(l.name)}</td>
                <td class="py-2 px-3 text-center uppercase text-[10px] font-black">${l.method}</td>
                <td class="py-2 px-3 text-center">${l.proration_days}j</td>
                <td class="py-2 px-3 text-right font-black">${fmt(l.amount)} F</td></tr>`;
        });
        html += `</tbody></table></div>
            <div class="mt-3 flex justify-between items-center">
              <span class="text-[11px] font-bold text-gray-500">Total débit 68x = crédit 28x</span>
              <span class="font-black text-asset-dark">${fmt(d.total_debit)} F</span>
            </div>
          </div>`;
        box.innerHTML = html;
    } catch (e) { box.innerHTML = "Erreur serveur"; }
}

async function runDotations() {
    const monthStr = document.getElementById('dotation_month').value;
    if(!monthStr) return LPC.modal.alert("Veuillez choisir un mois.");
    const [y, m] = monthStr.split('-').map(Number);
    if(!(await LPC.modal.confirm(`Générer les dotations aux amortissements pour ${monthStr} ?`))) return;

    try {
        const result = await jsonPost({ action: 'run_monthly_dotations', year: y, month: m });
        LPC.modal.alert(result.message || (result.status === 'success' ? 'OK' : 'Erreur'));
        fetchTabData('register');
    } catch(e) { LPC.modal.alert("Erreur serveur"); }
}

// Simple CSV Exporter (Answer 10B)
function exportCSV(tableId, filename) {
    let csv = [];
    const rows = document.querySelectorAll(`#${tableId} tr:not(.vnc-zero)`);

    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll("td, th");
        for (let j = 0; j < cols.length - 1; j++) {
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim();
            row.push('"' + data + '"');
        }
        csv.push(row.join(","));
    }

    const csvFile = new Blob(["﻿" + csv.join("\n")], {type: "text/csv;charset=utf-8;"});
    const link = document.createElement("a");
    link.download = `${filename}_LPC.csv`;
    link.href = window.URL.createObjectURL(csvFile);
    link.style.display = "none";
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
