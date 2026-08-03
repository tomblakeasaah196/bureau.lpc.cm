/**
 * assets/js/modules/sales-orders.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Ventes & Dispatch.
 *
 * Sprint 10 · brought to parity with inventory-procurement.js (Achats), and
 * built around one rule: A PRICE CHANGE IS A PRICE CHANGE, NEVER A DISCOUNT.
 *
 * When a line is priced differently from the client's standing tariff, nothing
 * here decides what that means. The server returns the disparities, this file
 * shows them, and the operator declares — ticked, it is the client's new price;
 * unticked, it is a one-off remise. See migration 062 for the full rationale.
 *
 * FIXED IN THIS PASS (all verified against the previous file):
 *   · The table was never paginated. loadTabData() fetched without a `page`
 *     param, so the API returned Paginator's default 25 rows, and
 *     `data.pagination` was discarded. Rows 26+ were unreachable with nothing
 *     on screen to suggest anything was missing.
 *   · filterData() searched only the rows already in the DOM — i.e. that same
 *     first page — while the server had supported `q` since Sprint 5.
 *   · The "À Livrer" badge counted pending orders in the loaded page only.
 *   · calculateOrderTotals() wrote to #calc_so_discount_warning and disabled
 *     #btn-submit-order. Neither element existed in orders.php; both lookups
 *     were null-guarded, so the discount warning never appeared and submit was
 *     never blocked.
 *   · submitCloseDelivery() posted `delivery_id`, but the server's
 *     driver_confirm_delivery reads `token` — so closing a delivery from this
 *     screen always failed with "Token manquant."
 *   · showBLShareModal() called openModal(), which is not defined anywhere in
 *     the codebase. The ReferenceError aborted the function before the QR modal
 *     opened and before the table refreshed.
 * -----------------------------------------------------------------------------
 */

// ---------------------------------------------------------------------------
// 1. STATE
// ---------------------------------------------------------------------------
let currentTab = 'orders';
let metaData = { clients: [], products: [], drivers: [], vehicles: [], suppliers: [], assignments: [] };
let pagers = {};              // one LPC.paginator instance per tab

// product_id -> { price, base_price, is_negotiated } for the selected client.
// The order form's single source of truth for "what does this client pay?".
let clientTariff = {};

// Disparities returned by the server, awaiting the operator's declaration.
let pendingDisparities = [];
let pendingSubmitPayload = null;

// Migration 061 · logistics channel. Kept in sync with the enum on
// deliveries.delivery_mode and with $ALLOWED_MODES in
// api/v1/sales_controller.php. INTERNAL ONLY — never printed on the BL.
const DELIVERY_MODES = {
    own_fleet:     { label: 'Flotte LPC',        icon: 'fa-truck',    short: 'Flotte' },
    supplier:      { label: 'Livr. Fournisseur', icon: 'fa-industry', short: 'Fournisseur' },
    client_pickup: { label: 'Enlèvement',        icon: 'fa-store',    short: 'Enlèvement' }
};

const DISCOUNT_MAX_PCT = Number(window.LPC_DISCOUNT_MAX_PCT ?? 15);

const config = {
    orders: {
        caption: 'Commandes Clients',
        // Migration 070 added a sixth card (Majorations Perçues). Was
        // xl:grid-cols-5 for the 062 layout; 6 fits the 12-col grid evenly.
        gridClass: 'grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-6 mb-8',
        columns: [
            { key: 'date', label: 'Date', render: v => `<span class="text-xs font-bold text-gray-500">${LPC.fmt.date(v)}</span>` },
            { key: 'reference', label: 'Réf. Commande', render: v => LPC.html`<span class="font-black text-gray-900 tracking-wide">${v}</span>` },
            { key: 'client_name', label: 'Client', render: v => LPC.html`<span class="font-bold text-gray-700">${v}</span>` },
            { key: 'status', label: 'Statut', render: v => {
                const dict = {
                    'pending':    '<span class="text-amber-600 bg-amber-50 px-2 py-1 rounded text-[10px] font-black uppercase">En Attente</span>',
                    'dispatched': '<span class="text-blue-600 bg-blue-50 px-2 py-1 rounded text-[10px] font-black uppercase"><i class="fas fa-truck mr-1"></i>En Transit</span>',
                    'delivered':  '<span class="text-green-600 bg-green-50 px-2 py-1 rounded text-[10px] font-black uppercase"><i class="fas fa-check-double mr-1"></i>Livré</span>',
                    'cancelled':  '<span class="text-red-600 bg-red-50 px-2 py-1 rounded text-[10px] font-black uppercase"><i class="fas fa-ban mr-1"></i>Annulé</span>'
                };
                return dict[v] || v;
            }},
            { key: 'payment_status', label: 'Paiement', render: (v, row) =>
                row.status === 'cancelled'
                    ? '<span class="text-gray-400 text-xs font-bold">—</span>'
                    : (v === 'paid'
                        ? '<span class="text-emerald-500 text-xs font-bold"><i class="fas fa-check-circle"></i> Payé</span>'
                        : '<span class="text-red-500 text-xs font-bold"><i class="fas fa-clock"></i> Non Payé</span>') },
            // The remise column. discount_amount has been written since this
            // module was built and displayed nowhere, so a discounted order was
            // indistinguishable from a full-price one in this list.
            { key: 'discount_amount', label: 'Remise', render: (v, row) => {
                const d = Number(v) || 0;
                if (d <= 0) return '<span class="text-gray-300">—</span>';
                const pct = Number(row.subtotal) > 0 ? (d / Number(row.subtotal) * 100) : 0;
                const note = row.discount_note ? String(row.discount_note) : 'Sans motif';
                return LPC.html`<span class="inline-flex flex-col" title="${note}">
                    <span class="font-black text-blue-700">${LPC.fmt.int(d)} F</span>
                    <span class="text-[9px] font-bold text-blue-400">${pct.toFixed(1)} %</span>
                </span>`;
            }},
            // Facturation. invoice_deliveries has existed all along and the
            // invoicing module reads it; this page never showed whether a
            // delivered order had actually been billed.
            { key: 'invoiced_count', label: 'Facturation', render: (v, row) => {
                const delivered = Number(row.delivery_count) || 0;
                const invoiced  = Number(v) || 0;
                if (row.status === 'cancelled') return '<span class="text-gray-300">—</span>';
                if (delivered === 0) return '<span class="text-[10px] font-bold text-gray-400">Pas de BL</span>';
                if (invoiced === 0)  return '<span class="text-amber-700 bg-amber-50 px-2 py-1 rounded text-[10px] font-black uppercase">À facturer</span>';
                if (invoiced < delivered) return '<span class="text-orange-700 bg-orange-50 px-2 py-1 rounded text-[10px] font-black uppercase">Partiel</span>';
                return LPC.html`<a href="/modules/accounting/invoices.php#invoices" onclick="event.stopPropagation()"
                    class="text-emerald-700 bg-emerald-50 hover:bg-emerald-100 px-2 py-1 rounded text-[10px] font-black uppercase">
                    ${row.invoice_reference || 'Facturé'}</a>`;
            }},
            { key: 'total_amount', label: 'Total TTC', render: v => `<span class="font-black text-gray-900">${LPC.fmt.int(v)} FCFA</span>` }
        ],
        kpis: [
            { id: 'kpi_so_total',    label: 'Ventes (Période)',   icon: 'fa-chart-pie',        color: 'text-indigo-600',  bg: 'bg-indigo-50' },
            { id: 'kpi_so_pending',  label: 'À Livrer',           icon: 'fa-hourglass-half',   color: 'text-amber-600',   bg: 'bg-amber-50' },
            { id: 'kpi_so_debt',     label: 'Créances Clients',   icon: 'fa-hand-holding-usd', color: 'text-red-600',     bg: 'bg-red-50' },
            { id: 'kpi_so_discount', label: 'Remises Accordées',  icon: 'fa-tags',             color: 'text-blue-700',    bg: 'bg-blue-50',
              hint: "Réductions explicitement accordées et motivées, ligne par ligne ou sur la commande entière. " +
                    "Un changement de prix client N'EST PAS une remise et n'apparaît jamais ici — il est tracé dans l'historique des prix du client." },
            // Migration 070 · symmetric to Remises Accordées, opposite sign,
            // never blended. A one-off markup (supplier cost pass-through,
            // urgency premium, small-qty uplift) that the operator declined
            // to fold into the negotiated tariff.
            { id: 'kpi_so_surcharge', label: 'Majorations Perçues', icon: 'fa-arrow-trend-up', color: 'text-emerald-700', bg: 'bg-emerald-50',
              hint: "Majorations ponctuelles motivées, ligne par ligne. Un changement de prix client n'apparaît jamais ici non plus — " +
                    "seuls les écarts déclarés comme exceptionnels par l'opérateur au moment de la saisie sont totalisés ici." },
            { id: 'kpi_so_count',    label: 'Volume Commandes',   icon: 'fa-shopping-cart',    color: 'text-blue-600',    bg: 'bg-blue-50' }
        ]
    },
    dispatch: {
        caption: 'Bons de Livraison',
        gridClass: 'grid grid-cols-1 md:grid-cols-4 gap-6 mb-8',
        columns: [
            { key: 'date', label: 'Date Sortie', render: v => `<span class="text-xs font-bold text-gray-500">${LPC.fmt.date(v)}</span>` },
            { key: 'reference', label: 'Réf. BL', render: v => LPC.html`<span class="font-black text-blue-700 tracking-wide">${v}</span>` },
            { key: 'client_name', label: 'Client', render: v => LPC.html`<span class="font-bold text-gray-700">${v}</span>` },
            // Migration 061: this column read driver_name alone and rendered
            // "Enlèvement" for anything null — which after the business-model
            // change would have mislabelled every supplier delivery as a
            // warehouse pickup. It now reports the explicit channel.
            // INTERNAL VIEW ONLY: none of this reaches the customer's BL.
            { key: 'delivery_mode', label: 'Acheminement', render: (v, row) => {
                const m = DELIVERY_MODES[v] || DELIVERY_MODES.own_fleet;
                const who = v === 'supplier' ? (row.supplier_name || '—')
                          : v === 'client_pickup' ? 'Véhicule client'
                          : (row.driver_name || '—');
                const tone = v === 'supplier' ? 'text-violet-700 bg-violet-50'
                           : v === 'client_pickup' ? 'text-gray-500 bg-gray-100'
                           : 'text-gray-700 bg-gray-50';
                return LPC.html`<span class="inline-flex items-center gap-1.5 px-2 py-1 rounded ${tone}">
                    <i class="fas ${m.icon} text-[10px]"></i>
                    <span class="text-[10px] font-black uppercase tracking-wide">${m.short}</span>
                    <span class="text-xs font-bold">${who}</span>
                </span>`;
            }},
            { key: 'status', label: 'Statut Logistique', render: v => {
                if (v === 'draft') return '<span class="text-gray-500 bg-gray-100 px-2 py-1 rounded text-[10px] font-black uppercase">Brouillon</span>';
                if (v === 'dispatched') return '<span class="text-blue-600 bg-blue-50 px-2 py-1 rounded text-[10px] font-black uppercase animate-pulse">En Transit</span>';
                if (v === 'driver_confirmed') return '<span class="text-amber-600 bg-amber-50 px-2 py-1 rounded text-[10px] font-black uppercase border border-amber-200"><i class="fas fa-user-check mr-1"></i>Confirmé Chauffeur</span>';
                if (v === 'completed') return '<span class="text-green-600 bg-green-50 px-2 py-1 rounded text-[10px] font-black uppercase">Terminé</span>';
                return v;
            }},
            { key: 'invoice_reference', label: 'Facture', render: (v, row) =>
                v ? LPC.html`<span class="text-emerald-700 bg-emerald-50 px-2 py-1 rounded text-[10px] font-black">${v}</span>`
                  : (row.status === 'completed'
                      ? '<span class="text-amber-700 bg-amber-50 px-2 py-1 rounded text-[10px] font-black uppercase">À facturer</span>'
                      : '<span class="text-gray-300">—</span>') },
            { key: 'payment_collected', label: 'Cash Collecté', render: v => parseFloat(v) > 0 ? `<span class="font-black text-emerald-600">${LPC.fmt.int(v)} FCFA</span>` : `<span class="text-gray-400 font-bold">-</span>` }
        ],
        kpis: [
            // Was "Camions en Route". The value is split by channel server-side,
            // so the label can no longer claim every in-transit BL is a truck.
            { id: 'kpi_dl_dispatched', label: 'En Transit (en cours)', icon: 'fa-truck-moving', color: 'text-blue-600', bg: 'bg-blue-50',
              hint: "Volontairement hors période : un camion parti le mois dernier roule toujours aujourd'hui." },
            { id: 'kpi_dl_completed', label: 'Livrées (Période)', icon: 'fa-check-double', color: 'text-emerald-600', bg: 'bg-emerald-50' },
            { id: 'kpi_dl_cash', label: 'Cash Collecté (Période)', icon: 'fa-money-bill-wave', color: 'text-gray-900', bg: 'bg-gray-200' },
            { id: 'kpi_dl_returns', label: 'Rejets / Avaries', icon: 'fa-exclamation-triangle', color: 'text-red-600', bg: 'bg-red-50' }
        ]
    }
};

