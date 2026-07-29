/**
 * assets/js/modules/admin-master_data.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Master Data Hub controller.
 *
 * Sprint 6 D2: extracted from modules/admin/master_data.php so the CSP
 * `script-src` could drop 'unsafe-inline'.
 *
 * Sprint 9 (migration 041) — product master data rebuild:
 *   · Categories come from the `product_categories` table and can be created
 *     inline, instead of a hardcoded array that had to agree with a DB ENUM
 *     and a PHP whitelist.
 *   · SKU codes are suggested by the server (WAT-20L-004) and stay editable.
 *   · Packs (6 / 12 per carton) have real fields.
 *   · Emballage is optional again — every dynamic_select was being rendered
 *     `required`, which made products that ship without a container
 *     unsaveable — and a new one can be created without leaving the form.
 *   · Returnable containers capture bottle_size + has_cork, the two columns
 *     cre_controller uses to find them. The form never collected them, so
 *     every empty created here since Sprint 4 was invisible to the empties
 *     ledger.
 *   · Cork variants of one size render as a single family row: one emballage,
 *     two return states, two consigne prices.
 *
 * The other four tabs still use the generic config-driven renderer below.
 * -----------------------------------------------------------------------------
 */

// --- 1. TAB CONFIGURATION ---
// `products` no longer declares a `form`; it has a purpose-built renderer
// (buildProductForm) because its fields are conditional on the category.
const mdmConfig = {
    products: {
        title: "Produits", addBtn: "Nouveau Produit", api: "products",
        custom: true,
        columns: [
            { key: 'code',  label: 'Code SKU' },
            { key: 'name',  label: 'Désignation' },
            { key: 'category', label: 'Catégorie' },
            { key: 'linked_empty_name', label: 'Emballage Lié' },
            { key: 'base_price', label: 'Prix Base' },
            { key: 'revenue_account_code', label: 'Compte Vente' },
            { key: 'is_active', label: 'Statut' }
        ]
    },
    pricing: {
        title: "Tarifs Clients", addBtn: "Nouveau Tarif", api: "pricing",
        columns: [
            { key: 'client_name', label: 'Client', render: v => `<span class="font-black text-gray-900">${v}</span>` },
            { key: 'product_name', label: 'Produit', render: v => `<span class="font-bold text-gray-600">${v}</span>` },
            { key: 'custom_price', label: 'Prix Négocié', render: v => `<span class="font-black text-lpc-dark">${LPC.fmt.int(v)} FCFA</span>` }
        ],
        form: [
            { name: 'client_id', label: 'Client', type: 'dynamic_select', source: 'clients', width: 'col-span-1' },
            { name: 'product_id', label: 'Produit', type: 'dynamic_select', source: 'products', width: 'col-span-1' },
            { name: 'custom_price', label: 'Prix Négocié (FCFA)', type: 'number', width: 'col-span-2' }
        ]
    },
    employees: {
        title: "Employés & RH", addBtn: "Nouvel Employé", api: "employees",
        columns: [
            { key: 'avatar', label: 'Profil', render: v => `<img alt="" src="${v || '/assets/img/default_avatar.png'}" class="w-10 h-10 rounded-full object-cover border border-gray-200">` },
            { key: 'employee_code', label: 'Matricule', render: v => `<span class="font-bold text-gray-500">${v || 'N/A'}</span>` },
            { key: 'full_name', label: 'Nom & Prénom', render: v => `<span class="font-black text-gray-900">${v}</span>` },
            { key: 'role_name', label: 'Rôle Système', render: v => `<span class="px-2 py-1 bg-blue-50 text-blue-600 rounded text-[10px] font-bold uppercase">${v}</span>` },
            { key: 'job_title', label: 'Poste', render: v => `<span class="text-sm text-gray-600">${v || '-'}</span>` },
            { key: 'status', label: 'Statut', render: v => v === 'active' ? '<span class="text-green-500 text-xs font-bold">Actif</span>' : '<span class="text-red-500 text-xs font-bold">Inactif</span>' }
        ],
        form: [
            { name: 'first_name', label: 'Prénom', type: 'text', width: 'col-span-1' },
            { name: 'last_name', label: 'Nom', type: 'text', width: 'col-span-1' },
            { name: 'email', label: 'Email (Connexion)', type: 'email', width: 'col-span-1' },
            { name: 'role_id', label: 'Rôle Système', type: 'dynamic_select', source: 'roles', width: 'col-span-1' },
            { name: 'job_title', label: 'Poste (ex: Chauffeur)', type: 'text', width: 'col-span-1' },
            { name: 'phone', label: 'Téléphone', type: 'text', width: 'col-span-1' },
            { name: 'base_salary', label: 'Salaire de Base', type: 'number', width: 'col-span-1' },
            { name: 'avatar', label: 'Photo de Profil', type: 'file', width: 'col-span-1' }
        ]
    },
    suppliers: {
        title: "Fournisseurs", addBtn: "Nouveau Fournisseur", api: "suppliers",
        columns: [
            { key: 'lpc_code', label: 'Code', render: v => `<span class="font-bold text-gray-500">${v}</span>` },
            { key: 'name', label: 'Fournisseur', render: v => `<span class="font-black text-gray-900">${v}</span>` },
            { key: 'phone', label: 'Contact', render: v => `<span class="text-sm text-gray-600">${v || '-'}</span>` },
            { key: 'is_active', label: 'Statut', render: v => parseInt(v) === 1 ? '<span class="text-green-500 text-xs font-bold">Actif</span>' : '<span class="text-red-500 text-xs font-bold">Inactif</span>' }
        ],
        form: [
            // lpc_code is completely removed. The backend handles it invisibly.
            { name: 'name', label: 'Raison Sociale', type: 'text', width: 'col-span-2' },
            { name: 'phone', label: 'Téléphone', type: 'text', width: 'col-span-1' },
            { name: 'email', label: 'Email', type: 'email', width: 'col-span-1' },
            { name: 'address', label: 'Adresse Complète', type: 'text', width: 'col-span-2' }
        ]
    },
    fleet: {
        title: "Flotte", addBtn: "Nouveau Véhicule", api: "fleet",
        columns: [
            { key: 'plate_number', label: 'Immatriculation', render: v => `<span class="font-black text-gray-900 bg-yellow-100 px-2 py-1 rounded border border-yellow-300">${v}</span>` },
            { key: 'type', label: 'Type', render: v => `<span class="text-sm uppercase font-bold text-gray-600">${v}</span>` },
            { key: 'status', label: 'État', render: v => v === 'active' ? '<span class="text-green-500 text-xs font-bold">Opérationnel</span>' : '<span class="text-red-500 text-xs font-bold">En Panne/Retiré</span>' }
        ],
        form: [
            { name: 'plate_number', label: 'Plaque d\'Immatriculation', type: 'text', width: 'col-span-1' },
            { name: 'type', label: 'Type de Véhicule', type: 'select', options: [{v:'tricycle',l:'Tricycle'}, {v:'bike',l:'Moto'}, {v:'truck',l:'Camion'}], width: 'col-span-1' },
            { name: 'make_model', label: 'Marque & Modèle', type: 'text', width: 'col-span-1' },
            { name: 'status', label: 'État Actuel', type: 'select', options: [{v:'active',l:'Opérationnel'}, {v:'repair',l:'En Panne'}, {v:'retired',l:'Retiré'}], width: 'col-span-1' }
        ]
    }
};

