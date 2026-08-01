/**
 * assets/js/modules/inventory-stock.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/inventory/stock.php (Sprint 6 D2).
 *
 * Original block was ~30,144 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        let currentTab = 'stock';
        const fmt = (num) => LPC.fmt.int(num || 0);

        // Deep link from a PO detail page's "Recevoir la Marchandise" button:
        // /modules/inventory/stock.php?receive_po=<id>#receptions lands here,
        // jumps straight to the Réceptions tab and opens that PO's reception
        // modal — with a "Retour à la commande" link back to where it came
        // from, so the operator never has to re-navigate to find the PO again.
        let deepLinkPoId = null;

        window.onload = () => {
            const params = new URLSearchParams(window.location.search);
            const receivePo = parseInt(params.get('receive_po'), 10);
            if (receivePo) {
                deepLinkPoId = receivePo;
                switchTab('receptions').then(() => openReceptionForOrder(receivePo));
            } else {
                switchTab('stock');
            }
        };

        /** Deep-link entry point: we may not have the PO's reference handy
         *  (it isn't in the URL), so fetch the detail endpoint for it first,
         *  then reuse the normal openReception() flow. */
        async function openReceptionForOrder(poId) {
            try {
                const res = await fetch(`/api/v1/procurement_controller.php?action=get_po_detail&id=${poId}`);
                const result = await res.json();
                if (result.status === 'success') {
                    await openReception(poId, result.data.po.reference);
                } else {
                    LPC.modal.alert("Bon de commande introuvable : " + result.message);
                }
            } catch (e) {
                LPC.modal.alert("Impossible de charger ce bon de commande.");
            }
        }

        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        async function switchTab(tab) {
            currentTab = tab;
            
            // Tab Styling
            document.querySelectorAll('.tab-link').forEach(el => {
                el.classList.remove('border-lpc-dark', 'text-lpc-dark', 'font-black');
                el.classList.add('border-transparent', 'text-gray-400', 'font-bold');
            });
            document.getElementById(`tab-${tab}`).classList.remove('border-transparent', 'text-gray-400', 'font-bold');
            document.getElementById(`tab-${tab}`).classList.add('border-lpc-dark', 'text-lpc-dark', 'font-black');

            // Content Switching
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById(`content-${tab}`).classList.add('active');

            await fetchTabData(tab);
        }

        // Sprint 5: server-side pagination + search for the growing tabs.
        // Pagers are instantiated once per tab and cached; other tabs stay
        // on the legacy fetch until they're rewired.
        const pagers = {};

        async function fetchTabData(tab) {
            try {
                if (tab === 'stock') {
                    attachStockPager();
                    return;
                }
                if (tab === 'movements') {
                    // Movements re-attach whenever the period changes so the URL is fresh.
                    const period = document.getElementById('filter-period').value;
                    if (pagers.movements) { pagers.movements.destroy(); }
                    pagers.movements = LPC.paginator.attach(document.getElementById('tbody-movements'), {
                        url: `/api/v1/inventory_controller.php?action=read&tab=movements&period=${encodeURIComponent(period)}`,
                        renderRow: renderMovementRow,
                        pageSize: 25,
                        searchInput: document.getElementById('search-movements'),
                        dataPath: 'data.table',
                        envelopePath: 'data.pagination',
                        colspan: 5,
                        hashKey: 'mvt',
                    });
                    return;
                }

                // Legacy fetch (receptions, audit) — small lists, still fine.
                let url = `/api/v1/inventory_controller.php?action=read&tab=${tab}`;
                const res = await fetch(url);
                if (!res.ok) return;
                const result = await res.json();

                if (result.status === 'success') {
                    if (tab === 'receptions') renderReceptions(result.data.pending_pos);
                    if (tab === 'audit') renderAudit(result.data.table);
                }
            } catch(e) { console.error("Fetch error", e); }
        }

        // ================= RENDERS ================= //

        // Sprint 5: per-row renderer for LPC.paginator (returns HTML string).
        function renderStockRow(p) {
            const qty     = parseInt(p.current_qty);
            const min     = parseInt(p.min_stock_level);
            const isAlert = qty <= min;
            const alertBadge = isAlert ? LPC.raw('<span class="bg-red-100 text-red-700 px-2 py-0.5 rounded text-[9px] font-black uppercase ml-2">Alerte</span>') : '';
            const qtyClass   = isAlert ? 'text-red-600 font-black' : 'text-gray-900 font-bold';
            return LPC.html`
                <tr class="hover:bg-gray-50 border-b border-gray-50">
                    <td class="py-3 px-6 font-black text-gray-800">${p.name} ${alertBadge}</td>
                    <td class="py-3 px-6 text-center text-xs text-gray-500 uppercase tracking-wider">${p.category}</td>
                    <td class="py-3 px-6 text-center bg-gray-50/50 ${LPC.raw(qtyClass)}">${qty}</td>
                    <td class="py-3 px-6 text-center text-xs text-gray-400">${min}</td>
                    <td class="py-3 px-6 text-right text-xs text-gray-500">${fmt(p.cump)} F</td>
                    <td class="py-3 px-6 text-right font-black text-indigo-700 bg-indigo-50/20">${fmt(p.total_value)} F</td>
                </tr>`;
        }

        // Current KPI filter narrowing the stock table. Cards mutate this
        // and rebuild the paginator so the WHOLE dataset is filtered, not
        // just the visible page.
        let currentStockFilter = 'all';

        function stockUrlFor(filter) {
            const f = filter && filter !== 'all' ? `&filter=${encodeURIComponent(filter)}` : '';
            return `/api/v1/inventory_controller.php?action=read&tab=stock${f}`;
        }

        function attachStockPager() {
            if (pagers.stock) { pagers.stock.destroy(); pagers.stock = null; }
            pagers.stock = LPC.paginator.attach(document.getElementById('tbody-stock'), {
                url: stockUrlFor(currentStockFilter),
                renderRow: renderStockRow,
                pageSize: 25,
                searchInput: document.getElementById('search-stock'),
                dataPath: 'data.table',
                envelopePath: 'data.pagination',
                colspan: 6,
                onRender: onStockPageRendered,
                hashKey: 'stk',
                emptyMsg: emptyMsgFor(currentStockFilter),
            });
            paintActiveKpi();
        }

        function emptyMsgFor(filter) {
            if (filter === 'rupture') return 'Aucun article en rupture.';
            if (filter === 'alert')   return 'Aucun article en alerte.';
            return 'Aucun résultat.';
        }

        function setStockFilter(filter) {
            const next = (filter === 'alert' || filter === 'rupture') ? filter : 'all';
            if (next === currentStockFilter) {
                // Second click on the active filter clears it — a common
                // affordance for toggle-style KPI cards.
                if (next === 'all') return;
                currentStockFilter = 'all';
            } else {
                currentStockFilter = next;
            }
            attachStockPager();
        }

        function paintActiveKpi() {
            document.querySelectorAll('.kpi-filter-btn').forEach(btn => {
                btn.classList.toggle('kpi-active', btn.dataset.kpiFilter === currentStockFilter);
            });
        }

        // Event delegation so we don't race the DOM: works whether this
        // deferred script runs before or after the KPI cards get parsed.
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.kpi-filter-btn');
            if (!btn) return;
            e.preventDefault();
            setStockFilter(btn.dataset.kpiFilter);
        });

        // Post-render: KPI cards read the accurate full-catalogue totals
        // out of the response envelope (data.kpis, added server-side); the
        // damage-modal dropdown still lists the current page since that's
        // all the operator picks from.
        function onStockPageRendered(rows, state, raw) {
            const selectDmg = document.getElementById('dmg_product');
            if (selectDmg) {
                selectDmg.innerHTML = (rows || []).map(p =>
                    LPC.html`<option value="${p.id}">${p.name} (Stock: ${parseInt(p.current_qty)})</option>`
                ).join('');
            }

            const kpis = raw && raw.data && raw.data.kpis;
            if (kpis) {
                setKpiNumbers(kpis);
            } else {
                // Fallback if the server didn't include KPIs (older cache,
                // partial response): derive from the current page.
                let totalVal = 0, alerts = 0, rupture = 0;
                (rows || []).forEach(p => {
                    const qty = parseInt(p.current_qty);
                    const min = parseInt(p.min_stock_level);
                    if (qty <= 0) rupture++;
                    else if (qty <= min) alerts++;
                    totalVal += Number(p.total_value || 0);
                });
                setKpiNumbers({
                    total_value: totalVal,
                    total_skus:  state ? state.total : (rows || []).length,
                    alert_count: alerts,
                    rupture_count: rupture,
                });
            }
        }

        function setKpiNumbers(k) {
            const $ = (id) => document.getElementById(id);
            if ($('kpi_stock_value'))   $('kpi_stock_value').innerText   = LPC.fmt.fcfa(Number(k.total_value || 0));
            if ($('kpi_stock_total'))   $('kpi_stock_total').innerText   = LPC.fmt.int(Number(k.total_skus || 0));
            if ($('kpi_stock_alerts'))  $('kpi_stock_alerts').innerText  = LPC.fmt.int(Number(k.alert_count || 0));
            if ($('kpi_stock_rupture')) $('kpi_stock_rupture').innerText = LPC.fmt.int(Number(k.rupture_count || 0));
        }

        function renderReceptions(pos) {
            const tbody = document.getElementById('tbody-receptions');
            const badge = document.getElementById('badge-receptions');
            tbody.innerHTML = '';
            
            if(!pos || pos.length === 0) {
                badge.classList.add('hidden');
                return tbody.innerHTML = LPC.html`<tr><td colspan="6" class="py-12 text-center text-gray-400 italic">Aucune réception en attente.</td></tr>`;
            }

            badge.innerText = pos.length;
            badge.classList.remove('hidden');

            pos.forEach(po => {
                // expected_items is [{qty, product_name}, ...] from the API. It
                // used to be a `product_details` string with the <span> already
                // baked in by SQL, which this template then escaped -- which is
                // why the column read
                //     <span class="font-black">10x</span> 20L Opur
                // instead of showing a bolded quantity. The markup is built here
                // now, so every product name goes through LPC.html on the way in.
                const expected = LPC.raw((po.expected_items || [])
                    .map(it => LPC.html`<span class="font-black">${it.qty}x</span> ${it.product_name}`)
                    .join('<br>'));

                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-blue-50/20 border-b border-gray-50">
                        <td class="py-3 px-6 text-xs font-bold text-gray-500">${po.date}</td>
                        <td class="py-3 px-6 font-black text-gray-900">${po.reference}</td>
                        <td class="py-3 px-6 font-bold text-blue-700">${po.supplier_name}</td>
                        <td class="py-3 px-6 text-xs text-gray-600 leading-relaxed">${expected}</td>
                        <td class="py-3 px-6 text-center font-black text-gray-800 bg-gray-50/50">${po.total_items} Unités</td>
                        <td class="py-3 px-6 text-right">
                            <button onclick="openReception(${po.id}, '${po.reference}')" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-xs font-bold shadow-sm hover:bg-blue-700">Recevoir</button>
                        </td>
                    </tr>`;
            });
        }

        // Sprint 5: per-row renderer for the movements paginator.
        function renderMovementRow(m) {
            const isOut = String(m.movement_type || '').startsWith('out');
            const sign  = isOut ? '-' : '+';
            const color = isOut ? (m.movement_type === 'out_damaged' ? 'text-red-600' : 'text-blue-600') : 'text-green-600';

            let detailBody, iconHtml;
            if (m.movement_type === 'in_supplier') {
                detailBody = LPC.html`Réception Fournisseur (BC: <span class="font-mono text-gray-800">${m.po_ref || 'N/A'}</span>) par ${m.logged_by_name}`;
                iconHtml   = LPC.raw('<i class="fas fa-truck-loading text-green-500 mr-2"></i>');
            } else if (m.movement_type === 'out_delivery') {
                detailBody = LPC.html`Expédition Client (BL: <span class="font-mono text-gray-800">${m.bl_ref || 'N/A'}</span>) par ${m.logged_by_name}`;
                iconHtml   = LPC.raw('<i class="fas fa-truck text-blue-500 mr-2"></i>');
            } else if (m.movement_type === 'in_return_emp') {
                detailBody = LPC.html`Retour Magasin / Emballage par ${m.logged_by_name}`;
                iconHtml   = LPC.raw('<i class="fas fa-undo text-amber-500 mr-2"></i>');
            } else if (m.movement_type === 'out_damaged') {
                detailBody = LPC.html`Avarie / Perte signalée par ${m.logged_by_name}`;
                iconHtml   = LPC.raw('<i class="fas fa-trash-alt text-red-500 mr-2"></i>');
            } else {
                const auditLink = m.audit_token
                    ? LPC.html` <a href="/modules/inventory/print_audit.php?token=${m.audit_token}" target="_blank" class="text-blue-600 hover:text-blue-800 font-black ml-2 bg-blue-50 px-2 py-0.5 rounded border border-blue-100 transition-colors"><i class="fas fa-external-link-alt text-[10px] mr-1"></i>Certificat ${m.audit_ref}</a>`
                    : '';
                detailBody = LPC.html`Ajustement Inventaire par ${m.logged_by_name}${LPC.raw(auditLink)}`;
                iconHtml   = LPC.raw('<i class="fas fa-clipboard-check text-gray-500 mr-2"></i>');
            }

            const dateObj = new Date(m.date);
            const timeStr = dateObj.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
            const dateHtml = LPC.html`${dateObj.toLocaleDateString('fr-FR')} <span class="text-[9px] text-gray-400 ml-1">${timeStr}</span>`;

            return LPC.html`
                <tr class="hover:bg-gray-50 border-b border-gray-50">
                    <td class="py-3 px-6 text-xs text-gray-600 font-bold">${LPC.raw(dateHtml)}</td>
                    <td class="py-3 px-6 font-black text-gray-900">${m.product_name}</td>
                    <td class="py-3 px-6 text-xs text-gray-500">${iconHtml} ${LPC.raw(detailBody)}</td>
                    <td class="py-3 px-6 text-center font-black ${LPC.raw(color)} text-base">${sign}${m.quantity}</td>
                    <td class="py-3 px-6 text-center font-black text-indigo-700 bg-indigo-50/30 text-base">${m.balance_after}</td>
                </tr>`;
        }

        function renderAudit(data) {
            const tbody = document.getElementById('tbody-audit');
            tbody.innerHTML = '';
            
            data.forEach(p => {
                tbody.innerHTML += LPC.html`
                    <tr class="border-b border-gray-50 hover:bg-gray-50" data-pid="${p.id}" data-theo="${p.current_qty}">
                        <td class="py-3 px-6 font-bold text-gray-800">${p.name} <span class="text-[9px] text-gray-400 uppercase ml-2">${p.category}</span></td>
                        <td class="py-3 px-6 text-center font-black text-gray-400 bg-gray-50/50">${p.current_qty}</td>
                        <td class="py-2 px-6 bg-blue-50/20 text-center">
                            <input type="number" min="0" class="audit-input w-24 border border-blue-200 rounded p-1.5 text-center font-black text-blue-900 outline-none focus:ring-2 focus:ring-blue-500">
                        </td>
                    </tr>`;
            });
        }

        // ================= UTILS & ACTIONS ================= //

        function filterTable(tbodyId, query) {
            const q = query.toLowerCase();
            const rows = document.getElementById(tbodyId).getElementsByTagName('tr');
            for (let i = 0; i < rows.length; i++) {
                rows[i].style.display = rows[i].innerText.toLowerCase().includes(q) ? '' : 'none';
            }
        }

        async function openReception(poId, ref) {
            document.getElementById('rec_po_ref').innerText = ref;
            document.getElementById('rec_po_id').value = poId;
            const tbody = document.getElementById('tbody-rec-lines');
            tbody.innerHTML = LPC.html`<tr><td colspan="4" class="text-center py-4"><i class="fas fa-spinner fa-spin"></i></td></tr>`;
            openModal('modal-reception');

            // Only show "back to order" when we actually got here from that
            // order's detail page — receiving from the plain list of pending
            // POs has nowhere meaningful to go back to.
            const backLink = document.getElementById('rec_back_to_order');
            if (deepLinkPoId && Number(deepLinkPoId) === Number(poId)) {
                backLink.href = `/modules/inventory/po_detail.php?id=${poId}`;
                backLink.classList.remove('hidden');
            } else {
                backLink.classList.add('hidden');
            }

            try {
                const res = await fetch(`/api/v1/inventory_controller.php?action=get_po_items&po_id=${poId}`);
                const result = await res.json();
                tbody.innerHTML = '';
                result.data.forEach(i => {
                    tbody.innerHTML += LPC.html`
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="py-3 px-4 font-bold text-gray-800">${i.product_name}</td>
                            <td class="py-3 px-4 text-center font-black text-gray-400">${i.ordered_qty}</td>
                            <td class="py-3 px-4 bg-blue-50/30">
                                <input type="number" value="${i.ordered_qty}" max="${i.ordered_qty}" min="0" 
                                    data-pid="${i.product_id}" data-price="${i.unit_price}" data-ordered="${i.ordered_qty}" 
                                    oninput="calcReceptionBalance(this, ${i.product_id})" 
                                    class="rec-qty w-full border border-blue-200 rounded p-1.5 text-center font-black text-blue-900 outline-none focus:ring-2 focus:ring-blue-500">
                            </td>
                            <td class="py-3 px-4 text-center font-black text-emerald-500" id="bal_${i.product_id}">0</td>
                        </tr>`;
                });
            } catch(e) {}
        }

        // Real-time balance calculation (Turns red if shortage)
        function calcReceptionBalance(inputElement, productId) {
            const ordered = parseInt(inputElement.getAttribute('data-ordered')) || 0;
            const received = parseInt(inputElement.value) || 0;
            const balanceCell = document.getElementById(`bal_${productId}`);
            
            const diff = ordered - received;
            
            if (diff > 0) {
                balanceCell.innerText = `-${diff}`;
                balanceCell.className = "py-3 px-4 text-center font-black text-red-600 bg-red-50/30";
            } else if (diff < 0) {
                balanceCell.innerText = `+${Math.abs(diff)}`;
                balanceCell.className = "py-3 px-4 text-center font-black text-amber-600 bg-amber-50/30";
            } else {
                balanceCell.innerText = "0";
                balanceCell.className = "py-3 px-4 text-center font-black text-emerald-500";
            }
        }

        async function submitReception() {
            const payload = { action: 'receive_po', po_id: document.getElementById('rec_po_id').value, items: [] };
            
            document.querySelectorAll('.rec-qty').forEach(i => {
                payload.items.push({ 
                    product_id: i.dataset.pid, 
                    unit_price: i.dataset.price, 
                    ordered_qty: i.dataset.ordered,
                    received_qty: i.value || 0 
                });
            });

            // Change button state
            const btn = document.querySelector('#modal-reception button.bg-blue-600');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btn.disabled = true;

            try {
                const response = await fetch('/api/v1/inventory_controller.php', { method: 'POST', body: JSON.stringify(payload) });
                const result = await response.json();
                
                if (result.status === 'success') {
                    const receivedPoId = document.getElementById('rec_po_id').value;
                    closeModal('modal-reception');
                    switchTab('stock'); // Refresh background data

                    // Populate and show the Success Summary Toast
                    const tbody = document.getElementById('tbody-summary');
                    tbody.innerHTML = '';
                    result.summary.forEach(item => {
                        let warning = item.shortage > 0 ? LPC.raw(LPC.html`<span class="text-[9px] text-red-500 block">(-${item.shortage} manquants)</span>`) : '';
                        tbody.innerHTML += LPC.html`
                            <tr>
                                <td class="py-2 px-4 font-bold text-gray-700">${item.product_name} ${warning}</td>
                                <td class="py-2 px-4 text-center font-black text-gray-900">${item.received}</td>
                                <td class="py-2 px-4 text-center font-black text-emerald-600 bg-emerald-50">${item.new_stock}</td>
                            </tr>
                        `;
                    });

                    const summaryBack = document.getElementById('summary_back_to_order');
                    if (deepLinkPoId && Number(deepLinkPoId) === Number(receivedPoId)) {
                        summaryBack.href = `/modules/inventory/po_detail.php?id=${receivedPoId}`;
                        summaryBack.classList.remove('hidden');
                        summaryBack.classList.add('flex');
                    } else {
                        summaryBack.classList.add('hidden');
                        summaryBack.classList.remove('flex');
                    }

                    openModal('summaryModal');
                } else {
                    LPC.modal.alert("Erreur: " + result.message);
                }
            } catch(e) {
                LPC.modal.alert("Erreur système.");
            } finally {
                btn.innerHTML = "Valider l'Entrée";
                btn.disabled = false;
            }
        }

        async function submitDamage() {
            const payload = {
                action: 'log_damage',
                product_id: document.getElementById('dmg_product').value,
                quantity: document.getElementById('dmg_qty').value,
                reason: document.getElementById('dmg_reason').value
            };
            try {
                await fetch('/api/v1/inventory_controller.php', { method: 'POST', body: JSON.stringify(payload) });
                closeModal('damageModal'); switchTab('stock');
            } catch(e) {}
        }

        let pendingAdjustments = [];

        function submitAudit() {
            pendingAdjustments = [];
            const tbody = document.getElementById('audit-summary-tbody');
            tbody.innerHTML = '';
            let hasChanges = false;

            document.querySelectorAll('#tbody-audit tr').forEach(r => {
                const input = r.querySelector('.audit-input').value;
                if(input !== '') {
                    const theo = parseInt(r.dataset.theo);
                    const real = parseInt(input);
                    const diff = real - theo;
                    const productName = r.querySelector('td').innerText;

                    if(theo !== real) {
                        hasChanges = true;
                        pendingAdjustments.push({ 
                            product_id: r.dataset.pid, 
                            theoretical: theo,
                            physical: real,
                            difference: diff 
                        });

                        let diffClass = diff > 0 ? 'text-green-600' : 'text-red-600';
                        let diffSign = diff > 0 ? '+' : '';
                        
                        tbody.innerHTML += LPC.html`
                            <tr>
                                <td class="py-2 px-4 font-bold text-gray-700">${productName}</td>
                                <td class="py-2 px-4 text-center font-black text-blue-700 bg-blue-50/50">${real}</td>
                                <td class="py-2 px-4 text-center font-black ${diffClass}">${diffSign}${diff}</td>
                            </tr>
                        `;
                    }
                }
            });

            if(!hasChanges) return LPC.modal.alert("Aucun écart détecté ou saisi. Rien à valider.");
            
            openModal('auditConfirmModal');
        }

        document.getElementById('btn-certify-audit').addEventListener('click', async function() {
            const btn = this;
            // Idempotency guard: reject re-submissions with the same adjustment set.
            // The server also generates a unique idempotency_key per audit; if the
            // user double-clicks, the second submit is rejected by /api/v1/inventory_controller.php
            // via ON DUPLICATE KEY on inventory_reports.idempotency_key.
            if (btn.disabled) return;                              // already submitting
            if (!pendingAdjustments.length) return LPC.modal.alert("Aucun ajustement à valider.");
            if (btn.dataset.sealed === 'true') {
                return LPC.modal.alert("Cet audit a déjà été scellé. Rechargez la page pour un nouvel inventaire.");
            }

            // Fresh idempotency key per session of the "Certify" button.
            const idempotencyKey = btn.dataset.idkey || (Date.now().toString(36) + Math.random().toString(36).slice(2, 10));
            btn.dataset.idkey = idempotencyKey;

            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scellement...';
            btn.disabled = true;

            try {
                const res = await fetch('/api/v1/inventory_controller.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'submit_audit',
                        adjustments: pendingAdjustments,
                        idempotency_key: idempotencyKey,
                    })
                });
                const result = await res.json();

                if (result.status === 'success') {
                    closeModal('auditConfirmModal');
                    // Mark button as sealed so accidental re-clicks after closing/reopening
                    // the modal don't fire the API again.
                    btn.dataset.sealed = 'true';
                    // Clear the pending list so a second Certify has nothing to submit.
                    pendingAdjustments = [];
                    window.open(`/modules/inventory/print_audit.php?token=${result.token}`, '_blank');
                    switchTab('stock');
                } else if (result.code === 'duplicate_audit') {
                    LPC.modal.alert("Cet inventaire a déjà été enregistré (idempotence).");
                    closeModal('auditConfirmModal');
                    switchTab('stock');
                } else {
                    LPC.modal.alert("Erreur : " + (result.message || 'inconnue'));
                }
            } catch (e) {
                LPC.modal.alert("Erreur système lors du scellement : " + e.message);
            } finally {
                btn.innerHTML = '<i class="fas fa-file-signature mr-2"></i> Certifier & Sceller';
                btn.disabled = false;
            }
        });

        // Also reset pending state when the audit-confirm modal is CLOSED without submitting.
        function _lpc_reset_pending_audit() {
            pendingAdjustments = [];
            const btn = document.getElementById('btn-certify-audit');
            if (btn) { delete btn.dataset.idkey; delete btn.dataset.sealed; }
        }
        
    async function openInventoryHistory() {
            openModal('historyAuditModal');
            const container = document.getElementById('audit-history-container');
            container.innerHTML = '<div class="text-center py-12 text-gray-400"><i class="fas fa-spinner fa-spin text-3xl"></i><p class="mt-3 font-bold text-xs">Chargement des rapports...</p></div>';

            try {
                const res = await fetch('/api/v1/inventory_controller.php?action=get_inventory_reports');
                const result = await res.json();
                
                if (result.status === 'success') {
                    renderInventoryHistory(result.data);
                } else {
                    container.innerHTML = LPC.html`<div class="text-red-500 text-center py-8 font-bold">Erreur: ${result.message}</div>`;
                }
            } catch(e) {
                container.innerHTML = LPC.html`<div class="text-red-500 text-center py-8 font-bold">Erreur de connexion.</div>`;
            }
        }

        function renderInventoryHistory(reports) {
            const container = document.getElementById('audit-history-container');
            if (reports.length === 0) {
                container.innerHTML = '<div class="text-center py-12 text-gray-400 font-bold"><i class="fas fa-box-open text-4xl mb-3 block opacity-50"></i>Aucun rapport d\'inventaire validé.</div>';
                return;
            }

            let html = '<div class="space-y-3">';
            reports.forEach(r => {
                const dateObj = new Date(r.created_at);
                const dateStr = dateObj.toLocaleDateString('fr-FR') + ' à ' + dateObj.toLocaleTimeString('fr-FR', {hour: '2-digit', minute:'2-digit'});
                
                let itemsHtml = '';
                r.items.forEach(i => {
                    const diffClass = i.difference > 0 ? 'text-green-600' : (i.difference < 0 ? 'text-red-600' : 'text-gray-400');
                    const diffSign = i.difference > 0 ? '+' : '';
                    itemsHtml += `
                        <tr class="border-b border-gray-100 last:border-0 hover:bg-gray-50">
                            <td class="py-2.5 px-4 text-xs font-bold text-gray-700">${i.product_name}</td>
                            <td class="py-2.5 px-4 text-xs text-center text-gray-500">${i.theoretical_qty}</td>
                            <td class="py-2.5 px-4 text-xs text-center font-black ${diffClass}">${diffSign}${i.difference}</td>
                            <td class="py-2.5 px-4 text-xs text-center font-black text-blue-700 bg-blue-50/30">${i.physical_qty}</td>
                        </tr>
                    `;
                });

                html += `
                    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm transition-all">
                        <button onclick="toggleAccordion('acc-${r.id}')" class="w-full px-5 py-4 flex justify-between items-center bg-white hover:bg-gray-50 transition-colors focus:outline-none">
                            <div class="flex items-center gap-4 text-left">
                                <div class="w-10 h-10 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center font-black">
                                    <i class="fas fa-clipboard-list"></i>
                                </div>
                                <div>
                                    <h4 class="font-black text-gray-900 text-sm">${r.reference}</h4>
                                    <p class="text-[10px] font-bold text-gray-500 mt-0.5"><i class="fas fa-calendar-alt mr-1"></i> ${dateStr} <span class="mx-1">•</span> <i class="fas fa-user-shield mr-1"></i> ${r.operator_name}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-4">
                                <span class="bg-gray-100 text-gray-700 text-[10px] font-black px-2.5 py-1 rounded-md border border-gray-200">${r.total_variations} Écart(s)</span>
                                <i class="fas fa-chevron-down text-gray-400 transition-transform duration-300" id="icon-${r.id}"></i>
                            </div>
                        </button>
                        
                        <div id="acc-${r.id}" class="hidden border-t border-gray-100 bg-slate-50/50">
                            <div class="p-5">
                                <div class="flex justify-between items-center mb-3">
                                    <h5 class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Détail de la régularisation</h5>
                                    <a href="/modules/inventory/print_audit.php?token=${r.token}" target="_blank" class="text-[10px] font-black bg-gray-900 hover:bg-black text-white px-3 py-1.5 rounded-lg flex items-center gap-2 transition-colors shadow-sm">
                                        <i class="fas fa-file-pdf text-red-500"></i> Ouvrir le PDF
                                    </a>
                                </div>
                                <table class="w-full text-left bg-white border border-gray-200 rounded-lg overflow-hidden shadow-sm">
                                    <thead class="bg-gray-100 text-[9px] uppercase text-gray-500 font-black tracking-widest">
                                        <tr>
                                            <th class="py-2.5 px-4">Produit</th>
                                            <th class="py-2.5 px-4 text-center">Théorique</th>
                                            <th class="py-2.5 px-4 text-center border-x border-gray-200">Écart</th>
                                            <th class="py-2.5 px-4 text-center text-blue-700">Stock Initial</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-50">
                                        ${itemsHtml}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            container.innerHTML = html;
        }

        function toggleAccordion(id) {
            const content = document.getElementById(id);
            const icon = document.getElementById(id.replace('acc-', 'icon-'));
            
            if (content.classList.contains('hidden')) {
                content.classList.remove('hidden');
                icon.classList.add('rotate-180');
            } else {
                content.classList.add('hidden');
                icon.classList.remove('rotate-180');
            }
        }
    