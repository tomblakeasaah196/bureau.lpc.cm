/**
 * assets/js/modules/sales-orders.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/sales/orders.php (Sprint 6 D2).
 *
 * Original block was ~29,945 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        // 1. STATE MANAGEMENT
        let currentTab = 'orders';
        let moduleData = [];
        let metaData = { clients: [], products: [], drivers: [], vehicles: [] };

        const config = {
            orders: {
                api: 'sales_orders',
                columns: [
                    { key: 'date', label: 'Date', render: v => `<span class="text-xs font-bold text-gray-500">${new Date(v).toLocaleDateString('fr-FR')}</span>` },
                    { key: 'reference', label: 'Réf. Commande', render: v => `<span class="font-black text-gray-900 tracking-wide">${v}</span>` },
                    { key: 'client_name', label: 'Client', render: v => `<span class="font-bold text-gray-700">${v}</span>` },
                    { key: 'status', label: 'Statut', render: v => {
                        const dict = { 'pending': '<span class="text-amber-600 bg-amber-50 px-2 py-1 rounded text-[10px] font-black uppercase">En Attente</span>', 'dispatched': '<span class="text-blue-600 bg-blue-50 px-2 py-1 rounded text-[10px] font-black uppercase"><i class="fas fa-truck mr-1"></i>En Transit</span>', 'delivered': '<span class="text-green-600 bg-green-50 px-2 py-1 rounded text-[10px] font-black uppercase"><i class="fas fa-check-double mr-1"></i>Livré</span>', 'cancelled': '<span class="text-red-600 bg-red-50 px-2 py-1 rounded text-[10px] font-black uppercase">Annulé</span>' };
                        return dict[v] || v;
                    }},
                    { key: 'payment_status', label: 'Paiement', render: v => v === 'paid' ? '<span class="text-emerald-500 text-xs font-bold"><i class="fas fa-check-circle"></i> Payé</span>' : '<span class="text-red-500 text-xs font-bold"><i class="fas fa-clock"></i> Non Payé</span>' },
                    { key: 'total_amount', label: 'Total TTC', render: v => `<span class="font-black text-gray-900">${LPC.fmt.int(v)} FCFA</span>` }
                ],
                kpis: [
                    { id: 'kpi_so_total', label: 'Ventes (Mois)', icon: 'fa-chart-pie', color: 'text-indigo-600', bg: 'bg-indigo-50' },
                    { id: 'kpi_so_pending', label: 'À Livrer', icon: 'fa-hourglass-half', color: 'text-amber-600', bg: 'bg-amber-50' },
                    { id: 'kpi_so_debt', label: 'Créances Clients', icon: 'fa-hand-holding-usd', color: 'text-red-600', bg: 'bg-red-50' },
                    { id: 'kpi_so_count', label: 'Volume Commandes', icon: 'fa-shopping-cart', color: 'text-blue-600', bg: 'bg-blue-50' }
                ]
            },
            dispatch: {
                api: 'deliveries',
                columns: [
                    { key: 'date', label: 'Date Sortie', render: v => `<span class="text-xs font-bold text-gray-500">${new Date(v).toLocaleDateString('fr-FR')}</span>` },
                    { key: 'reference', label: 'Réf. BL', render: v => `<span class="font-black text-blue-700 tracking-wide">${v}</span>` },
                    { key: 'client_name', label: 'Client', render: v => `<span class="font-bold text-gray-700">${v}</span>` },
                    { key: 'driver_name', label: 'Chauffeur', render: v => v ? `<span class="text-xs font-bold text-gray-600"><i class="fas fa-id-badge mr-1"></i>${v}</span>` : `<span class="text-[10px] font-bold text-gray-400 uppercase italic">Enlèvement</span>` },
                    { key: 'status', label: 'Statut Logistique', render: v => {
                        if(v === 'draft') return '<span class="text-gray-500 bg-gray-100 px-2 py-1 rounded text-[10px] font-black uppercase">Brouillon</span>';
                        if(v === 'dispatched') return '<span class="text-blue-600 bg-blue-50 px-2 py-1 rounded text-[10px] font-black uppercase animate-pulse">En Transit</span>';
                        if(v === 'driver_confirmed') return '<span class="text-amber-600 bg-amber-50 px-2 py-1 rounded text-[10px] font-black uppercase border border-amber-200"><i class="fas fa-user-check mr-1"></i>Confirmé Chauffeur</span>';
                        if(v === 'completed') return '<span class="text-green-600 bg-green-50 px-2 py-1 rounded text-[10px] font-black uppercase">Terminé</span>';
                        return v;
                    }},
                    { key: 'payment_collected', label: 'Cash Collecté', render: v => parseFloat(v) > 0 ? `<span class="font-black text-emerald-600">${LPC.fmt.int(v)} FCFA</span>` : `<span class="text-gray-400 font-bold">-</span>` }
                ],
                kpis: [
                    { id: 'kpi_dl_dispatched', label: 'Camions en Route', icon: 'fa-truck-moving', color: 'text-blue-600', bg: 'bg-blue-50' },
                    { id: 'kpi_dl_completed', label: 'Livrées (Aujourd\'hui)', icon: 'fa-check-double', color: 'text-emerald-600', bg: 'bg-emerald-50' },
                    { id: 'kpi_dl_cash', label: 'Cash Collecté (Jour)', icon: 'fa-money-bill-wave', color: 'text-gray-900', bg: 'bg-gray-200' },
                    { id: 'kpi_dl_returns', label: 'Rejets / Avaries', icon: 'fa-exclamation-triangle', color: 'text-red-600', bg: 'bg-red-50' }
                ]
            }
        };

        // 2. INIT & METADATA
        document.addEventListener('DOMContentLoaded', async () => {
            document.getElementById('so_date').valueAsDate = new Date();
            await fetchMetaData();
            switchTab('orders');
        });

        async function fetchMetaData() {
            try {
                const response = await fetch('/api/v1/sales_controller.php?action=fetch_metadata');
                const result = await response.json();
                if(result.status === 'success') {
                    metaData = result.data;
                    
                    // Inject Clients
                    const clientSel = document.getElementById('so_client');
                    metaData.clients.forEach(c => clientSel.innerHTML += LPC.html`<option value="${c.id}">${c.name}</option>`);

                    // Inject Drivers from Today's Assignments
                    const driverSel = document.getElementById('disp_driver');
                    metaData.assignments.forEach(a => {
                        driverSel.innerHTML += LPC.html`<option value="${a.driver_id}">${a.first_name} ${a.last_name}</option>`;
                    });
                }
            } catch(e) { console.error("Metadata load failed"); }
        }

        // NEW FUNCTION: Auto-link the vehicle when a driver is picked
        function autoSelectVehicle() {
            const driverId = document.getElementById('disp_driver').value;
            const vehSel = document.getElementById('disp_vehicle');
            
            vehSel.innerHTML = '<option value="">-- Aucun --</option>'; // Reset

            if(driverId) {
                // Find the assignment link in metaData
                const assignment = metaData.assignments.find(a => a.driver_id == driverId);
                if (assignment) {
                    vehSel.innerHTML = LPC.html`<option value="${assignment.vehicle_id}">${assignment.plate_number} (${assignment.type})</option>`;
                }
            }
        }

        async function switchTab(tab) {
            currentTab = tab;
            const c = config[currentTab];

            document.querySelectorAll('.tab-link').forEach(el => {
                el.classList.remove('border-lpc-dark', 'text-lpc-dark', 'font-black');
                el.classList.add('border-transparent', 'text-gray-400', 'font-bold');
            });
            document.getElementById(`tab-${tab}`).classList.remove('border-transparent', 'text-gray-400', 'font-bold');
            document.getElementById(`tab-${tab}`).classList.add('border-lpc-dark', 'text-lpc-dark', 'font-black');

            // Toggle toolbar Add button (Only for orders)
            if(tab === 'orders') {
                document.getElementById('toolbar-actions').style.display = 'block';
            } else {
                document.getElementById('toolbar-actions').style.display = 'none';
            }

            const ribbon = document.getElementById('kpi-ribbon');
            ribbon.innerHTML = '';
            c.kpis.forEach(kpi => {
                ribbon.innerHTML += LPC.html`
                    <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between">
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
            const tbody = document.getElementById('table-body');
            tbody.innerHTML = LPC.html`<tr><td colspan="10" class="py-8 text-center text-gray-400 font-bold animate-pulse">Chargement des données...</td></tr>`;
            
            try {
                const response = await fetch(`/api/v1/sales_controller.php?action=read&tab=${currentTab}`);
                const result = await response.json();
                
                if (result.status === 'success') {
                    moduleData = result.data.table;
                    if(result.data.kpis) {
                        for (const [key, value] of Object.entries(result.data.kpis)) {
                            const el = document.getElementById(key);
                            if(el) el.innerText = value;
                        }
                    }
                    
                    // Pending Dispatch Notification Badge Logic
                    if(currentTab === 'orders') {
                        const pendingCount = moduleData.filter(x => x.status === 'pending').length;
                        const badge = document.getElementById('badge-pending-dispatch');
                        if(pendingCount > 0) {
                            badge.innerText = pendingCount;
                            badge.classList.remove('hidden');
                        } else {
                            badge.classList.add('hidden');
                        }
                    }

                    renderTable(moduleData);
                } else throw new Error(result.message);
            } catch (e) {
                tbody.innerHTML = LPC.html`<tr><td colspan="10" class="py-8 text-center text-red-500 font-bold">Erreur Serveur</td></tr>`;
            }
        }

        function renderTable(dataArray) {
            const tbody = document.getElementById('table-body');
            tbody.innerHTML = '';
            if(dataArray.length === 0) return tbody.innerHTML = LPC.html`<tr><td colspan="10" class="py-8 text-center text-gray-400">Aucun enregistrement.</td></tr>`;

            dataArray.forEach(row => {
                let tr = '<tr class="hover:bg-gray-50 transition-colors">';
                config[currentTab].columns.forEach(col => tr += `<td class="py-4 px-6 border-b border-gray-50">${col.render(row[col.key], row)}</td>`);
                
                let actions = '';
                if(currentTab === 'orders') {
                    if(row.status === 'pending') {
                        actions = `
                            <button onclick="openDispatchModal(${row.id}, '${row.reference}', '${row.client_name}')" class="text-white bg-blue-600 hover:bg-blue-700 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest mr-2 shadow-sm transition-colors"><i class="fas fa-truck-loading mr-1"></i> Générer BL</button>
                            <button onclick="deleteRecord('order', ${row.id})" class="text-red-400 hover:text-red-600 p-2"><i class="fas fa-trash-alt"></i></button>`;
                    } else {
                        // If dispatched or delivered, no trash can, just view status
                        actions = `<span class="text-gray-400 text-xs italic">Logistique en charge</span>`;
                    }
                } else if(currentTab === 'dispatch') {
                    actions = `<a href="/bon_livraison.php?token=${row.token}" target="_blank" class="text-blue-600 bg-blue-50 hover:bg-blue-100 p-2 rounded-lg mr-2" title="Imprimer BL PDF"><i class="fas fa-print"></i></a>`;
                    
                    if(row.status === 'dispatched') {
                        actions += `<button onclick="openCompleteDeliveryModal(${row.id}, '${row.reference}', ${row.payment_collected || 0})" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest shadow-sm transition-colors"><i class="fas fa-check-double mr-1"></i> Retour Chauffeur</button>`;
                    } else if (row.status === 'driver_confirmed') {
                        actions += `<button onclick="showBLShareModal('${row.reference}', '${row.token}', '')" class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest shadow-sm transition-colors"><i class="fas fa-qrcode mr-1"></i> Signature Client</button>`;
                    } else if (row.status === 'completed') {
                        actions += `<span class="text-emerald-500 text-[10px] font-black uppercase px-2 py-1"><i class="fas fa-lock mr-1"></i>Clôturé</span>`;
                    }
                }

                tr += `<td class="py-4 px-6 border-b border-gray-50 text-right whitespace-nowrap">${actions}</td></tr>`;
                tbody.innerHTML += tr;
            });
        }


        // 3. ORDER CREATION LOGIC
        function openOrderModal() {
            document.getElementById('form-order').reset();
            document.getElementById('so_date').valueAsDate = new Date();
            document.getElementById('so-lines-container').innerHTML = '';
            addOrderLine(); 
            calculateOrderTotals();
            document.getElementById('orderModal').classList.remove('hidden');
        }

        function addOrderLine() {
            const container = document.getElementById('so-lines-container');
            const rowId = Date.now();
            
            let prodOptions = '<option value="">Choisir un produit...</option>';
            // Note: In Sales, we look at client-specific pricing. If metaData includes custom prices, we handle it via backend. For UI, we use base_price as fallback.
            metaData.products.forEach(p => {
                prodOptions += `<option value="${p.id}" data-price="${p.base_price}">${p.name} (${p.format || ''})</option>`;
            });

            const tr = document.createElement('tr');
            tr.className = "item-row hover:bg-gray-100 transition-colors";
            tr.innerHTML = LPC.html`
                <td class="p-1 border-r border-gray-100"><select class="so-prod-select seamless-input p-2 font-bold text-gray-800" onchange="autoFillPrice(this, ${rowId})" required>${prodOptions}</select></td>
                <td class="p-1 border-r border-gray-100"><input type="number" class="so-qty seamless-input p-2 text-center font-bold text-gray-900" value="1" min="1" oninput="calcRow(${rowId})" id="qty_${rowId}" required></td>
                <td class="p-1 border-r border-gray-100"><input type="number" class="so-price seamless-input p-2 text-right font-bold text-gray-900" value="0" min="0" oninput="calcRow(${rowId})" id="price_${rowId}" required></td>
                <td class="p-1 border-r border-gray-100 text-right pr-4 font-black text-gray-900" id="total_${rowId}">0</td>
                <td class="p-1 text-center"><button type="button" onclick="this.closest('tr').remove(); calculateOrderTotals();" class="text-red-300 hover:text-red-500 p-1"><i class="fas fa-times"></i></button></td>
            `;
            container.appendChild(tr);
        }

        function autoFillPrice(selectElement, rowId) {
            const price = selectElement.options[selectElement.selectedIndex].getAttribute('data-price') || 0;
            document.getElementById(`price_${rowId}`).value = price;
            calcRow(rowId);
        }

        function calcRow(rowId) {
            const qty = parseFloat(document.getElementById(`qty_${rowId}`).value) || 0;
            const price = parseFloat(document.getElementById(`price_${rowId}`).value) || 0;
            document.getElementById(`total_${rowId}`).innerText = LPC.fmt.int(qty * price);
            calculateOrderTotals();
        }

        function calculateOrderTotals() {
            let subtotal = 0;
            document.querySelectorAll('#so-lines-container .item-row').forEach(row => {
                const qty = parseFloat(row.querySelector('.so-qty').value) || 0;
                const price = parseFloat(row.querySelector('.so-price').value) || 0;
                subtotal += (qty * price);
            });
            const discount   = parseFloat(document.getElementById('so_discount').value) || 0;
            const grandTotal = subtotal - discount;

            const subEl   = document.getElementById('calc_so_subtotal');
            const grandEl = document.getElementById('calc_so_grandtotal');
            const warnEl  = document.getElementById('calc_so_discount_warning');

            subEl.innerText   = LPC.fmt.fcfa(subtotal);
            grandEl.innerText = LPC.fmt.fcfa(Math.max(0, grandTotal));

            // Discount validation: surface an EXPLICIT warning instead of silently
            // clamping to zero. Blocks submit if discount exceeds subtotal.
            const submitBtn = document.getElementById('btn-submit-order');
            if (discount < 0) {
                if (warnEl) { warnEl.textContent = 'Remise négative interdite.'; warnEl.className = 'text-xs font-bold text-rose-600 mt-1'; }
                grandEl.classList.add('text-rose-600');
                if (submitBtn) submitBtn.disabled = true;
            } else if (discount > subtotal) {
                const over = discount - subtotal;
                if (warnEl) {
                    warnEl.textContent = `Remise (${LPC.fmt.int(discount)} FCFA) supérieure au sous-total de ${LPC.fmt.int(over)} FCFA. Réduisez la remise.`;
                    warnEl.className = 'text-xs font-bold text-rose-600 mt-1';
                }
                grandEl.classList.add('text-rose-600');
                if (submitBtn) submitBtn.disabled = true;
            } else if (subtotal > 0 && discount > subtotal * 0.5) {
                if (warnEl) {
                    warnEl.textContent = `Remise supérieure à 50% du sous-total — vérifiez ou demandez une approbation.`;
                    warnEl.className = 'text-xs font-bold text-amber-600 mt-1';
                }
                grandEl.classList.remove('text-rose-600');
                if (submitBtn) submitBtn.disabled = false;
            } else {
                if (warnEl) { warnEl.textContent = ''; warnEl.className = 'text-xs mt-1 hidden'; }
                grandEl.classList.remove('text-rose-600');
                if (submitBtn) submitBtn.disabled = false;
            }
        }

        async function submitOrder() {
            const clientId = document.getElementById('so_client').value;
            if(!clientId) return LPC.modal.alert("Sélectionnez un client.");
            
            const rows = document.querySelectorAll('#so-lines-container .item-row');
            if(rows.length === 0) return LPC.modal.alert("Ajoutez un produit.");

            const payload = {
                action: 'save_order',
                client_id: clientId,
                date: document.getElementById('so_date').value,
                discount_amount: document.getElementById('so_discount').value || 0,
                items: []
            };

            let valid = true;
            rows.forEach(row => {
                const pId = row.querySelector('.so-prod-select').value;
                const qty = row.querySelector('.so-qty').value;
                const price = row.querySelector('.so-price').value;
                if(!pId || qty <= 0) valid = false;
                payload.items.push({ product_id: pId, quantity: qty, unit_price: price });
            });

            if(!valid) return LPC.modal.alert("Vérifiez les lignes produits.");

            try {
                const response = await fetch('/api/v1/sales_controller.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
                });
                const result = await response.json();
                if(result.status === 'success') {
                    closeModal('orderModal'); loadTabData();
                } else LPC.modal.alert("Erreur: " + result.message);
            } catch(e) { LPC.modal.alert("Erreur serveur."); }
        }

        // 4. DISPATCH LOGIC (Generates BL)
        function openDispatchModal(orderId, reference, clientName) {
            document.getElementById('form-dispatch').reset();
            document.getElementById('disp_so_id').value = orderId;
            document.getElementById('disp_so_ref').innerText = reference;
            document.getElementById('disp_client_name').innerText = clientName;
            document.getElementById('disp_date').valueAsDate = new Date();
            autoSelectVehicle(); // Ensure vehicle drops back to empty
            document.getElementById('dispatchModal').classList.remove('hidden');
        }

        async function submitDispatch() {
            const payload = {
                action: 'generate_dispatch',
                sales_order_id: document.getElementById('disp_so_id').value,
                date: document.getElementById('disp_date').value,
                driver_id: document.getElementById('disp_driver').value,
                vehicle_id: document.getElementById('disp_vehicle').value
            };

            try {
                const response = await fetch('/api/v1/sales_controller.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
                });
                const result = await response.json();
                if(result.status === 'success') {
                    closeModal('dispatchModal'); 
                    loadTabData(); // Will refresh and switch tab manually if needed
                    window.open(`/bon_livraison.php?token=${result.token}`, '_blank');
                    switchTab('dispatch');
                } else LPC.modal.alert("Erreur: " + result.message);
            } catch(e) { LPC.modal.alert("Erreur serveur."); }
        }

        // 5. DELIVERY COMPLETION (Driver Returns & Empties Collection)
        async function openCompleteDeliveryModal(deliveryId, reference, cashCollected) {
            document.getElementById('form-close-delivery').reset();
            document.getElementById('close_delivery_id').value = deliveryId;
            document.getElementById('close_bl_ref').innerText = reference;
            document.getElementById('close_cash').value = cashCollected || 0;
            
            try {
                const response = await fetch(`/api/v1/sales_controller.php?action=get_delivery_items&id=${deliveryId}`);
                const result = await response.json();
                
                if(result.status === 'success') {
                    const container = document.getElementById('close-lines-container');
                    container.innerHTML = '';
                    
                    result.data.forEach(item => {
                        // Dynamically generate Empties Input if the product requires it
                        let emptyInputHtml = '';
                        if (item.linked_empty_id) {
                            emptyInputHtml = `
                                <div class="mt-2 p-2 bg-amber-50 rounded-lg border border-amber-200 flex justify-between items-center shadow-sm">
                                    <span class="text-[10px] font-black text-amber-800 uppercase tracking-wide"><i class="fas fa-undo mr-1"></i> Vides rendus (${item.empty_name})</span>
                                    <input type="number" class="empty-qty-input w-16 bg-white border border-amber-300 rounded p-1 text-center font-black text-amber-900 outline-none focus:ring-2 focus:ring-amber-500" data-item-id="${item.id}" value="0" min="0">
                                </div>
                            `;
                        }

                        container.innerHTML += LPC.html`
                            <tr>
                                <td class="py-3 px-6 border-r border-gray-100 align-top">
                                    <p class="font-bold text-gray-800 mb-1">${item.product_name}</p>
                                    ${emptyInputHtml}
                                </td>
                                <td class="py-3 px-4 text-center font-black text-gray-500 bg-gray-50 border-r border-gray-100 align-middle">${item.dispatched_qty}</td>
                                <td class="py-3 px-4 align-middle">
                                    <input type="number" class="close-qty-input w-full bg-emerald-50 text-emerald-900 border border-emerald-200 rounded-lg p-3 text-center text-lg font-black outline-none focus:ring-2 focus:ring-emerald-500" data-item-id="${item.id}" value="${item.dispatched_qty}" min="0" max="${item.dispatched_qty}">
                                </td>
                            </tr>
                        `;
                    });
                    document.getElementById('closeDeliveryModal').classList.remove('hidden');
                }
            } catch (e) { LPC.modal.alert("Impossible de charger les lignes du BL."); }
        }

        async function submitCloseDelivery() {
            const payload = {
                action: 'driver_confirm_delivery', // NEW ACTION
                delivery_id: document.getElementById('close_delivery_id').value,
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
                    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
                });
                const result = await response.json();
                if(result.status === 'success') {
                    closeModal('closeDeliveryModal'); 
                    const blRef = document.getElementById('close_bl_ref').innerText;
                    showBLShareModal(blRef, result.token, ''); // Trigger QR Code
                } else LPC.modal.alert("Erreur: " + result.message);
            } catch(e) { LPC.modal.alert("Erreur serveur."); }
        }

        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
        function filterData() {
            const q = document.getElementById('search-input').value.toLowerCase();
            renderTable(moduleData.filter(i => Object.values(i).some(v => String(v).toLowerCase().includes(q))));
        }
        async function deleteRecord(type, id) {
            if(!(await LPC.modal.confirm("Supprimer cette commande ?"))) return;
            const fd = new FormData(); fd.append('action', 'delete_order'); fd.append('id', id);
            await fetch('/api/v1/sales_controller.php', { method: 'POST', body: fd });
            loadTabData();
        }
        
        let currentBLShareLink = "";
        let qrcode_bl_instance = null;
        const domain = window.location.origin;

        function showBLShareModal(ref, token, phone) {
            document.getElementById('share_bl_ref').innerText = ref;
            if (phone) document.getElementById('bl_client_phone').value = phone;
            currentBLShareLink = `${domain}/sign_bl.php?token=${token}`;
            
            const qrContainer = document.getElementById('qrcode-bl');
            qrContainer.innerHTML = "";
            qrcode_bl_instance = new QRCode(qrContainer, { text: currentBLShareLink, width: 192, height: 192, colorDark: "#0F172A", correctLevel: QRCode.CorrectLevel.H });
            
            openModal('modal-share-bl');
            loadTabData(); // Refresh background table
        }

        function shareBLWhatsApp() {
            let phone = document.getElementById('bl_client_phone').value.trim().replace(/\s+/g, '');
            if (phone.length === 9) phone = "237" + phone;
            const text = encodeURIComponent(`Bonjour,\nVoici le lien sécurisé pour vérifier et valider votre Bon de Livraison LPC :\n${currentBLShareLink}`);
            window.open(phone ? `https://wa.me/${phone}?text=${text}` : `https://api.whatsapp.com/send?text=${text}`, '_blank');
        }
    