let currentModule = 'products';
let moduleData = [];
let metaData = {};          // dynamic dropdown options (roles, clients, categories…)
let pageState = { page: 1, total_pages: 1, total: 0 };
let schemaV2 = false;       // migration 041 applied?
let codeTouched = false;    // has the user overtyped the suggested SKU?

const INPUT_CLS = 'w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-medium outline-none focus:ring-2 focus:ring-lpc-light';
const LABEL_CLS = 'block text-[10px] font-black uppercase text-gray-500 mb-1.5 ml-1';

function notify(message, type) {
    if (window.LPC && LPC.toast) LPC.toast(message, type || 'success');
    else if (type === 'error') LPC.modal.alert(message);
}

// ---------------------------------------------------------------------------
// 2. TABS
// ---------------------------------------------------------------------------
function initTabs() {
    const nav = document.getElementById('mdm-tabs');
    nav.innerHTML = '';
    Object.keys(mdmConfig).forEach(key => {
        const isActive = key === currentModule;
        const borderClass = isActive ? 'border-lpc-dark text-lpc-dark font-black' : 'border-transparent text-gray-400 font-bold hover:text-gray-600';
        nav.innerHTML += LPC.html`<button onclick="switchTab('${key}')" class="px-2 py-5 border-b-2 text-sm uppercase tracking-wider transition-colors ${borderClass}">${mdmConfig[key].title}</button>`;
    });
}

async function switchTab(key) {
    currentModule = key;
    pageState.page = 1;
    initTabs();
    document.getElementById('btn-add-text').innerText = mdmConfig[currentModule].addBtn;

    const thead = document.getElementById('table-head');
    let thHTML = '<tr>';
    mdmConfig[currentModule].columns.forEach(col => {
        thHTML += LPC.html`<th class="py-4 px-6 text-[10px] uppercase text-gray-500 font-black tracking-widest">${col.label}</th>`;
    });
    thHTML += `<th class="py-4 px-6 text-right text-[10px] uppercase text-gray-500 font-black tracking-widest">Actions</th></tr>`;
    thead.innerHTML = thHTML;

    await fetchData();
}

async function fetchData() {
    const tbody = document.getElementById('table-body');
    const colspan = mdmConfig[currentModule].columns.length + 1;
    tbody.innerHTML = LPC.html`<tr><td colspan="${colspan}" class="py-8 text-center text-gray-400 animate-pulse font-bold text-sm">Chargement des données...</td></tr>`;

    try {
        const q = (document.getElementById('mdm-search').value || '').trim();
        const url = `/api/v1/mdm_controller.php?action=read&module=${mdmConfig[currentModule].api}`
                  + `&page=${pageState.page}&per_page=50`
                  + (q ? `&q=${encodeURIComponent(q)}` : '');
        const response = await fetch(url);
        const result = await response.json();

        if (result.status !== 'success') throw new Error(result.message);

        moduleData = result.data;
        metaData   = result.meta || {};
        schemaV2   = !!metaData.schema_v2;
        pageState  = Object.assign(pageState, result.pagination || { page: 1, total_pages: 1, total: moduleData.length });

        renderTable(moduleData);
        renderPager();
    } catch (error) {
        tbody.innerHTML = LPC.html`<tr><td colspan="${colspan}" class="py-8 text-center text-red-500 font-bold">Erreur API: ${error.message}</td></tr>`;
    }
}

// ---------------------------------------------------------------------------
// 3. TABLE RENDERING
// ---------------------------------------------------------------------------
function actionsCell(pk, statusFlag, showToggle) {
    const editBtn = LPC.html`<button onclick="openModal(${pk})" title="Modifier" aria-label="Modifier" class="text-blue-500 hover:text-blue-700 bg-blue-50 p-2 rounded-lg mr-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg></button>`;

    // NOTE: this used to be built with `${LPC.raw(...)}` inside an UNTAGGED
    // template literal, so the RawHtml wrapper stringified to the literal text
    // "[object Object]" in the Actions column. lpc-dom.js now gives RawHtml a
    // toString(), and this function returns plain strings either way.
    if (!showToggle) return editBtn;

    // Normalise before testing. PDO hands back is_active as the STRING "0" for
    // an inactive row, and "0" is truthy in JavaScript — so the old
    // `statusFlag ? …` test painted deactivated rows with the "deactivate"
    // button.
    const active = parseInt(statusFlag, 10) === 1 ? 1 : 0;
    const cls = active ? 'text-red-500 bg-red-50' : 'text-green-500 bg-green-50';
    const label = active ? 'Désactiver' : 'Réactiver';
    return editBtn + LPC.html`<button onclick="toggleStatus(${pk}, ${active})" title="${label}" aria-label="${label}" class="${cls} p-2 rounded-lg"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path></svg></button>`;
}

