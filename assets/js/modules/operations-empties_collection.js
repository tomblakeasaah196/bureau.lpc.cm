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

                        // Migration 060: flag sales that were priced off-catalogue
                        // so whoever reconciles the cash knows to expect a
                        // different figure, and can click through to the motive.
                        const badge = Number(r.override_count) > 0
                            ? LPC.raw(LPC.html`<span class="ml-1 inline-block bg-orange-100 text-orange-700 px-1.5 py-0.5 rounded text-[9px] font-black uppercase" title="Prix négocié(s)"><i class="fas fa-tag"></i> ${r.override_count} prix négocié</span>`)
                            : '';

                        tbody.innerHTML += LPC.html`
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="py-3 px-4 text-xs font-bold text-gray-500">${dateStr}<br><span class="text-[10px] font-black text-amber-600">${r.reference}</span></td>
                                <td class="py-3 px-4 font-black text-gray-900">${r.driver_name}</td>
                                <td class="py-3 px-4">
                                    <p class="text-[10px] text-gray-400 font-black uppercase mb-1">${r.recycler_location}</p>
                                    <p class="text-xs font-bold text-blue-600 italic">${r.details}</p>
                                    ${badge}
                                </td>
                                <td class="py-3 px-4 text-right font-black text-emerald-600 text-lg">${fmt(r.total_amount)} F</td>
                            </tr>
                        `;
                    });

                    loadPriceOverrides();
                }
            } catch(e) { console.error(e); }
        }

        /**
         * The negotiated-price register: every deviation, who made it, by how
         * much, and why. Lives under the revenue table because that is where a
         * supervisor asks "why doesn't this match?" — the answer should be on
         * the same screen as the question, not in a separate report.
         */
        async function loadPriceOverrides() {
            const box = document.getElementById('override_log');
            if (!box) return;
            try {
                const res = await fetch('/api/v1/cre_controller.php?action=get_price_overrides');
                const result = await res.json();
                const rows = (result && result.data) || [];

                if (rows.length === 0) {
                    box.innerHTML = '<p class="text-center py-6 text-gray-400 font-bold text-xs">Aucun prix négocié enregistré.</p>';
                    return;
                }

                box.innerHTML = '';
                rows.forEach(o => {
                    const when   = new Date(o.created_at).toLocaleString('fr-FR');
                    const up     = Number(o.delta_amount) > 0;
                    const pct    = o.delta_pct === null || o.delta_pct === undefined
                        ? '' : ` (${Number(o.delta_pct) > 0 ? '+' : ''}${Number(o.delta_pct).toFixed(1)}%)`;
                    const impact = Number(o.total_impact);

                    box.innerHTML += LPC.html`
                        <div class="p-3 border-b border-gray-100 last:border-0">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-black text-gray-900">${o.product_name}
                                        <span class="text-[10px] font-bold text-gray-400 ml-1">${o.sale_reference || ''}</span>
                                    </p>
                                    <p class="text-[11px] font-bold text-gray-600 mt-0.5">
                                        ${fmt(o.base_price)} F → <span class="${up ? 'text-emerald-600' : 'text-rose-600'}">${fmt(o.override_price)} F</span>${pct}
                                        <span class="text-gray-400">· ${o.quantity} u.</span>
                                    </p>
                                    <p class="text-[11px] text-gray-500 italic mt-1 break-words">« ${o.reason} »</p>
                                    <p class="text-[10px] font-bold text-gray-400 mt-1">
                                        <i class="fas fa-user mr-1"></i>${o.user_name || 'Utilisateur supprimé'} · ${when}
                                        ${o.recycler_location ? LPC.raw(LPC.html` · <span>${o.recycler_location}</span>`) : ''}
                                    </p>
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="text-[9px] font-black uppercase text-gray-400">Impact</p>
                                    <p class="text-sm font-black ${impact > 0 ? 'text-emerald-600' : 'text-rose-600'}">${impact > 0 ? '+' : ''}${fmt(impact)} F</p>
                                </div>
                            </div>
                        </div>`;
                });
            } catch (e) {
                box.innerHTML = '<p class="text-center py-6 text-gray-400 font-bold text-xs">Registre indisponible.</p>';
            }
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
                    // row.site_name is client-entered, so the inner LPC.html
                    // escapes it before LPC.raw marks the snippet safe.
                    const siteName = row.site_name ? LPC.raw(LPC.html`<span class="text-xs text-blue-600 block">${row.site_name}</span>`) : '';
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

        /* ---------------------------------------------------------------------
           NEGOTIATED PRICES (migration 060).

           Prices at the recycler's gate are haggled — bottle shape, cork
           condition, lot quality, market. Until now the driver could only book
           the catalogue price, so the cash in his hand and the cash in the
           system drifted apart and nobody could say why.

           `priceOverrides` holds the deviations for the sale currently being
           composed, keyed by product id:
               { [productId]: { price: Number, reason: String } }

           It is deliberately transient — cleared on submit and on reset. A
           negotiated price belongs to one transaction, not to the catalogue,
           so nothing here is ever written back to globalRecyclePrices (which
           stays the pristine catalogue) and nothing is persisted client-side.
           The server re-validates every figure and refuses any deviation it
           cannot attribute and explain, so this object is a convenience, not
           a source of truth.
           ------------------------------------------------------------------ */
        let priceOverrides = {};
        let priceModalProductId = null;   // product being edited in the modal
        const canOverridePrice = window.LPC_CAN_OVERRIDE_PRICE === true;

        /** Catalogue price for a product — never mutated by an override. */
        function basePriceOf(productId) {
            return parseFloat(globalRecyclePrices[productId]) || 0;
        }

        /** Price actually applied: the negotiated one if present, else catalogue. */
        function effectivePriceOf(productId) {
            const o = priceOverrides[productId];
            return o ? o.price : basePriceOf(productId);
        }

        function productByLegacyId(legacyId) {
            return globalEmptyCatalog.find(p => LEGACY_TYPE_MAP[p.type_key] === String(legacyId)) || null;
        }

        async function loadRecyclingPrices() {
            try {
                const res = await fetch('/api/v1/cre_controller.php?action=get_empty_products');
                const result = await res.json();
                if (result.status === 'success') {
                    globalEmptyCatalog = result.data || [];
                    globalEmptyCatalog.forEach(p => {
                        globalRecyclePrices[p.id] = parseFloat(p.base_price);
                    });
                    renderRecyclingPrices();
                }
            } catch(e) { console.error("Could not load empty-product catalog", e); }
        }

        /**
         * Paint the four price labels. An overridden label turns orange with a
         * strikethrough of the catalogue price, so the deviation is visible at
         * a glance on the form itself — not only after submitting.
         */
        function renderRecyclingPrices() {
            globalEmptyCatalog.forEach(p => {
                const legacyId = LEGACY_TYPE_MAP[p.type_key];
                if (!legacyId) return;
                const labelEl = document.getElementById(`price_${legacyId}`);
                if (!labelEl) return;

                const base = basePriceOf(p.id);
                const ov   = priceOverrides[p.id];

                if (ov) {
                    labelEl.className = 'text-[10px] font-black text-orange-600';
                    labelEl.innerHTML = LPC.html`<span class="line-through text-gray-400 font-bold mr-1">${fmt(base)}</span>${fmt(ov.price)} F`;
                    labelEl.title = `Prix négocié — ${ov.reason}`;
                } else {
                    labelEl.className = 'text-[10px] font-black text-amber-600';
                    labelEl.innerText = `${fmt(base)} F`;
                    labelEl.title = '';
                }
            });
            renderOverrideBanner();
        }

        /** The running summary above the cash total. Hidden when nothing deviates. */
        function renderOverrideBanner() {
            const banner = document.getElementById('override_banner');
            const list   = document.getElementById('override_list');
            if (!banner || !list) return;

            const ids = Object.keys(priceOverrides);
            if (ids.length === 0) { banner.classList.add('hidden'); list.innerHTML = ''; return; }

            list.innerHTML = '';
            ids.forEach(pid => {
                const p  = globalEmptyCatalog.find(x => String(x.id) === String(pid));
                const ov = priceOverrides[pid];
                if (!p) return;
                const legacyId = LEGACY_TYPE_MAP[p.type_key];
                list.innerHTML += LPC.html`
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-[11px] font-black text-orange-900 leading-tight">${p.name}</p>
                            <p class="text-[10px] font-bold text-orange-700">${fmt(basePriceOf(p.id))} F → ${fmt(ov.price)} F</p>
                            <p class="text-[10px] text-orange-600 italic break-words">${ov.reason}</p>
                        </div>
                        <button type="button" class="js-clear-override shrink-0 text-orange-400 hover:text-rose-600 p-1" data-legacy="${legacyId}" title="Rétablir le prix catalogue" aria-label="Rétablir le prix catalogue">
                            <i class="fas fa-times-circle"></i>
                        </button>
                    </div>`;
            });
            banner.classList.remove('hidden');
        }

        /** Open the modal for one bottle type, prefilled with any existing override. */
        function openPriceModal(legacyId) {
            if (!canOverridePrice) return;
            const p = productByLegacyId(legacyId);
            if (!p) return LPC.modal.alert("Catalogue non chargé. Réessayez dans un instant.");

            priceModalProductId = p.id;
            const base = basePriceOf(p.id);
            const ov   = priceOverrides[p.id];

            document.getElementById('price_modal_product').innerText = p.name;
            document.getElementById('price_modal_base').innerText    = fmt(base);
            document.getElementById('price_modal_input').value       = ov ? ov.price : base;
            document.getElementById('price_modal_reason').value      = ov ? ov.reason : '';
            document.getElementById('price_modal_reset').classList.toggle('hidden', !ov);
            hidePriceModalError();
            updatePriceDelta();
            openModal('modal-price');
            setTimeout(() => {
                const input = document.getElementById('price_modal_input');
                input.focus(); input.select();
            }, 50);
        }

        /** Live "+50 F (+16.7%)" preview under the price input. */
        function updatePriceDelta() {
            const el = document.getElementById('price_modal_delta');
            if (!el || priceModalProductId === null) return;
            const base = basePriceOf(priceModalProductId);
            const val  = parseFloat(document.getElementById('price_modal_input').value);

            if (isNaN(val)) { el.innerText = ''; return; }
            const delta = val - base;
            if (Math.abs(delta) < 0.005) {
                el.className = 'text-[11px] font-bold mt-1.5 h-4 text-gray-400';
                el.innerText = 'Identique au prix catalogue';
                return;
            }
            const pct = base > 0 ? ` (${delta > 0 ? '+' : ''}${((delta / base) * 100).toFixed(1)}%)` : '';
            el.className = `text-[11px] font-bold mt-1.5 h-4 ${delta > 0 ? 'text-emerald-600' : 'text-rose-600'}`;
            el.innerText = `${delta > 0 ? '+' : ''}${fmt(delta)} F par unité${pct}`;
        }

        function showPriceModalError(msg) {
            const el = document.getElementById('price_modal_error');
            el.innerText = msg;
            el.classList.remove('hidden');
        }
        function hidePriceModalError() {
            document.getElementById('price_modal_error').classList.add('hidden');
        }

        /**
         * Validate and stage the override. These checks mirror the server's in
         * cre_controller.php (resolveRecyclingUnitPrice) so the operator gets
         * the message at the keyboard instead of after a round trip — the
         * server still owns the decision.
         */
        function applyPriceOverride() {
            if (priceModalProductId === null) return;
            const base   = basePriceOf(priceModalProductId);
            const raw    = document.getElementById('price_modal_input').value;
            const reason = document.getElementById('price_modal_reason').value.trim();
            const price  = parseFloat(raw);

            if (raw === '' || isNaN(price))  return showPriceModalError("Saisissez un prix valide.");
            if (price < 0)                   return showPriceModalError("Le prix ne peut pas être négatif.");
            if (price > 1000000)             return showPriceModalError("Prix irréaliste (max 1 000 000 FCFA/unité).");
            if (base > 0 && price > base * 10) {
                return showPriceModalError(`Prix irréaliste : ${fmt(price)} F contre ${fmt(base)} F au catalogue. Vérifiez la saisie.`);
            }

            // Back to catalogue → drop the override rather than storing a
            // zero-delta "deviation" that would pollute the audit log.
            if (Math.abs(price - base) < 0.005) {
                delete priceOverrides[priceModalProductId];
                closeModal('modal-price');
                renderRecyclingPrices();
                calcRecycleTotal();
                return;
            }

            if (reason.length < 5) return showPriceModalError("Motif requis (au moins 5 caractères) — il sera enregistré avec votre nom.");

            priceOverrides[priceModalProductId] = { price: Math.round(price * 100) / 100, reason: reason.slice(0, 500) };
            closeModal('modal-price');
            renderRecyclingPrices();
            calcRecycleTotal();
        }

        /** Revert one product to its catalogue price. */
        function resetPriceOverride() {
            if (priceModalProductId !== null) delete priceOverrides[priceModalProductId];
            closeModal('modal-price');
            renderRecyclingPrices();
            calcRecycleTotal();
        }

        function calcRecycleTotal() {
            let total = 0;
            globalEmptyCatalog.forEach(p => {
                const legacyId = LEGACY_TYPE_MAP[p.type_key];
                if (!legacyId) return;
                const qty = parseInt(document.getElementById(`rec_${legacyId}`).value) || 0;
                total += (qty * effectivePriceOf(p.id));
            });
            document.getElementById('recycle_total').innerText = fmt(total);
        }

        async function submitRecycling() {
            const location = document.getElementById('recycler_location').value.trim();
            if (!location) return LPC.modal.alert("Veuillez saisir le lieu de recyclage.");

            const itemsToSell = [];
            let overridesInSale = 0;
            globalEmptyCatalog.forEach(p => {
                const legacyId = LEGACY_TYPE_MAP[p.type_key];
                if (!legacyId) return;
                const qty = parseInt(document.getElementById(`rec_${legacyId}`).value) || 0;
                if (qty <= 0) return;

                const item = { product_id: p.id, quantity: qty };
                // Only send a price when it actually deviates. Staying silent
                // on untouched lines keeps the server on its catalogue path and
                // means a stray client value can never become an unlogged price.
                const ov = priceOverrides[p.id];
                if (ov) {
                    item.unit_price   = ov.price;
                    item.price_reason = ov.reason;
                    overridesInSale++;
                }
                itemsToSell.push(item);
            });

            if (itemsToSell.length === 0) return LPC.modal.alert("Veuillez saisir au moins une quantité à vendre.");

            // Deviations are consequential and irreversible once booked, so the
            // driver confirms them explicitly rather than discovering them in
            // the admin notification afterwards.
            if (overridesInSale > 0) {
                // LPC.modal.confirm() escapes its message into a single <p>, so
                // newlines would collapse and the list would read as one run-on
                // line. custom() takes real markup — worth it here, because the
                // whole point is that the driver can check each figure.
                let rows = '';
                itemsToSell.filter(i => i.unit_price !== undefined).forEach(i => {
                    const p = globalEmptyCatalog.find(x => x.id === i.product_id);
                    rows += LPC.html`
                        <li style="margin-bottom:.5rem">
                            <strong>${p ? p.name : i.product_id}</strong><br>
                            ${fmt(basePriceOf(i.product_id))} F → <strong>${fmt(i.unit_price)} F</strong><br>
                            <em style="opacity:.7">${priceOverrides[i.product_id].reason}</em>
                        </li>`;
                });
                const ok = await LPC.modal.custom({
                    level: 'warn',
                    title: 'Confirmer les prix négociés',
                    bodyHtml:
                        '<p><strong>' + overridesInSale + '</strong> prix hors catalogue sur cette vente :</p>' +
                        '<ul style="margin:.75rem 0;padding-left:1.1rem">' + rows + '</ul>' +
                        '<p>Votre nom et le motif seront enregistrés de façon permanente.</p>',
                    buttons: [
                        { label: 'Annuler',              value: false, variant: 'secondary' },
                        { label: 'Confirmer la vente',   value: true,  variant: 'primary', primary: true },
                    ],
                });
                if (!ok) return;
            }

            const btn = document.getElementById('btn-submit-recycling');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin text-lg"></i> Validation...';
            btn.disabled = true;

            const payload = { action: 'sell_to_recycler', location: location, items: itemsToSell };

            try {
                const res = await fetch('/api/v1/cre_controller.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
                const result = await res.json();
                if (result.status === 'success') {
                    const note = result.data.override_count > 0
                        ? ` — ${result.data.override_count} prix négocié(s) enregistré(s) à votre nom.`
                        : '';
                    LPC.modal.alert(`Vente validée ! Montant attendu en caisse : ${fmt(result.data.total_amount)} FCFA${note}`);
                    document.getElementById('form-recycle').reset();
                    // A negotiated price is scoped to the sale that just closed.
                    priceOverrides = {};
                    renderRecyclingPrices();
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

        /* Delegated listeners for the price-edit affordances. Delegated rather
           than per-element because the banner rows are re-rendered on every
           change, and the pencil buttons only exist when the server decided the
           user may override. */
        document.addEventListener('click', function (ev) {
            const editBtn = ev.target.closest('.js-edit-price');
            if (editBtn) { openPriceModal(editBtn.dataset.legacy); return; }

            const clearBtn = ev.target.closest('.js-clear-override');
            if (clearBtn) {
                const p = productByLegacyId(clearBtn.dataset.legacy);
                if (p) {
                    delete priceOverrides[p.id];
                    renderRecyclingPrices();
                    calcRecycleTotal();
                }
            }
        });

        document.addEventListener('input', function (ev) {
            if (ev.target && ev.target.id === 'price_modal_input') updatePriceDelta();
        });

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
                    // LPC.raw: static markup, no interpolation.
                    let statusUI = LPC.raw(
                                   cre.status === 'en_transit' ? '<span class="bg-amber-100 text-amber-700 px-2 py-1 rounded text-[9px] font-black uppercase"><i class="fas fa-clock"></i> En Attente</span>' :
                                   cre.status === 'signed' ? '<span class="bg-green-100 text-green-700 px-2 py-1 rounded text-[9px] font-black uppercase"><i class="fas fa-check-double"></i> Signé</span>' :
                                   '<span class="bg-rose-100 text-rose-700 px-2 py-1 rounded text-[9px] font-black uppercase"><i class="fas fa-times"></i> Refusé</span>');

                    // The "Renvoyer Lien" button used to build an inline
                    // onclick="showShareModal('<ref>', '<token>', '<phone>')".
                    // HTML-escaping cannot make that safe: the parser decodes
                    // entities BEFORE the JS is compiled, so an escaped quote in
                    // a client phone number turns back into a real quote and
                    // closes the argument list. The values move to data-*
                    // attributes — where escaping IS sufficient — and a single
                    // delegated listener below reads them back.
                    let actionBtn = cre.status === 'en_transit'
                        ? LPC.raw(LPC.html`<button type="button" class="js-cre-reshare text-lpc-dark font-black text-xs bg-green-50 px-3 py-2 rounded-lg border border-green-200 w-full mt-3" data-ref="${cre.reference}" data-token="${cre.token}" data-phone="${cre.client_phone || ''}">Renvoyer Lien</button>`)
                        : cre.status === 'signed'
                        ? LPC.raw(LPC.html`<a href="/print_cre.php?token=${cre.token}" target="_blank" class="block text-center text-gray-600 font-black text-xs bg-gray-100 hover:bg-gray-200 px-3 py-2 rounded-lg border border-gray-300 w-full mt-3"><i class="fas fa-file-pdf text-red-500 mr-1"></i> Voir le PDF</a>`)
                        : '';

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

        /* Delegated handler for the "Renvoyer Lien" buttons rendered above.
           Bound once on the container rather than per row, so it keeps working
           for cards added by later loadHistory() calls. */
        document.addEventListener('click', function (ev) {
            const btn = ev.target.closest('.js-cre-reshare');
            if (!btn) return;
            showShareModal(btn.dataset.ref, btn.dataset.token, btn.dataset.phone);
        });

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
