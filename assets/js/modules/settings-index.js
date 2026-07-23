/**
 * assets/js/modules/settings-index.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/settings/index.php (Sprint 6 D2).
 *
 * Original block was ~18,709 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
    // 1. STATE & CONFIGURATION
        let currentTab = 'users';
        let moduleData = [];

        // Config defines the UI structure for each tab perfectly.
        const settingsConfig = {
            users: {
                title: "Utilisateurs Système",
                api: "users",
                showActionBtn: true,
                btnText: "Nouvel Utilisateur",
                kpis: [
                    { id: 'kpi1', label: 'Total Comptes', icon: 'fa-users', color: 'text-blue-600', bg: 'bg-blue-50' },
                    { id: 'kpi2', label: 'Comptes Actifs', icon: 'fa-user-check', color: 'text-green-600', bg: 'bg-green-50' },
                    { id: 'kpi3', label: 'Verrouillés', icon: 'fa-user-lock', color: 'text-red-600', bg: 'bg-red-50' }
                ],
                columns: [
                    { key: 'employee_code', label: 'Code', render: v => `<span class="font-bold text-gray-500">${v}</span>` },
                    { key: 'first_name', label: 'Identité', render: (v, row) => `<span class="font-black text-gray-900">${v} ${row.last_name}</span>` },
                    { key: 'role_name', label: 'Rôle', render: v => `<span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-[10px] uppercase font-bold tracking-wider border border-gray-200">${v || 'NON ASSIGNÉ'}</span>` },
                    { key: 'status', label: 'Accès', render: v => v === 'active' ? '<span class="text-green-500 text-xs font-bold"><i class="fas fa-check-circle mr-1"></i>Actif</span>' : '<span class="text-red-500 text-xs font-bold"><i class="fas fa-lock mr-1"></i>Bloqué</span>' }
                ]
            },
            sessions: {
                title: "Sessions & Sécurité",
                api: "sessions",
                showActionBtn: false, // Read-only / Action inline
                kpis: [
                    { id: 'kpi1', label: 'Connectés (Actuels)', icon: 'fa-signal', color: 'text-emerald-500', bg: 'bg-emerald-50' },
                    { id: 'kpi2', label: 'Échecs Mdp (24h)', icon: 'fa-fingerprint', color: 'text-amber-500', bg: 'bg-amber-50' },
                    { id: 'kpi3', label: 'Tentatives Intrusions', icon: 'fa-skull-crossbones', color: 'text-red-600', bg: 'bg-red-50' }
                ],
                columns: [
                    { key: 'created_at', label: 'Horodatage', render: v => `<span class="text-xs font-bold text-gray-600">${new Date(v).toLocaleString('fr-FR')}</span>` },
                    { key: 'login_identifier', label: 'Identifiant / Code', render: v => `<span class="font-black text-gray-900">${v}</span>` },
                    { key: 'ip_address', label: 'Adresse IP', render: v => `<span class="text-xs font-mono text-gray-500 bg-gray-100 px-2 py-1 rounded border border-gray-200">${v}</span>` },
                    { key: 'login_status', label: 'Statut', render: v => {
                        const dict = { 'success': '<span class="text-green-500 font-bold"><i class="fas fa-check mr-1"></i>Succès</span>', 'failed_password': '<span class="text-amber-500 font-bold"><i class="fas fa-times mr-1"></i>Mot de passe erroné</span>', 'user_not_found': '<span class="text-red-500 font-bold"><i class="fas fa-user-ninja mr-1"></i>Inconnu (Intrusion)</span>', 'account_locked': '<span class="text-red-600 font-bold"><i class="fas fa-ban mr-1"></i>Compte Bloqué</span>' };
                        return dict[v] || v;
                    }},
                    { key: 'logout_time', label: 'État Session', render: (v, row) => {
                        if (row.login_status !== 'success') return '-';
                        return v ? `<span class="text-gray-400 text-xs font-medium">Déconnecté à ${new Date(v).toLocaleTimeString('fr-FR')}</span>` : `<span class="text-emerald-500 text-xs font-bold animate-pulse"><i class="fas fa-circle text-[8px] mr-1"></i>En Ligne</span>`;
                    }}
                ]
            },
            audits: {
                title: "Logs d'Audit",
                api: "audits",
                showActionBtn: false,
                kpis: [
                    { id: 'kpi1', label: 'Total Actions (24h)', icon: 'fa-database', color: 'text-indigo-600', bg: 'bg-indigo-50' },
                    { id: 'kpi2', label: 'Données Modifiées', icon: 'fa-edit', color: 'text-amber-600', bg: 'bg-amber-50' },
                    { id: 'kpi3', label: 'Données Supprimées', icon: 'fa-trash-alt', color: 'text-red-600', bg: 'bg-red-50' }
                ],
                columns: [
                    { key: 'created_at', label: 'Horodatage', render: v => `<span class="text-xs font-bold text-gray-500">${new Date(v).toLocaleString('fr-FR')}</span>` },
                    { key: 'first_name', label: 'Utilisateur', render: (v, row) => `<span class="font-bold text-gray-900">${v} ${row.last_name || ''}</span>` },
                    { key: 'action', label: 'Action', render: v => {
                        let color = v === 'DELETE' ? 'bg-red-100 text-red-700' : (v === 'UPDATE' ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700');
                        return `<span class="px-2 py-1 ${color} rounded text-[10px] font-black uppercase tracking-widest">${v}</span>`;
                    }},
                    { key: 'table_name', label: 'Table Impactée', render: (v, row) => `<span class="text-sm font-medium text-gray-600">${v} (ID: ${row.record_id})</span>` }
                ]
            }
        };

        // 2. CORE FUNCTIONS
        async function switchTab(tabKey) {
            currentTab = tabKey;
            const config = settingsConfig[currentTab];

            // Update Nav Styles
            document.querySelectorAll('.tab-link').forEach(el => {
                el.classList.remove('border-gray-900', 'text-gray-900', 'font-black');
                el.classList.add('border-transparent', 'text-gray-400', 'font-bold');
            });
            const activeTab = document.getElementById(`tab-${tabKey}`);
            if(activeTab) {
                activeTab.classList.remove('border-transparent', 'text-gray-400', 'font-bold');
                activeTab.classList.add('border-gray-900', 'text-gray-900', 'font-black');
            }

            // Update Toolbar
            const btn = document.getElementById('btn-primary-action');
            if (config.showActionBtn) {
                btn.classList.remove('hidden');
                document.getElementById('btn-action-text').innerText = config.btnText;
            } else {
                btn.classList.add('hidden');
            }

            // Render KPI Ribbon Skeleton
            const ribbon = document.getElementById('kpi-ribbon');
            ribbon.innerHTML = '';
            config.kpis.forEach(kpi => {
                ribbon.innerHTML += LPC.html`
                    <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">${kpi.label}</p>
                            <h3 class="text-2xl font-black text-gray-900 mt-1" id="val-${kpi.id}">...</h3>
                        </div>
                        <div class="w-12 h-12 ${kpi.bg} ${kpi.color} rounded-xl flex items-center justify-center text-xl">
                            <i class="fas ${kpi.icon}"></i>
                        </div>
                    </div>`;
            });

            // Render Table Header
            const thead = document.getElementById('table-head');
            let thHTML = '<tr>';
            config.columns.forEach(col => thHTML += `<th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">${col.label}</th>`);
            thHTML += `<th class="py-4 px-6 text-right text-[10px] uppercase text-gray-400 font-black tracking-widest">Actions</th></tr>`;
            thead.innerHTML = thHTML;

            // Fetch Live Data
            await fetchTabData();
        }

        async function fetchTabData() {
            const tbody = document.getElementById('table-body');
            tbody.innerHTML = LPC.html`<tr><td colspan="10" class="py-8 text-center text-gray-400 animate-pulse font-bold text-sm">Chargement sécurisé des données...</td></tr>`;
            
            try {
                const response = await fetch(`/api/v1/settings_controller.php?tab=${settingsConfig[currentTab].api}`);
                const result = await response.json();
                
                if (result.status === 'success') {
                    moduleData = result.data.table;
                    
                    // Inject Live KPI Data
                    if(result.data.kpis) {
                        document.getElementById('val-kpi1').innerText = result.data.kpis.kpi1;
                        document.getElementById('val-kpi2').innerText = result.data.kpis.kpi2;
                        document.getElementById('val-kpi3').innerText = result.data.kpis.kpi3;
                    }
                    
                    renderTable(moduleData);
                } else throw new Error(result.message);
            } catch (error) {
                tbody.innerHTML = LPC.html`<tr><td colspan="10" class="py-8 text-center text-red-500 font-bold">Erreur de connexion API: ${error.message}</td></tr>`;
            }
        }

        function renderTable(dataArray) {
            const tbody = document.getElementById('table-body');
            tbody.innerHTML = '';
            const config = settingsConfig[currentTab];

            if(dataArray.length === 0) return tbody.innerHTML = LPC.html`<tr><td colspan="10" class="py-8 text-center text-gray-400 font-medium">Aucun enregistrement trouvé.</td></tr>`;

            dataArray.forEach(row => {
                let tr = '<tr class="hover:bg-gray-50 transition-colors">';
                config.columns.forEach(col => tr += `<td class="py-4 px-6 border-b border-gray-50">${col.render(row[col.key], row)}</td>`);
                
                // Live Action Buttons Logic
                let actions = '';
                if (currentTab === 'users') {
                    const isLocked = row.status !== 'active';
                    actions = `
                        <button onclick="openActionModal(${row.id})" class="text-gray-400 hover:text-gray-900 p-2 transition-colors" title="Modifier l'utilisateur" aria-label="Modifier l'utilisateur"><i class="fas fa-edit"></i></button>
                        <button onclick="toggleUserStatus(${row.id}, '${row.status}')" class="${isLocked ? 'text-red-500 hover:text-red-700' : 'text-green-500 hover:text-green-700'} p-2 transition-colors" title="${isLocked ? 'Déverrouiller le compte' : 'Verrouiller le compte'}" aria-label="${isLocked ? 'Déverrouiller le compte' : 'Verrouiller le compte'}">
                            <i class="fas ${isLocked ? 'fa-lock' : 'fa-unlock'}"></i>
                        </button>`;
                } else if (currentTab === 'sessions' && row.login_status === 'success' && !row.logout_time) {
                    actions = `<button onclick="killSession(${row.id})" title="Forcer la déconnexion immédiate" class="bg-red-50 text-red-600 text-[10px] font-bold uppercase px-3 py-1.5 rounded-lg hover:bg-red-600 hover:text-white transition-colors border border-red-200 shadow-sm" aria-label="Forcer la déconnexion immédiate"><i class="fas fa-power-off mr-1"></i> Kill Session</button>`;
                } else if (currentTab === 'audits') {
                    // Placeholder for future detail view if needed
                    actions = `<span class="text-gray-300">-</span>`;
                }

                tr += `<td class="py-4 px-6 border-b border-gray-50 text-right whitespace-nowrap">${actions}</td></tr>`;
                tbody.innerHTML += tr;
            });
        }

        // 3. WRITE / UPDATE / DELETE LOGIC (RWUD)

        function openActionModal(id = null) {
            const form = document.getElementById('dynamic-form');
            form.innerHTML = ''; 
            
            if (currentTab === 'users') {
                document.getElementById('modal-title').innerText = id ? "Modifier Utilisateur" : "Nouvel Utilisateur";
                let user = id ? moduleData.find(u => u.id == id) : {};

                form.innerHTML = LPC.html`
                    <input type="hidden" name="id" value="${id || ''}">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Code Employé *</label>
                        <input type="text" name="employee_code" value="${user.employee_code || ''}" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm outline-none focus:ring-2 focus:ring-gray-900 font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Email *</label>
                        <input type="email" name="email" value="${user.email || ''}" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm outline-none focus:ring-2 focus:ring-gray-900 font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Prénom *</label>
                        <input type="text" name="first_name" value="${user.first_name || ''}" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm outline-none focus:ring-2 focus:ring-gray-900 font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Nom *</label>
                        <input type="text" name="last_name" value="${user.last_name || ''}" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm outline-none focus:ring-2 focus:ring-gray-900 font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Rôle Système *</label>
                        <select name="role_id" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:ring-2 focus:ring-gray-900 text-gray-800">
                            <option value="1" ${user.role_name === 'Admin' ? 'selected' : ''}>Admin (Accès Total)</option>
                            <option value="2" ${user.role_name === 'Accountant' ? 'selected' : ''}>Accountant (Finance)</option>
                            <option value="3" ${user.role_name === 'Operations' ? 'selected' : ''}>Operations (Logistique)</option>
                            <option value="4" ${user.role_name === 'Driver' ? 'selected' : ''}>Driver (Mobile)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Mot de passe ${id ? '(Laisser vide pour conserver)' : '*'}</label>
                        <input type="password" name="password" ${id ? '' : 'required'} placeholder="••••••••" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm outline-none focus:ring-2 focus:ring-gray-900 font-medium">
                    </div>
                `;
            }
            
            document.getElementById('actionModal').classList.remove('hidden');
        }

        function closeModal() { document.getElementById('actionModal').classList.add('hidden'); }

        async function saveData() {
            const formElement = document.getElementById('dynamic-form');
            if (!formElement.reportValidity()) return; // Browser validation

            const formData = new FormData(formElement);
            
            try {
                // Pointing directly to our new backend controller actions
                const response = await fetch(`/api/v1/settings_controller.php?action=save_${currentTab}`, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if(result.status === 'success') {
                    closeModal(); 
                    fetchTabData(); // Reload the table to show the new data instantly
                } else LPC.modal.alert("Erreur d'enregistrement: " + result.message);
            } catch (error) { 
                LPC.modal.alert("Erreur serveur lors de la sauvegarde."); 
            }
        }

        async function toggleUserStatus(id, currentStatus) {
            const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
            const actionText = newStatus === 'inactive' ? 'VERROUILLER ce compte' : 'DÉVERROUILLER ce compte';
            
            if(!(await LPC.modal.confirm(`Voulez-vous vraiment ${actionText} ? L'utilisateur sera immédiatement affecté.`))) return;

            const fd = new FormData();
            fd.append('id', id); fd.append('status', newStatus);
            
            try {
                const response = await fetch(`/api/v1/settings_controller.php?action=toggle_user`, { method: 'POST', body: fd });
                const result = await response.json();
                if(result.status === 'success') fetchTabData(); // Instantly visually lock the account on screen
                else LPC.modal.alert("Erreur: " + result.message);
            } catch(e) { LPC.modal.alert("Erreur serveur."); }
        }

        async function killSession(sessionId) {
            if(!(await LPC.modal.confirm("⚠️ ATTENTION : Voulez-vous vraiment forcer la déconnexion de cet utilisateur immédiatement ?"))) return;
            
            const fd = new FormData(); 
            fd.append('id', sessionId);
            
            try {
                const response = await fetch(`/api/v1/settings_controller.php?action=kill_session`, { method: 'POST', body: fd });
                const result = await response.json();
                if(result.status === 'success') fetchTabData(); // Will instantly change their status to "Déconnecté"
                else LPC.modal.alert("Erreur: " + result.message);
            } catch(e) { LPC.modal.alert("Erreur serveur."); }
        }

        // Search Filter
        function filterData() {
            const q = document.getElementById('search-input').value.toLowerCase();
            renderTable(moduleData.filter(i => Object.values(i).some(v => String(v).toLowerCase().includes(q))));
        }

        // Initialize App
        document.addEventListener('DOMContentLoaded', () => switchTab('users'));
    