function renderTable(dataArray) {
    const tbody = document.getElementById('table-body');
    const colspan = mdmConfig[currentModule].columns.length + 1;
    tbody.innerHTML = '';

    if (!dataArray.length) {
        tbody.innerHTML = LPC.html`<tr><td colspan="${colspan}" class="py-8 text-center text-gray-400 font-medium">Aucun enregistrement trouvé.</td></tr>`;
        return;
    }

    if (currentModule === 'products') { renderProductRows(dataArray); return; }

    let html = '';
    dataArray.forEach(row => {
        let tr = '<tr class="hover:bg-gray-50 transition-colors">';
        mdmConfig[currentModule].columns.forEach(col => { tr += `<td class="py-4 px-6">${col.render(row[col.key])}</td>`; });

        const pk = currentModule === 'pricing' ? `'${row.client_id}_${row.product_id}'` : row.id;
        const statusFlag = row.is_active !== undefined ? row.is_active : (row.status === 'active' ? 1 : 0);

        tr += `<td class="py-4 px-6 text-right whitespace-nowrap">${actionsCell(pk, statusFlag, currentModule !== 'pricing')}</td></tr>`;
        html += tr;
    });
    tbody.innerHTML = html;
}

/**
 * Empties of the same size are two SKUs — the empties ledger and the CRE
 * lookups key on (bottle_size, has_cork) and the consigne prices genuinely
 * differ — but they are ONE physical container. Group them so the list reads
 * the way the business thinks: "Bonbonne 10L, returned with or without cork".
 */
function groupProductRows(rows) {
    const units = [];
    const families = new Map();

    rows.forEach(row => {
        const isEmpty = parseInt(row.is_empty, 10) === 1;
        if (schemaV2 && isEmpty && row.bottle_size) {
            const key = String(row.bottle_size);
            if (!families.has(key)) {
                const family = { type: 'family', size: key, rows: [] };
                families.set(key, family);
                units.push(family);
            }
            families.get(key).rows.push(row);
        } else {
            units.push({ type: 'single', row: row });
        }
    });

    // A size with only one variant is not a family — render it normally.
    return units.map(u => (u.type === 'family' && u.rows.length === 1)
        ? { type: 'single', row: u.rows[0] }
        : u);
}

function packBadge(row) {
    const per = parseInt(row.units_per_pack || 1, 10);
    if (!schemaV2 || per <= 1) return '';
    const uom = row.unit_of_measure && row.unit_of_measure !== 'unite' ? row.unit_of_measure : 'unité';
    return LPC.html`<span class="ml-2 text-[10px] font-bold text-indigo-600 bg-indigo-50 border border-indigo-100 px-2 py-0.5 rounded">pack de ${per} ${uom}s</span>`;
}

function accountChip(row) {
    if (!schemaV2 || !row.revenue_account_code) {
        return '<span class="text-gray-300 text-xs" title="Aucun compte dédié — la vente crédite 701">701</span>';
    }
    const override = parseInt(row.revenue_is_override, 10) === 1;
    const cls = override ? 'text-purple-700 bg-purple-50 border-purple-200' : 'text-emerald-700 bg-emerald-50 border-emerald-200';
    const title = override
        ? `Compte spécifique au produit : ${row.revenue_account_name}`
        : `Hérité de la catégorie : ${row.revenue_account_name}`;
    return LPC.html`<span title="${title}" class="font-mono text-[11px] font-bold border px-2 py-1 rounded ${cls}">${row.revenue_account_code}</span>`;
}

function emballageCell(row) {
    if (row.linked_empty_name) {
        return LPC.html`<span class="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-1 rounded"><i class="fas fa-link mr-1"></i>${row.linked_empty_name}</span>`;
    }
    if (parseInt(row.is_empty, 10) === 1) {
        return '<span class="text-[10px] font-bold text-gray-400">— c\'est un emballage —</span>';
    }
    return '<span class="text-[10px] font-medium text-gray-400 italic">Aucun emballage</span>';
}

function statusCell(v) {
    return parseInt(v, 10) === 1
        ? '<span class="text-green-500 text-xs font-bold">Actif</span>'
        : '<span class="text-red-500 text-xs font-bold">Inactif</span>';
}

