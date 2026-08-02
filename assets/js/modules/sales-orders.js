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
        let metaData = { clients: [], products: [], drivers: [], vehicles: [], suppliers: [], assignments: [] };

        // Migration 061 · logistics channel. Kept in sync with the enum on
        // deliveries.delivery_mode and with $ALLOWED_MODES in
        // api/v1/sales_controller.php. INTERNAL ONLY — never printed on the BL.
        const DELIVERY_MODES = {
            own_fleet:     { label: 'Flotte LPC',        icon: 'fa-truck',    short: 'Flotte' },
            supplier:      { label: 'Livr. Fournisseur', icon: 'fa-industry', short: 'Fournisseur' },
            client_pickup: { label: 'Enlèvement',        icon: 'fa-store',    short: 'Enlèvement' }
        };

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
                    // Migration 061: this column read driver_name alone and
                    // rendered "Enlèvement" for anything null — which after the
                    // business-model change would have mislabelled every
                    // supplier delivery as a warehouse pickup. It now reports
                    // the explicit channel. INTERNAL VIEW ONLY: none of this
                    // reaches the customer's BL.
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
                        if(v === 'draft') return '<span class="text-gray-500 bg-gray-100 px-2 py-1 rounded text-[10px] font-black uppercase">Brouillon</span>';
                        if(v === 'dispatched') return '<span class="text-blue-600 bg-blue-50 px-2 py-1 rounded text-[10px] font-black uppercase animate-pulse">En Transit</span>';
                        if(v === 'driver_confirmed') return '<span class="text-amber-600 bg-amber-50 px-2 py-1 rounded text-[10px] font-black uppercase border border-amber-200"><i class="fas fa-user-check mr-1"></i>Confirmé Chauffeur</span>';
                        if(v === 'completed') return '<span class="text-green-600 bg-green-50 px-2 py-1 rounded text-[10px] font-black uppercase">Terminé</span>';
                        return v;
                    }},
                    { key: 'payment_collected', label: 'Cash Collecté', render: v => parseFloat(v) > 0 ? `<span class="font-black text-emerald-600">${LPC.fmt.int(v)} FCFA</span>` : `<span class="text-gray-400 font-bold">-</span>` }
                ],
                kpis: [
                    // Was "Camions en Route". The value is now split by channel
                    // (fleet vs supplier) server-side, so the label can no
                    // longer claim every in-transit BL is an LPC truck.
                    { id: 'kpi_dl_dispatched', label: 'En Transit', icon: 'fa-truck-moving', color: 'text-blue-600', bg: 'bg-blue-50' },
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

            // Mark a deliberate vehicle choice so switching driver afterwards
            // does not quietly overwrite it with that driver's affectation.
            document.getElementById('disp_vehicle')?.addEventListener('change', function () {
                if (this.value) { this.dataset.userPicked = '1'; }
                else { delete this.dataset.userPicked; }
            });
            setDeliveryMode('own_fleet');   // paint the default mode buttons

            // Honour a #hash so other pages can deep-link straight to a tab
            // (the sales dashboard's "en attente de dispatch" card links to
            // orders.php#dispatch). Validated against `config` rather than
            // trusted, so an arbitrary #fragment cannot reach switchTab.
            const hash = (window.location.hash || '').replace('#', '');
            switchTab(Object.prototype.hasOwnProperty.call(config, hash) ? hash : 'orders');
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

                    // Migration 061 · drivers come from the full active-driver
                    // roster, NOT from today's vehicle_assignments. The old
                    // code looped metaData.assignments here, so on any day
                    // Flotte had not yet run the morning affectation this
                    // dropdown was empty and no BL could name a driver.
                    const driverSel = document.getElementById('disp_driver');
                    (metaData.drivers || []).forEach(d => {
                        driverSel.innerHTML += LPC.html`<option value="${d.id}">${d.first_name} ${d.last_name}</option>`;
                    });

                    // Vehicle is now a free choice among active vehicles rather
                    // than a locked mirror of the day's pairing.
                    const vehSel = document.getElementById('disp_vehicle');
                    (metaData.vehicles || []).forEach(v => {
                        vehSel.innerHTML += LPC.html`<option value="${v.id}">${v.plate_number} (${v.type})</option>`;
                    });

                    const supSel = document.getElementById('disp_supplier');
                    (metaData.suppliers || []).forEach(s => {
                        supSel.innerHTML += LPC.html`<option value="${s.id}">${s.name}</option>`;
                    });
                }
            } catch(e) { console.error("Metadata load failed"); }
        }

        /**
         * Pre-select the vehicle from today's affectation, if there is one.
         *
         * This used to REPLACE the whole option list with the single vehicle
         * the driver was assigned to — the select was `pointer-events-none`, so
         * whatever Flotte had recorded that morning was the only vehicle a BL
         * could carry. Now the full active fleet stays listed and the
         * affectation only moves the selection: a hint, not a constraint.
         * Drivers with no affectation simply land on "Aucun".
         */
        function autoSelectVehicle() {
            const driverId = document.getElementById('disp_driver').value;
            const vehSel   = document.getElementById('disp_vehicle');
            const hint     = document.getElementById('disp_vehicle_hint');
            if (!vehSel) return;

            const assignment = driverId
                ? (metaData.assignments || []).find(a => a.driver_id == driverId)
                : null;

            // Only override an untouched selection, so a deliberate manual
            // choice is not silently undone by changing the driver.
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
         * Switch the logistics channel.
         *
         * The three modes are mutually exclusive, so this shows one field group
         * and clears the others. Clearing matters: the server normalises the
         * payload to the mode anyway (a supplier id sent in own_fleet mode is
         * dropped), but leaving stale values visible behind a hidden panel is
         * how a user ends up believing they recorded something they did not.
         */
        function setDeliveryMode(mode) {
            if (!Object.prototype.hasOwnProperty.call(DELIVERY_MODES, mode)) return;
            document.getElementById('disp_mode').value = mode;

            document.querySelectorAll('.disp-mode-btn').forEach(btn => {
                const on = btn.dataset.mode === mode;
                btn.setAttribute('aria-checked', on ? 'true' : 'false');
                btn.className = 'disp-mode-btn flex flex-col items-center gap-1.5 p-3 rounded-xl border-2 text-[10px] font-black uppercase tracking-wide transition-all '
                    + (on ? 'border-blue-600 bg-blue-50 text-blue-700 shadow-sm'
                          : 'border-gray-200 bg-white text-gray-400 hover:border-gray-300 hover:text-gray-600');
            });

            const fleet   = document.getElementById('disp_fleet_fields');
            const supp    = document.getElementById('disp_supplier_fields');
            const pickup  = document.getElementById('disp_pickup_note');
            const vehSel  = document.getElementById('disp_vehicle');

            fleet.classList.toggle('hidden',  mode !== 'own_fleet');
            supp.classList.toggle('hidden',   mode !== 'supplier');
            pickup.classList.toggle('hidden', mode !== 'client_pickup');

            if (mode !== 'own_fleet') {
                document.getElementById('disp_driver').value = '';
                vehSel.value = '';
                delete vehSel.dataset.userPicked;
            }
            if (mode !== 'supplier') {
                document.getElementById('disp_supplier').value = '';
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
                        // "Retour Chauffeur" only makes sense on the fleet
                        // channel. A supplier delivery has no driver coming
                        // back to the yard, and a pickup has no one to return
                        // at all — the same close-out step is just an ops user
                        // recording what the client actually accepted.
                        const closeLabel = row.delivery_mode === 'own_fleet'
                            ? 'Retour Chauffeur' : 'Clôturer';
                        actions += `<button onclick="openCompleteDeliveryModal(${row.id}, '${row.reference}', ${row.payment_collected || 0}, '${row.delivery_mode || 'own_fleet'}')" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest shadow-sm transition-colors"><i class="fas fa-check-double mr-1"></i> ${closeLabel}</button>`;
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

        /**
         * Append one order line.
         *
         * The <select> here used to be built by looping metaData.products into
         * <option> tags — one full copy of the catalogue per line, rebuilt on
         * every "Ajouter Produit", showing nothing but a name and a format.
         * It is now a single empty <select> upgraded in place by
         * lpc-product-picker.js, which is searchable, shows SKU / stock / price
         * / consigne, and hides emballages behind a toggle. The catalogue is
         * fetched once per page and shared by every line.
         *
         * `Date.now()` was the row id, which collides when two rows are added
         * inside the same millisecond — trivial to do by holding Enter on the
         * add button, and the result is two rows writing to one another's
         * total cell. A counter cannot collide.
         */
        let soRowSeq = 0;

        function addOrderLine() {
            const container = document.getElementById('so-lines-container');
            const rowId = ++soRowSeq;

            const tr = document.createElement('tr');
            tr.className = "item-row hover:bg-gray-100 transition-colors";
            tr.innerHTML = LPC.html`
                <td class="p-1 border-r border-gray-100 align-top"><select class="so-prod-select" data-lpc-product-picker="sell" aria-label="Produit" required></select></td>
                <td class="p-1 border-r border-gray-100 align-top"><input type="number" class="so-qty seamless-input p-2 text-center font-bold text-gray-900" value="1" min="1" oninput="calcRow(${rowId})" id="qty_${rowId}" aria-label="Quantité" required></td>
                <td class="p-1 border-r border-gray-100 align-top"><input type="number" class="so-price seamless-input p-2 text-right font-bold text-gray-900" value="0" min="0" oninput="calcRow(${rowId})" id="price_${rowId}" aria-label="Prix unitaire" required></td>
                <td class="p-1 border-r border-gray-100 text-right pr-4 font-black text-gray-900 align-top" id="total_${rowId}">0</td>
                <td class="p-1 text-center align-top"><button type="button" onclick="removeOrderLine(this)" class="text-red-300 hover:text-red-500 p-1" aria-label="Supprimer la ligne"><i class="fas fa-times"></i></button></td>
            `;
            container.appendChild(tr);

            window.LPC?.productPicker?.mount(tr.querySelector('.so-prod-select'), {
                purpose: 'sell',
                clientId: document.getElementById('so_client')?.value || null,
                onSelect(product) {
                    const priceEl = document.getElementById(`price_${rowId}`);
                    priceEl.value = product ? Math.round(product.price) : 0;
                    calcRow(rowId);
                    if (product) {
                        // Land the cursor on the price: on a sales order the
                        // price is the field most likely to be overridden, and
                        // the qty already defaults to something usable.
                        priceEl.focus();
                        priceEl.select();
                        // Out-of-stock is a warning, not a block — orders are
                        // routinely taken against next week's delivery.
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
            // The picker's popup is appended to <body>, so removing the row
            // alone would strand it. destroy() takes both.
            const picker = window.LPC?.productPicker?.get(row.querySelector('.so-prod-select'));
            if (picker) picker.destroy();
            row.remove();
            calculateOrderTotals();
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

            // form.reset() does not clear a dataset flag, and the mode buttons
            // are not form controls, so both are reset explicitly.
            delete document.getElementById('disp_vehicle').dataset.userPicked;
            setDeliveryMode('own_fleet');
            autoSelectVehicle();
            document.getElementById('dispatchModal').classList.remove('hidden');
        }

        async function submitDispatch() {
            const mode = document.getElementById('disp_mode').value;

            // Client-side guards mirror the server's, purely for a faster and
            // more specific message. sales_controller.php re-validates all of
            // this — the browser is not the enforcement point.
            if (mode === 'own_fleet' && !document.getElementById('disp_driver').value) {
                return LPC.modal.alert("Sélectionnez le chauffeur, ou choisissez un autre mode de livraison.");
            }
            if (mode === 'supplier' && !document.getElementById('disp_supplier').value) {
                return LPC.modal.alert("Sélectionnez le fournisseur qui assure la livraison.");
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
        async function openCompleteDeliveryModal(deliveryId, reference, cashCollected, mode) {
            document.getElementById('form-close-delivery').reset();
            document.getElementById('close_delivery_id').value = deliveryId;
            document.getElementById('close_bl_ref').innerText = reference;
            document.getElementById('close_cash').value = cashCollected || 0;

            // The cash field was hardcoded "collecté par le chauffeur". On a
            // supplier or pickup delivery nobody called a chauffeur handled the
            // money, and asking for it under that name is how a real payment
            // gets left at zero.
            const cashLabel = document.getElementById('close_cash_label');
            if (cashLabel) {
                cashLabel.innerText = (mode === 'own_fleet' || !mode)
                    ? 'Montant collecté par le chauffeur (FCFA)'
                    : 'Montant collecté à la livraison (FCFA)';
            }

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
                            // empty_name comes from master data and lands both in
                            // text and next to a data-* attribute, so it is escaped
                            // by the inner LPC.html before being marked raw.
                            emptyInputHtml = LPC.raw(LPC.html`
                                <div class="mt-2 p-2 bg-amber-50 rounded-lg border border-amber-200 flex justify-between items-center shadow-sm">
                                    <span class="text-[10px] font-black text-amber-800 uppercase tracking-wide"><i class="fas fa-undo mr-1"></i> Vides rendus (${item.empty_name})</span>
                                    <input type="number" class="empty-qty-input w-16 bg-white border border-amber-300 rounded p-1 text-center font-black text-amber-900 outline-none focus:ring-2 focus:ring-amber-500" data-item-id="${item.id}" value="0" min="0">
                                </div>
                            `);
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
    