// ---------------------------------------------------------------------------
// 2. INIT & METADATA
// ---------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', async () => {
    document.getElementById('so_date').valueAsDate = new Date();
    await fetchMetaData();

    // Mark a deliberate vehicle choice so switching driver afterwards does not
    // quietly overwrite it with that driver's affectation.
    document.getElementById('disp_vehicle')?.addEventListener('change', function () {
        if (this.value) { this.dataset.userPicked = '1'; }
        else { delete this.dataset.userPicked; }
    });
    setDeliveryMode('own_fleet');   // paint the default mode buttons

    refreshToInvoiceBadge();

    // Honour a #hash so other pages can deep-link straight to a tab (the sales
    // dashboard's "en attente de dispatch" card links to orders.php#dispatch).
    // Validated against `config` rather than trusted. The paginator also writes
    // its own namespaced keys into the hash, so only the bare fragment counts.
    const hash = (window.location.hash || '').replace('#', '').split('&')[0];
    switchTab(Object.prototype.hasOwnProperty.call(config, hash) ? hash : 'orders');
});

async function fetchMetaData() {
    try {
        const response = await fetch('/api/v1/sales_controller.php?action=fetch_metadata');
        const result = await response.json();
        if (result.status === 'success') {
            metaData = result.data;

            const clientSel = document.getElementById('so_client');
            metaData.clients.forEach(c => clientSel.innerHTML += LPC.html`<option value="${c.id}">${c.name}</option>`);

            // Migration 061 · drivers come from the full active-driver roster,
            // NOT from today's vehicle_assignments. The old code looped
            // metaData.assignments here, so on any day Flotte had not yet run
            // the morning affectation this dropdown was empty and no BL could
            // name a driver.
            const driverSel = document.getElementById('disp_driver');
            (metaData.drivers || []).forEach(d => {
                driverSel.innerHTML += LPC.html`<option value="${d.id}">${d.first_name} ${d.last_name}</option>`;
            });

            const vehSel = document.getElementById('disp_vehicle');
            (metaData.vehicles || []).forEach(v => {
                vehSel.innerHTML += LPC.html`<option value="${v.id}">${v.plate_number} (${v.type})</option>`;
            });

            const supSel = document.getElementById('disp_supplier');
            (metaData.suppliers || []).forEach(s => {
                supSel.innerHTML += LPC.html`<option value="${s.id}">${s.name}</option>`;
            });
        }
    } catch (e) { console.error('Metadata load failed', e); }
}

/** Count of completed-but-unbilled BLs, for the toolbar shortcut badge. */
async function refreshToInvoiceBadge() {
    const badge = document.getElementById('badge-to-invoice');
    if (!badge) return;
    try {
        // invoices_controller already computes this count for its own tab
        // badge, so it is read from there rather than recomputed — one source,
        // and the two screens cannot disagree.
        const inv = await fetch('/api/v1/invoices_controller.php?action=read&tab=dashboard');
        const ir = await inv.json();
        const n = Number(ir?.data?.badges?.to_invoice || 0);
        if (n > 0) { badge.textContent = n; badge.classList.remove('hidden'); }
        else badge.classList.add('hidden');
    } catch (e) { /* the badge is decorative; never block the page on it */ }
}

// ---------------------------------------------------------------------------
// 3. TABS, KPIs, TABLE
// ---------------------------------------------------------------------------
async function switchTab(tab) {
    currentTab = tab;
    const c = config[currentTab];

    document.querySelectorAll('.tab-link').forEach(el => {
        el.classList.remove('border-lpc-dark', 'text-lpc-dark', 'font-black');
        el.classList.add('border-transparent', 'text-gray-400', 'font-bold');
    });
    const activeLink = document.getElementById(`tab-${tab}`);
    if (activeLink) {
        activeLink.classList.remove('border-transparent', 'text-gray-400', 'font-bold');
        activeLink.classList.add('border-lpc-dark', 'text-lpc-dark', 'font-black');
    }

    // "Nouvelle Commande" belongs to the orders tab only. Guarded because RBAC
    // may have removed the node entirely (data-perm), and an absent element
    // must not abort switchTab the way the removed tab strip did on Achats.
    const primary = document.getElementById('btn-primary-action');
    if (primary) primary.classList.toggle('hidden', tab !== 'orders');

    const caption = document.getElementById('table-caption');
    if (caption) caption.textContent = c.caption;

    const search = document.getElementById('search-input');
    if (search) {
        search.placeholder = tab === 'orders'
            ? 'Rechercher une référence ou un client...'
            : 'Rechercher un BL, un client, un chauffeur...';
    }

    const ribbon = document.getElementById('kpi-ribbon');
    ribbon.className = c.gridClass;
    ribbon.innerHTML = '';
    c.kpis.forEach(kpi => {
        const hintIcon = kpi.hint
            ? LPC.raw(` <i class="fas fa-circle-info text-gray-400" title="${String(kpi.hint).replace(/"/g, '&quot;')}"></i>`)
            : '';
        ribbon.innerHTML += LPC.html`
            <div onclick="showKPIDetails('${kpi.id}')" role="button" tabindex="0"
                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();showKPIDetails('${kpi.id}');}"
                 class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between cursor-pointer hover:shadow-md hover:-translate-y-1 transition-all lpc-focusable">
                <div>
                    <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest">${kpi.label}${hintIcon}</p>
                    <h3 class="text-2xl font-black text-gray-900 mt-1" id="${kpi.id}">...</h3>
                </div>
                <div class="w-12 h-12 ${kpi.bg} ${kpi.color} rounded-xl flex items-center justify-center text-xl">
                    <i class="fas ${kpi.icon}"></i>
                </div>
            </div>`;
    });

    const thead = document.getElementById('table-head');
    let thHTML = '<tr>';
    c.columns.forEach(col => thHTML += `<th class="py-4 px-6 text-[10px] uppercase text-gray-500 font-black tracking-widest">${col.label}</th>`);
    thHTML += `<th class="py-4 px-6 text-right text-[10px] uppercase text-gray-500 font-black tracking-widest">Actions</th></tr>`;
    thead.innerHTML = thHTML;

    await loadTabData();
}