function renderProductRows(rows) {
    const tbody = document.getElementById('table-body');
    let html = '';

    groupProductRows(rows).forEach(unit => {
        if (unit.type === 'single') {
            const r = unit.row;
            html += LPC.html`<tr class="hover:bg-gray-50 transition-colors">
                <td class="py-4 px-6"><span class="font-bold text-gray-500 font-mono text-xs">${r.code}</span></td>
                <td class="py-4 px-6"><span class="font-black text-gray-900">${r.name}</span>${LPC.raw(packBadge(r))}</td>
                <td class="py-4 px-6"><span class="px-2 py-1 bg-gray-100 text-gray-600 rounded text-[10px] uppercase font-bold">${r.category}</span></td>
                <td class="py-4 px-6">${LPC.raw(emballageCell(r))}</td>
                <td class="py-4 px-6"><span class="font-bold text-emerald-600">${LPC.fmt.int(r.base_price)} FCFA</span></td>
                <td class="py-4 px-6">${LPC.raw(accountChip(r))}</td>
                <td class="py-4 px-6">${LPC.raw(statusCell(r.is_active))}</td>
                <td class="py-4 px-6 text-right whitespace-nowrap">${LPC.raw(actionsCell(r.id, r.is_active, true))}</td>
            </tr>`;
            return;
        }

        // Family row: one container, several return states.
        const variants = unit.rows.slice().sort((a, b) => (parseInt(a.has_cork, 10) === 1 ? -1 : 1));
        const anyActive = variants.some(v => parseInt(v.is_active, 10) === 1);
        const chips = variants.map(v => LPC.html`
            <button onclick="openModal(${v.id})"
                    class="text-left border rounded-lg px-3 py-2 hover:border-lpc-light hover:bg-green-50/40 transition-colors ${parseInt(v.is_active, 10) === 1 ? 'border-gray-200 bg-white' : 'border-gray-100 bg-gray-50 opacity-60'}">
                <span class="block text-[10px] font-black uppercase tracking-wide ${parseInt(v.has_cork, 10) === 1 ? 'text-amber-600' : 'text-gray-500'}">${parseInt(v.has_cork, 10) === 1 ? 'avec bouchon' : 'sans bouchon'}</span>
                <span class="block font-mono text-[10px] text-gray-400">${v.code}</span>
                <span class="block font-bold text-emerald-600 text-sm">${LPC.fmt.int(v.base_price)} FCFA</span>
            </button>`).join('');

        html += LPC.html`<tr class="hover:bg-gray-50 transition-colors align-top">
            <td class="py-4 px-6">
                <span class="text-[10px] font-black uppercase tracking-widest text-amber-700 bg-amber-50 border border-amber-200 px-2 py-1 rounded">Famille ${unit.size}</span>
            </td>
            <td class="py-4 px-6" colspan="4">
                <span class="font-black text-gray-900 block mb-2">Emballage ${unit.size}
                    <span class="ml-2 text-[10px] font-medium text-gray-400">un contenant, ${variants.length} états de retour</span>
                </span>
                <div class="flex flex-wrap gap-2">${LPC.raw(chips)}</div>
            </td>
            <td class="py-4 px-6">${LPC.raw(accountChip(variants[0]))}</td>
            <td class="py-4 px-6">${LPC.raw(statusCell(anyActive ? 1 : 0))}</td>
            <td class="py-4 px-6 text-right whitespace-nowrap">
                <span class="text-[10px] text-gray-400">Modifier une variante ci-contre</span>
            </td>
        </tr>`;
    });

    tbody.innerHTML = html;
}

function renderPager() {
    let footer = document.getElementById('mdm-pager');
    if (!footer) {
        footer = document.createElement('div');
        footer.id = 'mdm-pager';
        footer.className = 'flex items-center justify-between px-6 py-3 bg-gray-50 border-t border-gray-100';
        const card = document.getElementById('table-body').closest('.bg-white');
        if (card) card.appendChild(footer);
    }
    const page = pageState.page, total_pages = pageState.total_pages, total = pageState.total;
    if (!total_pages || total_pages <= 1) {
        footer.innerHTML = LPC.html`<span class="text-[11px] font-bold text-gray-400">${total || 0} enregistrement(s)</span>`;
        return;
    }
    footer.innerHTML = LPC.html`
        <span class="text-[11px] font-bold text-gray-400">${total} enregistrement(s)</span>
        <span class="flex items-center gap-2">
            <button onclick="gotoPage(${page - 1})" ${page <= 1 ? 'disabled' : ''} class="px-3 py-1.5 text-xs font-bold rounded-lg border border-gray-200 bg-white disabled:opacity-40">Précédent</button>
            <span class="text-[11px] font-bold text-gray-500">Page ${page} / ${total_pages}</span>
            <button onclick="gotoPage(${page + 1})" ${page >= total_pages ? 'disabled' : ''} class="px-3 py-1.5 text-xs font-bold rounded-lg border border-gray-200 bg-white disabled:opacity-40">Suivant</button>
        </span>`;
}

function gotoPage(n) {
    if (n < 1 || n > pageState.total_pages) return;
    pageState.page = n;
    fetchData();
}

// ---------------------------------------------------------------------------
// 4. MODAL — generic renderer (pricing / employees / suppliers / fleet)
// ---------------------------------------------------------------------------
function openModal(id = null) {
    const form = document.getElementById('dynamic-form');
    form.innerHTML = LPC.html`<input type="hidden" id="f_id" name="id" value="${id || ''}">`;
    document.getElementById('modal-title').innerText = id ? `Modifier` : mdmConfig[currentModule].addBtn;

    let existingData = {};
    if (id) {
        if (currentModule === 'pricing') {
            const parts = String(id).split('_');
            existingData = moduleData.find(i => i.client_id == parts[0] && i.product_id == parts[1]) || {};
        } else {
            existingData = moduleData.find(i => i.id == id) || {};
        }
    }

    if (mdmConfig[currentModule].custom) {
        buildProductForm(form, existingData);
        document.getElementById('mdmModal').classList.remove('hidden');
        return;
    }

    mdmConfig[currentModule].form.forEach(field => {
        const value = existingData[field.name] || '';
        let inputHTML = '';

        if (field.type === 'select') {
            const opts = field.options.map(o => LPC.html`<option value="${o.v}" ${value === o.v ? 'selected' : ''}>${o.l}</option>`).join('');
            inputHTML = LPC.html`<select required id="f_${field.name}" name="${field.name}" class="${INPUT_CLS}">${LPC.raw(opts)}</select>`;
        } else if (field.type === 'dynamic_select') {
            const opts = (metaData[field.source] || []).map(o => LPC.html`<option value="${o.id}" ${value == o.id ? 'selected' : ''}>${o.name}</option>`).join('');
            inputHTML = LPC.html`<select required id="f_${field.name}" name="${field.name}" class="${INPUT_CLS}"><option value="">Sélectionner...</option>${LPC.raw(opts)}</select>`;
        } else if (field.type === 'file') {
            inputHTML = LPC.html`<input type="file" id="f_${field.name}" name="${field.name}" accept="image/*" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-2 text-sm font-medium outline-none">`;
            if (value) inputHTML += LPC.html`<p class="text-[10px] text-gray-500 mt-1">Fichier actuel: ${value.split('/').pop()}</p>`;
        } else {
            inputHTML = LPC.html`<input required type="${field.type}" id="f_${field.name}" name="${field.name}" value="${value}" ${currentModule === 'pricing' && id && field.name.includes('id') ? 'readonly' : ''} class="${INPUT_CLS}">`;
        }

        form.innerHTML += LPC.html`<div class="${field.width}"><label class="${LABEL_CLS}">${field.label}</label>${LPC.raw(inputHTML)}</div>`;
    });

    document.getElementById('mdmModal').classList.remove('hidden');
}

