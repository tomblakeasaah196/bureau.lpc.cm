/**
 * assets/js/modules/fleet-vehicles.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/fleet/vehicles.php (Sprint 6 D2).
 *
 * Original block was ~29,060 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        /** Global State Context */
        let currentTab = 'dashboard';
        let globalData = {};
        let charts = {};
        let driversList = []; // Cached for assignment dropdown

        /**
         * Toast notifications — delegated to LPC.toast (assets/js/lpc-toast.js).
         * The copy that used to live here waited on an `animationend` from CSS
         * classes that were never defined, so nothing was ever removed.
         */
        function showToast(message, type = 'success') {
            return LPC.toast(message, type);
        }

        /**
         * Human-readable label for a vehicle in dropdowns / summaries.
         * Operators think in names ("Toyota Dyna"), not plates, so lead with the
         * name and keep the plate as the disambiguator. Falls back to the plate
         * alone when make_model is empty.
         */
        function vehicleLabel(v) {
            const name = (v.make_model || '').trim();
            return name ? `${name} — ${v.plate_number}` : v.plate_number;
        }

        /**
         * Two-line vehicle identity for table cells: name on top, plate beneath.
         * Returns LPC.raw so it can be interpolated into an LPC.html template —
         * the values inside are escaped by the inner LPC.html call.
         */
        function vehicleCell(v) {
            const name = (v.make_model || '').trim();
            if (!name) return LPC.raw(LPC.html`<span class="uppercase">${v.plate_number}</span>`);
            return LPC.raw(LPC.html`${name}<br><span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">${v.plate_number}</span>`);
        }

        /** Initialization Hook */
        // Deep-link support: /modules/fleet/vehicles.php#maintenance
        const VEHICLES_VALID_TABS = ['dashboard', 'flotte', 'affectations', 'maintenance', 'carburant'];

        window.onload = () => {
            document.getElementById('assign_date').valueAsDate = new Date(); // Default to today
            fetchDriversList(); // Pre-cache operations/driver users
            const hash = (window.location.hash || '').replace('#', '');
            switchTab(VEHICLES_VALID_TABS.includes(hash) ? hash : 'dashboard');
            document.getElementById('maint_type').addEventListener('change', function() {
        const expiryDiv = document.getElementById('expiry_update_div');
        if (['insurance', 'routine'].includes(this.value)) {
            expiryDiv.classList.remove('hidden');
        } else {
            expiryDiv.classList.add('hidden');
        }
    });
        };

        /** Tab Controller & Router */
        async function switchTab(tab) {
            currentTab = tab;

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

        async function fetchTabData(tab) {
    try {
        const response = await fetch(`/api/v1/fleet_controller.php?action=read&tab=${tab}`);
        
        // 1. Check for fatal PHP errors (500 Internal Server Error)
        if (!response.ok) {
            const errorText = await response.text();
            console.error(`HTTP Error ${response.status} on tab ${tab}:`, errorText);
            showToast(`Erreur HTTP ${response.status}. Vérifiez la console.`, 'error');
            return;
        }

        const result = await response.json();

        // 2. Loudly catch backend API errors (e.g., SQL syntax errors)
        if (result.status === 'error') {
            console.error(`Backend Error (${tab}):`, result.message);
            showToast(`Erreur Backend: ${result.message}`, 'error');
            return;
        }

        // 3. Normal execution
        if (result.status === 'success') {
            globalData[tab] = result.data;
            
            if(tab === 'dashboard') renderDashboard(result.data);
            if(tab === 'flotte') renderVehicles(result.data.vehicles);
            if(tab === 'affectations') renderAssignments(result.data.assignments);
            if(tab === 'maintenance') renderMaintenance(result.data.maintenance);
            if(tab === 'carburant') renderFuelLogs(result.data.fuel_logs);

            updateGlobalBadges(result.data.badges);
        }
    } catch(e) { 
        // 4. Loudly catch JavaScript parsing or rendering errors
        console.error(`Critical JS Error in tab [${tab}]:`, e);
        showToast(`Erreur d'affichage: ${e.message}`, 'error');
    }
}

        function updateGlobalBadges(badges) {
            if(!badges) return;
            const bMaint = document.getElementById('badge-maintenance');
            const gAlert = document.getElementById('global-alert-badge');
            
            if(badges.alerts > 0) { 
                bMaint.innerText = badges.alerts; 
                bMaint.classList.remove('hidden'); 
                
                document.getElementById('alert-count').innerText = badges.alerts;
                gAlert.classList.remove('hidden');
                gAlert.classList.add('flex');
            } else { 
                bMaint.classList.add('hidden'); 
                gAlert.classList.add('hidden');
                gAlert.classList.remove('flex');
            }
        }

        /** ================= RENDER FUNCTIONS ================= */

        const fmt = (num) => LPC.fmt.int(num);

        function renderDashboard(data) {
            document.getElementById('kpi_active_vehicles').innerText = data.kpis.active_count;
            document.getElementById('kpi_repair_vehicles').innerText = data.kpis.repair_count;
            document.getElementById('kpi_fuel_cost').innerText = LPC.fmt.fcfa(data.kpis.month_fuel_cost);
            document.getElementById('kpi_maint_cost').innerText = LPC.fmt.fcfa(data.kpis.month_maint_cost);

            // Chart
            if(charts.expense) charts.expense.destroy();
            const ctx = document.getElementById('fleetExpenseChart').getContext('2d');
            charts.expense = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.chart.labels,
                    datasets: [
                        {
                            label: 'Carburant (F)',
                            data: data.chart.fuel,
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245, 158, 11, 0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Maintenance (F)',
                            data: data.chart.maintenance,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: { 
                    maintainAspectRatio: false, 
                    plugins: { legend: { position: 'top' } }
                }
            });

            // Alerts Table
            const tbody = document.getElementById('dash-alerts-body');
            tbody.innerHTML = '';
            if(data.alerts.length === 0) {
                tbody.innerHTML = LPC.html`<tr><td colspan="2" class="py-6 text-center text-gray-400 font-bold text-xs">Aucune alerte parc critique.</td></tr>`;
            } else {
                data.alerts.forEach(a => {
                    tbody.innerHTML += LPC.html`
                        <tr class="hover:bg-red-50/30 transition-colors">
                            <td class="py-3.5 px-6 font-black text-gray-800 text-xs">${vehicleCell(a)}</td>
                            <td class="py-3.5 px-6 text-right font-bold text-red-600 text-xs">${a.message}</td>
                        </tr>`;
                });
            }
        }

        function renderVehicles(vehicles) {
            const tbody = document.getElementById('table-body-vehicles');
            tbody.innerHTML = '';
            
            if(vehicles.length === 0) {
                tbody.innerHTML = LPC.html`<tr><td colspan="6" class="py-8 text-center text-gray-400 font-bold italic">Aucun véhicule enregistré.</td></tr>`;
                return;
            }

            vehicles.forEach(v => {
                let badge = '';
                if(v.status === 'active') badge = `<span class="status-badge-active px-2.5 py-1 rounded text-[9px] uppercase font-black tracking-wider"><i class="fas fa-check-circle mr-1"></i>Actif</span>`;
                else if(v.status === 'repair') badge = `<span class="status-badge-repair px-2.5 py-1 rounded text-[9px] uppercase font-black tracking-wider"><i class="fas fa-tools mr-1"></i>En Panne</span>`;
                else badge = `<span class="status-badge-retired px-2.5 py-1 rounded text-[9px] uppercase font-black tracking-wider"><i class="fas fa-archive mr-1"></i>Retiré</span>`;

                const typeIcon = v.type === 'truck' ? 'fa-truck' : (v.type === 'tricycle' ? 'fa-motorcycle' : 'fa-bicycle');

                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-gray-50/50 transition-colors">
                        <td class="py-4 px-6 font-black text-gray-900 uppercase">${v.plate_number}</td>
                        <td class="py-4 px-6 font-bold text-gray-700 text-xs">${v.make_model || '-'}</td>
                        <td class="py-4 px-6 text-gray-500 text-xs font-bold uppercase tracking-wider"><i class="fas ${typeIcon} mr-1"></i> ${v.type}</td>
                        <td class="py-4 px-6 text-right font-black text-blue-700">${fmt(v.current_odometer)} Km</td>
                        <td class="py-4 px-6 text-center">${LPC.raw(badge)}</td>
                        <td class="py-4 px-6 text-right">
                            <button onclick="editVehicle(${v.id})" class="text-gray-500 hover:text-gray-900 bg-gray-100 p-2.5 rounded-xl transition-colors"><i class="fas fa-pen"></i></button>
                        </td>
                    </tr>`;
            });
        }

        function renderAssignments(assignments) {
            const tbody = document.getElementById('table-body-assignments');
            tbody.innerHTML = '';
            
            if(assignments.length === 0) {
                tbody.innerHTML = LPC.html`<tr><td colspan="5" class="py-8 text-center text-gray-400 font-bold italic">Aucune affectation ce jour.</td></tr>`;
                return;
            }

            assignments.forEach(a => {
                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-gray-50/50 transition-colors">
                        <td class="py-4 px-6 font-bold text-gray-500 text-xs">${new Date(a.assignment_date).toLocaleDateString('fr-FR')}</td>
                        <td class="py-4 px-6 font-black text-gray-900">${vehicleCell(a)}</td>
                        <td class="py-4 px-6 font-bold text-gray-700 text-xs"><i class="fas fa-user-tie text-blue-400 mr-1"></i> ${a.driver_name}</td>
                        <td class="py-4 px-6 text-right font-black text-gray-600">${fmt(a.odometer_start)}</td>
                        <td class="py-4 px-6 text-center"><span class="bg-blue-50 text-blue-600 px-2.5 py-1 rounded text-[9px] uppercase font-black tracking-wider border border-blue-200">${a.status}</span></td>
                    </tr>`;
            });
        }

        function renderMaintenance(data) {
        const tbody = document.getElementById('table-body-maintenance');
        tbody.innerHTML = '';
    
        // Safety Check: If data or history is missing, stop the crash
        if (!data || !data.history) {
            tbody.innerHTML = LPC.html`<tr><td colspan="6" class="py-8 text-center text-gray-400 font-bold italic">Erreur de chargement des données.</td></tr>`;
            return;
        }
    
        // 1. Render ALERTS first (The "Needs for Intervention" part)
        if (data.alerts && data.alerts.length > 0) {
            data.alerts.forEach(a => {
                // Check if it's a Date or a Km alert to format it correctly
                let displayValue = '';
                let alertLabel = 'À RÉNOUVELER';
                
                if (a.type === 'Entretien Odomètre') {
                    displayValue = `Critique: ${a.date_or_km}`;
                    alertLabel = 'ENTRETIEN REQUIS';
                } else {
                    displayValue = `Échéance: ${new Date(a.date_or_km).toLocaleDateString('fr-FR')}`;
                }
    
                tbody.innerHTML += LPC.html`
                    <tr class="bg-red-50 border-l-4 border-red-500 animate-pulse-slow">
                        <td class="py-4 px-6 font-black text-red-600 text-xs uppercase"><i class="fas fa-exclamation-triangle mr-2"></i>${alertLabel}</td>
                        <td class="py-4 px-6 font-black text-gray-900">${vehicleCell(a)}</td>
                        <td class="py-4 px-6 font-bold text-red-700 text-[10px] uppercase tracking-widest">${a.type}</td>
                        <td class="py-4 px-6 text-xs text-red-500 font-medium">${displayValue}</td>
                        <td class="py-4 px-6 text-right font-black">-</td>
                        <td class="py-4 px-6 text-right">
                            <button onclick="resolveAlert(${a.id}, '${a.type}')" class="bg-red-600 text-white px-3 py-1 rounded text-[9px] font-black uppercase shadow-sm hover:bg-red-700">Régler</button>
                        </td>
                    </tr>`;
            });
        }
    
        // 2. Render HISTORY (The old list)
        if (data.history.length === 0 && (!data.alerts || data.alerts.length === 0)) {
            tbody.innerHTML = LPC.html`<tr><td colspan="6" class="py-8 text-center text-gray-400 font-bold italic">Aucun historique disponible.</td></tr>`;
            return;
        }
    
        data.history.forEach(l => {
            tbody.innerHTML += LPC.html`
                <tr class="hover:bg-gray-50/50 transition-colors">
                    <td class="py-4 px-6 font-bold text-gray-500 text-xs">${new Date(l.service_date).toLocaleDateString('fr-FR')}</td>
                    <td class="py-4 px-6 font-black text-gray-900">${vehicleCell(l)}</td>
                    <td class="py-4 px-6 font-bold text-gray-700 text-xs uppercase tracking-wider">${l.service_type}</td>
                    <td class="py-4 px-6 font-medium text-gray-500 text-xs truncate max-w-[200px]">${l.description || '-'}</td>
                    <td class="py-4 px-6 text-right font-black text-gray-600">${l.odometer_at_service ? fmt(l.odometer_at_service) : '-'}</td>
                    <td class="py-4 px-6 text-right font-black text-blue-600">${fmt(l.total_cost)} F</td>
                </tr>`;
        });
    }
        

        function renderFuelLogs(logs) {
            const tbody = document.getElementById('table-body-fuel');
            tbody.innerHTML = '';
            if(logs.length === 0) {
                tbody.innerHTML = LPC.html`<tr><td colspan="6" class="py-8 text-center text-gray-400 font-bold italic">Aucune saisie de carburant.</td></tr>`;
                return;
            }
            logs.forEach(l => {
                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-gray-50/50 transition-colors">
                        <td class="py-4 px-6 font-bold text-gray-500 text-xs">${new Date(l.date).toLocaleDateString('fr-FR')}</td>
                        <td class="py-4 px-6 font-black text-gray-900">${vehicleCell(l)}</td>
                        <td class="py-4 px-6 text-right font-black text-amber-600">${l.liters} L</td>
                        <td class="py-4 px-6 text-right font-black text-gray-900">${fmt(l.total_cost)} F <br><span class="text-[9px] text-gray-400 font-normal">@ ${fmt(l.cost_per_liter)} F/L</span></td>
                        <td class="py-4 px-6 text-right font-black text-gray-600">${fmt(l.odometer_reading)}</td>
                        <td class="py-4 px-6 text-center text-xs font-bold text-gray-500">${l.logged_by_name}</td>
                    </tr>`;
            });
        }

        /** ================= MODAL LOGIC & DROPDOWN POPULATORS ================= */

        function openModal(id) { 
            const el = document.getElementById(id);
            el.classList.remove('hidden');
            setTimeout(() => el.classList.remove('opacity-0'), 10);
        }
        
        function closeModal(id) { 
            const el = document.getElementById(id);
            el.classList.add('hidden'); 
        }

        // --- Vehicle ---
        function openVehicleModal() {
            document.getElementById('form-vehicle').reset();
            document.getElementById('veh_action').value = 'create';
            document.getElementById('veh_id').value = '';
            document.getElementById('veh-modal-title').innerText = 'Nouveau Véhicule';
            openModal('modal-vehicle');
        }

        function editVehicle(id) {
    // Fix: Search inside globalData.flotte.vehicles
    if(!globalData.flotte || !globalData.flotte.vehicles) return;
    
    const v = globalData.flotte.vehicles.find(x => x.id == id);
    if(!v) return;
    
    document.getElementById('veh_action').value = 'update';
            document.getElementById('veh_id').value = v.id;
            document.getElementById('veh_plate').value = v.plate_number;
            document.getElementById('veh_type').value = v.type;
            document.getElementById('veh_make_model').value = v.make_model;
            document.getElementById('veh_fuel_type').value = v.fuel_type;
            document.getElementById('veh_status').value = v.status;
            document.getElementById('veh_odometer').value = v.current_odometer;
            document.getElementById('veh_insurance').value = v.insurance_expiry;
            document.getElementById('veh_tech_visit').value = v.technical_visit_expiry;
            document.getElementById('veh-modal-title').innerText = 'Modifier Véhicule';
            openModal('modal-vehicle');
        }

        async function submitVehicle() {
            if(!document.getElementById('form-vehicle').checkValidity()) return document.getElementById('form-vehicle').reportValidity();
            toggleBtn('btn-submit-veh', true);

            const payload = {
                action: document.getElementById('veh_action').value,
                id: document.getElementById('veh_id').value,
                plate_number: document.getElementById('veh_plate').value,
                type: document.getElementById('veh_type').value,
                make_model: document.getElementById('veh_make_model').value,
                fuel_type: document.getElementById('veh_fuel_type').value,
                status: document.getElementById('veh_status').value,
                current_odometer: document.getElementById('veh_odometer').value,
                insurance_expiry: document.getElementById('veh_insurance').value,
                technical_visit_expiry: document.getElementById('veh_tech_visit').value
            };

            await sendPostPayload(payload, 'modal-vehicle', "Véhicule sauvegardé.");
        }

        // --- Assign ---
        async function fetchDriversList() {
            try {
                // Gets users with role operations (or specific driver role)
                const res = await fetch('/api/v1/fleet_controller.php?action=get_drivers');
                const result = await res.json();
                if(result.status === 'success') driversList = result.data;
            } catch(e){}
        }

        function populateActiveVehiclesSelect(selectId) {
    const sel = document.getElementById(selectId);
    sel.innerHTML = '<option value="">-- Choisir --</option>';
    
    // Fix: Access .vehicles and check if it exists
    if(globalData.flotte && Array.isArray(globalData.flotte.vehicles)) {
        globalData.flotte.vehicles.filter(v => v.status === 'active').forEach(v => {
            sel.innerHTML += LPC.html`<option value="${v.id}">${vehicleLabel(v)} (Km: ${v.current_odometer})</option>`;
        });
    } else {
        // Fallback: If user hasn't clicked the 'Flotte' tab yet, we should fetch it
        fetchTabData('flotte');
    }
}

        async function openAssignModal() {
    // 1. Reset form and show basic modal UI
    document.getElementById('form-assign').reset();
    const selVeh = document.getElementById('assign_vehicle_id');
    const selDriver = document.getElementById('assign_driver_id');
    
    selVeh.innerHTML = '<option value="">Chargement du parc...</option>';
    selDriver.innerHTML = '<option value="">Chargement chauffeurs...</option>';
    
    openModal('modal-assign');

    try {
        // 2. Force a fresh fetch of both Vehicles and Drivers to ensure Odometer accuracy
        // We run these in parallel for speed
        const [vehRes, driverRes] = await Promise.all([
            fetch('/api/v1/fleet_controller.php?action=read&tab=flotte'),
            fetch('/api/v1/fleet_controller.php?action=get_drivers')
        ]);

        const vehResult = await vehRes.json();
        const driverResult = await driverRes.json();

        if (vehResult.status === 'success' && driverResult.status === 'success') {
            // Update local globalData so other tabs benefit from this refresh
            globalData.flotte = vehResult.data;
            driversList = driverResult.data;

            // 3. Populate Vehicles (Only Active ones)
            selVeh.innerHTML = '<option value="">-- Choisir un véhicule --</option>';
            vehResult.data.vehicles.filter(v => v.status === 'active').forEach(v => {
                selVeh.innerHTML += LPC.html`<option value="${v.id}">${vehicleLabel(v)} (Actuel: ${fmt(v.current_odometer)} Km)</option>`;
            });

            // 4. Populate Drivers
            selDriver.innerHTML = '<option value="">-- Choisir un chauffeur --</option>';
            driversList.forEach(d => {
                selDriver.innerHTML += LPC.html`<option value="${d.id}">${d.first_name} ${d.last_name}</option>`;
            });
        } else {
            showToast("Erreur lors de la mise à jour des données.", "error");
        }
    } catch (e) {
        console.error("Assign Modal Fetch Error:", e);
        showToast("Impossible de joindre le serveur.", "error");
    }
}

        async function submitAssignment() {
            if(!document.getElementById('form-assign').checkValidity()) return document.getElementById('form-assign').reportValidity();
            toggleBtn('btn-submit-assign', true);

            const payload = {
                action: 'create_assignment',
                vehicle_id: document.getElementById('assign_vehicle_id').value,
                driver_id: document.getElementById('assign_driver_id').value,
                assignment_date: document.getElementById('assign_date').value,
                odometer_start: document.getElementById('assign_odo_start').value
            };
            await sendPostPayload(payload, 'modal-assign', "Affectation enregistrée.");
        }

        // --- Fuel ---
        function calcFuelCost() {
            const l = parseFloat(document.getElementById('fuel_liters').value) || 0;
            const p = parseFloat(document.getElementById('fuel_price').value) || 0;
            document.getElementById('fuel_total_display').innerText = LPC.fmt.fcfa(l * p);
        }

        async function populateAllVehiclesSelect(selectId) {
    const sel = document.getElementById(selectId);
    sel.innerHTML = '<option value="">Chargement en cours...</option>'; // Show loading state
    
    // Wait for data to fetch if it doesn't exist yet
    if(!globalData.flotte || !Array.isArray(globalData.flotte.vehicles)) {
        await fetchTabData('flotte');
    }

    sel.innerHTML = '<option value="">-- Choisir un véhicule --</option>';
    if(globalData.flotte && Array.isArray(globalData.flotte.vehicles)) {
        globalData.flotte.vehicles.filter(v => v.status !== 'retired').forEach(v => {
            sel.innerHTML += LPC.html`<option value="${v.id}">${vehicleLabel(v)}</option>`;
        });
    }
}

// Make sure to add 'async' and 'await' to the modal openers:
async function openFuelModal() {
    document.getElementById('form-fuel').reset();
    document.getElementById('fuel_total_display').innerText = '0 FCFA';
    await populateAllVehiclesSelect('fuel_vehicle_id');
    openModal('modal-fuel');
}

        async function submitFuel() {
            if(!document.getElementById('form-fuel').checkValidity()) return document.getElementById('form-fuel').reportValidity();
            toggleBtn('btn-submit-fuel', true);

            const payload = {
                action: 'log_fuel',
                vehicle_id: document.getElementById('fuel_vehicle_id').value,
                liters: document.getElementById('fuel_liters').value,
                cost_per_liter: document.getElementById('fuel_price').value,
                odometer_reading: document.getElementById('fuel_odometer').value
            };
            await sendPostPayload(payload, 'modal-fuel', "Carburant enregistré.");
        }

            async function openMaintenanceModal() {
                document.getElementById('form-maint').reset();
                await populateAllVehiclesSelect('maint_vehicle_id');
                openModal('modal-maint');
            }

        async function submitMaintenance() {
        if(!document.getElementById('form-maint').checkValidity()) return document.getElementById('form-maint').reportValidity();
        toggleBtn('btn-submit-maint', true);
    
        const payload = {
            action: 'log_maintenance',
            vehicle_id: document.getElementById('maint_vehicle_id').value,
            service_type: document.getElementById('maint_type').value,
            total_cost: document.getElementById('maint_cost').value,
            description: document.getElementById('maint_desc').value,
            odometer_at_service: document.getElementById('maint_odo').value,
            next_service_odometer: document.getElementById('maint_next_odo').value,
            // ADD THIS LINE:
            new_expiry_date: document.getElementById('maint_new_expiry').value 
        };
    
        await sendPostPayload(payload, 'modal-maint', "Intervention sauvegardée.");
    }
        
            async function resolveAlert(vehicleId, alertType) {
                document.getElementById('form-maint').reset();
                await populateAllVehiclesSelect('maint_vehicle_id'); 
                
                document.getElementById('maint_vehicle_id').value = vehicleId;
                
                let mappedType = 'routine';
                if (alertType === 'Assurance') mappedType = 'insurance';
                else if (alertType === 'Visite Tech' || alertType === 'Entretien Odomètre') mappedType = 'routine';
                
                document.getElementById('maint_type').value = mappedType;
                document.getElementById('maint_type').dispatchEvent(new Event('change'));
                
                openModal('modal-maint');
            }

        /** ================= UTILITIES ================= */

        function toggleBtn(btnId, isSpinning) {
            const btn = document.getElementById(btnId);
            const txt = document.getElementById(`${btnId}-text`);
            const spin = document.getElementById(`${btnId}-spinner`);
            btn.disabled = isSpinning;
            if(isSpinning) { txt.classList.add('hidden'); spin.classList.remove('hidden'); }
            else { txt.classList.remove('hidden'); spin.classList.add('hidden'); }
        }

        async function sendPostPayload(payload, modalId, successMsg) {
            try {
                const response = await fetch('/api/v1/fleet_controller.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
                });
                const result = await response.json();
                
                if (result.status === 'success') {
                    closeModal(modalId);
                    showToast(successMsg);
                    fetchTabData(currentTab); // Auto refresh current view
                } else {
                    showToast(result.message, "error");
                }
            } catch(e) { 
                showToast("Erreur serveur interne.", "error"); 
            } finally {
                // Revert button state based on open modal
                document.querySelectorAll('button[id^="btn-submit"]').forEach(b => {
                    if(b.id) toggleBtn(b.id, false);
                });
            }
        }

        function filterTable(tbodyId, query) {
            const q = query.toLowerCase();
            const rows = document.getElementById(tbodyId).getElementsByTagName('tr');
            for (let i = 0; i < rows.length; i++) {
                rows[i].style.display = rows[i].innerText.toLowerCase().includes(q) ? '' : 'none';
            }
        }
    