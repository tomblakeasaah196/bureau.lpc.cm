/**
 * assets/js/modules/operations-empties_collection.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/operations/empties_collection.php (Sprint 6 D2).
 *
 * Original block was ~17,849 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        let globalClients = [];
        let currentShareLink = "";
        let globalRecyclePrices = {};
        const domain = window.location.origin;

        window.onload = () => {
            switchTab('owed');
            loadOwedEmpties();
            loadClients();
            loadHistory();
            loadRecyclingPrices();
        };

        const fmt = (num) => LPC.fmt.int(num || 0);

        function switchTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(el => {
                el.classList.remove('text-lpc-dark', 'border-lpc-dark');
                el.classList.add('text-gray-400', 'border-transparent');
            });
            document.getElementById(`tab-${tab}`).classList.remove('text-gray-400', 'border-transparent');
            document.getElementById(`tab-${tab}`).classList.add('text-lpc-dark', 'border-lpc-dark');
            
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById(`content-${tab}`).classList.add('active');
            if (tab === 'revenue') loadRevenueData();
        }
        
        // ==========================================
        // TAB 5: REVENUE LOGIC (ADMIN/FINANCE ONLY)
        // ==========================================
        async function loadRevenueData() {
            const tbody = document.getElementById('tbody-revenue');
            if (!tbody) return;

            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-8 text-gray-400 font-bold"><i class="fas fa-spinner fa-spin"></i> Chargement...</td></tr>';
            
            try {
                const res = await fetch('/api/v1/cre_controller.php?action=get_recycling_revenue');
                const result = await res.json();
                
                if (result.status === 'success') {
                    const s = result.data.stats;
                    // Update Money
                    document.getElementById('kpi_rev_total').innerText = LPC.fmt.fcfa(s.total_revenue);
                    // Update Quantities
                    document.getElementById('stat_20c').innerText = s.qty_20c;
                    document.getElementById('stat_20n').innerText = s.qty_20n;
                    document.getElementById('stat_10c').innerText = s.qty_10c;
                    document.getElementById('stat_10n').innerText = s.qty_10n;
                    
                    tbody.innerHTML = '';
                    if(result.data.table.length === 0) {
                        return tbody.innerHTML = '<tr><td colspan="5" class="text-center py-8 text-gray-400">Aucune vente.</td></tr>';
                    }
                    
                    result.data.table.forEach(r => {
                        const d = new Date(r.created_at);
                        const dateStr = d.toLocaleDateString('fr-FR');
                        
                        tbody.innerHTML += LPC.html`
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="py-3 px-4 text-xs font-bold text-gray-500">${dateStr}<br><span class="text-[10px] font-black text-amber-600">${r.reference}</span></td>
                                <td class="py-3 px-4 font-black text-gray-900">${r.driver_name}</td>
                                <td class="py-3 px-4">
                                    <p class="text-[10px] text-gray-400 font-black uppercase mb-1">${r.recycler_location}</p>
                                    <p class="text-xs font-bold text-blue-600 italic">${r.details}</p>
                                </td>
                                <td class="py-3 px-4 text-right font-black text-emerald-600 text-lg">${fmt(r.total_amount)} F</td>
                            </tr>
                        `;
                    });
                }
            } catch(e) { console.error(e); }
        }

        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
        
        function filterTable(tbodyId, q) {
            q = q.toLowerCase();
            const rows = document.getElementById(tbodyId).getElementsByTagName('tr');
            for (let i = 0; i < rows.length; i++) rows[i].style.display = rows[i].innerText.toLowerCase().includes(q) ? '' : 'none';
        }

        // ==========================================
        // TAB 1: OWED EMPTIES LOGIC
        // ==========================================
        async function loadOwedEmpties() {
            const tbody = document.getElementById('tbody-owed');
            try {
                const res = await fetch('/api/v1/cre_controller.php?action=get_owed_empties');
                const result = await res.json();
                
                tbody.innerHTML = '';
                if (result.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-8 text-gray-400 font-bold">Aucun solde dû.</td></tr>';
                    return;
                }

                result.data.forEach(row => {
                    const siteName = row.site_name ? `<span class="text-xs text-blue-600 block">${row.site_name}</span>` : '';
                    tbody.innerHTML += LPC.html`
                        <tr class="hover:bg-gray-50 border-b border-gray-50 transition-colors">
                            <td class="py-3 px-4 font-black text-gray-900">${row.client_name} ${siteName}</td>
                            <td class="py-3 px-4 text-xs font-bold text-gray-600">${row.product_name}</td>
                            <td class="py-3 px-4 text-center font-black text-blue-600">${row.total_out}</td>
                            <td class="py-3 px-4 text-center font-black text-green-600">${row.total_in}</td>
                            <td class="py-3 px-4 text-center font-black text-rose-600 bg-rose-50/50">${row.quantity_owed}</td>
                            <td class="py-3 px-4 text-right">
                                <button onclick="prefillCollection(${row.client_id}, ${row.site_id || 'null'})" class="bg-gray-900 hover:bg-black text-white px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest shadow-sm transition-all"><i class="fas fa-hand-holding-water mr-1"></i> Collecter</button>
                            </td>
                        </tr>
                    `;
                });
            } catch(e) { tbody.innerHTML = '<tr><td colspan="6" class="text-center py-8 text-red-500">Erreur réseau.</td></tr>'; }
        }

        function prefillCollection(clientId, siteId) {
            switchTab('new');
            document.getElementById('client_id').value = clientId;
            loadSites(); 
            setTimeout(() => {
                if (siteId) document.getElementById('site_id').value = siteId;
            }, 100);
        }

        // ==========================================
        // TAB 2: COLLECTION / CRE LOGIC
        // ==========================================
        async function loadClients() {
            try {
                const res = await fetch('/api/v1/cre_controller.php?action=get_clients');
                const result = await res.json();
                if (result.status === 'success') {
                    globalClients = result.data;
                    const sel = document.getElementById('client_id');
                    globalClients.forEach(c => { sel.innerHTML += LPC.html`<option value="${c.id}">${c.name}</option>`; });
                }
            } catch(e) {}
        }

        function loadSites() {
            const clientId = document.getElementById('client_id').value;
            const siteSel = document.getElementById('site_id');
            const siteContainer = document.getElementById('site_container');
            siteSel.innerHTML = '<option value="">-- Siège Principal --</option>';
            document.getElementById('client_phone').value = ""; 
            
            if (!clientId) return siteContainer.classList.add('hidden');
            const client = globalClients.find(c => c.id == clientId);
            if (client && client.phone) document.getElementById('client_phone').value = client.phone;

            if (client && client.sites && client.sites.length > 0) {
                siteContainer.classList.remove('hidden');
                client.sites.forEach(s => { siteSel.innerHTML += LPC.html`<option value="${s.id}" data-phone="${s.phone || ''}">${s.name}</option>`; });
            } else {
                siteContainer.classList.add('hidden');
            }
        }

        async function generateCRE() {
            const clientId = document.getElementById('client_id').value;
            if (!clientId) return LPC.modal.alert("Veuillez sélectionner un client.");

            const payload = {
                action: 'create_cre', client_id: clientId, site_id: document.getElementById('site_id').value,
                quantities: {
                    '20L_cork': parseInt(document.getElementById('qty_20l_cork').value) || 0,
                    '20L_nocork': parseInt(document.getElementById('qty_20l_nocork').value) || 0,
                    '10L_cork': parseInt(document.getElementById('qty_10l_cork').value) || 0,
                    '10L_nocork': parseInt(document.getElementById('qty_10l_nocork').value) || 0
                }
            };
            
            if (Object.values(payload.quantities).reduce((a,b)=>a+b,0) === 0) return LPC.modal.alert("Veuillez saisir au moins une quantité.");

            try {
                const res = await fetch('/api/v1/cre_controller.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
                const result = await res.json();
                if (result.status === 'success') {
                    document.getElementById('form-cre').reset(); loadSites();
                    showShareModal(result.data.reference, result.data.token, document.getElementById('client_phone').value);
                } else LPC.modal.alert(result.message);
            } catch(e) { LPC.modal.alert("Erreur."); }
        }

        let qrcode_instance = null;
        function showShareModal(ref, token, phone) {
            document.getElementById('share_ref').innerText = ref;
            if (phone) document.getElementById('client_phone').value = phone;
            currentShareLink = `${domain}/sign_cre.php?token=${token}`;
            const qrContainer = document.getElementById('qrcode');
            qrContainer.innerHTML = "";
            qrcode_instance = new QRCode(qrContainer, { text: currentShareLink, width: 192, height: 192, colorDark: "#0F172A", correctLevel: QRCode.CorrectLevel.H });
            openModal('modal-share');
            loadHistory();
        }

        function shareWhatsApp() {
            let phone = document.getElementById('client_phone').value.trim().replace(/\s+/g, '');
            if (phone.length === 9) phone = "237" + phone;
            const text = encodeURIComponent(`Bonjour,\nLien sécurisé pour signer le Retour d'Emballages LPC :\n${currentShareLink}`);
            window.open(phone ? `https://wa.me/${phone}?text=${text}` : `https://api.whatsapp.com/send?text=${text}`, '_blank');
        }

        // ==========================================
        // TAB 3: RECYCLING SALES LOGIC
        // ==========================================
        // Sprint-4-Batch-B: the four hardcoded IDs 901-904 are replaced with
        // a live catalog from cre_controller::get_empty_products. Legacy
        // markup still uses rec_901/902/903/904 as element IDs — they now
        // map to the returned bottle_size + has_cork tuple via type_key.
        let globalEmptyCatalog = [];    // [{ id, name, base_price, bottle_size, has_cork, type_key }]
        const LEGACY_TYPE_MAP = { '20L_cork':'901','20L_nocork':'902','10L_cork':'903','10L_nocork':'904' };

        async function loadRecyclingPrices() {
            try {
                const res = await fetch('/api/v1/cre_controller.php?action=get_empty_products');
                const result = await res.json();
                if (result.status === 'success') {
                    globalEmptyCatalog = result.data || [];
                    globalEmptyCatalog.forEach(p => {
                        globalRecyclePrices[p.id] = parseFloat(p.base_price);
                        const legacyId = LEGACY_TYPE_MAP[p.type_key];
                        if (legacyId) {
                            const labelEl = document.getElementById(`price_${legacyId}`);
                            if (labelEl) labelEl.innerText = `${p.base_price} F`;
                        }
                    });
                }
            } catch(e) { console.error("Could not load empty-product catalog", e); }
        }

        function calcRecycleTotal() {
            let total = 0;
            globalEmptyCatalog.forEach(p => {
                const legacyId = LEGACY_TYPE_MAP[p.type_key];
                if (!legacyId) return;
                const qty = parseInt(document.getElementById(`rec_${legacyId}`).value) || 0;
                total += (qty * (globalRecyclePrices[p.id] || 0));
            });
            document.getElementById('recycle_total').innerText = fmt(total);
        }

        async function submitRecycling() {
            const location = document.getElementById('recycler_location').value.trim();
            if (!location) return LPC.modal.alert("Veuillez saisir le lieu de recyclage.");

            const itemsToSell = [];
            globalEmptyCatalog.forEach(p => {
                const legacyId = LEGACY_TYPE_MAP[p.type_key];
                if (!legacyId) return;
                const qty = parseInt(document.getElementById(`rec_${legacyId}`).value) || 0;
                if (qty > 0) itemsToSell.push({ product_id: p.id, quantity: qty });
            });

            if (itemsToSell.length === 0) return LPC.modal.alert("Veuillez saisir au moins une quantité à vendre.");

            const btn = document.getElementById('btn-submit-recycling');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin text-lg"></i> Validation...';
            btn.disabled = true;

            const payload = { action: 'sell_to_recycler', location: location, items: itemsToSell };

            try {
                const res = await fetch('/api/v1/cre_controller.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
                const result = await res.json();
                if (result.status === 'success') {
                    LPC.modal.alert(`Vente validée ! Montant attendu en caisse : ${fmt(result.data.total_amount)} FCFA`);
                    document.getElementById('form-recycle').reset();
                    calcRecycleTotal();
                } else {
                    LPC.modal.alert("Erreur: " + result.message);
                }
            } catch (e) {
                LPC.modal.alert("Erreur réseau.");
            } finally {
                btn.innerHTML = '<i class="fas fa-hand-holding-usd text-lg"></i> Valider la Vente (Cash)';
                btn.disabled = false;
            }
        }

        // ==========================================
        // TAB 4: HISTORY LOGIC
        // ==========================================
        async function loadHistory() {
            const container = document.getElementById('history_container');
            try {
                const res = await fetch('/api/v1/cre_controller.php?action=get_history');
                const result = await res.json();
                container.innerHTML = '';
                if (result.data.length === 0) return container.innerHTML = '<p class="text-center py-8 text-gray-400 font-bold">Aucun historique de collecte.</p>';

                result.data.forEach(cre => {
                    let statusUI = cre.status === 'en_transit' ? '<span class="bg-amber-100 text-amber-700 px-2 py-1 rounded text-[9px] font-black uppercase"><i class="fas fa-clock"></i> En Attente</span>' :
                                   cre.status === 'signed' ? '<span class="bg-green-100 text-green-700 px-2 py-1 rounded text-[9px] font-black uppercase"><i class="fas fa-check-double"></i> Signé</span>' :
                                   '<span class="bg-rose-100 text-rose-700 px-2 py-1 rounded text-[9px] font-black uppercase"><i class="fas fa-times"></i> Refusé</span>';
                    
                    let actionBtn = cre.status === 'en_transit' ? `<button onclick="showShareModal('${cre.reference}', '${cre.token}', '${cre.client_phone}')" class="text-lpc-dark font-black text-xs bg-green-50 px-3 py-2 rounded-lg border border-green-200 w-full mt-3">Renvoyer Lien</button>` :
                                    cre.status === 'signed' ? `<a href="/print_cre.php?token=${cre.token}" target="_blank" class="block text-center text-gray-600 font-black text-xs bg-gray-100 hover:bg-gray-200 px-3 py-2 rounded-lg border border-gray-300 w-full mt-3"><i class="fas fa-file-pdf text-red-500 mr-1"></i> Voir le PDF</a>` : '';

                    container.innerHTML += LPC.html`
                        <div class="bg-white p-4 rounded-2xl shadow-sm border border-gray-100">
                            <div class="flex justify-between items-start mb-2">
                                <div><h4 class="font-black text-gray-900 text-sm">${cre.client_name}</h4><p class="text-[10px] font-bold text-gray-400 mt-0.5">${cre.reference}</p></div>
                                ${statusUI}
                            </div>
                            ${actionBtn}
                        </div>`;
                });
            } catch(e) {}
        }
    
        /* ---------------------------------------------------------------------
           Arrival from a deep link (Sprint 7F).
           Reached as ?client_id=..&client=..&from=crm_clients from the CRM
           "Dette Emballages" drill-down. Narrow the owed-balances table to that
           client and pre-select them in the collection form, so the next action
           after "who owes me bottles?" is one click away.
           ------------------------------------------------------------------ */
        document.addEventListener('DOMContentLoaded', function () {
            if (!window.LPC || !LPC.deeplink || !LPC.deeplink.hasFilter()) return;

            LPC.deeplink.arrive(['#tbody-owed']);

            // The client <select> is populated asynchronously by loadClients(),
            // so wait for the option to exist rather than racing it. Give up
            // after ~4s instead of polling forever on a client that was deleted.
            var tries = 0;
            var timer = setInterval(function () {
                var sel = document.getElementById('client_id');
                if (sel && sel.querySelector('option[value="' + LPC.deeplink.clientId + '"]')) {
                    sel.value = String(LPC.deeplink.clientId);
                    if (typeof loadSites === 'function') loadSites();
                    clearInterval(timer);
                } else if (++tries > 40) {
                    clearInterval(timer);
                }
            }, 100);
        });