function closeModal() { document.getElementById('mdmModal').classList.add('hidden'); }

// ---------------------------------------------------------------------------
// 5. PRODUCT FORM
// ---------------------------------------------------------------------------
function currentCategory() {
    const sel = document.getElementById('f_category_id');
    const id = sel ? sel.value : '';
    return (metaData.categories || []).find(c => String(c.id) === String(id)) || null;
}

function sectionHeader(title, hint) {
    return LPC.html`<div class="col-span-2 mt-2">
        <h4 class="text-[11px] font-black uppercase tracking-widest text-lpc-dark border-b border-gray-100 pb-2">${title}</h4>
        ${LPC.raw(hint ? LPC.html`<p class="text-[11px] text-gray-400 mt-1.5">${hint}</p>` : '')}
    </div>`;
}

function buildProductForm(form, d) {
    codeTouched = !!d.id;   // never silently rewrite an existing SKU

    if (!schemaV2) {
        form.innerHTML += LPC.html`<div class="col-span-2 p-4 rounded-xl bg-amber-50 border border-amber-200 text-[12px] font-bold text-amber-800">
            Migration 041 non appliquée — formulaire en mode restreint (catégories figées, code manuel).
        </div>`;
    }

    const cats = metaData.categories || [];
    const selectedCatId = d.category_id || (cats[0] ? cats[0].id : '');
    const catOpts = cats.map(c => LPC.html`<option value="${c.id}" ${String(c.id) === String(selectedCatId) ? 'selected' : ''}>${c.name}</option>`).join('');

    const uoms = metaData.uoms || [{ v: 'unite', l: 'Unité' }];
    const uomOpts = uoms.map(u => LPC.html`<option value="${u.v}" ${(d.unit_of_measure || 'unite') === u.v ? 'selected' : ''}>${u.l}</option>`).join('');

    form.innerHTML += LPC.html`
        ${LPC.raw(sectionHeader('Identification'))}

        <div class="col-span-1">
            <label class="${LABEL_CLS}">Catégorie</label>
            <div class="flex gap-2">
                <select required id="f_category_id" name="category_id" onchange="onCategoryChange()" class="${INPUT_CLS}">${LPC.raw(catOpts)}</select>
                <button type="button" onclick="openCategoryModal()" title="Ajouter une catégorie" aria-label="Ajouter une catégorie"
                        class="shrink-0 px-4 rounded-xl bg-lpc-dark text-white font-black text-lg leading-none hover:bg-green-800 active:scale-95 transition-transform">+</button>
            </div>
            <p id="cat-hint" class="text-[10px] text-gray-400 mt-1.5 ml-1"></p>
        </div>

        <div class="col-span-1">
            <label class="${LABEL_CLS}">Code SKU — généré, modifiable</label>
            <div class="flex gap-2">
                <input type="text" id="f_code" name="code" value="${d.code || ''}" oninput="markCodeTouched()"
                       placeholder="Généré automatiquement" class="${INPUT_CLS} font-mono">
                <button type="button" onclick="regenerateCode()" title="Régénérer le code" aria-label="Régénérer le code"
                        class="shrink-0 px-4 rounded-xl bg-gray-100 text-gray-600 font-bold hover:bg-gray-200 active:scale-95 transition-transform">&#8635;</button>
            </div>
        </div>

        <div class="col-span-2">
            <label class="${LABEL_CLS}">Désignation</label>
            <input required type="text" id="f_name" name="name" value="${d.name || ''}" class="${INPUT_CLS}">
        </div>

        ${LPC.raw(sectionHeader('Conditionnement', "Le format et le nombre d'unités par pack sont des champs à part entière : deux modules déduisaient jusqu'ici la contenance en analysant le code SKU."))}

        <div class="col-span-1">
            <label class="${LABEL_CLS}">Contenance / Format</label>
            <input type="text" id="f_format" name="format" value="${d.format || ''}" placeholder="20L, 1.5L, 50cl…"
                   oninput="refreshSuggestedCode()" class="${INPUT_CLS}">
        </div>

        <div class="col-span-1">
            <label class="${LABEL_CLS}">Unité de mesure</label>
            <select id="f_unit_of_measure" name="unit_of_measure" class="${INPUT_CLS}">${LPC.raw(uomOpts)}</select>
        </div>

        <div class="col-span-1" id="pack-qty-wrap">
            <label class="${LABEL_CLS}">Unités par pack</label>
            <input type="number" min="1" step="1" id="f_units_per_pack" name="units_per_pack"
                   value="${d.units_per_pack || 1}" onchange="onPackChange()" class="${INPUT_CLS}">
            <p class="text-[10px] text-gray-400 mt-1.5 ml-1">1 = vendu à l'unité. 6 ou 12 pour un pack de jus.</p>
        </div>

        <div class="col-span-1" id="sold-by-wrap">
            <label class="${LABEL_CLS}">Le prix de base s'applique à</label>
            <select id="f_sold_by" name="sold_by" class="${INPUT_CLS}">
                <option value="unit" ${d.sold_by !== 'pack' ? 'selected' : ''}>L'unité</option>
                <option value="pack" ${d.sold_by === 'pack' ? 'selected' : ''}>Le pack complet</option>
            </select>
        </div>

        ${LPC.raw(sectionHeader('Consigne & Emballage'))}
        <div class="col-span-2" id="emballage-zone"></div>

        ${LPC.raw(sectionHeader('Prix & Comptabilité'))}

        <div class="col-span-1">
            <label class="${LABEL_CLS}">Prix de base (FCFA)</label>
            <input required type="number" min="0" step="1" id="f_base_price" name="base_price" value="${d.base_price != null ? Math.round(d.base_price) : ''}" class="${INPUT_CLS}">
        </div>

        <div class="col-span-1">
            <label class="${LABEL_CLS}">Compte de vente</label>
            <div id="revenue-preview" class="w-full bg-emerald-50/60 border border-emerald-100 rounded-xl p-3 text-sm font-bold text-emerald-800"></div>
            <p class="text-[10px] text-gray-400 mt-1.5 ml-1">Hérité de la catégorie. Chaque facture crédite ce compte au lieu du 701 global.</p>
        </div>
    `;

    onCategoryChange(d);
}