/**
 * Load one tab.
 *
 * The table is server-paginated via LPC.paginator, which owns the tbody from
 * here on. KPIs are NOT part of the paginated payload's row count — the API
 * computes them over the whole date range regardless of page — but the
 * paginator forwards only rows/pagination to callers, not the sibling `kpis`
 * key. So KPIs are fetched once per range change with a minimal
 * page=1&per_page=1 request.
 */
async function loadTabData() {
    const range = getActiveDateRange();
    if (!range) return;   // half-filled custom range: don't fetch

    await fetchKpis(range);
    attachPager(currentTab, range);
}

async function fetchKpis(range) {
    try {
        const response = await fetch(
            `/api/v1/sales_controller.php?action=read&tab=${currentTab}&start=${range.start}&end=${range.end}&page=1&per_page=1`);
        const result = await response.json();
        if (result.status === 'success') {
            if (result.data.kpis) {
                for (const [key, value] of Object.entries(result.data.kpis)) {
                    const el = document.getElementById(key);
                    if (el) el.innerText = value;
                }
            }
            // The dispatch badge is the count of orders still awaiting a BL. It
            // used to be derived from the loaded page, so it under-reported the
            // moment there were more than 25 orders. `total` from a filtered
            // one-row request is the real figure.
            if (currentTab === 'orders') await refreshPendingBadge(range);
        }
    } catch (e) { console.error('KPI fetch failed', e); }
}

async function refreshPendingBadge(range) {
    const badge = document.getElementById('badge-pending-dispatch');
    if (!badge) return;
    try {
        const res = await fetch(
            `/api/v1/sales_controller.php?action=read&tab=orders&start=${range.start}&end=${range.end}&page=1&per_page=1&q=pending`);
        const r = await res.json();
        const n = Number(r?.data?.pagination?.total || 0);
        if (n > 0) { badge.innerText = n; badge.classList.remove('hidden'); }
        else badge.classList.add('hidden');
    } catch (e) { /* decorative */ }
}

function attachPager(tab, range) {
    if (pagers[tab]) { pagers[tab].destroy(); pagers[tab] = null; }

    const cfg = config[tab];
    const tbody = document.getElementById('table-body');
    const searchInput = document.getElementById('search-input');

    pagers[tab] = LPC.paginator.attach(tbody, {
        url: `/api/v1/sales_controller.php?action=read&tab=${tab}`,
        extraParams: { start: range.start, end: range.end },
        renderRow: (row) => renderTableRow(row, tab),
        pageSize: 20,
        searchInput: searchInput,
        dataPath: 'data.table',
        envelopePath: 'data.pagination',
        colspan: cfg.columns.length + 1,
        emptyMsg: `Aucune donnée pour ${describeActivePeriod()}.`,
        hashKey: tab,
    });
}

/**
 * One <tr>. The whole row clicks through to the detail page; action buttons
 * stopPropagation so they still work without also navigating.
 */
