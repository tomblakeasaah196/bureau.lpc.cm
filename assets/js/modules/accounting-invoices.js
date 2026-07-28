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

        /** Custom Toast Notification System */
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            
            // Define styling based on type
            const colors = type === 'success' 
                ? 'bg-gray-900 border-lpc-light text-white' 
                : 'bg-red-50 border-red-500 text-red-900';
            const icon = type === 'success' ? 'fa-check-circle text-lpc-light' : 'fa-exclamation-circle text-red-500';
            
            toast.className = `flex items-center gap-3 px-5 py-4 rounded-xl border-l-4 shadow-2xl animate-toast-enter ${colors}`;
            toast.innerHTML = LPC.html`<i class="fas ${icon} text-lg"></i> <span class="font-bold text-sm">${message}</span>`;
            
            container.appendChild(toast);
            
            // Auto remove after 4 seconds
            setTimeout(() => {
                toast.classList.replace('animate-toast-enter', 'animate-toast-leave');
                toast.addEventListener('animationend', () => toast.remove());
            }, 4000);
        }

        /** Initialization Hook */
        window.onload = () => {
            // Setup defaults for Modals
            document.getElementById('gen_date').valueAsDate = new Date();
            let dueDate = new Date();
            dueDate.setDate(dueDate.getDate() + 30); // Default standard Net 30
            document.getElementById('gen_due_date').valueAsDate = dueDate;
            
            // Navigate to default view
            switchTab('dashboard');
            
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

        /** Core Fetcher logic handling combined tabs natively with Crash-Proof JSON Parsing */
        async function fetchTabData(tab) {
            try {
                // The ultimate safety wrapper for parsing JSON
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
                    if(tab === 'to_invoice') renderToInvoice(result.data.deliveries);
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
                            grid: { color: '#f1f5f9', drawBorder: false },
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

        /** ================= RENDER: TO INVOICE ================= */
        function renderToInvoice(deliveries) {
            const tbody = document.getElementById('table-body-to-invoice');
            tbody.innerHTML = '';
            
            if(deliveries.length === 0) {
                tbody.innerHTML = LPC.html`<tr><td colspan="6" class="py-20 text-center text-gray-400 font-bold"><i class="fas fa-check-circle text-5xl mb-4 text-gray-200 block"></i>Félicitations, tous les bons de livraison sont facturés.</td></tr>`;
                resetBatching();
                return;
            }

            deliveries.forEach(del => {
                // Determine row state based on batch selection logic
                const isLocked = activeBatchClientId !== null && activeBatchClientId != del.client_id;
                const isChecked = selectedDeliveries.some(d => d.id == del.id);
                
                const trClass = isLocked ? 'disabled-row transition-opacity' : 'hover:bg-blue-50/40 transition-colors cursor-pointer';

                tbody.innerHTML += LPC.html`
                    <tr class="${trClass}" onclick="toggleDeliverySelection(${del.id}, ${del.client_id}, '${del.client_name.replace(/'/g, "\\'")}', ${del.sales_subtotal}, ${del.payment_collected})">
                        <td class="py-4 px-6 text-center" onclick="event.stopPropagation()">
                            <input type="checkbox" class="checkbox-custom" id="chk_${del.id}" ${isChecked ? 'checked' : ''} ${isLocked ? 'disabled' : ''} onchange="toggleDeliverySelection(${del.id}, ${del.client_id}, '${del.client_name.replace(/'/g, "\\'")}', ${del.sales_subtotal}, ${del.payment_collected})">
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

        function toggleDeliverySelection(id, clientId, clientName, subtotal, cashCollected) {
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
                // Add to batch and secure lock
                activeBatchClientId = clientId;
                activeBatchClientName = clientName;
                selectedDeliveries.push({ id, subtotal });
                pendingCashInBatch += parseFloat(cashCollected);
            }
            renderToInvoice(globalData.to_invoice.deliveries);
        }

        function resetBatching() {
            selectedDeliveries = [];
            activeBatchClientId = null;
            activeBatchClientName = "";
            pendingCashInBatch = 0;
            renderToInvoice(globalData.to_invoice.deliveries); // Redraw
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
                        <td class="py-4 px-6 text-[10px] font-black tracking-wide text-blue-600">${pay.invoice_ref || '<span class="text-purple-500 italic px-2 py-0.5 bg-purple-50 rounded">Avance Libre</span>'}</td>
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
                tbody.innerHTML = LPC.html`<tr><td colspan="3" class="py-12 text-center text-gray-400 font-bold italic">Aucun avoir client actif enregistré.</td></tr>`;
            } else {
                wallets.forEach(w => {
                    totalAvoirs += parseFloat(w.balance);
                    tbody.innerHTML += LPC.html`
                        <tr class="hover:bg-purple-50/30 transition-colors">
                            <td class="py-5 px-8 font-black text-gray-900">${w.client_name}</td>
                            <td class="py-5 px-8 text-right font-black text-purple-700 bg-purple-50/10">${LPC.fmt.int(w.balance)} F</td>
                            <td class="py-5 px-8 text-right text-xs font-bold text-gray-400">${new Date(w.last_updated).toLocaleDateString('fr-FR')}</td>
                        </tr>`;
                });
            }
            
            // Update Summary Header
            document.getElementById('wallet-total-summary').innerText = LPC.fmt.fcfa(totalAvoirs);
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
                const deliveryObj = globalData.to_invoice.deliveries.find(d => d.id == del.id);
                rawSubtotal += parseFloat(del.subtotal);
                tbody.innerHTML += LPC.html`
                    <tr>
                        <td class="py-3 px-2 font-bold text-gray-700 text-xs">Fourniture Ligne - <span class="text-blue-600">BL ${deliveryObj.bl_ref}</span></td>
                        <td class="py-3 px-2 text-right font-black text-gray-900">${LPC.fmt.int(del.subtotal)} F</td>
                    </tr>`;
            });

            window.tempInvoiceSubtotal = rawSubtotal;
            calculateInvoicePreview();
            openModal('modal-generate-invoice');
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
                notes: document.getElementById('gen_notes').value
            };

            try {
                const response = await fetch('/api/v1/invoices_controller.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
                });
                const result = await response.json();
                
                if (result.status === 'success') {
                    closeModal('modal-generate-invoice');
                    resetBatching();
                    showToast("Facture générée avec succès.");
                    switchTab('invoices_payments');
                    
                    // Open PDF in new tab
                    setTimeout(() => window.open(`/facture.php?token=${result.token}`, '_blank'), 500);
                } else {
                    showToast(result.message, "error");
                }
            } catch(e) { 
                showToast("Erreur serveur interne lors de la facturation.", "error"); 
            } finally {
                btn.disabled = false; txt.classList.remove('hidden'); spinner.classList.add('hidden');
            }
        }

        /** Payment Management Flow */
        async function fetchClientsList() {
            try {
                const res = await fetch('/api/v1/invoices_controller.php?action=get_clients');
                const data = await res.json();
                const sel = document.getElementById('pay_client_id');
                data.data.forEach(c => sel.innerHTML += LPC.html`<option value="${c.id}">${c.name}</option>`);
            } catch(e){}
        }

        async function fetchClientUnpaidInvoices(clientId) {
            if(!clientId) return;
            const div = document.getElementById('pay_invoice_selector_div');
            const sel = document.getElementById('pay_invoice_select');
            
            div.classList.remove('hidden');
            sel.innerHTML = '<option value="">-- Verser en Avance / Avoir (Portefeuille) --</option>';
            
            try {
                const res = await fetch(`/api/v1/invoices_controller.php?action=get_unpaid_invoices&client_id=${clientId}`);
                const data = await res.json();
                data.data.forEach(i => {
                    sel.innerHTML += LPC.html`<option value="${i.id}">Facture ${i.reference} (Reste Dû: ${LPC.fmt.int(i.balance)} F)</option>`;
                });
            } catch(e){}
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

            if (actionType === 'new') {
                payload = {
                    action: 'register_payment',
                    client_id: document.getElementById('pay_client_id').value,
                    invoice_id: document.getElementById('pay_invoice_select').value,
                    amount: document.getElementById('pay_amount').value,
                    method: document.getElementById('pay_method').value
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
                const result = await response.json();
                
                if (result.status === 'success') {
                    closeModal('modal-payment');
                    showToast(actionType === 'new' ? "Paiement enregistré et imputé." : "Caisse validée avec succès.");
                    fetchTabData(currentTab); // Refresh view dynamically
                } else {
                    showToast(result.message, "error");
                }
            } catch(e) { 
                showToast("Erreur serveur lors de la transaction.", "error"); 
            } finally {
                btn.disabled = false; txt.classList.remove('hidden'); spinner.classList.add('hidden');
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
    