function markCodeTouched() { codeTouched = true; }
function regenerateCode()  { codeTouched = false; refreshSuggestedCode(); }

/**
 * Repaint everything that depends on the category: the consigne block, the
 * pack fields, the accounting preview and the suggested SKU.
 * `d` is only passed on first paint, to restore an existing product's values.
 */
function onCategoryChange(d) {
    const cat = currentCategory();
    const data = d || {};
    const zone = document.getElementById('emballage-zone');
    const hint = document.getElementById('cat-hint');

    if (!cat) { if (zone) zone.innerHTML = ''; return; }

    if (hint) {
        hint.textContent = cat.revenue_code
            ? `Préfixe ${cat.sku_prefix} · ventes au compte ${cat.revenue_code}`
            : `Préfixe ${cat.sku_prefix}`;
    }

    // -- Packs: meaningless for a bonbonne, essential for juice.
    const packWrap = document.getElementById('pack-qty-wrap');
    const soldWrap = document.getElementById('sold-by-wrap');
    const showPacks = parseInt(cat.allows_packs, 10) === 1;
    if (packWrap) packWrap.style.display = showPacks ? '' : 'none';
    if (soldWrap) soldWrap.style.display = showPacks ? '' : 'none';
    if (!showPacks) {
        const upp = document.getElementById('f_units_per_pack');
        const sb  = document.getElementById('f_sold_by');
        if (upp) upp.value = 1;
        if (sb)  sb.value  = 'unit';
    }

    if (parseInt(cat.is_empty_container, 10) === 1) {
        // This product IS a returnable container. bottle_size + has_cork are
        // what cre_controller and the empties ledger search on — a container
        // saved without them simply never appears in a CRE.
        const sizes = ['20L', '10L', '5L', '1.5L', '1L', '0.5L'];
        const current = data.bottle_size || '';
        const opts = sizes.map(s => LPC.html`<option value="${s}" ${s === current ? 'selected' : ''}>${s}</option>`).join('');
        const custom = (current && sizes.indexOf(current) === -1)
            ? LPC.html`<option value="${current}" selected>${current}</option>` : '';

        zone.innerHTML = LPC.html`
            <div class="grid grid-cols-2 gap-6 p-4 rounded-xl bg-amber-50/50 border border-amber-100">
                <div class="col-span-2 text-[11px] font-bold text-amber-800">
                    Contenant consigné. Le format et l'état du bouchon déterminent le prix de reprise —
                    c'est aussi ainsi que le registre des consignes retrouve ce contenant.
                </div>
                <div>
                    <label class="${LABEL_CLS}">Format du contenant</label>
                    <select required id="f_bottle_size" name="bottle_size" onchange="refreshSuggestedCode()" class="${INPUT_CLS}">
                        <option value="">Sélectionner...</option>${LPC.raw(custom)}${LPC.raw(opts)}
                    </select>
                </div>
                <div>
                    <label class="${LABEL_CLS}">État du bouchon</label>
                    <select id="f_has_cork" name="has_cork" class="${INPUT_CLS}">
                        <option value="0" ${parseInt(data.has_cork, 10) !== 1 ? 'selected' : ''}>Sans bouchon</option>
                        <option value="1" ${parseInt(data.has_cork, 10) === 1 ? 'selected' : ''}>Avec bouchon</option>
                    </select>
                    <p class="text-[10px] text-gray-400 mt-1.5 ml-1">Même contenant, deux prix de reprise : gardez une fiche par état.</p>
                </div>
            </div>`;
    } else {
        // A normal product. The emballage is OPTIONAL — the old form marked
        // every dynamic select `required`, which made products that ship
        // without a container impossible to save.
        const empties = metaData.empties || [];
        const opts = empties.map(e => {
            const label = e.bottle_size
                ? `${e.bottle_size} · ${parseInt(e.has_cork, 10) === 1 ? 'avec bouchon' : 'sans bouchon'} (${e.name})`
                : e.name;
            return LPC.html`<option value="${e.id}" ${String(data.linked_empty_id || '') === String(e.id) ? 'selected' : ''}>${label}</option>`;
        }).join('');

        const advisory = parseInt(cat.requires_emballage, 10) === 1
            ? "Les produits de cette catégorie sont généralement consignés — mais ce champ reste facultatif."
            : "Facultatif : beaucoup de produits se vendent sans emballage consigné.";

        zone.innerHTML = LPC.html`
            <label class="${LABEL_CLS}">Emballage consigné associé</label>
            <div class="flex gap-2">
                <select id="f_linked_empty_id" name="linked_empty_id" class="${INPUT_CLS}">
                    <option value="">— Aucun emballage —</option>${LPC.raw(opts)}
                </select>
                <button type="button" onclick="openEmballageModal()" title="Créer un emballage" aria-label="Créer un emballage"
                        class="shrink-0 px-4 rounded-xl bg-amber-500 text-white font-black text-lg leading-none hover:bg-amber-600 active:scale-95 transition-transform">+</button>
            </div>
            <p class="text-[10px] text-gray-500 mt-1.5 ml-1">${advisory}</p>`;
    }

    const prev = document.getElementById('revenue-preview');
    if (prev) {
        prev.innerHTML = cat.revenue_code
            ? LPC.html`<span class="font-mono">${cat.revenue_code}</span> — ${cat.revenue_name}`
            : '<span class="text-amber-700">701 — compte global (aucun compte dédié)</span>';
    }

    refreshSuggestedCode();
}

function onPackChange() {
    const uppEl = document.getElementById('f_units_per_pack');
    const sb = document.getElementById('f_sold_by');
    if (!uppEl || !sb) return;
    if (parseInt(uppEl.value || 1, 10) <= 1) sb.value = 'unit';
}

