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
        let currentTab = 'inventory'; // 'inventory' or 'overheads'
        let moduleData = [];
        let metaData = { suppliers: [], products: [] };
        
        let sdpSupplierId = null; // Stored to quickly identify SDP logic
        let currentSdpBalance = 0;

        const config = {
            inventory: {
                api: 'purchase_orders',
                btnText: "Nouveau Bon de Commande",
                columns: [
                    { key: 'date', label: 'Date', render: v => `<span class="text-xs font-bold text-gray-500">${new Date(v).toLocaleDateString('fr-FR')}</span>` },
                    { key: 'reference', label: 'Référence PO', render: v => `<span class="font-black text-gray-900 bg-gray-100 px-2 py-1 rounded border border-gray-200">${v}</span>` },
                    { key: 'supplier_name', label: 'Fournisseur', render: v => `<span class="font-bold text-gray-700">${v}</span>` },
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
                    { id: 'kpi_inv_discount', label: 'Remises Obtenues', icon: 'fa-tags', color: 'text-emerald-600', bg: 'bg-emerald-50' },
                    { id: 'kpi_inv_count', label: 'Volume Commandes', icon: 'fa-boxes', color: 'text-blue-600', bg: 'bg-blue-50' }
                ]
            },
            overheads: {
                api: 'overheads',
                btnText: "Saisir Frais Généraux",
                columns: [
                    { key: 'date', label: 'Date', render: v => `<span class="text-xs font-bold text-gray-500">${new Date(v).toLocaleDateString('fr-FR')}</span>` },
                    { key: 'reference', label: 'Ref', render: v => `<span class="text-xs font-mono text-gray-400">${v}</span>` },
                    { key: 'title', label: 'Désignation de la Dépense', render: v => `<span class="font-bold text-gray-900">${v}</span>` },
                    { key: 'category', label: 'Catégorie', render: v => `<span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-[10px] uppercase font-bold tracking-wider">${v}</span>` },
                    { key: 'payment_status', label: 'Statut', render: v => v === 'paid' ? '<span class="text-green-500 text-xs font-bold"><i class="fas fa-check-circle mr-1"></i>Payé</span>' : '<span class="text-red-500 text-xs font-bold"><i class="fas fa-exclamation-circle mr-1"></i>Impayé</span>' },
                    { key: 'amount', label: 'Montant', render: v => `<span class="font-black text-gray-900">${LPC.fmt.int(v)} FCFA</span>` }
                ],
                kpis: [
                    { id: 'kpi_oh_total', label: 'Total OPEX (Période)', icon: 'fa-calculator', color: 'text-gray-900', bg: 'bg-gray-200' },
                    { id: 'kpi_oh_log', label: 'Dépenses Logistique', icon: 'fa-truck', color: 'text-amber-600', bg: 'bg-amber-50' },
                    { id: 'kpi_oh_unpaid', label: 'Factures en Attente', icon: 'fa-clock', color: 'text-red-600', bg: 'bg-red-50' },
                    { id: 'kpi_oh_count', label: 'Nbre de Lignes', icon: 'fa-receipt', color: 'text-blue-600', bg: 'bg-blue-50' }
                ]
            }
        };

        // --- 2. INITIALIZATION & DATA FETCHING ---
        document.addEventListener('DOMContentLoaded', async () => {
            document.getElementById('po_date').valueAsDate = new Date();
            document.getElementById('oh_date').valueAsDate = new Date();
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
                        // Identify SDP for the Ristourne Logic
                        if(s.name.toLowerCase().includes('source du pays') || s.name.toLowerCase().includes('sdp')) {
                            sdpSupplierId = s.id;
                        }
                    });
                    
                    // Show SDP button globally if SDP exists in DB
                    if(sdpSupplierId) document.getElementById('btn-sdp-ristourne').classList.remove('hidden');
                }
            } catch (e) { console.error("Failed to load metadata", e); }
        }

        async function switchTab(tab) {
            currentTab = tab;
            const c = config[currentTab];

            document.querySelectorAll('.tab-link').forEach(el => {
                el.classList.remove('border-lpc-dark', 'text-lpc-dark', 'font-black');
                el.classList.add('border-transparent', 'text-gray-400', 'font-bold');
            });
            const activeLink = document.getElementById(`tab-${tab}`);
            activeLink.classList.remove('border-transparent', 'text-gray-400', 'font-bold');
            activeLink.classList.add('border-lpc-dark', 'text-lpc-dark', 'font-black');

            document.getElementById('btn-action-text').innerText = c.btnText;
            
            // Manage SDP Ristourne Button visibility based on tab
            const sdpBtn = document.getElementById('btn-sdp-ristourne');
            if(sdpSupplierId && tab === 'inventory') sdpBtn.classList.remove('hidden');
            else sdpBtn.classList.add('hidden');

            const ribbon = document.getElementById('kpi-ribbon');
            ribbon.innerHTML = '';
            c.kpis.forEach(kpi => {
                ribbon.innerHTML += LPC.html`
                    <div onclick="showKPIDetails('${kpi.id}')" class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between cursor-pointer hover:shadow-md hover:-translate-y-1 transition-all">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">${kpi.label}</p>
                            <h3 class="text-2xl font-black text-gray-900 mt-1" id="${kpi.id}">...</h3>
                        </div>
                        <div class="w-12 h-12 ${kpi.bg} ${kpi.color} rounded-xl flex items-center justify-center text-xl">
                            <i class="fas ${kpi.icon}"></i>
                        </div>
                    </div>`;
            });

            const thead = document.getElementById('table-head');
            let thHTML = '<tr>';
            c.columns.forEach(col => thHTML += `<th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">${col.label}</th>`);
            thHTML += `<th class="py-4 px-6 text-right text-[10px] uppercase text-gray-400 font-black tracking-widest">Actions</th></tr>`;
            thead.innerHTML = thHTML;

            await loadTabData();
        }

        async function loadTabData() {
            const range = getActiveDateRange();
            if (!range) return; // Prevent loading if custom range is half-empty

            const tbody = document.getElementById('table-body');
            tbody.innerHTML = LPC.html`<tr><td colspan="10" class="py-8 text-center text-gray-400 font-bold text-sm animate-pulse">Connexion sécurisée en cours...</td></tr>`;
            
            try {
                const response = await fetch(`/api/v1/procurement_controller.php?action=read&tab=${currentTab}&start=${range.start}&end=${range.end}`);
                const result = await response.json();
                
                if (result.status === 'success') {
                    moduleData = result.data.table;
                    if(result.data.kpis) {
                        for (const [key, value] of Object.entries(result.data.kpis)) {
                            const el = document.getElementById(key);
                            if(el) el.innerText = value;
                        }
                    }
                    renderTable(moduleData);
                } else throw new Error(result.message);
            } catch (error) {
                tbody.innerHTML = LPC.html`<tr><td colspan="10" class="py-8 text-center text-red-500 font-bold">Erreur Serveur: ${error.message}</td></tr>`;
            }
        }
        
        function showKPIDetails(kpiId) {
            const titleEl = document.getElementById('kpi-detail-title');
            const tbody = document.getElementById('kpi-detail-body');
            tbody.innerHTML = '';
            let filteredData = [];

            // Filter logic based on which KPI was clicked
            if (currentTab === 'inventory') {
                if (kpiId === 'kpi_inv_total' || kpiId === 'kpi_inv_count') {
                    titleEl.innerText = "Toutes les Commandes (Période)";
                    filteredData = moduleData; 
                } else if (kpiId === 'kpi_inv_unpaid') {
                    titleEl.innerText = "Dettes Fournisseurs (Non Payées)";
                    filteredData = moduleData.filter(x => x.payment_status !== 'paid');
                } else if (kpiId === 'kpi_inv_discount') {
                    titleEl.innerText = "Détails des Achats";
                    filteredData = moduleData; // Fallback
                }
                
                filteredData.forEach(row => {
                    tbody.innerHTML += LPC.html`<tr>
                        <td class="py-3 px-4">${new Date(row.date).toLocaleDateString('fr-FR')}</td>
                        <td class="py-3 px-4 font-mono text-xs">${row.reference}</td>
                        <td class="py-3 px-4 font-bold text-gray-800">${row.supplier_name}</td>
                        <td class="py-3 px-4 text-right font-black text-lpc-dark">${LPC.fmt.int(row.total_amount)} F</td>
                    </tr>`;
                });
            } else {
                 if (kpiId === 'kpi_oh_unpaid') {
                     titleEl.innerText = "Factures OPEX Impayées";
                     filteredData = moduleData.filter(x => x.payment_status !== 'paid');
                 } else if (kpiId === 'kpi_oh_log') {
                     titleEl.innerText = "Dépenses Logistiques";
                     filteredData = moduleData.filter(x => x.category === 'Logistique');
                 } else {
                     titleEl.innerText = "Toutes les Dépenses OPEX";
                     filteredData = moduleData;
                 }
                 
                 filteredData.forEach(row => {
                    tbody.innerHTML += LPC.html`<tr>
                        <td class="py-3 px-4">${new Date(row.date).toLocaleDateString('fr-FR')}</td>
                        <td class="py-3 px-4 font-mono text-xs">${row.reference}</td>
                        <td class="py-3 px-4 font-bold text-gray-800">${row.title} <span class="ml-2 px-2 py-0.5 bg-gray-100 text-gray-500 rounded text-[9px] uppercase tracking-wider">${row.category}</span></td>
                        <td class="py-3 px-4 text-right font-black text-lpc-dark">${LPC.fmt.int(row.amount)} F</td>
                    </tr>`;
                });
            }
            
            if(filteredData.length === 0) {
                tbody.innerHTML = LPC.html`<tr><td colspan="4" class="py-6 text-center text-gray-400 font-bold italic">Aucune donnée pour ce critère.</td></tr>`;
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

        function renderTable(dataArray) {
            const tbody = document.getElementById('table-body');
            tbody.innerHTML = '';

            if (dataArray.length === 0) {
                const period = describeActivePeriod();
                const showWidenButton = document.getElementById('kpi_period_type').value !== 'all';

                // Name the filter and offer the way out. Widening the period is
                // one click, so nobody has to guess whether their records still
                // exist.
                const widen = showWidenButton ? LPC.raw(`
                    <button onclick="widenToAllHistory()"
                            class="mt-4 px-4 py-2 bg-lpc-dark text-white rounded-lg text-xs font-black uppercase tracking-widest hover:opacity-90 transition-opacity">
                        <i class="fas fa-history mr-2"></i>Voir tout l'historique
                    </button>`) : '';

                tbody.innerHTML = LPC.html`
                    <tr><td colspan="10" class="py-12 text-center">
                        <p class="text-gray-500 font-bold text-sm">Aucune commande pour ${period}.</p>
                        <p class="text-gray-400 text-xs mt-1">Vos données ne sont pas perdues — seul le filtre de période est en cause.</p>
                        ${widen}
                    </td></tr>`;
                return;
            }

            dataArray.forEach(row => {
                const cancelled = row.status === 'cancelled';
                let tr = `<tr class="${cancelled ? 'opacity-50 ' : ''}hover:bg-gray-50 transition-colors">`;
                config[currentTab].columns.forEach(col => tr += `<td class="py-4 px-6 border-b border-gray-50">${col.render(row[col.key], row)}</td>`);

                let actions = '';
                if(currentTab === 'inventory') {
                    // Already-cancelled orders keep their PDF link but lose the
                    // cancel button — the server rejects a second cancellation
                    // anyway, and offering an action that can only fail is worse
                    // than not offering it.
                    const cancelBtn = cancelled ? '' : `
                        <button onclick="cancelPurchaseOrder(${row.id}, '${String(row.reference).replace(/'/g, "\\'")}')"
                                class="text-red-400 hover:text-red-600 p-2" title="Annuler Commande" aria-label="Annuler Commande"><i class="fas fa-ban"></i></button>`;
                    actions = `
                        <a href="/bon_commande.php?token=${row.token}" target="_blank" class="text-lpc-dark hover:text-green-700 bg-green-50 p-2 rounded-lg mr-2" title="Voir PDF"><i class="fas fa-file-pdf"></i></a>${cancelBtn}`;
                } else {
                    actions = `
                        <button onclick="openActionModal(${row.id})" class="text-gray-400 hover:text-gray-900 p-2 mr-2" title="Modifier" aria-label="Modifier"><i class="fas fa-edit"></i></button>
                        <button onclick="deleteRecord(${row.id})" class="text-red-400 hover:text-red-600 p-2"><i class="fas fa-trash-alt"></i></button>`;
                }

                tr += `<td class="py-4 px-6 border-b border-gray-50 text-right whitespace-nowrap">${actions}</td></tr>`;
                tbody.innerHTML += tr;
            });
        }

        function filterData() {
            const q = document.getElementById('search-input').value.toLowerCase();
            renderTable(moduleData.filter(i => Object.values(i).some(v => String(v).toLowerCase().includes(q))));
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
            if (currentTab === 'inventory') {
                document.getElementById('form-po').reset();
                document.getElementById('po_date').valueAsDate = new Date();
                document.getElementById('po_id').value = '';
                document.getElementById('po-lines-container').innerHTML = '';
                document.getElementById('sdp-rebate-info').classList.add('hidden'); // Hide rebate hint by default
                addPOLine(); 
                calculatePOTotals();
                
                document.getElementById('poModal').classList.remove('hidden');
            } else {
                document.getElementById('form-overhead').reset();
                document.getElementById('oh_id').value = id || '';
                document.getElementById('oh-modal-title').innerText = id ? 'Modifier Dépense' : 'Enregistrer une Dépense';
                document.getElementById('oh_date').valueAsDate = new Date();
                
                if (id) {
                    const oh = moduleData.find(x => x.id == id);
                    document.getElementById('oh_title').value = oh.title;
                    document.getElementById('oh_category').value = oh.category;
                    document.getElementById('oh_amount').value = oh.amount;
                    document.getElementById('oh_date').value = oh.date;
                    document.getElementById('oh_payment_status').value = oh.payment_status;
                }
                document.getElementById('overheadModal').classList.remove('hidden');
            }
        }

        function closeModal(modalId) { document.getElementById(modalId).classList.add('hidden'); }

        // SDP Ristourne Logic (Check Balance when selecting supplier)
        async function checkSupplierRebate(selectElement) {
            const supplierId = selectElement.value;
            const rebateInfoDiv = document.getElementById('sdp-rebate-info');
            
            if(supplierId && sdpSupplierId && supplierId == sdpSupplierId) {
                // Fetch dynamic balance
                try {
                    const res = await fetch(`/api/v1/procurement_controller.php?action=get_ristourne_data&supplier_id=${sdpSupplierId}`);
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

        async function openRistourneModal() {
            if(!sdpSupplierId) return LPC.modal.alert("Source du Pays n'est pas configuré.");
            
            try {
                const res = await fetch(`/api/v1/procurement_controller.php?action=get_ristourne_data&supplier_id=${sdpSupplierId}`);
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
                        tbody.innerHTML = LPC.html`<tr><td colspan="4" class="py-6 text-center text-gray-400">Aucun historique disponible.</td></tr>`;
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
                            const color = isReversed
                                ? 'text-gray-400 line-through'
                                : (isAccrual ? 'text-emerald-600' : 'text-rose-600');
                            const prefix = isAccrual ? '+' : '-';
                            const rowClass = isReversed
                                ? 'opacity-60 hover:bg-gray-50 transition-colors'
                                : 'hover:bg-gray-50 transition-colors';

                            tbody.innerHTML += LPC.html`
                                <tr class="${rowClass}">
                                    <td class="py-4 px-6 text-xs text-gray-500 font-bold">${LPC.fmt.date(l.date)}<br><span class="text-[10px] text-amber-600 font-mono tracking-wide">${l.reference}</span></td>
                                    <td class="py-4 px-6">${badge}</td>
                                    <td class="py-4 px-6 text-xs font-bold text-gray-700">${l.notes}</td>
                                    <td class="py-4 px-6 text-right font-black ${color}">${prefix} ${LPC.fmt.int(l.amount)}</td>
                                </tr>
                            `;
                        });
                    }

                    document.getElementById('ristourneModal').classList.remove('hidden');
                }
            } catch(e) {
                LPC.modal.alert("Impossible de charger les données Ristourne.");
            }
        }


        function addPOLine() {
            const container = document.getElementById('po-lines-container');
            const rowId = Date.now();
            
            let prodOptions = '<option value="">Choisir un produit...</option>';
            metaData.products.forEach(p => {
                prodOptions += LPC.html`<option value="${p.id}" data-price="${p.base_price}">${p.name} (${p.format || 'Standard'})</option>`;
            });

            const tr = document.createElement('tr');
            tr.className = "item-row hover:bg-gray-100 transition-colors";
            tr.innerHTML = LPC.html`
                <td class="p-1 border-r border-gray-100">
                    <select class="po-prod-select seamless-input p-2 font-bold text-gray-800" onchange="autoFillPrice(this, ${rowId})" required>
                        ${LPC.raw(prodOptions)}
                    </select>
                </td>
                <td class="p-1 border-r border-gray-100">
                    <input type="number" class="po-qty seamless-input p-2 text-center font-bold text-gray-900" value="1" min="1" oninput="calcRow(${rowId})" id="qty_${rowId}" required>
                </td>
                <td class="p-1 border-r border-gray-100">
                    <input type="number" class="po-price seamless-input p-2 text-right font-bold text-gray-900" value="0" min="0" oninput="calcRow(${rowId})" id="price_${rowId}" required>
                </td>
                <td class="p-1 border-r border-gray-100 text-right pr-4 font-black text-gray-900" id="total_${rowId}">
                    0
                </td>
                <td class="p-1 text-center">
                    <button type="button" onclick="this.closest('tr').remove(); calculatePOTotals();" class="text-red-300 hover:text-red-500 p-1"><i class="fas fa-times"></i></button>
                </td>
            `;
            container.appendChild(tr);
        }

        function autoFillPrice(selectElement, rowId) {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const basePrice = selectedOption.getAttribute('data-price') || 0;
            document.getElementById(`price_${rowId}`).value = basePrice;
            calcRow(rowId);
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
                items: []
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
                
                if(result.status === 'success') {
                    closeModal('poModal');
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

        async function submitOverhead() {
            const formElement = document.getElementById('form-overhead');
            if (!formElement.reportValidity()) return;

            const formData = new FormData(formElement);
            formData.append('action', 'save_overhead');

            try {
                const response = await fetch('/api/v1/procurement_controller.php', { method: 'POST', body: formData });
                const result = await response.json();
                
                if(result.status === 'success') {
                    closeModal('overheadModal');
                    loadTabData();
                } else LPC.modal.alert("Erreur: " + result.message);
            } catch (e) { LPC.modal.alert("Erreur système."); }
        }

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

        /** Overheads only — they carry no stock and no reversal chain. */
        async function deleteRecord(id) {
            if(!(await LPC.modal.confirm("⚠️ ATTENTION : Voulez-vous vraiment supprimer cet enregistrement ? Cette action est irréversible et impactera la comptabilité."))) return;

            const fd = new FormData();
            fd.append('action', `delete_${currentTab}`);
            fd.append('id', id);

            try {
                const response = await fetch('/api/v1/procurement_controller.php', { method: 'POST', body: fd });
                const result = await response.json();
                if(result.status === 'success') loadTabData();
                else LPC.modal.alert("Erreur: " + result.message);
            } catch (e) { LPC.modal.alert("Erreur système."); }
        }
    