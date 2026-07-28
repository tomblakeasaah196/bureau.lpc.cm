/**
 * assets/js/modules/crm-clients.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/crm/clients.php (Sprint 6 D2).
 *
 * Original block was ~14,914 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            if (sidebar) sidebar.classList.toggle('-translate-x-full');
            if (overlay) overlay.classList.toggle('hidden');
        }
        // Setup Proposal Modal for specific client
        function openProposalModal(clientId, clientName) {
            document.getElementById('prop_client_id').value = clientId;
            document.getElementById('prop_client_name').innerText = clientName;
            openModal('proposalModal');
        }

        // Add dynamic row to products table
        function addProposalRow() {
            const tbody = document.getElementById('proposal-items-tbody');
            // Clone the first row as a template
            const template = tbody.children[0].cloneNode(true);
            template.querySelector('.prod-qty').value = 1;
            tbody.appendChild(template);
        }
        async function submitClient() {
    const nameInput = document.getElementById('new_client_name');
    if (!nameInput.value.trim()) { LPC.modal.alert("Le nom est obligatoire."); return; }

    const editId = document.getElementById('edit_client_id').value;
    const endpoint = editId ? '/api/v1/update_client.php' : '/api/v1/create_client.php';

    const payload = {
        id: editId,
        name: nameInput.value,
        type: document.getElementById('new_client_type').value,
        contact_person: document.getElementById('new_client_contact').value,
        email: document.getElementById('new_client_email').value,
        phone: document.getElementById('new_client_phone').value,
        address: document.getElementById('new_client_address').value,
        niu: document.getElementById('new_client_niu').value,
        rc: document.getElementById('new_client_rc').value,
        credit_limit: document.getElementById('new_client_credit').value,
        status: document.getElementById('new_client_status').value // Will be captured during updates
    };

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    LPC.modal.alert(result.message);
                    closeModal('clientModal');
                    loadClients(); // Refresh the table instantly without reloading the whole page!
                } else {
                    LPC.modal.alert("Erreur: " + result.message);
                }
            } catch (error) {
                LPC.modal.alert("Erreur de connexion.");
            }
        }
        
        // Gather data and send to backend API
        async function submitProposal() {
            // 1. Gather Metadata
            const payload = {
                client_id: document.getElementById('prop_client_id').value,
                language: document.getElementById('prop_lang').value,
                validity_days: document.getElementById('prop_validity').value,
                delivery_frequency: document.getElementById('prop_frequency').value,
                buffer_stock_weeks: document.getElementById('prop_buffer').value,
                payment_terms: document.getElementById('prop_payment').value,
                empties_policy: document.getElementById('prop_empties').value,
                items: []
            };

            // 2. Gather Line Items
            document.querySelectorAll('.item-row').forEach(row => {
                payload.items.push({
                    product_id: row.querySelector('.prod-id').value,
                    quantity: row.querySelector('.prod-qty').value,
                    unit_price: row.querySelector('.prod-price').value
                });
            });

            // 3. Send to API
            try {
                // We will build this API next
                const response = await fetch('/api/v1/create_proposal.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    closeModal('proposalModal');
                    const link = `https://bureau.lpc.cm/quote.php?token=${result.token}`;
                    
                    // Instantly open the quote in a new tab
                    window.open(link, '_blank');
                    
                    // Refresh the table to show the new prospect-to-client status if applicable
                    loadClients(); 
                } else {
                    LPC.modal.alert("Erreur: " + result.message);
                }
            } catch (error) {
                console.error("Error submitting proposal:", error);
                
                // MOCK SUCCESS FOR TESTING THE UI
                closeModal('proposalModal');
                window.open("https://bureau.lpc.cm/quote.php?token=sec_a8f93j2k", '_blank');
            }
        }
        // Load clients AND KPIs when the page loads
        document.addEventListener('DOMContentLoaded', () => {
            loadClients();
            loadKPIs();
        });

        // Global array to store fetched clients
        let clientList = []; 

        async function loadClients() {
            try {
                const response = await fetch('/api/v1/fetch_clients.php');
                const result = await response.json();

                if (result.status === 'success') {
                    clientList = result.data; // Store in global array
                    renderClientTable(clientList); // Draw the table with all data initially
                }
            } catch (error) {
                console.error("Failed to fetch clients", error);
            }
        }

        // Dedicated function to draw the table (makes filtering easy)
        function renderClientTable(clientsToRender) {
            const tbody = document.getElementById('clients-tbody');
            tbody.innerHTML = ''; 

            // If search returns no results
            if (clientsToRender.length === 0) {
                tbody.innerHTML = LPC.html`<tr><td colspan="5" class="py-8 text-center text-gray-500 font-medium italic">Aucun client trouvé. / No clients found.</td></tr>`;
                return;
            }

            clientsToRender.forEach(client => {
                let statusBadge = client.status === 'prospect' 
                    ? `<span class="px-2 py-1 bg-gray-100 text-gray-600 rounded text-[10px] font-bold uppercase tracking-wider">Prospect</span>`
                    : `<span class="px-2 py-1 bg-green-100 text-green-700 rounded text-[10px] font-bold uppercase tracking-wider">Actif</span>`;
                
                let convertBtn = client.status === 'prospect'
                    ? `<button onclick="convertClient(${client.id})" class="text-xs bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white px-2 py-1 rounded transition-colors" title="Convertir en Client Actif" aria-label="Convertir en Client Actif">Convertir</button>`
                    : '';

                const row = `
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="py-4 px-6">
                            <div class="flex items-center gap-2">
                                <div class="font-extrabold text-gray-900 text-base">${client.name}</div>
                                ${statusBadge}
                            </div>
                            <div class="text-xs text-lpc-light font-bold uppercase tracking-wide">${client.type} | ${client.lpc_code}</div>
                        </td>
                        <td class="py-4 px-6">
                            <div class="text-sm font-medium text-gray-800">${client.contact_person || 'N/A'}</div>
                            <div class="text-xs text-gray-500">${client.address || 'N/A'}</div>
                        </td>
                        <td class="py-4 px-6 text-right">
                            <div class="text-sm font-bold text-gray-900">0 FCFA</div>
                            <div class="text-xs text-gray-400">Plafond: ${LPC.fmt.int(client.credit_limit)} FCFA</div>
                        </td>
                        <td class="py-4 px-6 text-center">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-600">0 Vides</span>
                        </td>
                        <td class="py-4 px-6 text-right space-x-2 flex justify-end items-center">
                            ${convertBtn}
                            <button onclick="openProposalModal(${client.id}, '${client.name.replace(/'/g, "\\'")}')" class="bg-lpc-light hover:bg-green-600 text-white px-3 py-1.5 rounded-lg text-sm font-bold shadow-sm transition-colors inline-flex items-center">
                                Devis
                            </button>
                            <button onclick="openEditModal(${client.id})" class="text-gray-400 hover:text-lpc-dark transition-colors px-2 py-1.5" title="Modifier" aria-label="Modifier">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                            </button>
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        }

        // The Real-Time Search Filter Function
        function filterClients() {
            const query = document.getElementById('search-client').value.toLowerCase();
            
            // If the search bar is empty, show everyone
            if (!query) {
                renderClientTable(clientList);
                return;
            }

            // Filter the global array based on name, LPC code, or contact person
            const filteredData = clientList.filter(client => {
                const nameMatch = client.name.toLowerCase().includes(query);
                const codeMatch = client.lpc_code && client.lpc_code.toLowerCase().includes(query);
                const contactMatch = client.contact_person && client.contact_person.toLowerCase().includes(query);
                
                return nameMatch || codeMatch || contactMatch;
            });

            // Re-draw the table with only the matches
            renderClientTable(filteredData);
        }
        
        async function loadKPIs() {
            try {
                const response = await fetch('/api/v1/fetch_crm_kpis.php');
                const result = await response.json();

                if (result.status === 'success') {
                    // Inject data into the DOM
                    document.getElementById('kpi-clients').innerText = result.data.active_clients;
                    
                    // Format Financial Debt with FCFA (LPC.fmt.fcfa adds the suffix).
                    document.getElementById('kpi-ar').innerText = LPC.fmt.fcfa(result.data.financial_debt);
                    
                    // Format Bottle Debt
                    document.getElementById('kpi-bottles').innerText = result.data.bottle_debt + ' Vides';
                }
            } catch (error) {
                console.error("Failed to fetch KPIs", error);
                document.getElementById('kpi-clients').innerText = "0";
                document.getElementById('kpi-ar').innerText = "0 FCFA";
                document.getElementById('kpi-bottles').innerText = "0 Vides";
            }
        }
        
        function openEditModal(clientId) {
    const client = clientList.find(c => c.id == clientId);
    if (!client) return;

    // Fill all fields
    document.getElementById('edit_client_id').value = client.id;
    document.getElementById('new_client_name').value = client.name;
    document.getElementById('new_client_type').value = client.type;
    document.getElementById('new_client_contact').value = client.contact_person;
    document.getElementById('new_client_email').value = client.email || '';
    document.getElementById('new_client_phone').value = client.phone || '';
    document.getElementById('new_client_address').value = client.address || '';
    document.getElementById('new_client_niu').value = client.niu || client.tax_id || ''; // Handles both column names
    document.getElementById('new_client_rc').value = client.rc || '';
    document.getElementById('new_client_credit').value = client.credit_limit;

    // SHOW the status dropdown and set the value
    document.getElementById('status-field-wrapper').classList.remove('hidden');
    document.getElementById('new_client_status').value = client.status;

    openModal('clientModal');
}
        
        function openNewClientModal() {
                document.getElementById('edit_client_id').value = ""; 
                document.getElementById('form-add-client').reset();
                
                // HIDE the status dropdown (new clients are always prospects)
                document.getElementById('status-field-wrapper').classList.add('hidden');
                
                openModal('clientModal');
            }

        // Trigger the conversion API
        async function convertClient(clientId) {
            if(!(await LPC.modal.confirm("Voulez-vous convertir ce prospect en client actif ? Un compte comptable OHADA sera généré."))) return;

            try {
                const response = await fetch('/api/v1/convert_client.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ client_id: clientId })
                });
                const result = await response.json();
                
                if(result.status === 'success') {
                    LPC.modal.alert(result.message);
                    loadClients(); // Reload the table
                } else {
                    LPC.modal.alert(result.message);
                }
            } catch (error) {
                LPC.modal.alert("Erreur de connexion.");
            }
        }
    
        /* =====================================================================
           KPI card drill-down (Sprint 7F)
           ---------------------------------------------------------------------
           Each card opens a modal breaking its number down per client, and each
           row deep-links to the page that can actually do something about it.

           Rows are built with createElement/textContent rather than innerHTML.
           Client names are user input — a client called `<img onerror=...>` is
           a perfectly legal thing to type into the Nouveau Client form, and
           this modal is exactly where it would fire. README §6.4.
           ===================================================================== */

        var KPI_TITLES = {
            clients: 'Répartition des clients',
            ar:      'Dette financière par client',
            empties: 'Emballages détenus par client'
        };

        function kpiFormat(value, unit) {
            if (unit === 'fcfa')  return LPC.fmt.fcfa(value);
            if (unit === 'vides') return LPC.fmt.int(value) + ' vides';
            return LPC.fmt.int(value);
        }

        function kpiEl(tag, cls, text) {
            var el = document.createElement(tag);
            if (cls)  el.className = cls;
            if (text != null) el.textContent = text;
            return el;
        }

        function kpiEmpty(msg) {
            var p = kpiEl('p', 'text-sm text-gray-400 text-center py-8', msg);
            return p;
        }

        /** Breakdown + recent list for the "clients" metric. */
        function renderClientsDetail(body, data) {
            (data.breakdown || []).forEach(function (group) {
                if (!group.rows || !group.rows.length) return;
                var box = kpiEl('div', 'mb-5');
                box.appendChild(kpiEl('p', 'text-xs font-bold text-gray-400 uppercase tracking-wider mb-2', group.heading));

                var total = group.rows.reduce(function (a, r) { return a + Number(r.n); }, 0);
                group.rows.forEach(function (r) {
                    var line = kpiEl('div', 'kpi-stat');
                    line.appendChild(kpiEl('span', 'font-bold text-gray-700', r.label));
                    line.appendChild(kpiEl('span', 'text-gray-900 font-black', LPC.fmt.int(r.n)));
                    box.appendChild(line);

                    var bar = kpiEl('div', 'kpi-bar');
                    var fill = document.createElement('i');
                    fill.style.width = total ? Math.round((r.n / total) * 100) + '%' : '0%';
                    bar.appendChild(fill);
                    box.appendChild(bar);
                });
                body.appendChild(box);
            });

            if (data.recent && data.recent.length) {
                body.appendChild(kpiEl('p', 'text-xs font-bold text-gray-400 uppercase tracking-wider mb-2 mt-6', 'Derniers clients ajoutés'));
                data.recent.forEach(function (c) {
                    var row = kpiEl('div', 'kpi-row');
                    var left = kpiEl('div');
                    left.appendChild(kpiEl('div', 'kpi-row-name', c.name));
                    left.appendChild(kpiEl('div', 'kpi-row-meta', [c.code, c.type].filter(Boolean).join(' · ')));
                    row.appendChild(left);
                    row.appendChild(kpiEl('div', 'kpi-row-meta', c.created));
                    body.appendChild(row);
                });
            }
        }

        /** Per-client rows for the "ar" and "empties" metrics. */
        function renderDebtDetail(body, data) {
            if (!data.rows || !data.rows.length) {
                body.appendChild(kpiEmpty(
                    data.metric === 'ar'
                        ? 'Aucune facture impayée. Rien à recouvrer.'
                        : 'Aucun emballage en attente de retour.'
                ));
                return;
            }

            var max = data.rows.reduce(function (m, r) { return Math.max(m, Number(r.value)); }, 0);

            data.rows.forEach(function (r) {
                // An <a> rather than a div+onclick: middle-click and
                // open-in-new-tab work, and it is reachable by keyboard.
                var row = document.createElement('a');
                row.className = 'kpi-row';
                row.href = r.link;

                var left = kpiEl('div', 'flex-1');
                left.appendChild(kpiEl('div', 'kpi-row-name', r.name));

                var meta = [];
                if (r.code) meta.push(r.code);
                if (data.metric === 'ar') {
                    meta.push(r.invoice_count + ' facture(s)');
                    if (r.oldest_due) meta.push('échéance ' + r.oldest_due);
                } else {
                    meta.push(r.product_count + ' référence(s)');
                    if (r.last_move) meta.push('dernier mouvement ' + r.last_move);
                }
                var metaEl = kpiEl('div', 'kpi-row-meta', meta.join(' · '));
                if (data.metric === 'ar' && r.overdue) {
                    metaEl.classList.add('kpi-overdue');
                    metaEl.textContent += ' · en retard';
                }
                left.appendChild(metaEl);

                var bar = kpiEl('div', 'kpi-bar');
                var fill = document.createElement('i');
                fill.style.width = max ? Math.round((r.value / max) * 100) + '%' : '0%';
                bar.appendChild(fill);
                left.appendChild(bar);

                row.appendChild(left);

                var val = kpiEl('div', 'kpi-row-value', kpiFormat(r.value, data.unit));
                if (data.metric === 'ar' && r.overdue) val.classList.add('kpi-overdue');
                row.appendChild(val);

                body.appendChild(row);
            });
        }

        async function openKpiModal(metric) {
            var body  = document.getElementById('kpi-modal-body');
            var title = document.getElementById('kpi-modal-title');
            var total = document.getElementById('kpi-modal-total');
            var foot  = document.getElementById('kpi-modal-foot');

            title.textContent = KPI_TITLES[metric] || 'Détail';
            total.textContent = '';
            foot.textContent  = '';
            body.textContent  = '';
            body.appendChild(kpiEmpty('Chargement…'));
            openModal('kpiModal');

            try {
                var res  = await fetch('/api/v1/fetch_crm_kpi_detail.php?metric=' + encodeURIComponent(metric));
                var data = await res.json();

                if (data.status !== 'success') throw new Error(data.message || 'Erreur.');

                title.textContent = data.title || KPI_TITLES[metric];
                total.textContent = metric === 'clients'
                    ? LPC.fmt.int(data.total) + ' client(s) actif(s)'
                    : 'Total : ' + kpiFormat(data.total, data.unit);

                body.textContent = '';
                if (metric === 'clients') renderClientsDetail(body, data);
                else                      renderDebtDetail(body, data);

                if (data.rows && data.rows.length && data.target_label) {
                    foot.textContent = 'Cliquez une ligne pour l’ouvrir dans ' + data.target_label + '.';
                }
            } catch (err) {
                body.textContent = '';
                body.appendChild(kpiEmpty('Impossible de charger le détail.'));
                console.error('KPI detail failed', err);
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.lpc-kpi-card').forEach(function (card) {
                card.addEventListener('click', function () {
                    openKpiModal(card.dataset.metric);
                });
            });

            // Escape closes the drill-down. The client modal predates this file
            // and handles its own dismissal, so scope the handler to ours.
            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') return;
                var m = document.getElementById('kpiModal');
                if (m && !m.classList.contains('hidden')) closeModal('kpiModal');
            });

            // Backdrop click.
            var kpiModal = document.getElementById('kpiModal');
            if (kpiModal) {
                kpiModal.addEventListener('click', function (e) {
                    if (e.target === kpiModal) closeModal('kpiModal');
                });
            }
        });