function renderTableRow(row, tab) {
    const cancelled = row.status === 'cancelled';
    const href = tab === 'orders'
        ? `/modules/sales/order_detail.php?id=${row.id}`
        : `/modules/sales/bl_detail.php?id=${row.id}`;

    let tr = `<tr class="${cancelled ? 'opacity-50 ' : ''}hover:bg-gray-50 transition-colors cursor-pointer" onclick="location.href='${href}'">`;
    config[tab].columns.forEach(col => tr += `<td class="py-4 px-6 border-b border-gray-50">${col.render(row[col.key], row)}</td>`);

    let actions = '';
    const esc = s => String(s === null || s === undefined ? '' : s).replace(/'/g, "\\'");

    if (tab === 'orders') {
        actions = `<a href="${href}" onclick="event.stopPropagation()" class="text-indigo-500 hover:text-indigo-700 bg-indigo-50 p-2 rounded-lg mr-2" title="Voir le détail"><i class="fas fa-eye"></i></a>`;
        if (row.status === 'pending') {
            actions += `
                <button onclick="event.stopPropagation(); openOrderModal(${row.id})" data-perm="sales.orders.edit" class="text-gray-500 hover:text-gray-900 p-2 mr-1" title="Modifier" aria-label="Modifier"><i class="fas fa-edit"></i></button>
                <button onclick="event.stopPropagation(); openDispatchModal(${row.id}, '${esc(row.reference)}', '${esc(row.client_name)}')" data-perm="sales.orders.dispatch" class="text-white bg-blue-600 hover:bg-blue-700 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest mr-2 shadow-sm transition-colors"><i class="fas fa-truck-loading mr-1"></i> Générer BL</button>
                <button onclick="event.stopPropagation(); deleteRecord('order', ${row.id})" data-perm="sales.orders.delete" class="text-red-400 hover:text-red-600 p-2" title="Supprimer"><i class="fas fa-trash-alt"></i></button>`;
        } else if (!cancelled) {
            // Cancelling is available after dispatch, where deleting is not —
            // that is the whole difference between the two and why they are
            // separate permissions (migration 062).
            actions += `<button onclick="event.stopPropagation(); openCancelOrderModal(${row.id}, '${esc(row.reference)}')" data-perm="sales.orders.cancel" class="text-red-600 hover:text-red-700 p-2" title="Annuler la commande" aria-label="Annuler"><i class="fas fa-ban"></i></button>`;
        }
    } else {
        actions = `<a href="${href}" onclick="event.stopPropagation()" class="text-indigo-500 hover:text-indigo-700 bg-indigo-50 p-2 rounded-lg mr-2" title="Voir le détail"><i class="fas fa-eye"></i></a>
                   <a href="/bon_livraison.php?token=${esc(row.token)}" target="_blank" onclick="event.stopPropagation()" class="text-blue-600 bg-blue-50 hover:bg-blue-100 p-2 rounded-lg mr-2" title="Imprimer BL PDF"><i class="fas fa-print"></i></a>`;

        if (row.status === 'dispatched') {
            // "Retour Chauffeur" only makes sense on the fleet channel. A
            // supplier delivery has no driver coming back to the yard, and a
            // pickup has no one to return at all — the same close-out step is
            // just an ops user recording what the client actually accepted.
            const closeLabel = row.delivery_mode === 'own_fleet' ? 'Retour Chauffeur' : 'Clôturer';
            actions += `<button onclick="event.stopPropagation(); openCompleteDeliveryModal(${row.id}, '${esc(row.reference)}', ${row.payment_collected || 0}, '${esc(row.delivery_mode || 'own_fleet')}', '${esc(row.token)}')" data-perm="sales.deliveries.close" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest shadow-sm transition-colors"><i class="fas fa-check-double mr-1"></i> ${closeLabel}</button>`;
        } else if (row.status === 'driver_confirmed') {
            actions += `<button onclick="event.stopPropagation(); showBLShareModal('${esc(row.reference)}', '${esc(row.token)}', '')" class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest shadow-sm transition-colors"><i class="fas fa-qrcode mr-1"></i> Signature Client</button>`;
        } else if (row.status === 'completed') {
            actions += `<span class="text-emerald-500 text-[10px] font-black uppercase px-2 py-1"><i class="fas fa-lock mr-1"></i>Clôturé</span>`;
        }
    }

    tr += `<td class="py-4 px-6 border-b border-gray-50 text-right whitespace-nowrap" onclick="event.stopPropagation()">${actions}</td></tr>`;
    return tr;
}

// ---------------------------------------------------------------------------
// 4. PERIOD
// ---------------------------------------------------------------------------
function handlePeriodUI() {
    const type = document.getElementById('kpi_period_type').value;
    const customWrap = document.getElementById('custom_period_wrapper');

    if (type === 'custom') {
        customWrap.classList.remove('hidden');
        customWrap.classList.add('flex');
    } else {
        customWrap.classList.add('hidden');
        customWrap.classList.remove('flex');
        loadTabData();
    }
}

/**
 * Format a Date as YYYY-MM-DD in LOCAL time.
 *
 * Not toISOString(): the Date is built in local time but toISOString() renders
 * UTC, so at WAT (UTC+1) midnight on the 1st becomes 23:00 on the last day of
 * the previous month. The "start of month" boundary then lands a day early,
 * quietly pulling the previous month's last day into every monthly figure.
 * Same bug Achats hit; not repeating it here.
 */
function toLocalIso(d) {
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

function getActiveDateRange() {
    const type = document.getElementById('kpi_period_type').value;
    const today = new Date();
    let start, end;

    if (type === 'current_month') {
        start = toLocalIso(new Date(today.getFullYear(), today.getMonth(), 1));
        end   = toLocalIso(new Date(today.getFullYear(), today.getMonth() + 1, 0));
    } else if (type === 'ytd') {
        start = toLocalIso(new Date(today.getFullYear(), 0, 1));
        end   = toLocalIso(new Date(today.getFullYear(), 11, 31));
    } else if (type === 'all') {
        start = '2000-01-01';
        end   = '2099-12-31';
    } else if (type === 'custom') {
        const sm = document.getElementById('kpi_start_month').value;
        const em = document.getElementById('kpi_end_month').value;
        if (!sm || !em) return null;
        if (em < sm) {
            LPC.modal.alert('La date de fin doit être postérieure à la date de début.');
            return null;
        }
        start = sm + '-01';
        const [eYear, eMonth] = em.split('-');
        end = toLocalIso(new Date(Number(eYear), Number(eMonth), 0));
    }
    return { start, end };
}

/**
 * Human-readable description of the filter in force, for the empty state. An
 * empty table has two very different causes — "nothing happened in this period"
 * and "the data is gone" — and a bare "Aucun enregistrement." is
 * indistinguishable from the second.
 */
function describeActivePeriod() {
    const sel = document.getElementById('kpi_period_type');
    const type = sel ? sel.value : 'ytd';
    const today = new Date();

    if (type === 'current_month') return today.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
    if (type === 'ytd')  return `l'année ${today.getFullYear()}`;
    if (type === 'all')  return "tout l'historique";
    if (type === 'custom') {
        const sm = document.getElementById('kpi_start_month').value;
        const em = document.getElementById('kpi_end_month').value;
        return (sm && em) ? `${sm} → ${em}` : 'la plage choisie';
    }
    return 'la période choisie';
}

// ---------------------------------------------------------------------------
// 5. KPI DRILL-DOWN
// ---------------------------------------------------------------------------
const KPI_COLUMNS = {
    kpi_so_discount: [
        { label: 'Date',      get: r => LPC.fmt.date(r.date) },
        { label: 'Référence', get: r => r.reference, cls: 'font-mono text-xs' },
        { label: 'Client',    get: r => r.label, cls: 'font-bold text-gray-800' },
        { label: 'Motif',     get: r => r.discount_note || '— sans motif —',
          cls: r => r.discount_note ? 'text-xs text-gray-600' : 'text-xs italic text-red-500' },
        { label: '%',         get: r => (r.pct !== null && r.pct !== undefined) ? r.pct + ' %' : '—', align: 'text-right' },
        { label: 'Remise',    get: r => LPC.fmt.int(r.amount) + ' F', align: 'text-right', cls: 'font-black text-blue-700' },
    ],
    // Same shape as kpi_so_discount, opposite tone. Reused as-is on purpose:
    // the drill-down question is identical ("which orders, from whom, for
    // which reason") and giving surcharges a subtly different layout would
    // suggest they mean something different in kind, which they do not.
    kpi_so_surcharge: [
        { label: 'Date',      get: r => LPC.fmt.date(r.date) },
        { label: 'Référence', get: r => r.reference, cls: 'font-mono text-xs' },
        { label: 'Client',    get: r => r.label, cls: 'font-bold text-gray-800' },
        { label: 'Motif',     get: r => r.discount_note || '— sans motif —',
          cls: r => r.discount_note ? 'text-xs text-gray-600' : 'text-xs italic text-red-500' },
        { label: '%',         get: r => (r.pct !== null && r.pct !== undefined) ? r.pct + ' %' : '—', align: 'text-right' },
        { label: 'Majoration', get: r => LPC.fmt.int(r.amount) + ' F', align: 'text-right', cls: 'font-black text-emerald-700' },
    ],
    kpi_so_debt: [
        { label: 'Date',      get: r => LPC.fmt.date(r.date) },
        { label: 'Référence', get: r => r.reference, cls: 'font-mono text-xs' },
        { label: 'Client',    get: r => r.label, cls: 'font-bold text-gray-800' },
        { label: 'Âge',       get: r => (r.age_days ?? 0) + ' j', align: 'text-right',
          cls: r => Number(r.age_days) > 60 ? 'font-black text-red-600' : 'font-bold text-gray-600' },
        { label: 'Montant',   get: r => LPC.fmt.int(r.amount) + ' F', align: 'text-right', cls: 'font-black text-gray-900' },
    ],
    kpi_dl_returns: [
        { label: 'Date',      get: r => LPC.fmt.date(r.date) },
        { label: 'BL',        get: r => r.reference, cls: 'font-mono text-xs' },
        { label: 'Client / Produit', get: r => r.label, cls: 'font-bold text-gray-800' },
        { label: 'Expédié',   get: r => r.qty_shipped, align: 'text-center' },
        { label: 'Accepté',   get: r => r.qty_accepted, align: 'text-center' },
        { label: 'Écart',     get: r => r.amount, align: 'text-right', cls: 'font-black text-red-600' },
    ],
};

const KPI_COLUMNS_DEFAULT = [
    { label: 'Date',      get: r => LPC.fmt.date(r.date) },
    { label: 'Référence', get: r => r.reference, cls: 'font-mono text-xs' },
    { label: 'Client',    get: r => r.label, cls: 'font-bold text-gray-800' },
    { label: 'Montant',   get: r => LPC.fmt.int(r.amount) + ' F', align: 'text-right', cls: 'font-black text-lpc-dark' },
];

/**
 * Ask the SERVER for the drill-down, rather than filtering rows already in the
 * browser. Achats does the latter and is silently capped at whatever page it
 * happens to hold; asking the database means the modal always agrees with the
 * number on the card that opened it.
 */
async function showKPIDetails(kpiId) {
    const range = getActiveDateRange();
    if (!range) return;

    const titleEl  = document.getElementById('kpi-detail-title');
    const periodEl = document.getElementById('kpi-detail-period');
    const headEl   = document.getElementById('kpi-detail-head');
    const bodyEl   = document.getElementById('kpi-detail-body');

    const cols = KPI_COLUMNS[kpiId] || KPI_COLUMNS_DEFAULT;

    titleEl.innerText = 'Chargement…';
    periodEl.innerText = describeActivePeriod();
    headEl.innerHTML = '<tr>' + cols.map(c =>
        `<th class="py-3 px-4 ${c.align || ''}">${c.label}</th>`).join('') + '</tr>';
    bodyEl.innerHTML = `<tr><td colspan="${cols.length}" class="py-8 text-center text-gray-400 font-bold animate-pulse">Chargement…</td></tr>`;
    document.getElementById('kpiDetailsModal').classList.remove('hidden');

    try {
        const res = await fetch(
            `/api/v1/sales_controller.php?action=kpi_detail&kpi=${encodeURIComponent(kpiId)}&start=${range.start}&end=${range.end}`);
        const result = await res.json();
        if (result.status !== 'success') throw new Error(result.message || 'Erreur');

        titleEl.innerText = result.data.title;
        const rows = result.data.rows || [];

        if (rows.length === 0) {
            bodyEl.innerHTML = LPC.html`<tr><td colspan="${cols.length}" class="py-8 text-center text-gray-500 font-bold italic">Aucune donnée pour ${describeActivePeriod()}.</td></tr>`;
            return;
        }

        bodyEl.innerHTML = rows.map(r => {
            const tds = cols.map(c => {
                const cls = typeof c.cls === 'function' ? c.cls(r) : (c.cls || '');
                return `<td class="py-3 px-4 ${c.align || ''} ${cls}">${LPC.escapeHtml(String(c.get(r) ?? ''))}</td>`;
            }).join('');
            const isBL = String(r.reference || '').indexOf('BL') === 0;
            const href = r.id
                ? (isBL ? `/modules/sales/bl_detail.php?id=${r.id}`
                        : `/modules/sales/order_detail.php?id=${r.id}`)
                : null;
            return href
                ? `<tr class="hover:bg-gray-50 cursor-pointer" onclick="location.href='${href}'">${tds}</tr>`
                : `<tr>${tds}</tr>`;
        }).join('');
    } catch (e) {
        titleEl.innerText = 'Erreur';
        bodyEl.innerHTML = `<tr><td colspan="${cols.length}" class="py-8 text-center text-red-500 font-bold">Impossible de charger le détail.</td></tr>`;
    }
}

// ---------------------------------------------------------------------------
// 6. ORDER FORM
// ---------------------------------------------------------------------------
let soRowSeq = 0;
let editingOrderId = null;

async function openOrderModal(orderId = null) {
    document.getElementById('form-order').reset();
    document.getElementById('so_date').valueAsDate = new Date();
    document.getElementById('so-lines-container').innerHTML = '';
    document.getElementById('so_id').value = orderId || '';
    editingOrderId = orderId;
    clientTariff = {};
    pendingDisparities = [];
    pendingSubmitPayload = null;

    document.getElementById('order-modal-title').innerText =
        orderId ? 'Modifier la commande' : 'Saisie de commande client';

    if (orderId) {
        // sales.orders.edit existed in the permission table from the RBAC seed
        // and was granted to two roles, but no endpoint implemented it — the
        // permission was dead and an order was immutable once saved. Both ends
        // exist now (update_order).
        try {
            const res = await fetch(`/api/v1/sales_controller.php?action=get_order_detail&id=${orderId}`);
            const r = await res.json();
            if (r.status !== 'success') throw new Error(r.message);

            document.getElementById('so_client').value = r.data.order.client_id;
            document.getElementById('so_date').value   = r.data.order.date;
            await onOrderClientChange();

            for (const line of r.data.lines) {
                const tr = addOrderLine();
                const sel = tr.querySelector('.so-prod-select');
                const picker = window.LPC?.productPicker?.get(sel);
                if (picker && typeof picker.setValue === 'function') picker.setValue(line.product_id);
                else sel.value = line.product_id;
                tr.querySelector('.so-qty').value   = line.qty_ordered;
                tr.querySelector('.so-price').value = Math.round(line.unit_price);
                refreshRowTariff(tr);
            }

            const orderLevel = Number(r.data.totals.order_discount) || 0;
            document.getElementById('so_discount').value = orderLevel;
            document.getElementById('so_discount_note').value = r.data.order.discount_note || '';
        } catch (e) {
            LPC.modal.alert('Impossible de charger la commande.');
            return;
        }
    } else {
        addOrderLine();
    }

    calculateOrderTotals();
    document.getElementById('orderModal').classList.remove('hidden');
}

/**
 * Re-resolve the tariff when the client changes, and re-price every line
 * already on screen.
 *
 * Without this, choosing products and then changing the client leaves client
 * A's prices on client B's order — and since the server compares against B's
 * tariff, every one of those lines would raise a disparity the operator never
 * intended. Asking a question that is always wrong teaches people to dismiss
 * it, which would destroy the control this whole flow depends on.
 */
async function onOrderClientChange() {
    const clientId = document.getElementById('so_client').value;
    const hint = document.getElementById('so_client_hint');
    clientTariff = {};

    if (!clientId) { if (hint) hint.textContent = ''; return; }

    try {
        const res = await fetch(`/api/v1/sales_controller.php?action=get_client_tariff&client_id=${clientId}`);
        const r = await res.json();
        if (r.status === 'success') {
            clientTariff = r.data || {};
            const negotiated = Object.values(clientTariff).filter(t => t.is_negotiated).length;
            if (hint) {
                hint.textContent = negotiated > 0
                    ? `${negotiated} prix négocié(s) pour ce client.`
                    : 'Aucun prix négocié — tarif catalogue.';
            }
        }
    } catch (e) { /* fall back to catalogue prices already in the picker */ }

    document.querySelectorAll('#so-lines-container .item-row').forEach(tr => {
        refreshRowTariff(tr, true);
    });
    calculateOrderTotals();
}

/** Paint the tariff cell for one row, and optionally reset the price to it. */
function refreshRowTariff(tr, resetPrice = false) {
    const sel = tr.querySelector('.so-prod-select');
    const tariffCell = tr.querySelector('.so-tariff');
    const priceInput = tr.querySelector('.so-price');
    const pid = sel ? sel.value : '';

    if (!pid || !clientTariff[pid]) {
        if (tariffCell) tariffCell.innerHTML = '<span class="text-gray-300">—</span>';
        return;
    }

    const t = clientTariff[pid];
    if (tariffCell) {
        tariffCell.innerHTML = LPC.html`<span class="font-bold text-gray-700">${LPC.fmt.int(t.price)}</span>`
            + (t.is_negotiated
                ? ' <span class="block text-[9px] font-black uppercase text-emerald-600">négocié</span>'
                : ' <span class="block text-[9px] font-bold uppercase text-gray-400">catalogue</span>');
    }
    if (resetPrice && priceInput) priceInput.value = Math.round(t.price);
    markRowPriceState(tr);
}

/**
 * Colour the price input when it departs from the tariff, and surface the
 * signed delta as a chip beside it. Purely informational — states that a
 * question will be asked at submit, does not answer it.
 *
 * Direction matters: amber for a markdown (a remise coming), emerald for a
 * markup (a majoration coming). Migration 070 splits those into two different
 * quantities on the server; making them visually distinct on the row keeps
 * the operator's mental model in step with what will actually be recorded.
 */
function markRowPriceState(tr) {
    const sel = tr.querySelector('.so-prod-select');
    const priceInput = tr.querySelector('.so-price');
    const chip = tr.querySelector('.so-delta-chip');
    if (!sel || !priceInput) return;

    // Reset both directions before deciding — the operator might have flipped
    // from below-tariff to above-tariff without an intermediate equal state.
    priceInput.classList.remove(
        'bg-amber-50', 'text-amber-900', 'ring-1', 'ring-amber-300',
        'bg-emerald-50', 'text-emerald-900', 'ring-emerald-300'
    );
    if (chip) chip.className = 'so-delta-chip hidden';

    const t = clientTariff[sel.value];
    if (!t) { priceInput.title = ''; return; }

    const typed = Number(priceInput.value);
    const list  = Number(t.price);
    const delta = typed - list;

    if (Math.abs(delta) < 0.005) {
        priceInput.title = '';
        return;
    }

    const up = delta > 0;
    priceInput.classList.add(
        up ? 'bg-emerald-50' : 'bg-amber-50',
        up ? 'text-emerald-900' : 'text-amber-900',
        'ring-1',
        up ? 'ring-emerald-300' : 'ring-amber-300'
    );
    priceInput.title = up
        ? `Au-dessus du tarif (${LPC.fmt.int(list)} F). Nouveau prix ou majoration ponctuelle — confirmation à l'enregistrement.`
        : `Sous le tarif (${LPC.fmt.int(list)} F). Nouveau prix ou remise ponctuelle — confirmation à l'enregistrement.`;

    if (chip) {
        chip.className = 'so-delta-chip inline-block ml-1 px-1.5 py-0.5 rounded text-[10px] font-black tracking-wide '
            + (up ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800');
        chip.textContent = (up ? '+' : '−') + LPC.fmt.int(Math.abs(delta)) + ' F';
    }
}

function addOrderLine() {
    const container = document.getElementById('so-lines-container');
    // Date.now() used to be the row id, which collides when two rows are added
    // inside the same millisecond — trivial by holding Enter on the add button,
    // and the result is two rows writing to one another's total cell.
    const rowId = ++soRowSeq;

    const tr = document.createElement('tr');
    tr.className = 'item-row hover:bg-gray-100 transition-colors';
    tr.innerHTML = LPC.html`
        <td class="p-1 border-r border-gray-100 align-top"><select class="so-prod-select" data-lpc-product-picker="sell" aria-label="Produit" required></select></td>
        <td class="p-1 border-r border-gray-100 align-top"><input type="number" class="so-qty seamless-input p-2 text-center font-bold text-gray-900" value="1" min="1" oninput="calcRow(${rowId})" id="qty_${rowId}" aria-label="Quantité" required></td>
        <td class="p-1 border-r border-gray-100 text-right pr-3 align-middle text-xs so-tariff"><span class="text-gray-300">—</span></td>
        <td class="p-1 border-r border-gray-100 align-top">
            <div class="flex items-center justify-end gap-1">
                <input type="number" class="so-price seamless-input p-2 text-right font-bold text-gray-900" value="0" min="0" oninput="calcRow(${rowId})" id="price_${rowId}" aria-label="Prix unitaire" required>
                <span class="so-delta-chip hidden"></span>
            </div>
        </td>
        <td class="p-1 border-r border-gray-100 text-right pr-4 font-black text-gray-900 align-top" id="total_${rowId}">0</td>
        <td class="p-1 text-center align-top"><button type="button" onclick="removeOrderLine(this)" class="text-red-300 hover:text-red-500 p-1" aria-label="Supprimer la ligne"><i class="fas fa-times"></i></button></td>
    `;
    container.appendChild(tr);

    window.LPC?.productPicker?.mount(tr.querySelector('.so-prod-select'), {
        purpose: 'sell',
        clientId: document.getElementById('so_client')?.value || null,
        onSelect(product) {
            const priceEl = document.getElementById(`price_${rowId}`);
            // product.price now already honours client_prices — api/v1/
            // product_catalog.php ignored the client_id the picker had always
            // been sending, so this used to be the catalogue price for
            // everyone regardless of what the client had negotiated.
            priceEl.value = product ? Math.round(product.price) : 0;
            if (product && clientTariff[product.id] === undefined) {
                clientTariff[product.id] = {
                    price: product.price,
                    base_price: product.base_price,
                    is_negotiated: !!product.is_negotiated
                };
            }
            refreshRowTariff(tr);
            calcRow(rowId);
            if (product) {
                priceEl.focus();
                priceEl.select();
                // Out-of-stock is a warning, not a block — orders are routinely
                // taken against next week's delivery.
                if (product.stock_state === 'out') {
                    LPC.toast(`${product.name} — rupture de stock.`, 'warning');
                }
            }
        }
    });
    return tr;
}

function removeOrderLine(btn) {
    const row = btn.closest('tr');
    if (!row) return;
    // The picker's popup is appended to <body>, so removing the row alone would
    // strand it. destroy() takes both.
    const picker = window.LPC?.productPicker?.get(row.querySelector('.so-prod-select'));
    if (picker) picker.destroy();
    row.remove();
    calculateOrderTotals();
}

function calcRow(rowId) {
    const qty = parseFloat(document.getElementById(`qty_${rowId}`).value) || 0;
    const price = parseFloat(document.getElementById(`price_${rowId}`).value) || 0;
    document.getElementById(`total_${rowId}`).innerText = LPC.fmt.int(qty * price);
    const tr = document.getElementById(`price_${rowId}`).closest('tr');
    if (tr) markRowPriceState(tr);
    calculateOrderTotals();
}

/**
 * Totals.
 *
 * The subtotal shown here is at the TARIFF, matching what the server computes,
 * so the arithmetic on screen and the arithmetic in the database agree. Lines
 * priced below tariff show up in "Remises sur lignes", lines priced above
 * tariff in "Majorations sur lignes" — both provisionally, because whether
 * they are actually one-offs is the operator's decision at submit. Once the
 * operator confirms a line as a new price, that line stops contributing to
 * either row (its list becomes its unit).
 *
 * Discounts SUBTRACT, surcharges ADD, and the two are shown side by side —
 * never blended into a single "net adjustment". Migration 070's rationale.
 */
function calculateOrderTotals() {
    let subtotal = 0;
    let lineDiscount = 0;
    let lineSurcharge = 0;

    document.querySelectorAll('#so-lines-container .item-row').forEach(row => {
        const sel = row.querySelector('.so-prod-select');
        const qty = parseFloat(row.querySelector('.so-qty').value) || 0;
        const price = parseFloat(row.querySelector('.so-price').value) || 0;
        const t = sel && clientTariff[sel.value];
        const list = t ? Number(t.price) : price;

        // A confirmed reprice is priced AT the new tariff, so it contributes
        // to neither the discount nor the surcharge row — which is the point.
        const decided = sel && pendingDecisionFor(sel.value);
        if (decided === true) {
            subtotal += price * qty;
        } else {
            subtotal += list * qty;
            if (price < list) lineDiscount  += (list - price) * qty;
            if (price > list) lineSurcharge += (price - list) * qty;
        }
    });

    const orderDiscount = parseFloat(document.getElementById('so_discount').value) || 0;
    const totalDiscount = lineDiscount + orderDiscount;
    const grandTotal    = subtotal - totalDiscount + lineSurcharge;

    const subEl   = document.getElementById('calc_so_subtotal');
    const grandEl = document.getElementById('calc_so_grandtotal');
    const warnEl  = document.getElementById('calc_so_discount_warning');
    const lineRow = document.getElementById('calc_so_line_discount_row');
    const lineEl  = document.getElementById('calc_so_line_discount');
    const surchRow = document.getElementById('calc_so_line_surcharge_row');
    const surchEl  = document.getElementById('calc_so_line_surcharge');
    const noteWrap = document.getElementById('so_discount_note_wrap');
    const submitBtn = document.getElementById('btn-submit-order');

    subEl.innerText   = LPC.fmt.fcfa(subtotal);
    grandEl.innerText = LPC.fmt.fcfa(Math.max(0, grandTotal));

    if (lineDiscount > 0) {
        lineRow.classList.remove('hidden');
        lineRow.classList.add('flex');
        lineEl.innerText = LPC.fmt.fcfa(lineDiscount);
    } else {
        lineRow.classList.add('hidden');
        lineRow.classList.remove('flex');
    }

    if (surchRow) {
        if (lineSurcharge > 0) {
            surchRow.classList.remove('hidden');
            surchRow.classList.add('flex');
            surchEl.innerText = LPC.fmt.fcfa(lineSurcharge);
        } else {
            surchRow.classList.add('hidden');
            surchRow.classList.remove('flex');
        }
    }

    // The motif is required by the server whenever ANY one-off exists — an
    // order-level remise, a line-level surcharge, or a line-level remise. The
    // field appears exactly when it becomes mandatory. Line-level remises
    // route through the priceConfirmModal, which enforces the motif in its own
    // submit path (submitPriceDecisions); this toggle is for the two cases
    // that are decidable from the main form alone.
    const needsMotif = orderDiscount > 0 || lineSurcharge > 0;
    noteWrap.classList.toggle('hidden', !needsMotif);

    let msg = '', tone = '', block = false;
    if (orderDiscount < 0) {
        msg = 'Remise négative interdite.'; tone = 'text-rose-600'; block = true;
    } else if (totalDiscount > subtotal) {
        msg = `Remise (${LPC.fmt.int(totalDiscount)} FCFA) supérieure au sous-total de ${LPC.fmt.int(totalDiscount - subtotal)} FCFA.`;
        tone = 'text-rose-600'; block = true;
    } else if (needsMotif && !document.getElementById('so_discount_note').value.trim()) {
        // Same field, two possible triggers — the message names whichever
        // is in play so the operator knows what they are being asked about.
        msg = lineSurcharge > 0 && orderDiscount <= 0
              ? 'Motif de la majoration obligatoire.'
              : 'Motif obligatoire.';
        tone = 'text-rose-600'; block = true;
    } else if (DISCOUNT_MAX_PCT > 0 && subtotal > 0 && (totalDiscount / subtotal * 100) > DISCOUNT_MAX_PCT) {
        msg = `Remise de ${(totalDiscount / subtotal * 100).toFixed(1)} % au-delà du plafond de ${DISCOUNT_MAX_PCT} % — approbation requise.`;
        tone = 'text-amber-600';
    }

    if (warnEl) {
        warnEl.textContent = msg;
        warnEl.className = msg ? `text-xs font-bold mt-1 ${tone}` : 'text-xs mt-1 hidden';
    }
    grandEl.classList.toggle('text-rose-600', block);
    if (submitBtn) submitBtn.disabled = block;
}

document.addEventListener('input', e => {
    if (e.target && e.target.id === 'so_discount_note') calculateOrderTotals();
});

/** The operator's decision for a product, if the modal has been answered. */
function pendingDecisionFor(productId) {
    if (!pendingSubmitPayload || !pendingSubmitPayload.price_decisions) return undefined;
    const d = pendingSubmitPayload.price_decisions;
    return d[productId] ?? d[String(productId)];
}

// ---------------------------------------------------------------------------
// 7. SUBMIT + PRICE RECONCILIATION
// ---------------------------------------------------------------------------
function collectOrderPayload() {
    const clientId = document.getElementById('so_client').value;
    if (!clientId) { LPC.modal.alert('Sélectionnez un client.'); return null; }

    const rows = document.querySelectorAll('#so-lines-container .item-row');
    if (rows.length === 0) { LPC.modal.alert('Ajoutez un produit.'); return null; }

    const items = [];
    let valid = true;
    rows.forEach(row => {
        const pId = row.querySelector('.so-prod-select').value;
        const qty = row.querySelector('.so-qty').value;
        const price = row.querySelector('.so-price').value;
        if (!pId || Number(qty) <= 0) valid = false;
        items.push({ product_id: pId, quantity: qty, unit_price: price });
    });
    if (!valid) { LPC.modal.alert('Vérifiez les lignes produits.'); return null; }

    const payload = {
        action: editingOrderId ? 'update_order' : 'save_order',
        client_id: clientId,
        date: document.getElementById('so_date').value,
        discount_amount: document.getElementById('so_discount').value || 0,
        discount_note: document.getElementById('so_discount_note').value.trim(),
        items,
        price_decisions: {},
    };
    if (editingOrderId) payload.id = editingOrderId;
    return payload;
}

async function submitOrder() {
    const payload = collectOrderPayload();
    if (!payload) return;
    pendingSubmitPayload = payload;
    await postOrder(payload);
}

async function postOrder(payload) {
    const btn = document.getElementById('btn-submit-order');
    if (btn) btn.disabled = true;
    try {
        const response = await fetch('/api/v1/sales_controller.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
        });
        const result = await response.json();

        if (result.status === 'needs_price_confirmation') {
            // The server found lines priced off-tariff and is asking, not
            // deciding. Nothing has been written.
            pendingDisparities = result.disparities || [];
            openPriceConfirmModal();
            return;
        }

        if (result.status === 'success') {
            closeModal('orderModal');
            closeModal('priceConfirmModal');
            pendingSubmitPayload = null;
            pendingDisparities = [];
            editingOrderId = null;
            // Name what actually happened, in the order it will matter to the
            // operator: price moves first (they affect the next order), then a
            // one-off surcharge on this order. Discount is silent — the
            // "Remises Accordées" KPI already surfaces it.
            const parts = [];
            if (result.repriced > 0)         parts.push(`${result.repriced} prix client mis à jour`);
            if (Number(result.surcharge) > 0) parts.push(`${LPC.fmt.int(result.surcharge)} F de majoration`);
            LPC.toast(parts.length
                ? `Commande ${result.reference || ''} enregistrée · ${parts.join(' · ')}.`
                : 'Commande enregistrée.', 'success');
            loadTabData();
        } else {
            LPC.modal.alert(result.message || 'Erreur.');
        }
    } catch (e) {
        LPC.modal.alert('Erreur serveur.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

function openPriceConfirmModal() {
    const body = document.getElementById('price-confirm-body');
    body.innerHTML = '';

    pendingDisparities.forEach(d => {
        const up = Number(d.delta) > 0;
        body.innerHTML += LPC.html`
            <tr class="hover:bg-gray-50">
                <td class="py-3 px-6">
                    <p class="font-bold text-gray-900">${d.product_name}</p>
                    <p class="text-[10px] font-bold ${up ? 'text-emerald-600' : 'text-amber-600'}">
                        ${up ? '▲' : '▼'} ${LPC.fmt.int(Math.abs(d.delta))} F par unité
                    </p>
                </td>
                <td class="py-3 px-4 text-right font-bold text-gray-500">${LPC.fmt.int(d.current_price)} F</td>
                <td class="py-3 px-4 text-right font-black text-gray-900">${LPC.fmt.int(d.new_price)} F</td>
                <td class="py-3 px-4 text-center font-bold text-gray-600">${d.quantity}</td>
                <td class="py-3 px-6 text-center">
                    <input type="checkbox" class="price-confirm-box w-5 h-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                           data-product-id="${d.product_id}" data-delta-up="${up ? '1' : '0'}"
                           onchange="updatePriceConfirmSummary()">
                </td>
            </tr>`;
    });

    // Nothing is pre-ticked, and "tout confirmer" starts clear. A default here
    // would be the system deciding on the operator's behalf, which is precisely
    // what this modal exists to prevent.
    document.getElementById('price_confirm_all').checked = false;
    updatePriceConfirmSummary();
    document.getElementById('priceConfirmModal').classList.remove('hidden');
}

function togglePriceConfirmAll(checked) {
    document.querySelectorAll('.price-confirm-box').forEach(b => { b.checked = checked; });
    updatePriceConfirmSummary();
}

function updatePriceConfirmSummary() {
    const boxes = Array.from(document.querySelectorAll('.price-confirm-box'));
    const confirmed = boxes.filter(b => b.checked).length;
    // Declined lines split into remises (typed < tariff) and surcharges (typed
    // > tariff). The row itself carries the direction — data-delta-up = '1' for
    // markups — so the summary can name each count honestly rather than lumping
    // both under "remise(s) ponctuelle(s)". Same shape, opposite sign.
    let remises = 0, surcharges = 0;
    boxes.forEach(b => {
        if (b.checked) return;
        if (b.getAttribute('data-delta-up') === '1') surcharges++;
        else                                         remises++;
    });

    const all = document.getElementById('price_confirm_all');
    if (all) all.checked = boxes.length > 0 && confirmed === boxes.length;

    const parts = [`${confirmed} nouveau(x) prix`];
    if (remises)    parts.push(`${remises} remise(s) ponctuelle(s)`);
    if (surcharges) parts.push(`${surcharges} majoration(s) ponctuelle(s)`);
    document.getElementById('price_confirm_summary').textContent = parts.join(' · ');
}

async function submitPriceDecisions() {
    if (!pendingSubmitPayload) return;

    const decisions = {};
    document.querySelectorAll('.price-confirm-box').forEach(b => {
        decisions[b.getAttribute('data-product-id')] = b.checked;
    });
    pendingSubmitPayload.price_decisions = decisions;

    // A declined line — up OR down — is a declared one-off and needs a motif.
    // Checked here rather than only server-side so the operator is not bounced
    // back and forth between two modals. The message names the direction that
    // triggered it so "motif de la majoration" doesn't surprise a user who was
    // thinking about a discount.
    const anyDeclined = Object.values(decisions).some(v => v === false);
    const note = document.getElementById('so_discount_note').value.trim();
    if (anyDeclined && !note) {
        const declinedUp = Array.from(document.querySelectorAll('.price-confirm-box'))
            .some(b => !b.checked && b.getAttribute('data-delta-up') === '1');
        const declinedDown = Array.from(document.querySelectorAll('.price-confirm-box'))
            .some(b => !b.checked && b.getAttribute('data-delta-up') !== '1');
        closeModal('priceConfirmModal');
        document.getElementById('so_discount_note_wrap').classList.remove('hidden');
        document.getElementById('so_discount_note').focus();
        const msg = declinedUp && !declinedDown ? 'Indiquez le motif de la majoration avant de valider.'
                  : declinedDown && !declinedUp ? 'Indiquez le motif de la remise avant de valider.'
                  : 'Indiquez le motif de l’ajustement avant de valider.';
        LPC.toast(msg, 'warning');
        return;
    }
    pendingSubmitPayload.discount_note = note;

    calculateOrderTotals();
    await postOrder(pendingSubmitPayload);
}

// ---------------------------------------------------------------------------
// 8. CANCEL / DELETE
// ---------------------------------------------------------------------------
function openCancelOrderModal(id, reference) {
    document.getElementById('cancel_so_id').value = id;
    document.getElementById('cancel_so_ref').innerText = reference;
    document.getElementById('cancel_reason').value = '';
    document.getElementById('cancelOrderModal').classList.remove('hidden');
}

async function submitCancelOrder() {
    const id = document.getElementById('cancel_so_id').value;
    const reason = document.getElementById('cancel_reason').value.trim();
    if (!reason) { LPC.modal.alert("Motif de l'annulation obligatoire."); return; }

    try {
        const res = await fetch('/api/v1/sales_controller.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'cancel_order', id, reason })
        });
        const r = await res.json();
        if (r.status === 'success') {
            closeModal('cancelOrderModal');
            LPC.toast('Commande annulée.', 'success');
            loadTabData();
        } else LPC.modal.alert(r.message || 'Erreur.');
    } catch (e) { LPC.modal.alert('Erreur serveur.'); }
}

async function deleteRecord(type, id) {
    if (!(await LPC.modal.confirm('Supprimer cette commande ?'))) return;
    const fd = new FormData(); fd.append('action', 'delete_order'); fd.append('id', id);
    const res = await fetch('/api/v1/sales_controller.php', { method: 'POST', body: fd });
    const r = await res.json().catch(() => ({}));
    if (r.status === 'success') { LPC.toast('Commande supprimée.', 'success'); loadTabData(); }
    else LPC.modal.alert(r.message || 'Suppression impossible.');
}

// ---------------------------------------------------------------------------
// 9. DISPATCH (Generates BL)
// ---------------------------------------------------------------------------
/**
 * Pre-select the vehicle from today's affectation, if there is one.
 *
 * This used to REPLACE the whole option list with the single vehicle the driver
 * was assigned to — the select was pointer-events-none, so whatever Flotte had
 * recorded that morning was the only vehicle a BL could carry. Now the full
 * active fleet stays listed and the affectation only moves the selection.
 */
function autoSelectVehicle() {
    const driverId = document.getElementById('disp_driver').value;
    const vehSel   = document.getElementById('disp_vehicle');
    const hint     = document.getElementById('disp_vehicle_hint');
    if (!vehSel) return;

    const assignment = driverId
        ? (metaData.assignments || []).find(a => a.driver_id == driverId)
        : null;

    if (assignment && !vehSel.dataset.userPicked) {
        vehSel.value = assignment.vehicle_id;
    } else if (!assignment && !vehSel.dataset.userPicked) {
        vehSel.value = '';
    }

    if (hint) {
        hint.innerHTML = assignment
            ? '<i class="fas fa-link"></i> Pré-rempli depuis l\'affectation du jour — modifiable.'
            : '<i class="fas fa-info-circle"></i> Véhicule optionnel. Aucune affectation aujourd\'hui pour ce chauffeur.';
    }
}

/**
 * Switch the logistics channel. The three modes are mutually exclusive, so this
 * shows one field group and clears the others. Clearing matters: the server
 * normalises the payload to the mode anyway, but leaving stale values behind a
 * hidden panel is how a user ends up believing they recorded something they did
 * not.
 */
function setDeliveryMode(mode) {
    if (!Object.prototype.hasOwnProperty.call(DELIVERY_MODES, mode)) return;
    document.getElementById('disp_mode').value = mode;

    document.querySelectorAll('.disp-mode-btn').forEach(btn => {
        const on = btn.dataset.mode === mode;
        btn.setAttribute('aria-checked', on ? 'true' : 'false');
        btn.classList.toggle('border-blue-600', on);
        btn.classList.toggle('bg-blue-50', on);
        btn.classList.toggle('text-blue-700', on);
        btn.classList.toggle('border-gray-200', !on);
        btn.classList.toggle('text-gray-400', !on);
    });

    const fleet = document.getElementById('disp_fleet_fields');
    const sup   = document.getElementById('disp_supplier_fields');
    const pick  = document.getElementById('disp_pickup_note');

    fleet.classList.toggle('hidden', mode !== 'own_fleet');
    sup.classList.toggle('hidden', mode !== 'supplier');
    pick.classList.toggle('hidden', mode !== 'client_pickup');

    if (mode !== 'own_fleet') {
        document.getElementById('disp_driver').value = '';
        document.getElementById('disp_vehicle').value = '';
        delete document.getElementById('disp_vehicle').dataset.userPicked;
    }
    if (mode !== 'supplier') document.getElementById('disp_supplier').value = '';
}

function openDispatchModal(orderId, reference, clientName) {
    document.getElementById('form-dispatch').reset();
    document.getElementById('disp_so_id').value = orderId;
    document.getElementById('disp_so_ref').innerText = reference;
    document.getElementById('disp_client_name').innerText = clientName;
    document.getElementById('disp_date').valueAsDate = new Date();

    // form.reset() does not clear a dataset flag, and the mode buttons are not
    // form controls, so both are reset explicitly.
    delete document.getElementById('disp_vehicle').dataset.userPicked;
    setDeliveryMode('own_fleet');
    autoSelectVehicle();
    document.getElementById('dispatchModal').classList.remove('hidden');
}

async function submitDispatch() {
    const mode = document.getElementById('disp_mode').value;

    // Client-side guards mirror the server's, purely for a faster and more
    // specific message. sales_controller.php re-validates all of this — the
    // browser is not the enforcement point.
    if (mode === 'own_fleet' && !document.getElementById('disp_driver').value) {
        return LPC.modal.alert('Sélectionnez le chauffeur, ou choisissez un autre mode de livraison.');
    }
    if (mode === 'supplier' && !document.getElementById('disp_supplier').value) {
        return LPC.modal.alert('Sélectionnez le fournisseur qui assure la livraison.');
    }

    const payload = {
        action: 'generate_dispatch',
        sales_order_id: document.getElementById('disp_so_id').value,
        date: document.getElementById('disp_date').value,
        delivery_mode: mode,
        driver_id: mode === 'own_fleet' ? document.getElementById('disp_driver').value : '',
        vehicle_id: mode === 'own_fleet' ? document.getElementById('disp_vehicle').value : '',
        delivery_supplier_id: mode === 'supplier' ? document.getElementById('disp_supplier').value : ''
    };

    try {
        const response = await fetch('/api/v1/sales_controller.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
        });
        const result = await response.json();
        if (result.status === 'success') {
            closeModal('dispatchModal');
            window.open(`/bon_livraison.php?token=${result.token}`, '_blank');
            switchTab('dispatch');
        } else LPC.modal.alert(result.message || 'Erreur.');
    } catch (e) { LPC.modal.alert('Erreur serveur.'); }
}

// ---------------------------------------------------------------------------
// 10. DELIVERY COMPLETION
// ---------------------------------------------------------------------------
let currentCloseToken = '';

async function openCompleteDeliveryModal(deliveryId, reference, cashCollected, mode, token) {
    document.getElementById('form-close-delivery').reset();
    document.getElementById('close_delivery_id').value = deliveryId;
    document.getElementById('close_bl_ref').innerText = reference;
    document.getElementById('close_cash').value = cashCollected || 0;
    // The server's driver_confirm_delivery identifies the BL by TOKEN. The old
    // caller passed no token and the payload sent `delivery_id`, so this action
    // failed with "Token manquant." every single time it was used from this
    // screen. The token is on the row; it is now carried through.
    currentCloseToken = token || '';

    // The cash field was hardcoded "collecté par le chauffeur". On a supplier or
    // pickup delivery nobody called a chauffeur handled the money, and asking
    // for it under that name is how a real payment gets left at zero.
    const cashLabel = document.getElementById('close_cash_label');
    if (cashLabel) {
        cashLabel.innerText = (mode === 'own_fleet' || !mode)
            ? 'Montant collecté par le chauffeur (FCFA)'
            : 'Montant collecté à la livraison (FCFA)';
    }

    try {
        const response = await fetch(`/api/v1/sales_controller.php?action=get_delivery_items&id=${deliveryId}`);
        const result = await response.json();

        if (result.status === 'success') {
            const container = document.getElementById('close-lines-container');
            container.innerHTML = '';

            result.data.forEach(item => {
                // The empties input now lives in its own column rather than
                // being injected under the product name, so the ops close-out
                // records returned consignes the same way the driver flow does.
                const emptyCell = item.linked_empty_id
                    ? LPC.raw(LPC.html`
                        <div class="flex flex-col items-center gap-1">
                            <input type="number" class="empty-qty-input w-20 bg-amber-50 border border-amber-300 rounded p-2 text-center font-black text-amber-900 outline-none focus:ring-2 focus:ring-amber-500" data-item-id="${item.id}" value="0" min="0">
                            <span class="text-[9px] font-black text-amber-700 uppercase">${item.empty_name}</span>
                        </div>`)
                    : LPC.raw('<span class="text-gray-300">—</span>');

                container.innerHTML += LPC.html`
                    <tr>
                        <td class="py-3 px-6 border-r border-gray-100 align-middle">
                            <p class="font-bold text-gray-800">${item.product_name}</p>
                        </td>
                        <td class="py-3 px-4 text-center font-black text-gray-500 bg-gray-50 border-r border-gray-100 align-middle">${item.dispatched_qty}</td>
                        <td class="py-3 px-4 align-middle">
                            <input type="number" class="close-qty-input w-full bg-emerald-50 text-emerald-900 border border-emerald-200 rounded-lg p-3 text-center text-lg font-black outline-none focus:ring-2 focus:ring-emerald-500" data-item-id="${item.id}" value="${item.dispatched_qty}" min="0" max="${item.dispatched_qty}">
                        </td>
                        <td class="py-3 px-4 text-center align-middle">${emptyCell}</td>
                    </tr>`;
            });
            document.getElementById('closeDeliveryModal').classList.remove('hidden');
        }
    } catch (e) { LPC.modal.alert('Impossible de charger les lignes du BL.'); }
}

async function submitCloseDelivery() {
    if (!currentCloseToken) {
        return LPC.modal.alert("Ce BL n'a pas de jeton exploitable. Rechargez la page.");
    }

    const payload = {
        action: 'driver_confirm_delivery',
        token: currentCloseToken,
        payment_collected: document.getElementById('close_cash').value || 0,
        adjustments: []
    };

    document.querySelectorAll('.close-qty-input').forEach(input => {
        const itemId = input.getAttribute('data-item-id');
        const emptyInput = document.querySelector(`.empty-qty-input[data-item-id="${itemId}"]`);
        payload.adjustments.push({
            item_id: itemId,
            accepted_qty: input.value,
            returned_empty_qty: emptyInput ? emptyInput.value : 0
        });
    });

    try {
        const response = await fetch('/api/v1/sales_controller.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
        });
        const result = await response.json();
        if (result.status === 'success') {
            closeModal('closeDeliveryModal');
            const blRef = document.getElementById('close_bl_ref').innerText;
            showBLShareModal(blRef, result.token, '');
        } else LPC.modal.alert(result.message || 'Erreur.');
    } catch (e) { LPC.modal.alert('Erreur serveur.'); }
}

// ---------------------------------------------------------------------------
// 11. SHARE / SIGN
// ---------------------------------------------------------------------------
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

let currentBLShareLink = '';
const domain = window.location.origin;

function showBLShareModal(ref, token, phone) {
    document.getElementById('share_bl_ref').innerText = ref;
    if (phone) document.getElementById('bl_client_phone').value = phone;
    currentBLShareLink = `${domain}/sign_bl.php?token=${token}`;

    const qrContainer = document.getElementById('qrcode-bl');
    qrContainer.innerHTML = '';
    new QRCode(qrContainer, {
        text: currentBLShareLink, width: 192, height: 192,
        colorDark: '#0F172A', correctLevel: QRCode.CorrectLevel.H
    });

    // Was `openModal('modal-share-bl')` — a function that does not exist
    // anywhere in the codebase. The ReferenceError aborted this function before
    // the modal opened AND before the table refreshed, so closing a delivery
    // appeared to do nothing at all.
    document.getElementById('modal-share-bl').classList.remove('hidden');
    loadTabData();
}

function shareBLWhatsApp() {
    let phone = document.getElementById('bl_client_phone').value.trim().replace(/\s+/g, '');
    if (phone.length === 9) phone = '237' + phone;
    const text = encodeURIComponent(`Bonjour,\nVoici le lien sécurisé pour vérifier et valider votre Bon de Livraison LPC :\n${currentBLShareLink}`);
    window.open(phone ? `https://wa.me/${phone}?text=${text}` : `https://api.whatsapp.com/send?text=${text}`, '_blank');
}