/** Ask the server for the next free SKU. Never overwrites a code the user typed. */
async function refreshSuggestedCode() {
    if (!schemaV2 || codeTouched) return;
    const cat = currentCategory();
    const codeInput = document.getElementById('f_code');
    if (!cat || !codeInput) return;

    const sizeEl = document.getElementById('f_bottle_size');
    const fmtEl  = document.getElementById('f_format');
    const size = sizeEl ? sizeEl.value : (fmtEl ? fmtEl.value : '');

    try {
        const r = await fetch('/api/v1/mdm_controller.php?action=next_code&module=products'
                            + `&category_id=${encodeURIComponent(cat.id)}`
                            + `&bottle_size=${encodeURIComponent(size || '')}`);
        const j = await r.json();
        if (j.status === 'success' && j.code && !codeTouched) codeInput.value = j.code;
    } catch (e) { /* suggestion only — the server generates authoritatively on save */ }
}

// ---------------------------------------------------------------------------
// 6. STACKED MODALS — add a category / an emballage without losing the form
// ---------------------------------------------------------------------------
function openStacked(title, bodyHTML, onSave, saveLabel) {
    const host = document.getElementById('mdmStackedModal');
    host.innerHTML = LPC.html`
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col">
            <div class="bg-lpc-dark px-8 py-5 flex justify-between items-center text-white">
                <h3 class="font-black text-lg tracking-wide">${title}</h3>
                <button type="button" onclick="closeStacked()" aria-label="Fermer" class="text-white/70 hover:text-white text-2xl leading-none">&times;</button>
            </div>
            <div class="p-8 overflow-y-auto max-h-[60vh]">
                <div id="stacked-form" class="grid grid-cols-2 gap-5">${LPC.raw(bodyHTML)}</div>
                <p id="stacked-error" class="hidden mt-4 text-xs font-bold text-red-600"></p>
            </div>
            <div class="px-8 py-5 bg-gray-50 border-t border-gray-100 flex justify-end gap-3">
                <button type="button" onclick="closeStacked()" class="px-6 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-800">Annuler</button>
                <button type="button" id="stacked-save" class="px-8 py-2.5 bg-lpc-light hover:bg-green-500 text-white rounded-xl font-bold text-sm shadow-md">${saveLabel || 'Créer'}</button>
            </div>
        </div>`;
    host.classList.remove('hidden');
    document.getElementById('stacked-save').onclick = onSave;
}

function closeStacked() {
    const host = document.getElementById('mdmStackedModal');
    host.classList.add('hidden');
    host.innerHTML = '';
}

function stackedError(msg) {
    const el = document.getElementById('stacked-error');
    if (!el) return;
    el.textContent = msg;
    el.classList.remove('hidden');
}

function openCategoryModal() {
    if (!schemaV2) { LPC.modal.alert("Appliquez la migration 041 pour ajouter des catégories."); return; }

    openStacked('Nouvelle Catégorie', LPC.html`
        <div class="col-span-2">
            <label class="${LABEL_CLS}">Nom de la catégorie</label>
            <input type="text" id="c_name" placeholder="Jus, Sirop, Boisson gazeuse…" class="${INPUT_CLS}">
        </div>
        <div class="col-span-1">
            <label class="${LABEL_CLS}">Préfixe SKU — optionnel</label>
            <input type="text" id="c_prefix" maxlength="4" placeholder="auto" class="${INPUT_CLS} font-mono uppercase">
        </div>
        <div class="col-span-1 flex items-end">
            <p class="text-[10px] text-gray-400 mb-3">Les comptes de vente et de stock sont créés automatiquement.</p>
        </div>
        <div class="col-span-2 space-y-2.5 pt-1">
            <label class="flex items-center gap-3 text-xs font-bold text-gray-600">
                <input type="checkbox" id="c_packs" checked class="w-4 h-4 rounded border-gray-300">
                Se vend en packs (afficher les champs pack)
            </label>
            <label class="flex items-center gap-3 text-xs font-bold text-gray-600">
                <input type="checkbox" id="c_req" class="w-4 h-4 rounded border-gray-300">
                Généralement consigné (suggère un emballage, sans l'imposer)
            </label>
            <label class="flex items-center gap-3 text-xs font-bold text-gray-600">
                <input type="checkbox" id="c_container" class="w-4 h-4 rounded border-gray-300">
                Cette catégorie EST un contenant consigné (bonbonnes vides…)
            </label>
        </div>
    `, saveCategory);
}

