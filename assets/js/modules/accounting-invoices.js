/**
 * assets/js/modules/accounting-invoices.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/accounting/invoices.php (Sprint 6 D2).
 *
 * Original block was ~33,563 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        /** Global State Context */
        let currentTab = 'dashboard';
        let globalData = {};
        let charts = {};
        
        // Batching State for Tab 2
        let selectedDeliveries = [];
        let activeBatchClientId = null;
        let activeBatchClientName = "";
        let pendingCashInBatch = 0;

        /**
         * Toast notifications — delegated to LPC.toast (assets/js/lpc-toast.js).
         *
         * The local implementation that used to live here never removed
         * anything: it swapped `animate-toast-enter` for `animate-toast-leave`
         * and waited for an `animationend` that could not fire, because neither
         * class was ever defined in the stylesheet. Toasts accumulated in the
         * corner until the page was reloaded. The shared version removes on a
         * timer (5 s, 8 s for errors), pauses while hovered, and ships a close
         * button.
         */
        function showToast(message, type = 'success') {
            return LPC.toast(message, type);
        }

        /** Initialization Hook */
        window.onload = () => {
            // Setup defaults for Modals
            document.getElementById('gen_date').valueAsDate = new Date();
            let dueDate = new Date();
            dueDate.setDate(dueDate.getDate() + 30); // Default standard Net 30
            document.getElementById('gen_due_date').valueAsDate = dueDate;
            
            // Navigate to default view. A #hash lets another page deep-link
            // straight to a register — the sales dashboard's "encours en
            // retard" card arrives at #invoices_payments. Whitelisted, so an
            // arbitrary fragment can never reach switchTab.
            const VALID_TABS = ['dashboard', 'to_invoice', 'invoices_payments', 'wallets'];
            const hash = (window.location.hash || '').replace('#', '');
            switchTab(VALID_TABS.includes(hash) ? hash : 'dashboard');
            
            // Pre-fetch clients list for payment UI
            fetchClientsList();
        };

        /** Tab Controller & Router */
        async function switchTab(tab) {
            currentTab = tab;

            // Reset UI States
            document.querySelectorAll('.tab-link').forEach(el => {
                el.classList.remove('border-lpc-dark', 'text-lpc-dark', 'font-black');
                el.classList.add('border-transparent', 'text-gray-400', 'font-bold');
            });
            document.getElementById(`tab-${tab}`).classList.remove('border-transparent', 'text-gray-400', 'font-bold');
            document.getElementById(`tab-${tab}`).classList.add('border-lpc-dark', 'text-lpc-dark', 'font-black');

            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById(`content-${tab}`).classList.add('active');

            await fetchTabData(tab);
        }

        /**
         * The ultimate safety wrapper for parsing JSON. Module-scoped (not
         * local to fetchTabData) because loadToInvoice() — triggered on its
         * own by search/pagination, not only via fetchTabData — needs it too.
         */
        const fetchJsonSafely = async (url) => {
            const res = await fetch(url);
            const text = await res.text(); // Get raw text first
            try {
                return JSON.parse(text);
            } catch(e) {
                console.error("🔥 CRITICAL PHP ERROR ON URL:", url);
                console.error("🔥 THE SERVER RETURNED HTML INSTEAD OF JSON:", text);
                throw new Error("Erreur fatale PHP. Appuyez sur F12 pour lire l'erreur exacte dans la console.");
            }
        };

        /** Core Fetcher logic handling combined tabs natively with Crash-Proof JSON Parsing */
        async function fetchTabData(tab) {
            try {
                // SPECIAL CASE: BL non facturés carries its own sort / filter /
                // page state, so it builds its own query string.
                if (tab === 'to_invoice') {
                    await loadToInvoice();
                    return;
                }

                // SPECIAL CASE: Combined Tab 3 mapping to 2 distinct backend endpoints
                if(tab === 'invoices_payments') {
                    const dataInv = await fetchJsonSafely(`/api/v1/invoices_controller.php?action=read&tab=invoices`);
                    if(dataInv.status === 'success') {
                        globalData['invoices'] = dataInv.data;
                        renderInvoices(dataInv.data.invoices);
                        updateGlobalBadges(dataInv.data.badges);
                    }
                    
                    const dataPay = await fetchJsonSafely(`/api/v1/invoices_controller.php?action=read&tab=payments`);
                    if(dataPay.status === 'success') {
                        globalData['payments'] = dataPay.data;
                        renderPayments(dataPay.data.payments);
                    }
                    return;
                }

                // STANDARD CASE: Direct 1-to-1 mapping
                const result = await fetchJsonSafely(`/api/v1/invoices_controller.php?action=read&tab=${tab}`);

                if (result.status === 'success') {
                    globalData[tab] = result.data;
                    
                    if(tab === 'dashboard') renderDashboard(result.data);
                    // to_invoice owns its own request: it carries sort, filter
                    // and page state the generic ?tab= fetch above knows nothing
                    // about. Fetching it twice is avoided by the early return.
                    if(tab === 'wallets') renderWallets(result.data.wallets);

                    updateGlobalBadges(result.data.badges);
                } else {
                    showToast(result.message, "error");
                }
            } catch(e) { 
                console.error("Network or parsing error", e);
                showToast(e.message, "error");
            }
        }

        /** Global UI Badge Updater */
        function updateGlobalBadges(badges) {
            if(!badges) return;
            
            const bInv = document.getElementById('badge-to-invoice');
            if(badges.to_invoice > 0) { 
                bInv.innerText = badges.to_invoice; 
                bInv.classList.remove('hidden'); 
            } else { bInv.classList.add('hidden'); }

            const bCash = document.getElementById('global-cash-alert');
            if(badges.pending_cash > 0) {
                document.getElementById('cash-alert-count').innerText = badges.pending_cash;
                bCash.classList.remove('hidden');
                bCash.classList.add('flex');
            } else {
                bCash.classList.add('hidden');
                bCash.classList.remove('flex');
            }
        }

        /** ================= RENDER: DASHBOARD ================= */
        function renderDashboard(data) {
            // Apply numeric formats dynamically
            const fmt = (num) => LPC.fmt.fcfa(num);
            
            document.getElementById('kpi_total_ar').innerText = fmt(data.kpis.total_ar);
            document.getElementById('kpi_overdue').innerText = fmt(data.kpis.overdue);
            document.getElementById('kpi_wallets').innerText = fmt(data.kpis.wallets);
            document.getElementById('kpi_pending_cash').innerText = fmt(data.kpis.pending_cash);

            // Progress Bar Math
            const total = parseFloat(data.kpis.total_ar) || 1; 
            const unpaid = parseFloat(data.kpis.unpaid_ar) || 0;
            const paid = total - unpaid;
            
            // Timeout allows the CSS transition to play beautifully
            setTimeout(() => {
                document.getElementById('bar_paid').style.width = `${(paid/total)*100}%`;
                document.getElementById('bar_unpaid').style.width = `${(unpaid/total)*100}%`;
            }, 100);

            // Render ChartJS Aging Matrix
            if(charts.aging) charts.aging.destroy();
            const ctx = document.getElementById('agingChart').getContext('2d');
            charts.aging = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['En Cours (Non échu)', '1 - 30 Jours', '31 - 60 Jours', '90+ Jours (Critique)'],
                    datasets: [{
                        label: 'Montant Dû (FCFA)',
                        data: [data.aging.current, data.aging.d30, data.aging.d60, data.aging.d90],
                        backgroundColor: ['#8CC63F', '#facc15', '#f97316', '#ef4444'],
                        borderWidth: 0,
                        borderRadius: 6,
                        barPercentage: 0.6
                    }]
                },
                options: { 
                    maintainAspectRatio: false, 
                    plugins: { 
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return LPC.fmt.fcfa(context.raw);
                                }
                            }
                        }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            // `color` dropped (was '#f1f5f9'); it comes from
                            // Chart.defaults via lpc-chart-theme.js so it
                            // follows the theme.
                            grid: { drawBorder: false },
                            ticks: { font: { family: 'Inter', size: 10 }, color: '#94a3b8' }
                        },
                        x: { 
                            grid: { display: false },
                            ticks: { font: { family: 'Inter', size: 10, weight: 'bold' }, color: '#64748b' }
                        }
                    }
                }
            });

            // Populate Debtors Risk Table
            const tbody = document.getElementById('dash-debtors-body');
            tbody.innerHTML = '';
            if(data.top_debtors.length === 0) {
                tbody.innerHTML = LPC.html`<tr><td colspan="2" class="py-6 text-center text-gray-400 font-bold text-xs">Aucun débiteur identifié</td></tr>`;
            }
            data.top_debtors.forEach(d => {
                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-red-50/30 transition-colors">
                        <td class="py-3.5 px-6 font-black text-gray-800 text-xs">${d.client_name}</td>
                        <td class="py-3.5 px-6 text-right font-black text-red-600">${fmt(d.total_debt)}</td>
                    </tr>`;
            });
        }

        /** ================= BL NON FACTURÉS — query state =================
         * Sorting, filtering and paging are all resolved by the server. The
         * page previously sorted nothing (the header icons had no handler) and
         * filtered by hiding DOM rows, which stops being true the moment the
         * list is paged: the row you are looking for may not be rendered yet.
         */
        const toInvoiceQuery = { page: 1, per_page: 50, sort: 'date', dir: 'ASC', client_id: '', q: '' };
        let toInvoiceSearchTimer = null;

        function toInvoiceSort(key) {
            // Same column again flips direction; a new column starts ascending.
            if (toInvoiceQuery.sort === key) {
                toInvoiceQuery.dir = toInvoiceQuery.dir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                toInvoiceQuery.sort = key;
                toInvoiceQuery.dir = 'ASC';
            }
            toInvoiceQuery.page = 1;
            loadToInvoice();
        }

        function toInvoiceSetClient(clientId) {
            toInvoiceQuery.client_id = clientId || '';
            toInvoiceQuery.page = 1;
            // Selecting a different client while a batch is open would produce a
            // batch spanning two clients, which the invoice cannot represent.
            if (selectedDeliveries.length > 0 && String(activeBatchClientId) !== String(clientId)) {
                resetBatching();
            }
            loadToInvoice();
        }

        function toInvoiceSearch(term) {
            clearTimeout(toInvoiceSearchTimer);
            toInvoiceSearchTimer = setTimeout(() => {
                toInvoiceQuery.q = term.trim();
                toInvoiceQuery.page = 1;
                loadToInvoice();
            }, 300);   // debounced — one request per pause, not per keystroke
        }

        function toInvoicePage(delta) {
            const p = globalData.to_invoice && globalData.to_invoice.pagination;
            if (!p) return;
            const next = p.page + delta;
            if (next < 1 || next > p.total_pages) return;
            toInvoiceQuery.page = next;
            loadToInvoice();
        }

        async function loadToInvoice() {
            const params = new URLSearchParams({
                action: 'read',
                tab: 'to_invoice',
                page: toInvoiceQuery.page,
                per_page: toInvoiceQuery.per_page,
                sort: toInvoiceQuery.sort,
                dir: toInvoiceQuery.dir,
            });
            if (toInvoiceQuery.client_id) params.set('client_id', toInvoiceQuery.client_id);
            if (toInvoiceQuery.q) params.set('q', toInvoiceQuery.q);

            try {
                const result = await fetchJsonSafely(`/api/v1/invoices_controller.php?${params.toString()}`);
                if (result.status !== 'success') {
                    showToast(result.message || 'Chargement impossible.', 'error');
                    return;
                }
                globalData.to_invoice = result.data;
                renderToInvoice(result.data.deliveries);
                renderToInvoiceClientFilter(result.data.filter_clients);
                renderToInvoicePagination(result.data.pagination);
                updateSortIndicators();
                updateGlobalBadges(result.data.badges);
            } catch(e) {
                console.error("Network or parsing error", e);
                showToast(e.message, "error");
            }
        }

        /** Reflect the active sort in the header icons. */
        function updateSortIndicators() {
            document.querySelectorAll('#content-to_invoice .th-sort').forEach(th => {
                const icon = th.querySelector('i');
                if (!icon) return;
                const active = th.getAttribute('data-sort') === toInvoiceQuery.sort;
                icon.className = active
                    ? `fas fa-sort-${toInvoiceQuery.dir === 'ASC' ? 'up' : 'down'} ml-1 text-gray-700`
                    : 'fas fa-sort ml-1';
                th.classList.toggle('text-gray-700', active);
                th.classList.toggle('text-gray-400', !active);
            });
        }

        function renderToInvoiceClientFilter(clients) {
            const sel = document.getElementById('filter-to-invoice-client');
            if (!sel || !Array.isArray(clients)) return;

            // Preserve the choice across reloads; the option list is rebuilt
            // each time because a client drops off once fully invoiced.
            const current = toInvoiceQuery.client_id;
            sel.innerHTML = LPC.html`<option value="">Tous les clients</option>`
                + clients.map(c => LPC.html`<option value="${c.id}">${c.name} (${c.pending})</option>`).join('');
            sel.value = current;
        }

        function renderToInvoicePagination(p) {
            const bar = document.getElementById('to-invoice-pagination');
            if (!bar) return;
            if (!p || p.total === 0) { bar.classList.add('hidden'); return; }

            bar.classList.remove('hidden');
            const from = (p.page - 1) * p.per_page + 1;
            const to   = Math.min(p.page * p.per_page, p.total);
            document.getElementById('to-invoice-page-info').innerText =
                `${from}–${to} sur ${p.total} BL · page ${p.page}/${p.total_pages}`;
            document.getElementById('to-invoice-prev').disabled = !p.has_prev;
            document.getElementById('to-invoice-next').disabled = !p.has_next;
        }

        /** ================= RENDER: TO INVOICE ================= */
        function renderToInvoice(deliveries) {
            const tbody = document.getElementById('table-body-to-invoice');
            tbody.innerHTML = '';
            
            if(deliveries.length === 0) {
                // "Nothing left to invoice" and "nothing matched your filter"
                // are very different messages to show someone.
                const filtered = toInvoiceQuery.q !== '' || toInvoiceQuery.client_id !== '';
                tbody.innerHTML = filtered
                    ? LPC.html`<tr><td colspan="6" class="py-20 text-center text-gray-400 font-bold"><i class="fas fa-filter text-5xl mb-4 text-gray-200 block"></i>Aucun BL ne correspond à ce filtre.</td></tr>`
                    : LPC.html`<tr><td colspan="6" class="py-20 text-center text-gray-400 font-bold"><i class="fas fa-check-circle text-5xl mb-4 text-gray-200 block"></i>Félicitations, tous les bons de livraison sont facturés.</td></tr>`;
                updateBatchFooterUI();
                return;
            }

            deliveries.forEach(del => {
                // Determine row state based on batch selection logic
                const isLocked = activeBatchClientId !== null && activeBatchClientId != del.client_id;
                const isChecked = selectedDeliveries.some(d => d.id == del.id);
                
                const trClass = isLocked ? 'disabled-row transition-opacity' : 'hover:bg-blue-50/40 transition-colors cursor-pointer';

                // The BL reference travels with the click. It used to be looked
                // up later from the rendered list, which breaks the moment a
                // selected row is on a page that is no longer displayed.
                const args = `${del.id}, ${del.client_id}, '${String(del.client_name).replace(/'/g, "\\'")}', `
                           + `${del.sales_subtotal}, ${del.payment_collected}, '${String(del.bl_ref).replace(/'/g, "\\'")}'`;

                tbody.innerHTML += LPC.html`
                    <tr class="${trClass}" onclick="toggleDeliverySelection(${LPC.raw(args)})">
                        <td class="py-4 px-6 text-center" onclick="event.stopPropagation()">
                            <input type="checkbox" class="checkbox-custom" id="chk_${del.id}" ${isChecked ? 'checked' : ''} ${isLocked ? 'disabled' : ''} onchange="toggleDeliverySelection(${LPC.raw(args)})">
                        </td>
                        <td class="py-4 px-6 text-xs font-bold text-gray-500">${new Date(del.date).toLocaleDateString('fr-FR')}</td>
                        <td class="py-4 px-6 font-black text-gray-900">${del.client_name}</td>
                        <td class="py-4 px-6 font-black text-blue-700">${del.bl_ref}</td>
                        <td class="py-4 px-6 text-xs font-bold text-gray-400">${del.so_ref}</td>
                        <td class="py-4 px-6 text-right font-black ${del.payment_collected > 0 ? 'text-emerald-600' : 'text-gray-300'}">
                            ${del.payment_collected > 0 ? LPC.fmt.fcfa(del.payment_collected) : '-'}
                        </td>
                    </tr>`;
            });

            updateBatchFooterUI();
        }

        function toggleDeliverySelection(id, clientId, clientName, subtotal, cashCollected, blRef) {
            if (activeBatchClientId !== null && activeBatchClientId != clientId) return; // Ignore locked rows

            const idx = selectedDeliveries.findIndex(d => d.id == id);
            if (idx > -1) {
                // Remove from batch
                selectedDeliveries.splice(idx, 1);
                pendingCashInBatch -= parseFloat(cashCollected);
                // Clear locks if batch empty
                if(selectedDeliveries.length === 0) {
                    activeBatchClientId = null;
                    activeBatchClientName = "";
                    pendingCashInBatch = 0;
                }
            } else {
                // Add to batch and secure lock. bl_ref is stored on the batch
                // entry rather than resolved from the rendered page, so a BL
                // ticked on page 1 still names itself correctly in the modal
                // after paging to page 3.
                activeBatchClientId = clientId;
                activeBatchClientName = clientName;
                selectedDeliveries.push({ id, subtotal, bl_ref: blRef });
                pendingCashInBatch += parseFloat(cashCollected);
            }
            renderToInvoice(currentToInvoiceRows());
        }

        function resetBatching() {
            selectedDeliveries = [];
            activeBatchClientId = null;
            activeBatchClientName = "";
            pendingCashInBatch = 0;
            renderToInvoice(currentToInvoiceRows()); // Redraw
        }

        /** Rows currently on screen. Empty before the first load. */
        function currentToInvoiceRows() {
            return (globalData.to_invoice && globalData.to_invoice.deliveries) || [];
        }

        function updateBatchFooterUI() {
            const footer = document.getElementById('batch-action-footer');
            if (selectedDeliveries.length > 0) {
                document.getElementById('batch-count').innerText = selectedDeliveries.length;
                document.getElementById('batch-client-name').innerText = activeBatchClientName;
                footer.classList.remove('translate-y-full');
            } else {
                footer.classList.add('translate-y-full');
            }
        }

        /** ================= RENDER: COMBINED INVOICES/PAYMENTS ================= */
        function renderInvoices(invoices) {
            const tbody = document.getElementById('table-body-invoices');
            tbody.innerHTML = '';
            
            if(invoices.length === 0) {
                tbody.innerHTML = LPC.html`<tr><td colspan="7" class="py-8 text-center text-gray-400 font-bold text-xs italic">Aucune facture enregistrée dans le système.</td></tr>`;
                return;
            }

            invoices.forEach(inv => {
                let statusBadge = '';
                if(inv.status === 'paid') statusBadge = '<span class="bg-emerald-50 text-emerald-700 px-2.5 py-1 rounded text-[9px] uppercase font-black tracking-wider border border-emerald-200"><i class="fas fa-check-double mr-1"></i>Payée</span>';
                else if(inv.status === 'partial') statusBadge = '<span class="bg-amber-50 text-amber-700 px-2.5 py-1 rounded text-[9px] uppercase font-black tracking-wider border border-amber-200"><i class="fas fa-adjust mr-1"></i>Partiel</span>';
                else statusBadge = '<span class="bg-red-50 text-red-700 px-2.5 py-1 rounded text-[9px] uppercase font-black tracking-wider border border-red-200"><i class="fas fa-times mr-1"></i>Impayée</span>';

                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-gray-50/50 transition-colors">
                        <td class="py-4 px-6 text-xs font-bold text-gray-500">${new Date(inv.date).toLocaleDateString('fr-FR')}</td>
                        <td class="py-4 px-6 font-black text-gray-900">${inv.reference}</td>
                        <td class="py-4 px-6 font-bold text-gray-700 text-xs">${inv.client_name}</td>
                        <td class="py-4 px-6 text-right font-black text-gray-900">${LPC.fmt.int(inv.total_amount)} F</td>
                        <td class="py-4 px-6 text-center text-xs font-bold text-gray-500">${new Date(inv.due_date).toLocaleDateString('fr-FR')}</td>
                        <td class="py-4 px-6 text-center">${LPC.raw(statusBadge)}</td>
                        <td class="py-4 px-6 text-right whitespace-nowrap">
                            <a href="/facture.php?token=${inv.token}" target="_blank" class="text-blue-500 bg-blue-50 hover:bg-blue-100 hover:text-blue-700 p-2.5 rounded-xl mr-2 transition-colors inline-block" title="Aperçu PDF"><i class="fas fa-file-pdf"></i></a>
                            ${LPC.raw(inv.status !== 'paid' ? `<button onclick="openPaymentModal(${inv.id}, ${inv.client_id})" class="text-emerald-600 bg-emerald-50 hover:bg-emerald-100 p-2.5 rounded-xl transition-colors shadow-sm" title="Encaisser direct" aria-label="Encaisser direct"><i class="fas fa-plus"></i></button>` : '')}
                        </td>
                    </tr>`;
            });
        }

        function renderPayments(payments) {
            const tbody = document.getElementById('table-body-payments');
            tbody.innerHTML = '';
            
            if(payments.length === 0) {
                tbody.innerHTML = LPC.html`<tr><td colspan="7" class="py-8 text-center text-gray-400 font-bold text-xs italic">Aucun mouvement de caisse.</td></tr>`;
                return;
            }

            payments.forEach(pay => {
                let mBadge = '';
                if(pay.payment_method === 'cash') mBadge = '<span class="text-emerald-600 bg-emerald-50 px-2 py-1 rounded text-[10px] font-black"><i class="fas fa-money-bill-wave"></i> Cash</span>';
                else if(pay.payment_method === 'bank') mBadge = '<span class="text-blue-600 bg-blue-50 px-2 py-1 rounded text-[10px] font-black"><i class="fas fa-university"></i> Banque</span>';
                else mBadge = '<span class="text-orange-600 bg-orange-50 px-2 py-1 rounded text-[10px] font-black"><i class="fas fa-mobile-alt"></i> MoMo</span>';

                let sBadge = '';
                let actionBtn = '';
                if(pay.status === 'pending') {
                    sBadge = '<span class="text-amber-600 font-black text-[10px] uppercase tracking-wider pulse-alert bg-amber-50 px-2 py-1 rounded"><i class="fas fa-clock mr-1"></i>À Valider</span>';
                    actionBtn = `<button onclick="openValidateCashModal(${pay.id}, ${pay.amount})" class="ml-3 bg-gray-900 text-white px-3 py-1.5 rounded-lg text-[10px] font-black uppercase shadow hover:bg-black transition-colors transform hover:-translate-y-0.5"><i class="fas fa-check"></i></button>`;
                } else {
                    sBadge = '<span class="text-emerald-500 text-[10px] font-black uppercase tracking-wider bg-emerald-50 px-2 py-1 rounded"><i class="fas fa-check-double mr-1"></i>Validé</span>';
                }

                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-gray-50/50 transition-colors">
                        <td class="py-4 px-6 text-xs font-bold text-gray-500">${new Date(pay.payment_date).toLocaleDateString('fr-FR')}</td>
                        <td class="py-4 px-6 font-black text-gray-900">${pay.reference}</td>
                        <td class="py-4 px-6 font-bold text-gray-700 text-xs">${pay.client_name}</td>
                        <td class="py-4 px-6 text-[10px] font-black tracking-wide text-blue-600">${pay.invoice_ref ? pay.invoice_ref : LPC.raw('<span class="text-purple-500 italic px-2 py-0.5 bg-purple-50 rounded">Avance Libre</span>')}</td>
                        <td class="py-4 px-6 text-right font-black text-emerald-600">${LPC.fmt.int(pay.amount)} F</td>
                        <td class="py-4 px-6 text-center">${LPC.raw(mBadge)}</td>
                        <td class="py-4 px-6 text-center flex justify-center items-center h-full">${LPC.raw(sBadge)} ${LPC.raw(actionBtn)}</td>
                    </tr>`;
            });
        }

        /** ================= RENDER: WALLETS ================= */
        function renderWallets(wallets) {
            const tbody = document.getElementById('table-body-wallets');
            tbody.innerHTML = '';

            let totalAvoirs = 0;

            if(wallets.length === 0) {
                tbody.innerHTML = LPC.html`<tr><td colspan="4" class="py-12 text-center text-gray-400 font-bold italic">Aucun avoir client actif enregistré.</td></tr>`;
            } else {
                wallets.forEach(w => {
                    totalAvoirs += parseFloat(w.balance);
                    // JSON.stringify(name) escapes any quote in the client name
                    // so we can inline it in the onclick without breaking out of
                    // the attribute — LPC.html above escapes for text nodes but
                    // this is an attribute string built by hand.
                    const nameArg = JSON.stringify(w.client_name || '');
                    tbody.innerHTML += LPC.html`
                        <tr class="hover:bg-purple-50/30 transition-colors">
                            <td class="py-5 px-8 font-black text-gray-900">${w.client_name}</td>
                            <td class="py-5 px-8 text-right font-black text-purple-700 bg-purple-50/10">${LPC.fmt.int(w.balance)} F</td>
                            <td class="py-5 px-8 text-right text-xs font-bold text-gray-400">${new Date(w.last_updated).toLocaleDateString('fr-FR')}</td>
                            <td class="py-5 px-8 text-right">
                                ${LPC.raw(`<button onclick="openWalletApplyModal(${parseInt(w.client_id,10)}, ${nameArg}, ${parseFloat(w.balance)})" class="bg-purple-700 hover:bg-purple-800 text-white px-4 py-2 rounded-lg text-[11px] font-black uppercase tracking-wider shadow transition-colors"><i class="fas fa-hand-holding-usd mr-1"></i> Utiliser</button>`)}
                            </td>
                        </tr>`;
                });
            }

            // Update Summary Header
            document.getElementById('wallet-total-summary').innerText = LPC.fmt.fcfa(totalAvoirs);
        }

        /** ================= WALLET APPLICATION FLOW ================= */
        async function openWalletApplyModal(clientId, clientName, balance) {
            document.getElementById('wallet_apply_client_id').value = clientId;
            document.getElementById('wallet_apply_client_name').innerText = clientName || '—';
            document.getElementById('wallet_apply_balance').innerText = LPC.fmt.fcfa(balance);
            document.getElementById('wallet_apply_amount').value = '';
            document.getElementById('wallet_apply_amount').max = Math.floor(balance);

            const sel = document.getElementById('wallet_apply_invoice_id');
            sel.innerHTML = '<option value="">— Chargement… —</option>';

            openModal('modal-wallet-apply');

            try {
                const data = await fetchJsonSafely(`/api/v1/invoices_controller.php?action=get_wallet_targets&client_id=${clientId}`);
                if (data.status !== 'success') {
                    sel.innerHTML = '<option value="">— Erreur —</option>';
                    showToast(data.message || "Impossible de charger les factures.", "error");
                    return;
                }
                if (!Array.isArray(data.invoices) || data.invoices.length === 0) {
                    sel.innerHTML = '<option value="">— Aucune facture ouverte pour ce client —</option>';
                    return;
                }
                sel.innerHTML = data.invoices.map(i =>
                    LPC.html`<option value="${i.id}" data-balance="${i.balance}">Facture ${i.reference} (Reste Dû: ${LPC.fmt.int(i.balance)} F)</option>`
                ).join('');
                // Pre-fill the amount at the smaller of (wallet balance, first-invoice balance).
                const firstBal = parseFloat(data.invoices[0].balance) || 0;
                document.getElementById('wallet_apply_amount').value = Math.floor(Math.min(balance, firstBal));
            } catch (e) {
                sel.innerHTML = '<option value="">— Erreur réseau —</option>';
                showToast(e.message, "error");
            }
        }

        async function submitWalletApply() {
            const btn = document.getElementById('btn-submit-wallet-apply');
            const txt = document.getElementById('btn-submit-wallet-apply-text');
            const spinner = document.getElementById('btn-submit-wallet-apply-spinner');
            const reset = () => { btn.disabled = false; txt.classList.remove('hidden'); spinner.classList.add('hidden'); };

            const clientId  = parseInt(document.getElementById('wallet_apply_client_id').value, 10);
            const invoiceId = parseInt(document.getElementById('wallet_apply_invoice_id').value, 10);
            const amount    = parseFloat(document.getElementById('wallet_apply_amount').value);

            if (!clientId || !invoiceId) return showToast("Sélectionnez une facture cible.", "error");
            if (!(amount > 0)) return showToast("Montant invalide.", "error");

            btn.disabled = true; txt.classList.add('hidden'); spinner.classList.remove('hidden');

            try {
                const response = await fetch('/api/v1/invoices_controller.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'apply_client_wallet',
                        client_id: clientId,
                        invoice_id: invoiceId,
                        amount: Math.round(amount),
                    })
                });
                const raw = await response.text();
                let result;
                try {
                    result = JSON.parse(raw);
                } catch (e) {
                    console.error("submitWalletApply — non-JSON response:", raw);
                    throw new Error("Le serveur a renvoyé une erreur HTML. Consultez la console (F12).");
                }

                if (result.status === 'success') {
                    closeModal('modal-wallet-apply');
                    showToast(`Avoir imputé : ${LPC.fmt.fcfa(result.amount_applied)}. Facture ${result.invoice_status === 'paid' ? 'soldée' : 'partiellement réglée'}.`);
                    fetchTabData(currentTab);
                } else {
                    showToast(result.message || "Erreur inconnue.", "error");
                }
            } catch (e) {
                showToast(e.message || "Erreur réseau.", "error");
            } finally {
                reset();
            }
        }


        /** ================= MODAL & FORM LOGIC ================= */
        
        function openModal(id) { 
            const el = document.getElementById(id);
            el.classList.remove('hidden');
            setTimeout(() => el.classList.remove('opacity-0'), 10);
        }
        
        function closeModal(id) { 
            const el = document.getElementById(id);
            el.classList.add('hidden'); 
        }

        /** Invoice Generation Flow */
        function openGenerateInvoiceModal() {
            document.getElementById('gen_client_id').value = activeBatchClientId;
            document.getElementById('gen_client_name').innerText = activeBatchClientName;
            document.getElementById('prev_item_count').innerText = `${selectedDeliveries.length} BL(s) Inclus`;
            
            const tbody = document.getElementById('gen_preview_lines');
            tbody.innerHTML = '';
            let rawSubtotal = 0;

            selectedDeliveries.forEach(del => {
                rawSubtotal += parseFloat(del.subtotal);
                // del.bl_ref is captured at selection time. This used to be a
                // .find() over the rendered rows, which returned undefined —
                // and threw — as soon as the selected BL was on another page.
                tbody.innerHTML += LPC.html`
                    <tr>
                        <td class="py-3 px-2 font-bold text-gray-700 text-xs">Fourniture Ligne - <span class="text-blue-600">BL ${del.bl_ref || ''}</span></td>
                        <td class="py-3 px-2 text-right font-black text-gray-900">${LPC.fmt.int(del.subtotal)} F</td>
                    </tr>`;
            });

            window.tempInvoiceSubtotal = rawSubtotal;
            calculateInvoicePreview();
            renderPaymentAccountPicker(activeBatchClientId);
            openModal('modal-generate-invoice');
        }

        /**
         * Collection accounts offered on this invoice.
         *
         * Ticks default to the client's own default account when one is set
         * (Afriland on the large accounts, microfinance on the rest), otherwise
         * to the first account in display order. The first ticked box is the
         * settlement account — it decides which treasury sub-ledger the payment
         * journal entry will debit, which is why the order is preserved.
         */
        async function renderPaymentAccountPicker(clientId) {
            const host = document.getElementById('gen_payment_accounts');
            if (!host) return;
            host.innerHTML = LPC.html`<p class="text-xs text-gray-400 italic">Chargement...</p>`;

            try {
                const res = await fetch(`/api/v1/invoices_controller.php?action=payment_accounts&client_id=${encodeURIComponent(clientId)}`);
                const json = await res.json();
                const accounts = (json.status === 'success' && Array.isArray(json.accounts)) ? json.accounts : [];

                if (!accounts.length) {
                    host.innerHTML = LPC.html`
                        <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-3 font-medium">
                            Aucun compte d'encaissement configuré. La facture sera émise sans bloc de règlement.
                        </p>`;
                    return;
                }

                const defaultId = json.default_account_id || accounts[0].id;
                host.innerHTML = accounts.map(a => {
                    const checked = String(a.id) === String(defaultId) ? 'checked' : '';
                    const detail = a.type === 'momo'
                        ? (a.account_number || '')
                        : [a.bank_name, a.account_number].filter(Boolean).join(' · ');
                    return LPC.html`
                        <label class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 hover:border-gray-400 cursor-pointer transition-colors">
                            <input type="checkbox" class="gen-pay-acct mt-0.5 w-4 h-4 accent-gray-900 cursor-pointer"
                                   value="${a.id}" ${LPC.raw(checked)}>
                            <span class="min-w-0">
                                <span class="block text-sm font-bold text-gray-900 truncate">${a.name}</span>
                                <span class="block text-[11px] text-gray-500 truncate">${detail}</span>
                            </span>
                        </label>`;
                }).join('');
            } catch (e) {
                host.innerHTML = LPC.html`
                    <p class="text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg p-3 font-medium">
                        Impossible de charger les comptes d'encaissement.
                    </p>`;
            }
        }

        function calculateInvoicePreview() {
            const tvaRate = parseFloat(document.getElementById('gen_tva').value) || 0;
            const subtotal = window.tempInvoiceSubtotal;
            const tvaAmount = subtotal * (tvaRate / 100);
            const grandTotal = subtotal + tvaAmount;

            document.getElementById('prev_subtotal').innerText = LPC.fmt.fcfa(subtotal);
            document.getElementById('prev_tva_label').innerText = tvaRate;
            document.getElementById('prev_tva_amount').innerText = LPC.fmt.fcfa(tvaAmount);
            document.getElementById('prev_grandtotal').innerText = LPC.fmt.fcfa(grandTotal);

            const cashBanner = document.getElementById('prev_cash_banner');
            if(pendingCashInBatch > 0) {
                document.getElementById('prev_cash_amount').innerText = LPC.fmt.fcfa(pendingCashInBatch);
                cashBanner.classList.remove('hidden');
            } else {
                cashBanner.classList.add('hidden');
            }
        }

        async function submitInvoice() {
            // Button State
            const btn = document.getElementById('btn-submit-invoice');
            const txt = document.getElementById('btn-submit-invoice-text');
            const spinner = document.getElementById('btn-submit-invoice-spinner');
            
            btn.disabled = true; txt.classList.add('hidden'); spinner.classList.remove('hidden');

            const payload = {
                action: 'generate_invoice',
                client_id: document.getElementById('gen_client_id').value,
                deliveries: selectedDeliveries.map(d => d.id),
                date: document.getElementById('gen_date').value,
                due_date: document.getElementById('gen_due_date').value,
                tva_rate: document.getElementById('gen_tva').value,
                notes: document.getElementById('gen_notes').value,
                // Document order matters: the server treats the first as the
                // settlement account.
                payment_account_ids: Array.from(
                    document.querySelectorAll('.gen-pay-acct:checked')
                ).map(el => parseInt(el.value, 10))
            };

            try {
                const response = await fetch('/api/v1/invoices_controller.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
                });
                // Read as text first so a PHP notice/HTML error surfaces
                // verbatim in the toast instead of "Unexpected token '<'".
                const raw = await response.text();
                let result;
                try {
                    result = JSON.parse(raw);
                } catch (e) {
                    console.error("submitInvoice — non-JSON response:", raw);
                    throw new Error("Le serveur a renvoyé une erreur HTML. Consultez la console (F12).");
                }

                if (result.status === 'success') {
                    closeModal('modal-generate-invoice');
                    resetBatching();
                    showToast("Facture générée avec succès.");
                    switchTab('invoices_payments');

                    // Open PDF in new tab
                    setTimeout(() => window.open(`/facture.php?token=${result.token}`, '_blank'), 500);
                } else {
                    showToast(result.message || "Erreur inconnue lors de la facturation.", "error");
                }
            } catch(e) {
                showToast(e.message || "Erreur serveur interne lors de la facturation.", "error");
            } finally {
                btn.disabled = false; txt.classList.remove('hidden'); spinner.classList.add('hidden');
            }
        }

        /** Payment Management Flow */
        async function fetchClientsList() {
            const sel = document.getElementById('pay_client_id');
            try {
                const data = await fetchJsonSafely('/api/v1/invoices_controller.php?action=get_clients');
                if (data.status !== 'success' || !Array.isArray(data.data)) {
                    showToast(data.message || "Impossible de charger la liste des clients.", "error");
                    return;
                }
                data.data.forEach(c => sel.innerHTML += LPC.html`<option value="${c.id}">${c.name}</option>`);
            } catch (e) {
                // Silent-catch bit us before: the picker just stayed empty and the
                // operator had no clue why "Enregistrer" refused their client.
                showToast("Erreur au chargement des clients (" + e.message + ")", "error");
            }
        }

        async function fetchClientUnpaidInvoices(clientId) {
            if(!clientId) return;
            const div = document.getElementById('pay_invoice_selector_div');
            const sel = document.getElementById('pay_invoice_select');

            div.classList.remove('hidden');
            sel.innerHTML = '<option value="">-- Verser en Avance / Avoir (Portefeuille) --</option>';

            try {
                const data = await fetchJsonSafely(`/api/v1/invoices_controller.php?action=get_unpaid_invoices&client_id=${encodeURIComponent(clientId)}`);
                if (data.status !== 'success' || !Array.isArray(data.data)) {
                    showToast(data.message || "Impossible de charger les factures ouvertes.", "error");
                    return;
                }
                data.data.forEach(i => {
                    sel.innerHTML += LPC.html`<option value="${i.id}">Facture ${i.reference} (Reste Dû: ${LPC.fmt.int(i.balance)} F)</option>`;
                });
            } catch (e) {
                showToast("Erreur au chargement des factures (" + e.message + ")", "error");
            }
        }

        // Cache of active treasury accounts for the receipt picker.
        // Refreshed on every openPaymentModal() call so newly created wallets
        // appear without a hard reload.
        let payAccountsCache = [];

        async function loadPayAccounts() {
            const sel = document.getElementById('pay_account_id');
            try {
                const res = await fetch('/api/v1/invoices_controller.php?action=active_treasury_accounts');
                const data = await res.json();
                if (data.status !== 'success' || !Array.isArray(data.accounts)) {
                    sel.innerHTML = '<option value="">— Aucun compte configuré —</option>';
                    payAccountsCache = [];
                    return;
                }
                payAccountsCache = data.accounts;
                filterPayAccounts();
            } catch (e) {
                sel.innerHTML = '<option value="">— Erreur de chargement —</option>';
                payAccountsCache = [];
            }
        }

        // Restrict the destination-account dropdown to wallets whose type
        // matches the selected method (cash → caisse, bank → banque, momo → momo).
        // Falls back to showing every active wallet if no match — better a
        // usable picker than an empty one that dead-ends the operator.
        function filterPayAccounts() {
            const sel = document.getElementById('pay_account_id');
            if (!sel) return;
            const method = document.getElementById('pay_method').value;
            const typeMap = { cash: 'caisse', bank: 'bank', momo: 'momo' };
            const wantedType = typeMap[method] || null;
            const previous = sel.value;

            let list = wantedType
                ? payAccountsCache.filter(a => a.type === wantedType)
                : payAccountsCache.slice();
            if (list.length === 0) list = payAccountsCache.slice();

            if (list.length === 0) {
                sel.innerHTML = '<option value="">— Aucun compte configuré —</option>';
                return;
            }

            sel.innerHTML = list.map(a => {
                const suffix = a.bank_name ? ` (${a.bank_name})` : '';
                return LPC.html`<option value="${a.id}">${a.name}${suffix}</option>`;
            }).join('');

            // Preserve the operator's choice if it's still valid for the new method.
            if (previous && list.some(a => String(a.id) === String(previous))) {
                sel.value = previous;
            }
        }

        function openPaymentModal(invoiceId = null, clientId = null) {
            document.getElementById('form-payment').reset();
            document.getElementById('pay_action_type').value = 'new';
            document.getElementById('pay_validation_banner').classList.add('hidden');
            document.getElementById('pay_client_selector_div').classList.remove('hidden');

            document.getElementById('pay-modal-title').innerText = 'Nouvel Encaissement';
            document.getElementById('pay-modal-header').classList.replace('bg-amber-500', 'bg-gray-900');
            document.getElementById('btn-submit-payment-text').innerHTML = '<i class="fas fa-check"></i> Enregistrer & Imputer';
            document.getElementById('btn-submit-payment').classList.replace('bg-amber-600', 'bg-gray-900');

            if(clientId && invoiceId) {
                document.getElementById('pay_client_id').value = clientId;
                fetchClientUnpaidInvoices(clientId).then(() => {
                    setTimeout(() => document.getElementById('pay_invoice_select').value = invoiceId, 200);
                });
            } else {
                document.getElementById('pay_invoice_selector_div').classList.add('hidden');
            }

            // Unlock inputs
            document.getElementById('pay_amount').readOnly = false;
            document.getElementById('pay_method').disabled = false;

            // Load / refresh the destination-account picker every time — a
            // wallet the operator just created should show up without F5.
            loadPayAccounts();

            openModal('modal-payment');
        }

        function openValidateCashModal(paymentId, amount) {
            document.getElementById('form-payment').reset();
            document.getElementById('pay_action_type').value = 'validate';
            document.getElementById('pay_payment_id').value = paymentId;

            // UI Tweaks for validation mode
            document.getElementById('pay_client_selector_div').classList.add('hidden');
            document.getElementById('pay_invoice_selector_div').classList.add('hidden');

            document.getElementById('pay_amount').value = amount;
            document.getElementById('pay_amount').readOnly = true;
            document.getElementById('pay_method').value = 'cash';
            document.getElementById('pay_method').disabled = true;

            document.getElementById('pay_validation_banner').classList.remove('hidden');
            document.getElementById('pay-modal-title').innerText = 'Validation de Caisse';
            document.getElementById('pay-modal-header').classList.replace('bg-gray-900', 'bg-amber-500');

            document.getElementById('btn-submit-payment-text').innerHTML = '<i class="fas fa-lock"></i> Valider Entrée Caisse';
            document.getElementById('btn-submit-payment').classList.replace('bg-gray-900', 'bg-amber-600');

            // Validation path uses the payment's existing account (or the
            // JournalPoster fallback), so the picker is not shown. Load it
            // anyway so the field is populated if the operator flips
            // pay_action_type back to 'new' via devtools — cheap safety net.
            loadPayAccounts();

            openModal('modal-payment');
        }

        async function submitPayment() {
            const btn = document.getElementById('btn-submit-payment');
            const txt = document.getElementById('btn-submit-payment-text');
            const spinner = document.getElementById('btn-submit-payment-spinner');
            
            // HTML5 Form validation check
            if(!document.getElementById('form-payment').checkValidity()) {
                document.getElementById('form-payment').reportValidity();
                return;
            }

            btn.disabled = true; txt.classList.add('hidden'); spinner.classList.remove('hidden');

            const actionType = document.getElementById('pay_action_type').value;
            let payload = {};

            // Small helper — before this every failure path had to duplicate
            // three lines of button-state reset, which is exactly the shape of
            // bug that leaves the operator staring at a spinning button.
            const resetBtn = () => {
                btn.disabled = false;
                txt.classList.remove('hidden');
                spinner.classList.add('hidden');
            };

            if (actionType === 'new') {
                const acctVal = document.getElementById('pay_account_id').value;
                const clientVal = document.getElementById('pay_client_id').value;
                const amountVal = parseFloat(document.getElementById('pay_amount').value);
                if (!clientVal) {
                    resetBtn();
                    showToast("Sélectionnez un client.", "error");
                    return;
                }
                if (!(amountVal > 0)) {
                    resetBtn();
                    showToast("Montant invalide.", "error");
                    return;
                }
                if (!acctVal) {
                    resetBtn();
                    showToast("Sélectionnez un compte de destination.", "error");
                    return;
                }
                payload = {
                    action: 'register_payment',
                    client_id: clientVal,
                    invoice_id: document.getElementById('pay_invoice_select').value,
                    // FCFA has no subunit — round to the franc so the JE totals
                    // never drift because a decimal snuck through the number
                    // input on a copy-paste.
                    amount: Math.round(amountVal),
                    method: document.getElementById('pay_method').value,
                    treasury_account_id: parseInt(acctVal, 10),
                };
            } else {
                payload = {
                    action: 'validate_cash',
                    payment_id: document.getElementById('pay_payment_id').value
                };
            }

            try {
                const response = await fetch('/api/v1/invoices_controller.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
                });
                const raw = await response.text();
                let result;
                try {
                    result = JSON.parse(raw);
                } catch (e) {
                    console.error("submitPayment — non-JSON response:", raw);
                    throw new Error("Le serveur a renvoyé une erreur HTML. Consultez la console (F12).");
                }

                if (result.status === 'success') {
                    closeModal('modal-payment');
                    showToast(actionType === 'new' ? "Paiement enregistré et imputé." : "Caisse validée avec succès.");
                    fetchTabData(currentTab); // Refresh view dynamically
                } else {
                    showToast(result.message || "Erreur inconnue lors du paiement.", "error");
                }
            } catch(e) {
                showToast(e.message || "Erreur serveur lors de la transaction.", "error");
            } finally {
                resetBtn();
            }
        }

        /** Utility Function: Universal Table Filter */
        function filterTable(tbodyId, query) {
            const q = query.toLowerCase();
            const rows = document.getElementById(tbodyId).getElementsByTagName('tr');
            for (let i = 0; i < rows.length; i++) {
                rows[i].style.display = rows[i].innerText.toLowerCase().includes(q) ? '' : 'none';
            }
        }
        
        /** Utility Function: Filter Both Combined Tables */
        function filterBothTables(query) {
            filterTable('table-body-invoices', query);
            filterTable('table-body-payments', query);
        }
    
        /* ---------------------------------------------------------------------
           Arrival from a deep link (Sprint 7F).
           Reached as ?client_id=..&client=..&from=crm_clients from the CRM KPI
           drill-down. lpc-deeplink.js renders the return + filter chips; we
           just tell it which tables to narrow. Both registers are filtered, not
           only invoices: arriving to chase a debt and seeing that client's
           payments alongside is the point.
           ------------------------------------------------------------------ */
        document.addEventListener('DOMContentLoaded', function () {
            if (window.LPC && LPC.deeplink) {
                LPC.deeplink.arrive(['#table-body-invoices', '#table-body-payments']);
            }
        });
