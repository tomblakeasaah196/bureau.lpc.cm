/**
 * assets/js/modules/inventory-procurement.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/inventory/procurement.php (Sprint 6 D2).
 *
 * Original block was ~30,459 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        // --- 1. STATE MANAGEMENT ---
        // OPEX tab was removed on 31 July 2026 — see migration 053_expenses_module.sql
        // and modules/accounting/expenses.php. currentTab is retained as a
        // constant because switchTab() still handles KPI ribbon rendering
        // for the (now single) inventory tab.
        let currentTab = 'inventory';
        let moduleData = [];
        let metaData = { suppliers: [], products: [], delivery_places: [] };

        let currentSdpBalance = 0;
        let ristourneSupplierId = null; // Supplier currently shown in the Ristournes modal
        let pagers = {}; // one LPC.paginator instance per tab

        const config = {
            inventory: {
                api: 'purchase_orders',
                btnText: "Nouveau Bon de Commande",
                columns: [
                    { key: 'date', label: 'Date', render: v => `<span class="text-xs font-bold text-gray-500">${new Date(v).toLocaleDateString('fr-FR')}</span>` },
                    { key: 'reference', label: 'Référence PO', render: v => `<span class="font-black text-gray-900 bg-gray-100 px-2 py-1 rounded border border-gray-200">${v}</span>` },
                    { key: 'supplier_name', label: 'Fournisseur', render: v => `<span class="font-bold text-gray-700">${v}</span>` },
                    { key: 'delivery_place_name', label: 'Lieu de Livraison', render: v => v
                        ? `<span class="text-xs font-bold text-gray-600"><i class="fas fa-map-marker-alt mr-1 text-gray-500"></i>${v}</span>`
                        : `<span class="text-xs text-gray-300 italic">Non spécifié</span>` },
                    // A cancelled order is no longer owed, whatever its payment
                    // status column still says — show that first so a reversed
                    // order can never be read as an outstanding debt.
                    { key: 'payment_status', label: 'Paiement', render: (v, row) =>
                        row.status === 'cancelled'
                            ? '<span class="text-gray-500 bg-gray-100 px-2 py-1 rounded text-[10px] font-black uppercase"><i class="fas fa-ban mr-1"></i>Annulé</span>'
                            : (v === 'paid'
                                ? '<span class="text-green-600 bg-green-50 px-2 py-1 rounded text-[10px] font-black uppercase"><i class="fas fa-check mr-1"></i>Payé</span>'
                                : '<span class="text-red-600 bg-red-50 px-2 py-1 rounded text-[10px] font-black uppercase"><i class="fas fa-hourglass-half mr-1"></i>À Crédit</span>') },
                    { key: 'total_amount', label: 'Net à Payer', render: v => `<span class="font-black text-lpc-dark text-base">${LPC.fmt.int(v)} FCFA</span>` }
                ],
                kpis: [
                    // "(Période)" not "(Mois)": these figures follow the period
                    // selector, so a hardcoded "Mois" was actively lying
                    // whenever the user picked YTD, Tout, or a custom range.
                    { id: 'kpi_inv_total', label: 'Total Achats (Période)', icon: 'fa-chart-line', color: 'text-indigo-600', bg: 'bg-indigo-50' },
                    { id: 'kpi_inv_unpaid', label: 'Dettes Fournisseurs', icon: 'fa-hand-holding-usd', color: 'text-red-600', bg: 'bg-red-50' },
                    { id: 'kpi_inv_discount', label: 'Remises Obtenues', icon: 'fa-tags', color: 'text-emerald-700', bg: 'bg-emerald-50',
                      hint: "Toute remise saisie manuellement sur une commande, qu'elle vienne d'une ristourne ou non. Différent de « Ristournes » (bouton ci-dessus), qui est le système de paliers par catégorie chez un fournisseur." },
                    { id: 'kpi_inv_count', label: 'Volume Commandes', icon: 'fa-boxes', color: 'text-blue-600', bg: 'bg-blue-50' }
                ]
            },
            // overheads config removed with the OPEX tab. See
            // modules/accounting/expenses.php for its replacement.
        };

        // --- 2. INITIALIZATION & DATA FETCHING ---
        document.addEventListener('DOMContentLoaded', async () => {
            document.getElementById('po_date').valueAsDate = new Date();
            // Overhead date field (oh_date) removed with the OPEX tab.
            await fetchMetaData();
            switchTab('inventory');
        });

        async function fetchMetaData() {
            try {
                const response = await fetch('/api/v1/procurement_controller.php?action=fetch_metadata');
                const result = await response.json();
                if(result.status === 'success') {
                    metaData = result.data;

                    const supSelect = document.getElementById('po_supplier');
                    metaData.suppliers.forEach(s => {
                        supSelect.innerHTML += LPC.html`<option value="${s.id}">${s.name}</option>`;
                    });

                    populateDeliveryPlaceSelect();

                    // Which supplier the Ristournes modal opens for by default —
                    // no more name-matching "Source du Pays"/"SDP", and no more
                    // landing on whichever supplier happens to sort first
                    // alphabetically (which was often an unrelated supplier
                    // with nothing configured, showing an empty panel by
                    // coincidence). Prefer the first supplier that actually has
                    // an active ladder tier; only fall back to alphabetical
                    // first if none are configured yet.
                    const withLadder = metaData.suppliers_with_ladder || [];
                    const firstConfigured = metaData.suppliers.find(s => withLadder.includes(s.id));
                    ristourneSupplierId = firstConfigured
                        ? firstConfigured.id
                        : (metaData.suppliers.length ? metaData.suppliers[0].id : null);
                }
            } catch (e) { console.error("Failed to load metadata", e); }
        }

        function populateDeliveryPlaceSelect() {
            const sel = document.getElementById('po_delivery_place');
            const current = sel.value;
            sel.innerHTML = '<option value="">Non spécifié</option>';
            (metaData.delivery_places || []).forEach(p => {
                sel.innerHTML += LPC.html`<option value="${p.id}">${p.name}</option>`;
            });
            if (current) sel.value = current;
        }

        async function switchTab(tab) {
            currentTab = tab;
            const c = config[currentTab];

            document.querySelectorAll('.tab-link').forEach(el => {
                el.classList.remove('border-lpc-dark', 'text-lpc-dark', 'font-black');
                el.classList.add('border-transparent', 'text-gray-500', 'font-bold');
            });
            // The tab strip was removed with the OPEX tab on 31 July 2026, so
            // #tab-inventory no longer exists in procurement.php. This lookup
            // returned null and the unguarded .classList threw, aborting
            // switchTab() before the KPI ribbon, table head and loadTabData()
            // ever ran — the page rendered its shell and nothing else.
            const activeLink = document.getElementById(`tab-${tab}`);
            if (activeLink) {
                activeLink.classList.remove('border-transparent', 'text-gray-500', 'font-bold');
                activeLink.classList.add('border-lpc-dark', 'text-lpc-dark', 'font-black');
            }

            const actionText = document.getElementById('btn-action-text');
            if (actionText) actionText.innerText = c.btnText;

            // Ristournes + its settings shortcut. Both are toggled defensively:
            // RBAC may hide either, and with the OPEX tab gone there is only one
            // tab left — so an absent node must never abort switchTab() the way
            // the removed tab strip did.
            ['btn-sdp-ristourne', 'btn-config-ristournes'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.classList.toggle('hidden', tab !== 'inventory');
            });

            const ribbon = document.getElementById('kpi-ribbon');
            ribbon.innerHTML = '';
            c.kpis.forEach(kpi => {
                // kpi.hint is optional: a native-tooltip (title attribute)
                // clarification shown next to the label. Added specifically
                // for "Remises Obtenues" — it sits right next to the
                // "Ristournes" button and both are about money taken off a
                // purchase order, but they are not the same thing: this KPI
                // totals whatever was typed into the discount field on any PO
                // (ristourne-funded or not), while "Ristournes" is the
                // supplier ladder system that computes and tracks the rebate
                // itself.
                const hintIcon = kpi.hint
                    ? LPC.raw(` <i class="fas fa-circle-info text-gray-500" title="${String(kpi.hint).replace(/"/g, '&quot;')}"></i>`)
                    : '';

                ribbon.innerHTML += LPC.html`
                    <div onclick="showKPIDetails('${kpi.id}')" class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between cursor-pointer hover:shadow-md hover:-translate-y-1 transition-all">
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
         * Loads one tab's data.
         *
         * The table itself is now server-paginated (20 rows/page) via
         * LPC.paginator, which owns the tbody's HTML from here on — renderTable()
         * is no longer used for the main grid, only search-free legacy call
         * sites (there are none left, kept for safety) would need it.
         *
         * KPIs are NOT part of the paginated payload's row count — the API's
         * `read` action always computes them over the full date range
         * regardless of which page is requested — but the paginator only
         * forwards rows/pagination to callers, not the sibling `kpis` key. So
         * KPIs are fetched once per date-range change with a minimal
         * page=1&per_page=1 request just to read `data.kpis`.
         */
        async function loadTabData() {
            const range = getActiveDateRange();
            if (!range) return; // Prevent loading if custom range is half-empty

            await fetchKpis(range);
            attachPager(currentTab, range);
        }

        async function fetchKpis(range) {
            try {
                // per_page=200 (Paginator's ceiling) doubles as the source for
                // the "KPI details" modal below — that modal is a convenience
                // popup, not an audit tool, so capping it at 200 rows for a
                // date range is an acceptable trade-off against re-fetching
                // the whole table separately.
                const response = await fetch(`/api/v1/procurement_controller.php?action=read&tab=${currentTab}&start=${range.start}&end=${range.end}&page=1&per_page=200`);
                const result = await response.json();
                if (result.status === 'success') {
                    moduleData = result.data.table || [];
                    if (result.data.kpis) {
                        for (const [key, value] of Object.entries(result.data.kpis)) {
                            const el = document.getElementById(key);
                            if (el) el.innerText = value;
                        }
                    }
                }
            } catch (e) { console.error('KPI fetch failed', e); }
        }

        function attachPager(tab, range) {
            if (pagers[tab]) { pagers[tab].destroy(); pagers[tab] = null; }

            const cfg = config[tab];
            const tbody = document.getElementById('table-body');
            const searchInput = document.getElementById('search-input');

            pagers[tab] = LPC.paginator.attach(tbody, {
                url: `/api/v1/procurement_controller.php?action=read&tab=${tab}`,
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
        
        function showKPIDetails(kpiId) {
            const titleEl = document.getElementById('kpi-detail-title');
            const tbody = document.getElementById('kpi-detail-body');
            tbody.innerHTML = '';
            let filteredData = [];

            // Only the inventory branch remains — the OPEX branch was removed
            // with its tab on 31 July 2026 (migration 053).
            if (kpiId === 'kpi_inv_total' || kpiId === 'kpi_inv_count') {
                titleEl.innerText = "Toutes les Commandes (Période)";
                filteredData = moduleData;
            } else if (kpiId === 'kpi_inv_unpaid') {
                titleEl.innerText = "Dettes Fournisseurs (Non Payées)";
                filteredData = moduleData.filter(x => x.payment_status !== 'paid');
            } else if (kpiId === 'kpi_inv_discount') {
                titleEl.innerText = "Détails des Achats";
                filteredData = moduleData;
            }

            filteredData.forEach(row => {
                tbody.innerHTML += LPC.html`<tr>
                    <td class="py-3 px-4">${new Date(row.date).toLocaleDateString('fr-FR')}</td>
                    <td class="py-3 px-4 font-mono text-xs">${row.reference}</td>
                    <td class="py-3 px-4 font-bold text-gray-800">${row.supplier_name}</td>
                    <td class="py-3 px-4 text-right font-black text-lpc-dark">${LPC.fmt.int(row.total_amount)} F</td>
                </tr>`;
            });
            
            if(filteredData.length === 0) {
                tbody.innerHTML = LPC.html`<tr><td colspan="4" class="py-6 text-center text-gray-500 font-bold italic">Aucune donnée pour ce critère.</td></tr>`;
            }
            
            document.getElementById('kpiDetailsModal').classList.remove('hidden');
        }

        // Human-readable description of the filter currently in force, for the
        // empty state. An empty table has two very different causes — "nothing
        // happened in this period" and "the data is gone" — and the old
        // "Aucune donnée disponible." was indistinguishable from the second.
        function describeActivePeriod() {
            const sel = document.getElementById('kpi_period_type');
            const type = sel ? sel.value : 'ytd';
            const today = new Date();

            if (type === 'current_month') {
                return today.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
            }
            if (type === 'ytd')  return `l'année ${today.getFullYear()}`;
            if (type === 'all')  return 'tout l\'historique';
            if (type === 'custom') {
                const sm = document.getElementById('kpi_start_month').value;
                const em = document.getElementById('kpi_end_month').value;
                return (sm && em) ? `${sm} → ${em}` : 'la plage choisie';
            }
            return 'la période choisie';
        }

        /**
         * Renders one <tr> for the paginator (assets/js/lpc-paginator.js).
         *
         * The whole row is clickable through to the full-page order detail
         * (modules/inventory/po_detail.php) on the inventory tab — action
         * buttons stop propagation so "Voir PDF" / "Annuler" still work without
         * also navigating.
         */
        function renderTableRow(row, tab) {
            const cancelled = row.status === 'cancelled';
            const rowClickable = tab === 'inventory';
            const rowAttrs = rowClickable
                ? `class="${cancelled ? 'opacity-50 ' : ''}hover:bg-gray-50 transition-colors cursor-pointer" onclick="location.href='/modules/inventory/po_detail.php?id=${row.id}'"`
                : `class="${cancelled ? 'opacity-50 ' : ''}hover:bg-gray-50 transition-colors"`;

            let tr = `<tr ${rowAttrs}>`;
            config[tab].columns.forEach(col => tr += `<td class="py-4 px-6 border-b border-gray-50">${col.render(row[col.key], row)}</td>`);

            let actions = '';
            if (tab === 'inventory') {
                // Already-cancelled orders keep their PDF link but lose the
                // cancel button — the server rejects a second cancellation
                // anyway, and offering an action that can only fail is worse
                // than not offering it.
                const cancelBtn = cancelled ? '' : `
                    <button onclick="event.stopPropagation(); cancelPurchaseOrder(${row.id}, '${String(row.reference).replace(/'/g, "\\'")}')"
                            class="text-red-600 hover:text-red-700 p-2" title="Annuler Commande" aria-label="Annuler Commande"><i class="fas fa-ban"></i></button>`;
                actions = `
                    <a href="/bon_commande.php?token=${row.token}" target="_blank" onclick="event.stopPropagation()" class="text-lpc-dark hover:text-green-700 bg-green-50 p-2 rounded-lg mr-2" title="Voir PDF"><i class="fas fa-file-pdf"></i></a>
                    <a href="/modules/inventory/po_detail.php?id=${row.id}" onclick="event.stopPropagation()" class="text-indigo-500 hover:text-indigo-700 bg-indigo-50 p-2 rounded-lg mr-2" title="Voir le détail"><i class="fas fa-eye"></i></a>${cancelBtn}`;
            } else {
                actions = `
                    <button onclick="openActionModal(${row.id})" class="text-gray-500 hover:text-gray-900 p-2 mr-2" title="Modifier" aria-label="Modifier"><i class="fas fa-edit"></i></button>
                    <button onclick="deleteRecord(${row.id})" class="text-red-600 hover:text-red-700 p-2"><i class="fas fa-trash-alt"></i></button>`;
            }

            tr += `<td class="py-4 px-6 border-b border-gray-50 text-right whitespace-nowrap" onclick="event.stopPropagation()">${actions}</td></tr>`;
            return tr;
        }

        // Target of the "Voir tout l'historique" button in the empty state.
        function widenToAllHistory() {
            const sel = document.getElementById('kpi_period_type');
            sel.value = 'all';
            handlePeriodUI();
        }

        function handlePeriodUI() {
            const type = document.getElementById('kpi_period_type').value;
            const customWrap = document.getElementById('custom_period_wrapper');
            
            if (type === 'custom') {
                customWrap.classList.remove('hidden');
                customWrap.classList.add('flex');
            } else {
                customWrap.classList.add('hidden');
                customWrap.classList.remove('flex');
                loadTabData(); // Auto-refresh for preset periods
            }
        }

        // Format a Date as YYYY-MM-DD in LOCAL time.
        //
        // This function used to build every boundary with
        // `new Date(y, m, 1).toISOString().split('T')[0]`. The Date is
        // constructed in local time but toISOString() renders it in UTC, so at
        // WAT (UTC+1) midnight on the 1st becomes 23:00 on the last day of the
        // previous month and the "start of month" boundary silently landed one
        // day early — quietly pulling the previous month's last day into every
        // monthly figure, and dropping a day off custom ranges. Same class of
        // bug as the period default: invisible until the numbers matter.
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
                end   = toLocalIso(new Date(today.getFullYear(), today.getMonth() + 1, 0)); // Last day of month
            } else if (type === 'ytd') {
                start = toLocalIso(new Date(today.getFullYear(), 0, 1));   // Jan 1st
                end   = toLocalIso(new Date(today.getFullYear(), 11, 31)); // Dec 31st
            } else if (type === 'all') {
                start = '2000-01-01';
                end = '2099-12-31';
            } else if (type === 'custom') {
                const sm = document.getElementById('kpi_start_month').value; // YYYY-MM
                const em = document.getElementById('kpi_end_month').value;   // YYYY-MM
                if (!sm || !em) return null; // Don't fetch if range is incomplete
                if (em < sm) {
                    LPC.modal.alert("La date de fin doit être postérieure à la date de début.");
                    return null;
                }

                start = sm + '-01';
                const [eYear, eMonth] = em.split('-');
                end = toLocalIso(new Date(Number(eYear), Number(eMonth), 0)); // Last day of end month
            }
            return { start, end };
        }


        // --- 3. DYNAMIC PURCHASE ORDER & SDP RISTOURNE LOGIC ---

        function openActionModal(id = null) {
            // OPEX branch removed with the tab — see modules/accounting/expenses.php.
            document.getElementById('form-po').reset();
            document.getElementById('po_date').valueAsDate = new Date();
            document.getElementById('po_id').value = '';
            document.getElementById('po-lines-container').innerHTML = '';
            document.getElementById('sdp-rebate-info').classList.add('hidden');
            addPOLine();
            calculatePOTotals();
            document.getElementById('poModal').classList.remove('hidden');
        }

        function closeModal(modalId) { document.getElementById(modalId).classList.add('hidden'); }

        // Ristourne balance check — any supplier can carry a balance now, not
        // just a hardcoded "SDP" one (migration 051 moved the rebate off a
        // blanket per-supplier rate and onto per-supplier/per-product config).
        async function checkSupplierRebate(selectElement) {
            const supplierId = selectElement.value;
            const rebateInfoDiv = document.getElementById('sdp-rebate-info');

            if (supplierId) {
                try {
                    const res = await fetch(`/api/v1/procurement_controller.php?action=get_ristourne_data&supplier_id=${supplierId}`);
                    const result = await res.json();
                    if(result.status === 'success') {
                        currentSdpBalance = result.data.balance;
                        if(currentSdpBalance > 0) {
                            document.getElementById('sdp-rebate-val').innerText = LPC.fmt.int(currentSdpBalance);
                            rebateInfoDiv.classList.remove('hidden');
                        } else {
                            rebateInfoDiv.classList.add('hidden');
                        }
                    }
                } catch(e) {}
            } else {
                rebateInfoDiv.classList.add('hidden');
                currentSdpBalance = 0;
            }
        }

        function applyMaxRebate() {
            // Apply maximum available rebate, but not exceeding the subtotal
            const subtotal = parseFloat(document.getElementById('calc_subtotal').innerText.replace(/[^\d.-]/g, '')) || 0;
            const amountToApply = Math.min(currentSdpBalance, subtotal);
            
            document.getElementById('po_discount_amount').value = amountToApply;
            document.getElementById('po_discount_note').value = "Utilisation de Ristourne";
            calculatePOTotals();
        }

function openRistourneModal() {
            if (!metaData.suppliers || !metaData.suppliers.length) {
                return LPC.modal.alert("Aucun fournisseur enregistré.");
            }
            const sel = document.getElementById('ristourne_supplier_select');
            sel.innerHTML = metaData.suppliers.map(s =>
                `<option value="${s.id}">${s.name}</option>`
            ).join('');
            sel.value = ristourneSupplierId || metaData.suppliers[0].id;

            document.getElementById('ristourneModal').classList.remove('hidden');
            onRistourneSupplierChange(sel.value);
        }

        /** Switching the supplier in the Ristournes modal reloads its ledger.
         *  The ladder config itself lives on the dedicated page
         *  (modules/inventory/rebate_config.php), linked from this modal. */
        async function onRistourneSupplierChange(supplierId) {
            ristourneSupplierId = supplierId;
            await loadRistourneLedger(supplierId);
        }

        async function loadRistourneLedger(supplierId) {
            try {
                const res = await fetch(`/api/v1/procurement_controller.php?action=get_ristourne_data&supplier_id=${supplierId}`);
                const result = await res.json();

                if(result.status === 'success') {
                    // Inject Dashboard KPIs
                    document.getElementById('rist_total_earned').innerText = LPC.fmt.fcfa(result.data.earned);
                    document.getElementById('rist_total_used').innerText = LPC.fmt.fcfa(result.data.used);
                    document.getElementById('rist_current_balance').innerText = LPC.fmt.fcfa(result.data.balance);

                    // Inject Ledger Table
                    const tbody = document.getElementById('ristourne-ledger-body');
                    tbody.innerHTML = '';

                    if(result.data.ledger.length === 0) {
                        tbody.innerHTML = LPC.html`<tr><td colspan="4" class="py-6 text-center text-gray-500">Aucun historique disponible.</td></tr>`;
                    } else {
                        result.data.ledger.forEach(l => {
                            const isAccrual = l.type === 'accrual';
                            const isReversed = Number(l.reversed) === 1;

                            // LPC.raw, not a bare string. Every ${…} inside an
                            // LPC.html`…` template is HTML-escaped by default
                            // (see assets/js/lpc-dom.js) — that is the whole
                            // point of the helper. Interpolating pre-built
                            // markup without raw() printed the <span> tags as
                            // visible text in the ledger instead of rendering
                            // the badge, which is what made this panel look
                            // like a broken dark-mode stylesheet.
                            const badge = LPC.raw(isAccrual
                                ? '<span class="px-2 py-1 bg-emerald-100 text-emerald-800 text-[9px] font-black uppercase rounded"><i class="fas fa-plus mr-1"></i>Crédité</span>'
                                : '<span class="px-2 py-1 bg-rose-100 text-rose-800 text-[9px] font-black uppercase rounded"><i class="fas fa-minus mr-1"></i>Déduit</span>');

                            // Reversed rows stay visible but are struck through
                            // and muted: a clawed-back credit is part of the
                            // history, not something to hide.
                            // Reversed rows use gray-600 (not gray-400) and a
                            // lighter opacity-80 wrapper rather than opacity-60
                            // — gray-400 at 60% opacity over white falls well
                            // under the 4.5:1 WCAG AA text contrast minimum;
                            // this combination keeps the "de-emphasized" cue
                            // without making the row unreadable.
                            const color = isReversed
                                ? 'text-gray-600 line-through'
                                : (isAccrual ? 'text-emerald-700' : 'text-rose-700');
                            const prefix = isAccrual ? '+' : '-';
                            const rowClass = isReversed
                                ? 'opacity-80 hover:bg-lpc-bg transition-colors'
                                : 'hover:bg-lpc-bg transition-colors';

                            tbody.innerHTML += LPC.html`
                                <tr class="${rowClass}">
                                    <td class="py-4 px-6 text-xs text-gray-700 font-bold">${LPC.fmt.date(l.date)}<br><span class="text-[10px] text-gray-500 font-mono tracking-wide">${l.reference}</span></td>
                                    <td class="py-4 px-6">${badge}</td>
                                    <td class="py-4 px-6 text-xs font-bold text-gray-700">${l.notes}</td>
                                    <td class="py-4 px-6 text-right font-black ${color}">${prefix} ${LPC.fmt.int(l.amount)}</td>
                                </tr>
                            `;
                        });
                    }

                }
            } catch(e) {
                LPC.modal.alert("Impossible de charger les données Ristourne.");
            }
        }

        // --- 3b. DELIVERY PLACES MANAGEMENT ---

        function openDeliveryPlacesModal() {
            renderDeliveryPlacesList();
            document.getElementById('deliveryPlacesModal').classList.remove('hidden');
        }

        function renderDeliveryPlacesList() {
            const list = document.getElementById('delivery-places-list');
            list.innerHTML = '';
            (metaData.delivery_places || []).forEach(p => {
                list.innerHTML += LPC.html`
                    <div class="flex items-center justify-between bg-gray-50 border border-gray-200 rounded-lg px-4 py-2.5">
                        <span class="font-bold text-sm text-gray-700"><i class="fas fa-map-marker-alt mr-2 text-gray-500"></i>${p.name}</span>
                        <div class="flex gap-2">
                            <button onclick="editDeliveryPlace(${p.id}, '${String(p.name).replace(/'/g, "\\'")}')" class="text-gray-500 hover:text-gray-800 p-1"><i class="fas fa-edit"></i></button>
                            <button onclick="deactivateDeliveryPlace(${p.id})" class="text-red-600 hover:text-red-700 p-1"><i class="fas fa-trash-alt"></i></button>
                        </div>
                    </div>`;
            });
            if (!metaData.delivery_places || !metaData.delivery_places.length) {
                list.innerHTML = '<p class="text-sm text-gray-500 italic text-center py-4">Aucun lieu de livraison enregistré.</p>';
            }
        }

        async function saveDeliveryPlace(id = null) {
            const input = document.getElementById('new_delivery_place_name');
            const name = input.value.trim();
            if (!name) return;

            try {
                const res = await fetch('/api/v1/procurement_controller.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delivery_places_save', id: id, name: name })
                });
                const result = await res.json();
                if (result.status === 'success') {
                    input.value = '';
                    delete input.dataset.editingId;
                    const btn = document.querySelector('#deliveryPlacesModal button[onclick^="saveDeliveryPlace"]');
                    if (btn) btn.setAttribute('onclick', 'saveDeliveryPlace()');
                    await refreshDeliveryPlaces();
                    renderDeliveryPlacesList();
                } else LPC.modal.alert("Erreur: " + result.message);
            } catch (e) { LPC.modal.alert("Erreur système."); }
        }

        function editDeliveryPlace(id, currentName) {
            const input = document.getElementById('new_delivery_place_name');
            input.value = currentName;
            input.dataset.editingId = id;
            input.focus();
            const btn = document.querySelector('#deliveryPlacesModal button[onclick^="saveDeliveryPlace"]');
            if (btn) btn.setAttribute('onclick', `saveDeliveryPlace(${id})`);
        }

        async function deactivateDeliveryPlace(id) {
            if (!(await LPC.modal.confirm("Retirer ce lieu de livraison de la liste ?"))) return;
            const fd = new FormData();
            fd.append('action', 'delivery_places_deactivate');
            fd.append('id', id);
            try {
                const res = await fetch('/api/v1/procurement_controller.php', { method: 'POST', body: fd });
                const result = await res.json();
                if (result.status === 'success') {
                    await refreshDeliveryPlaces();
                    renderDeliveryPlacesList();
                } else LPC.modal.alert("Erreur: " + result.message);
            } catch (e) { LPC.modal.alert("Erreur système."); }
        }

        async function refreshDeliveryPlaces() {
            try {
                const res = await fetch('/api/v1/procurement_controller.php?action=delivery_places_list');
                const result = await res.json();
                if (result.status === 'success') {
                    metaData.delivery_places = result.data;
                    populateDeliveryPlaceSelect();
                }
            } catch (e) { console.error(e); }
        }


        /**
         * Append one purchase-order line.
         *
         * Two changes beyond swapping the <select> for the shared picker:
         *
         *  1. purpose:'buy'. The old dropdown auto-filled a PO with
         *     `base_price` — the SELLING price. Every purchase order this
         *     screen has ever generated started life priced at what we charge
         *     the customer rather than what the supplier charges us, and the
         *     buyer had to know to overwrite it. 'buy' asks the catalogue for
         *     cump (weighted average cost), falling back to base_price only
         *     when a product has never been received.
         *
         *  2. The catalogue excludes inactive products. procurement_controller
         *     built its list without `is_active = 1` — the one clause the sales
         *     copy of the same query had — so retired SKUs stayed orderable
         *     here long after they disappeared everywhere else.
         *
         * rowId was Date.now(), which collides for two rows added in the same
         * millisecond and makes them share a total cell. Now a counter.
         */
        let poRowSeq = 0;

        function addPOLine() {
            const container = document.getElementById('po-lines-container');
            const rowId = ++poRowSeq;

            const tr = document.createElement('tr');
            tr.className = "item-row hover:bg-gray-100 transition-colors";
            tr.innerHTML = LPC.html`
                <td class="p-1 border-r border-gray-100 align-top">
                    <select class="po-prod-select" data-lpc-product-picker="buy" aria-label="Produit" required></select>
                </td>
                <td class="p-1 border-r border-gray-100 align-top">
                    <input type="number" class="po-qty seamless-input p-2 text-center font-bold text-gray-900" value="1" min="1" oninput="calcRow(${rowId})" id="qty_${rowId}" aria-label="Quantité" required>
                </td>
                <td class="p-1 border-r border-gray-100 align-top">
                    <input type="number" class="po-price seamless-input p-2 text-right font-bold text-gray-900" value="0" min="0" oninput="calcRow(${rowId})" id="price_${rowId}" aria-label="Prix unitaire" required>
                </td>
                <td class="p-1 border-r border-gray-100 text-right pr-4 font-black text-gray-900 align-top" id="total_${rowId}">
                    0
                </td>
                <td class="p-1 text-center align-top">
                    <button type="button" onclick="removePOLine(this)" class="text-red-600 hover:text-red-700 p-1" aria-label="Supprimer la ligne"><i class="fas fa-times"></i></button>
                </td>
            `;
            container.appendChild(tr);

            window.LPC?.productPicker?.mount(tr.querySelector('.po-prod-select'), {
                purpose: 'buy',
                onSelect(product) {
                    const priceEl = document.getElementById(`price_${rowId}`);
                    priceEl.value = product ? Math.round(product.price) : 0;
                    calcRow(rowId);
                    if (product) {
                        document.getElementById(`qty_${rowId}`).focus();
                        document.getElementById(`qty_${rowId}`).select();
                    }
                }
            });
            return tr;
        }

        function removePOLine(btn) {
            const row = btn.closest('tr');
            if (!row) return;
            // The popup lives on <body>; destroy() removes it with the row.
            const picker = window.LPC?.productPicker?.get(row.querySelector('.po-prod-select'));
            if (picker) picker.destroy();
            row.remove();
            calculatePOTotals();
        }

        function calcRow(rowId) {
            const qty = parseFloat(document.getElementById(`qty_${rowId}`).value) || 0;
            const price = parseFloat(document.getElementById(`price_${rowId}`).value) || 0;
            const total = qty * price;
            document.getElementById(`total_${rowId}`).innerText = LPC.fmt.int(total);
            calculatePOTotals(); 
        }

        function calculatePOTotals() {
            let subtotal = 0;
            document.querySelectorAll('.item-row').forEach(row => {
                const qty = parseFloat(row.querySelector('.po-qty').value) || 0;
                const price = parseFloat(row.querySelector('.po-price').value) || 0;
                subtotal += (qty * price);
            });

            const discount = parseFloat(document.getElementById('po_discount_amount').value) || 0;
            const grandTotal = subtotal - discount;

            document.getElementById('calc_subtotal').innerText = LPC.fmt.int(subtotal);
            document.getElementById('calc_grandtotal').innerText = LPC.fmt.fcfa(Math.max(0, grandTotal));
        }


        // --- 4. DATA SUBMISSION (WRITE/UPDATE/DELETE) ---

        // Migration 063 · a supplier price change is a price change.
        //
        // Held between the two round trips of a save: the first returns the
        // lines that differ from the supplier's tariff, the buyer declares what
        // each one is, and the second carries those declarations.
        let poPendingDisparities = [];
        let poPendingPayload = null;

        async function submitPO() {
            const supplierId = document.getElementById('po_supplier').value;
            if(!supplierId) return LPC.modal.alert("Veuillez sélectionner un fournisseur.");

            const rows = document.querySelectorAll('.item-row');
            if(rows.length === 0) return LPC.modal.alert("Veuillez ajouter au moins un produit.");

            const payload = {
                action: 'save_po',
                supplier_id: supplierId,
                date: document.getElementById('po_date').value,
                payment_status: document.getElementById('po_payment_status').value,
                discount_amount: document.getElementById('po_discount_amount').value || 0,
                discount_note: document.getElementById('po_discount_note').value,
                delivery_place_id: document.getElementById('po_delivery_place').value || null,
                items: [],
                price_decisions: {}
            };

            let valid = true;
            rows.forEach(row => {
                const pId = row.querySelector('.po-prod-select').value;
                const qty = row.querySelector('.po-qty').value;
                const price = row.querySelector('.po-price').value;
                if(!pId || qty <= 0) valid = false;
                payload.items.push({ product_id: pId, quantity: qty, unit_price: price });
            });

            if(!valid) return LPC.modal.alert("Veuillez remplir correctement toutes les lignes produit.");

            poPendingPayload = payload;
            await postPO(payload);
        }

        async function postPO(payload) {
            const btn = document.querySelector('#poModal button.bg-lpc-dark');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Traitement...';
            btn.disabled = true;

            try {
                const response = await fetch('/api/v1/procurement_controller.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const result = await response.json();

                if (result.status === 'needs_price_confirmation') {
                    // The server found lines priced off the supplier's tariff
                    // and is asking, not deciding. Nothing has been written.
                    poPendingDisparities = result.disparities || [];
                    openPOPriceConfirmModal();
                    return;
                }

                if(result.status === 'success') {
                    closeModal('poPriceConfirmModal');
                    closeModal('poModal');
                    poPendingPayload = null;
                    poPendingDisparities = [];
                    loadTabData();
                    window.open(`/bon_commande.php?token=${result.token}`, '_blank');
                } else LPC.modal.alert("Erreur: " + result.message);
            } catch (e) {
                LPC.modal.alert("Erreur système.");
            } finally {
                btn.innerHTML = '<i class="fas fa-save"></i> Enregistrer & Valider Stock';
                btn.disabled = false;
            }
        }

        function openPOPriceConfirmModal() {
            const body = document.getElementById('po-price-confirm-body');
            body.innerHTML = '';

            poPendingDisparities.forEach(d => {
                const up = Number(d.delta) > 0;
                body.innerHTML += LPC.html`
                    <tr class="hover:bg-gray-50">
                        <td class="py-3 px-6">
                            <p class="font-bold text-gray-900">${d.product_name}</p>
                            <p class="text-[10px] font-bold ${up ? 'text-rose-600' : 'text-emerald-600'}">
                                ${up ? '▲' : '▼'} ${LPC.fmt.int(Math.abs(d.delta))} F par unité
                            </p>
                        </td>
                        <td class="py-3 px-4 text-right font-bold text-gray-500">${LPC.fmt.int(d.current_price)} F</td>
                        <td class="py-3 px-4 text-right font-black text-gray-900">${LPC.fmt.int(d.new_price)} F</td>
                        <td class="py-3 px-4 text-center font-bold text-gray-600">${d.quantity}</td>
                        <td class="py-3 px-6 text-center">
                            <input type="checkbox" class="po-price-confirm-box w-5 h-5 rounded border-gray-300 text-lpc-dark focus:ring-lpc-dark"
                                   data-product-id="${d.product_id}" onchange="updatePOPriceConfirmSummary()">
                        </td>
                    </tr>`;
            });

            // Nothing pre-ticked. A default would decide for the buyer, which
            // is exactly what this modal exists to prevent.
            document.getElementById('po_price_confirm_all').checked = false;
            updatePOPriceConfirmSummary();
            document.getElementById('poPriceConfirmModal').classList.remove('hidden');
        }

        function togglePOPriceConfirmAll(checked) {
            document.querySelectorAll('.po-price-confirm-box').forEach(b => { b.checked = checked; });
            updatePOPriceConfirmSummary();
        }

        function updatePOPriceConfirmSummary() {
            const boxes = Array.from(document.querySelectorAll('.po-price-confirm-box'));
            const confirmed = boxes.filter(b => b.checked).length;
            const remise = boxes.length - confirmed;

            const all = document.getElementById('po_price_confirm_all');
            if (all) all.checked = boxes.length > 0 && confirmed === boxes.length;

            document.getElementById('po_price_confirm_summary').textContent =
                `${confirmed} nouveau(x) prix · ${remise} remise(s) ponctuelle(s)`;
        }

        async function submitPOPriceDecisions() {
            if (!poPendingPayload) return;

            const decisions = {};
            document.querySelectorAll('.po-price-confirm-box').forEach(b => {
                decisions[b.getAttribute('data-product-id')] = b.checked;
            });
            poPendingPayload.price_decisions = decisions;

            // A declined line becomes a remise, and the server requires a motif
            // for any remise. Checked here so the buyer is not bounced between
            // two modals to discover it.
            const anyRemise = Object.values(decisions).some(v => v === false);
            const note = document.getElementById('po_discount_note').value.trim();
            if (anyRemise && !note) {
                closeModal('poPriceConfirmModal');
                document.getElementById('po_discount_note').focus();
                LPC.toast("Indiquez le motif de la remise avant de valider.", 'warning');
                return;
            }
            poPendingPayload.discount_note = note;

            await postPO(poPendingPayload);
        }

        // submitOverhead() removed 31 July 2026. The Frais Généraux tab is gone
        // from this page; expense creation lives in modules/accounting/expenses.php
        // and its API at api/v1/expenses_controller.php.

        /**
         * Cancel a purchase order.
         *
         * The wording matters here. The old prompt said the action was
         * "irréversible" — and under the old hard-delete endpoint it was: the
         * order and its lines were destroyed while its journal entries and
         * ristourne credits stayed behind, orphaned. The server now reverses
         * instead of deleting, so the honest message is that the order is kept
         * and its accounting entries are extourned.
         */
        async function cancelPurchaseOrder(id, reference) {
            const ok = await LPC.modal.confirm(
                `Annuler la commande ${reference} ?\n\n` +
                `· Les écritures comptables seront extournées (contre-passées), pas supprimées.\n` +
                `· Le stock reçu sera retiré et le CUMP recalculé.\n` +
                `· Toute ristourne gagnée ou utilisée sur cette commande sera reprise.\n\n` +
                `La commande restera visible, marquée « Annulée ».`
            );
            if (!ok) return;

            const fd = new FormData();
            fd.append('action', 'cancel_inventory');
            fd.append('id', id);

            try {
                const response = await fetch('/api/v1/procurement_controller.php', { method: 'POST', body: fd });
                const result = await response.json();
                if (result.status === 'success') {
                    loadTabData();
                    if (result.message) LPC.modal.alert(result.message);
                } else LPC.modal.alert("Erreur: " + result.message);
            } catch (e) { LPC.modal.alert("Erreur système."); }
        }

        // deleteRecord() was only called from the OPEX tab's row actions —
        // removed with the tab. Purchase orders are cancelled through
        // cancelPurchaseOrder() (soft-reversal), never deleted, so nothing on
        // this page needs a generic delete helper anymore.
    