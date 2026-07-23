/**
 * assets/js/modules/admin-master_data.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/admin/master_data.php (Sprint 6 D2).
 *
 * Original block was ~17,968 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        // --- 1. FULL 5-TAB CONFIGURATION ---
        const mdmConfig = {
            products: {
                title: "Produits", addBtn: "Nouveau Produit", api: "products",
                columns: [
                    { key: 'code', label: 'Code SKU', render: v => `<span class="font-bold text-gray-500">${v}</span>` },
                    { key: 'name', label: 'Désignation', render: v => `<span class="font-black text-gray-900">${v}</span>` },
                    { key: 'category', label: 'Catégorie', render: v => `<span class="px-2 py-1 bg-gray-100 text-gray-600 rounded text-[10px] uppercase font-bold">${v}</span>` },
                    { key: 'linked_empty_name', label: 'Emballage Lié', render: v => v ? `<span class="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-1 rounded"><i class="fas fa-link mr-1"></i>${v}</span>` : '<span class="text-gray-300">-</span>' },
                    { key: 'base_price', label: 'Prix Base', render: v => `<span class="font-bold text-emerald-600">${LPC.fmt.int(v)} FCFA</span>` },
                    { key: 'is_active', label: 'Statut', render: v => parseInt(v) === 1 ? '<span class="text-green-500 text-xs font-bold">Actif</span>' : '<span class="text-red-500 text-xs font-bold">Inactif</span>' }
                ],
                form: [
                    { name: 'code', label: 'Code', type: 'text', width: 'col-span-1' },
                    { name: 'name', label: 'Désignation', type: 'text', width: 'col-span-1' },
                    { name: 'category', label: 'Catégorie', type: 'select', options: [{v:'Eau',l:'Eau'},{v:'Emballage',l:'Emballage'},{v:'Equipement',l:'Equipement'}], width: 'col-span-1' },
                    { name: 'base_price', label: 'Prix de Base', type: 'number', width: 'col-span-1' },
                    { name: 'format', label: 'Format (ex: 20L)', type: 'text', width: 'col-span-1' },
                    { name: 'linked_empty_id', label: 'Emballage Associé', type: 'dynamic_select', source: 'empties', width: 'col-span-1' }
                ]
            },
            pricing: {
                title: "Tarifs Clients", addBtn: "Nouveau Tarif", api: "pricing",
                columns: [
                    { key: 'client_name', label: 'Client', render: v => `<span class="font-black text-gray-900">${v}</span>` },
                    { key: 'product_name', label: 'Produit', render: v => `<span class="font-bold text-gray-600">${v}</span>` },
                    { key: 'custom_price', label: 'Prix Négocié', render: v => `<span class="font-black text-lpc-dark">${LPC.fmt.int(v)} FCFA</span>` }
                ],
                form: [
                    { name: 'client_id', label: 'Client', type: 'dynamic_select', source: 'clients', width: 'col-span-1' },
                    { name: 'product_id', label: 'Produit', type: 'dynamic_select', source: 'products', width: 'col-span-1' },
                    { name: 'custom_price', label: 'Prix Négocié (FCFA)', type: 'number', width: 'col-span-2' }
                ]
            },
            employees: {
                title: "Employés & RH", addBtn: "Nouvel Employé", api: "employees",
                columns: [
                    { key: 'avatar', label: 'Profil', render: v => `<img alt="" src="${v || '/assets/img/default_avatar.png'}" class="w-10 h-10 rounded-full object-cover border border-gray-200">` },
                    { key: 'employee_code', label: 'Matricule', render: v => `<span class="font-bold text-gray-500">${v || 'N/A'}</span>` },
                    { key: 'full_name', label: 'Nom & Prénom', render: v => `<span class="font-black text-gray-900">${v}</span>` },
                    { key: 'role_name', label: 'Rôle Système', render: v => `<span class="px-2 py-1 bg-blue-50 text-blue-600 rounded text-[10px] font-bold uppercase">${v}</span>` },
                    { key: 'job_title', label: 'Poste', render: v => `<span class="text-sm text-gray-600">${v || '-'}</span>` },
                    { key: 'status', label: 'Statut', render: v => v === 'active' ? '<span class="text-green-500 text-xs font-bold">Actif</span>' : '<span class="text-red-500 text-xs font-bold">Inactif</span>' }
                ],
                form: [
                    { name: 'first_name', label: 'Prénom', type: 'text', width: 'col-span-1' },
                    { name: 'last_name', label: 'Nom', type: 'text', width: 'col-span-1' },
                    { name: 'email', label: 'Email (Connexion)', type: 'email', width: 'col-span-1' },
                    { name: 'role_id', label: 'Rôle Système', type: 'dynamic_select', source: 'roles', width: 'col-span-1' },
                    { name: 'job_title', label: 'Poste (ex: Chauffeur)', type: 'text', width: 'col-span-1' },
                    { name: 'phone', label: 'Téléphone', type: 'text', width: 'col-span-1' },
                    { name: 'base_salary', label: 'Salaire de Base', type: 'number', width: 'col-span-1' },
                    { name: 'avatar', label: 'Photo de Profil', type: 'file', width: 'col-span-1' }
                ]
            },
            suppliers: {
                title: "Fournisseurs", addBtn: "Nouveau Fournisseur", api: "suppliers",
                columns: [
                    { key: 'lpc_code', label: 'Code', render: v => `<span class="font-bold text-gray-500">${v}</span>` },
                    { key: 'name', label: 'Fournisseur', render: v => `<span class="font-black text-gray-900">${v}</span>` },
                    { key: 'phone', label: 'Contact', render: v => `<span class="text-sm text-gray-600">${v || '-'}</span>` },
                    { key: 'is_active', label: 'Statut', render: v => parseInt(v) === 1 ? '<span class="text-green-500 text-xs font-bold">Actif</span>' : '<span class="text-red-500 text-xs font-bold">Inactif</span>' }
                ],
                form: [
                    // lpc_code is completely removed. The backend handles it invisibly.
                    { name: 'name', label: 'Raison Sociale', type: 'text', width: 'col-span-2' },
                    { name: 'phone', label: 'Téléphone', type: 'text', width: 'col-span-1' },
                    { name: 'email', label: 'Email', type: 'email', width: 'col-span-1' },
                    { name: 'address', label: 'Adresse Complète', type: 'text', width: 'col-span-2' }
                ]
            },
            fleet: {
                title: "Flotte", addBtn: "Nouveau Véhicule", api: "fleet",
                columns: [
                    { key: 'plate_number', label: 'Immatriculation', render: v => `<span class="font-black text-gray-900 bg-yellow-100 px-2 py-1 rounded border border-yellow-300">${v}</span>` },
                    { key: 'type', label: 'Type', render: v => `<span class="text-sm uppercase font-bold text-gray-600">${v}</span>` },
                    { key: 'status', label: 'État', render: v => v === 'active' ? '<span class="text-green-500 text-xs font-bold">Opérationnel</span>' : '<span class="text-red-500 text-xs font-bold">En Panne/Retiré</span>' }
                ],
                form: [
                    { name: 'plate_number', label: 'Plaque d\'Immatriculation', type: 'text', width: 'col-span-1' },
                    { name: 'type', label: 'Type de Véhicule', type: 'select', options: [{v:'tricycle',l:'Tricycle'}, {v:'bike',l:'Moto'}, {v:'truck',l:'Camion'}], width: 'col-span-1' },
                    { name: 'make_model', label: 'Marque & Modèle', type: 'text', width: 'col-span-1' },
                    { name: 'status', label: 'État Actuel', type: 'select', options: [{v:'active',l:'Opérationnel'}, {v:'repair',l:'En Panne'}, {v:'retired',l:'Retiré'}], width: 'col-span-1' }
                ]
            }
        };

        let currentModule = 'products';
        let moduleData = [];
        let metaData = {}; // Stores dynamic dropdown options (Roles, Clients, etc.)

        // --- 2. LOGIC ---
        function initTabs() {
            const nav = document.getElementById('mdm-tabs');
            nav.innerHTML = '';
            Object.keys(mdmConfig).forEach(key => {
                const isActive = key === currentModule;
                const borderClass = isActive ? 'border-lpc-dark text-lpc-dark font-black' : 'border-transparent text-gray-400 font-bold hover:text-gray-600';
                nav.innerHTML += LPC.html`<button onclick="switchTab('${key}')" class="px-2 py-5 border-b-2 text-sm uppercase tracking-wider transition-colors ${borderClass}">${mdmConfig[key].title}</button>`;
            });
        }

        async function switchTab(key) {
            currentModule = key;
            initTabs();
            document.getElementById('btn-add-text').innerText = mdmConfig[currentModule].addBtn;
            
            const thead = document.getElementById('table-head');
            let thHTML = '<tr>';
            mdmConfig[currentModule].columns.forEach(col => thHTML += `<th class="py-4 px-6 text-[10px] uppercase text-gray-500 font-black tracking-widest">${col.label}</th>`);
            thHTML += `<th class="py-4 px-6 text-right text-[10px] uppercase text-gray-500 font-black tracking-widest">Actions</th></tr>`;
            thead.innerHTML = thHTML;

            await fetchData();
        }

        async function fetchData() {
            const tbody = document.getElementById('table-body');
            tbody.innerHTML = LPC.html`<tr><td colspan="10" class="py-8 text-center text-gray-400 animate-pulse font-bold text-sm">Chargement des données...</td></tr>`;
            
            try {
                const response = await fetch(`/api/v1/mdm_controller.php?action=read&module=${mdmConfig[currentModule].api}`);
                const result = await response.json();
                
                if(result.status === 'success') {
                    moduleData = result.data;
                    metaData = result.meta || {}; // Save relational data for forms
                    renderTable(moduleData);
                } else throw new Error(result.message);
            } catch (error) {
                tbody.innerHTML = LPC.html`<tr><td colspan="10" class="py-8 text-center text-red-500 font-bold">Erreur API: ${error.message}</td></tr>`;
            }
        }

        function renderTable(dataArray) {
            const tbody = document.getElementById('table-body');
            tbody.innerHTML = '';
            if(dataArray.length === 0) return tbody.innerHTML = LPC.html`<tr><td colspan="10" class="py-8 text-center text-gray-400 font-medium">Aucun enregistrement trouvé.</td></tr>`;

            dataArray.forEach(row => {
                let tr = '<tr class="hover:bg-gray-50 transition-colors">';
                mdmConfig[currentModule].columns.forEach(col => tr += `<td class="py-4 px-6">${col.render(row[col.key])}</td>`);
                
                // Primary Key logic (Pricing uses composite keys, others use ID)
                const pk = currentModule === 'pricing' ? `'${row.client_id}_${row.product_id}'` : row.id;
                const statusFlag = row.is_active !== undefined ? row.is_active : (row.status === 'active' ? 1 : 0);

                tr += `
                    <td class="py-4 px-6 text-right whitespace-nowrap">
                        <button onclick="openModal(${pk})" class="text-blue-500 hover:text-blue-700 bg-blue-50 p-2 rounded-lg mr-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg></button>
                        ${currentModule !== 'pricing' ? `<button onclick="toggleStatus(${pk}, ${statusFlag})" class="${statusFlag ? 'text-red-500 bg-red-50' : 'text-green-500 bg-green-50'} p-2 rounded-lg"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path></svg></button>` : ''}
                    </td></tr>`;
                tbody.innerHTML += tr;
            });
        }

        function openModal(id = null) {
            const form = document.getElementById('dynamic-form');
            form.innerHTML = LPC.html`<input type="hidden" id="f_id" name="id" value="${id || ''}">`;
            document.getElementById('modal-title').innerText = id ? `Modifier` : mdmConfig[currentModule].addBtn;

            let existingData = {};
            if (id) {
                if(currentModule === 'pricing') {
                    const parts = id.split('_');
                    existingData = moduleData.find(i => i.client_id == parts[0] && i.product_id == parts[1]) || {};
                } else {
                    existingData = moduleData.find(i => i.id == id) || {};
                }
            }

            mdmConfig[currentModule].form.forEach(field => {
                const value = existingData[field.name] || '';
                let inputHTML = '';

                if (field.type === 'select') {
                    const opts = field.options.map(o => `<option value="${o.v}" ${value === o.v ? 'selected' : ''}>${o.l}</option>`).join('');
                    inputHTML = `<select required id="f_${field.name}" name="${field.name}" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-medium outline-none focus:ring-2 focus:ring-lpc-light">${opts}</select>`;
                } else if (field.type === 'dynamic_select') {
                    const opts = (metaData[field.source] || []).map(o => `<option value="${o.id}" ${value == o.id ? 'selected' : ''}>${o.name}</option>`).join('');
                    inputHTML = `<select required id="f_${field.name}" name="${field.name}" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-medium outline-none focus:ring-2 focus:ring-lpc-light"><option value="">Sélectionner...</option>${opts}</select>`;
                } else if (field.type === 'file') {
                    inputHTML = `<input type="file" id="f_${field.name}" name="${field.name}" accept="image/*" class="w-full bg-gray-50 border border-gray-200 rounded-xl p-2 text-sm font-medium outline-none">`;
                    if(value) inputHTML += `<p class="text-[10px] text-gray-500 mt-1">Fichier actuel: ${value.split('/').pop()}</p>`;
                } else {
                    inputHTML = `<input required type="${field.type}" id="f_${field.name}" name="${field.name}" value="${value}" ${currentModule==='pricing' && id && field.name.includes('id') ? 'readonly' : ''} class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-medium outline-none focus:ring-2 focus:ring-lpc-light">`;
                }

                form.innerHTML += LPC.html`<div class="${field.width}"><label class="block text-[10px] font-black uppercase text-gray-500 mb-1.5 ml-1">${field.label}</label>${inputHTML}</div>`;
            });

            document.getElementById('mdmModal').classList.remove('hidden');
        }

        function closeModal() { document.getElementById('mdmModal').classList.add('hidden'); }

        async function toggleStatus(id, currentStatus) {
            if(!(await LPC.modal.confirm("Modifier le statut ?"))) return;
            const fd = new FormData();
            fd.append('id', id); fd.append('is_active', currentStatus);
            await fetch(`/api/v1/mdm_controller.php?action=toggle_status&module=${mdmConfig[currentModule].api}`, { method: 'POST', body: fd });
            fetchData();
        }

        async function saveRecord() {
            const formElement = document.getElementById('dynamic-form');

            // Forces browser validation
            if (!formElement.reportValidity()) return;

            // Sprint 5: compress any <input type=file accept=image/*> before
            // building the FormData so the avatar upload doesn't push a 10 MB
            // phone selfie through PHP-FPM.
            if (window.LPC && LPC.compress && LPC.compress.compressToBlob) {
                const imgInputs = formElement.querySelectorAll('input[type="file"][accept^="image/"]');
                for (const inp of imgInputs) {
                    if (inp.files && inp.files[0]) {
                        try {
                            const smaller = await LPC.compress.compressToBlob(inp.files[0], { maxDim: 512, quality: 0.8 });
                            if (smaller !== inp.files[0]) {
                                const dt = new DataTransfer();
                                dt.items.add(smaller);
                                inp.files = dt.files;
                            }
                        } catch (e) { console.warn('avatar compression skipped:', e); }
                    }
                }
            }

            // Initialize form data ONCE (after compression has swapped the file in).
            const formData = new FormData(formElement);

            try {
                const response = await fetch(`/api/v1/mdm_controller.php?action=save&module=${mdmConfig[currentModule].api}`, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if(result.status === 'success') {
                    closeModal(); 
                    fetchData();
                } else LPC.modal.alert("Erreur: " + result.message);
            } catch (error) { 
                LPC.modal.alert("Erreur serveur."); 
            }
        }

        function filterTable() {
            const q = document.getElementById('mdm-search').value.toLowerCase();
            renderTable(moduleData.filter(i => Object.values(i).some(v => String(v).toLowerCase().includes(q))));
        }

        document.addEventListener('DOMContentLoaded', () => { initTabs(); switchTab('products'); });
    