async function saveCategory() {
    const name = (document.getElementById('c_name').value || '').trim();
    if (!name) { stackedError('Le nom est obligatoire.'); return; }

    const fd = new FormData();
    fd.append('name', name);
    fd.append('sku_prefix', document.getElementById('c_prefix').value || '');
    if (document.getElementById('c_packs').checked)     fd.append('allows_packs', '1');
    if (document.getElementById('c_req').checked)       fd.append('requires_emballage', '1');
    if (document.getElementById('c_container').checked) fd.append('is_empty_container', '1');

    const btn = document.getElementById('stacked-save');
    btn.disabled = true;
    try {
        const r = await fetch('/api/v1/mdm_controller.php?action=save_category&module=products', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.status !== 'success') { stackedError(j.message || 'Erreur.'); return; }

        // Refresh meta so the new category is a real option, then select it.
        await reloadMeta();
        closeStacked();

        const sel = document.getElementById('f_category_id');
        if (sel) {
            const cats = metaData.categories || [];
            sel.innerHTML = cats.map(c => LPC.html`<option value="${c.id}">${c.name}</option>`).join('');
            sel.value = j.id;
            codeTouched = false;
            onCategoryChange();
        }
        notify(`Catégorie « ${j.name} » créée · ventes ${j.revenue_code}, stock ${j.stock_code}.`);
    } catch (e) {
        stackedError('Erreur serveur.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

function openEmballageModal() {
    const containerCat = (metaData.categories || []).find(c => parseInt(c.is_empty_container, 10) === 1);
    if (!containerCat) { LPC.modal.alert("Aucune catégorie de contenant consigné n'est définie."); return; }

    openStacked('Nouvel Emballage Consigné', LPC.html`
        <div class="col-span-2">
            <label class="${LABEL_CLS}">Désignation</label>
            <input type="text" id="e_name" placeholder="10L sans Bouchon" class="${INPUT_CLS}">
        </div>
        <div class="col-span-1">
            <label class="${LABEL_CLS}">Format</label>
            <select id="e_size" class="${INPUT_CLS}">
                <option value="20L">20L</option><option value="10L">10L</option>
                <option value="5L">5L</option><option value="1.5L">1.5L</option>
                <option value="1L">1L</option><option value="0.5L">0.5L</option>
            </select>
        </div>
        <div class="col-span-1">
            <label class="${LABEL_CLS}">Bouchon</label>
            <select id="e_cork" class="${INPUT_CLS}">
                <option value="0">Sans bouchon</option>
                <option value="1">Avec bouchon</option>
            </select>
        </div>
        <div class="col-span-2">
            <label class="${LABEL_CLS}">Prix de consigne (FCFA)</label>
            <input type="number" min="0" step="1" id="e_price" value="0" class="${INPUT_CLS}">
            <p class="text-[10px] text-gray-400 mt-1.5 ml-1">C'est ce montant que le registre des consignes utilisera pour ce contenant.</p>
        </div>
    `, function () { saveEmballage(containerCat.id); });
}

async function saveEmballage(categoryId) {
    const name = (document.getElementById('e_name').value || '').trim();
    if (!name) { stackedError('La désignation est obligatoire.'); return; }

    const fd = new FormData();
    fd.append('category_id', categoryId);
    fd.append('name', name);
    fd.append('bottle_size', document.getElementById('e_size').value);
    fd.append('has_cork', document.getElementById('e_cork').value);
    fd.append('base_price', document.getElementById('e_price').value || '0');
    fd.append('unit_of_measure', 'bonbonne');
    fd.append('units_per_pack', '1');

    const btn = document.getElementById('stacked-save');
    btn.disabled = true;
    try {
        const r = await fetch('/api/v1/mdm_controller.php?action=save&module=products', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.status !== 'success') { stackedError(j.message || 'Erreur.'); return; }

        await reloadMeta();
        closeStacked();

        const sel = document.getElementById('f_linked_empty_id');
        if (sel) {
            const empties = metaData.empties || [];
            sel.innerHTML = '<option value="">— Aucun emballage —</option>' + empties.map(e => {
                const label = e.bottle_size
                    ? `${e.bottle_size} · ${parseInt(e.has_cork, 10) === 1 ? 'avec bouchon' : 'sans bouchon'} (${e.name})`
                    : e.name;
                return LPC.html`<option value="${e.id}">${label}</option>`;
            }).join('');
            if (j.product) sel.value = j.product.id;
        }
        notify(`Emballage « ${name} » créé${j.product ? ' (' + j.product.code + ')' : ''}.`);
    } catch (e) {
        stackedError('Erreur serveur.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/** Re-fetch dropdown metadata without disturbing the table or the open form. */
async function reloadMeta() {
    try {
        const r = await fetch('/api/v1/mdm_controller.php?action=read&module=products&per_page=1');
        const j = await r.json();
        if (j.status === 'success') {
            metaData = Object.assign(metaData, j.meta || {});
            schemaV2 = !!metaData.schema_v2;
        }
    } catch (e) { /* keep the stale meta rather than blanking the dropdowns */ }
}

// ---------------------------------------------------------------------------
// 7. PERSISTENCE
// ---------------------------------------------------------------------------
async function toggleStatus(id, currentStatus) {
    if (!(await LPC.modal.confirm("Modifier le statut ?"))) return;
    const fd = new FormData();
    fd.append('id', id); fd.append('is_active', currentStatus);
    await fetch(`/api/v1/mdm_controller.php?action=toggle_status&module=${mdmConfig[currentModule].api}`, { method: 'POST', body: fd });
    fetchData();
}

async function saveRecord() {
    const formElement = document.getElementById('dynamic-form');

    // Forces browser validation
    if (!formElement.reportValidity()) return;

    // Sprint 5: compress any <input type=file accept=image/*> before
    // building the FormData so the avatar upload doesn't push a 10 MB
    // phone selfie through PHP-FPM.
    if (window.LPC && LPC.compress && LPC.compress.compressToBlob) {
        const imgInputs = formElement.querySelectorAll('input[type="file"][accept^="image/"]');
        for (const inp of imgInputs) {
            if (inp.files && inp.files[0]) {
                try {
                    const smaller = await LPC.compress.compressToBlob(inp.files[0], { maxDim: 512, quality: 0.8 });
                    if (smaller !== inp.files[0]) {
                        const dt = new DataTransfer();
                        dt.items.add(smaller);
                        inp.files = dt.files;
                    }
                } catch (e) { console.warn('avatar compression skipped:', e); }
            }
        }
    }

    // Initialize form data ONCE (after compression has swapped the file in).
    const formData = new FormData(formElement);

    try {
        const response = await fetch(`/api/v1/mdm_controller.php?action=save&module=${mdmConfig[currentModule].api}`, {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.status === 'success') {
            closeModal();
            // Confirm what was actually booked, not what was typed — the code
            // and the revenue account are both resolved server-side.
            if (result.product) {
                notify(`${result.product.name} enregistré · ${result.product.code} · compte ${result.product.revenue_code}`);
            }
            fetchData();
        } else {
            // Validation messages now come back verbatim with a 400; only real
            // faults stay masked behind the generic 500 string.
            LPC.modal.alert(result.message || "Erreur inconnue.");
        }
    } catch (error) {
        LPC.modal.alert("Erreur serveur.");
    }
}

// ---------------------------------------------------------------------------
// 8. SEARCH — server-side, so it searches the whole table and not just the
// rows that happen to be on the current page.
// ---------------------------------------------------------------------------
let searchTimer = null;
function filterTable() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () { pageState.page = 1; fetchData(); }, 300);
}

document.addEventListener('DOMContentLoaded', () => { initTabs(); switchTab('products'); });
