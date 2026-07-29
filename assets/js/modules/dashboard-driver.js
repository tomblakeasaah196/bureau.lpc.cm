/**
 * assets/js/modules/dashboard-driver.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/dashboard/views/driver_dashboard.php (Sprint 6 D2).
 *
 * Original block contained PHP interpolations; they were relocated to
 * `window.PAGE_DATA` in a small hoister block just above the src include.
 * -----------------------------------------------------------------------------
 */

// D2 hoister prelude: populate window.PAGE_DATA from the inert JSON block.
(function(){
    if (window.PAGE_DATA) return;
    var el = document.getElementById('lpc-page-data');
    if (!el) { window.PAGE_DATA = {}; return; }
    try { window.PAGE_DATA = JSON.parse(el.textContent || '{}'); }
    catch (e) { console.warn('PAGE_DATA parse failed:', e); window.PAGE_DATA = {}; }
})();

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            if(sidebar) sidebar.classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }

        async function fetchDriverRoute() {
            try {
                const response = await fetch('/api/v1/driver_dashboard_api.php');
                if (!response.ok) throw new Error("API Pending");
                
                const result = await response.json();
                renderCards(result.data);

            } catch (error) {
                // UI Mock for visual testing on mobile if API fails
                const mockData = {
                    vehicle: 'Non Assigné',
                    kpis: { pending_bl: 0, total_bottles: 0 },
                    deliveries: []
                };
                renderCards(mockData);
            }
        }

        function renderCards(data) {
            // Update Header/KPIs
            const plateEl = document.getElementById('vehicle-plate');
            plateEl.innerText = data.vehicle;
            plateEl.classList.remove('animate-pulse', 'bg-gray-200');
            
            document.getElementById('kpi-pending-bl').innerText = data.kpis.pending_bl;
            document.getElementById('kpi-bottles').innerText = data.kpis.total_bottles;

            // Render Cards
            const container = document.getElementById('deliveries-container');
            container.innerHTML = '';

            if (data.deliveries.length === 0) {
                container.innerHTML = LPC.html`
                    <div class="bg-emerald-50 rounded-2xl p-8 text-center border border-emerald-100">
                        <i class="fas fa-check-circle text-5xl text-emerald-500 mb-3"></i>
                        <h4 class="text-emerald-800 font-bold text-lg">Tournée Terminée !</h4>
                        <p class="text-emerald-600 text-sm mt-1">N'oubliez pas de déclarer votre retour de caisse et d'emballages.</p>
                    </div>`;
                return;
            }

            data.deliveries.forEach(del => {
                container.innerHTML += LPC.html`
                    <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 relative overflow-hidden transition-colors">
                        <div class="absolute top-0 left-0 w-1.5 h-full bg-blue-500"></div>
                        <div class="flex justify-between items-start mb-2 pl-2">
                            <span class="text-xs font-bold text-gray-400 tracking-wider">${del.id}</span>
                            <span class="bg-blue-100 text-blue-800 text-[10px] font-extrabold px-2.5 py-1 rounded-full uppercase tracking-wide">À Livrer</span>
                        </div>
                        <h4 class="text-xl font-black text-gray-900 mb-1 pl-2">${del.client}</h4>
                        <p class="text-sm text-gray-500 flex items-center mb-4 pl-2 font-medium">
                            <i class="fas fa-map-marker-alt mr-2 text-gray-400"></i>
                            ${del.address}
                        </p>
                        <div class="bg-gray-50 rounded-xl p-3 mb-4 flex items-center ml-2 border border-gray-100">
                            <i class="fas fa-boxes text-lpc-dark text-2xl mr-3"></i>
                            <div>
                                <span class="block text-xs text-gray-500 font-semibold uppercase tracking-wider">Quantité</span>
                                <span class="block text-base font-black text-gray-900">${del.qty}</span>
                            </div>
                        </div>
                        <div class="flex ml-2 gap-2">
                            <button onclick="window.open('/bon_livraison.php?token=${del.token}', '_blank')" class="bg-gray-100 text-gray-500 hover:text-gray-900 font-bold p-3 rounded-xl transition-colors">
                                <i class="fas fa-file-pdf"></i>
                            </button>
                            <button onclick="openConfirmModal('${del.id}', '${del.client}')" class="flex-1 bg-lpc-dark hover:bg-green-800 text-white font-black text-sm py-3 rounded-xl transition-colors active:scale-95 flex items-center justify-center">
                                <i class="fas fa-check-circle mr-2"></i> Confirmer Livraison
                            </button>
                        </div>
                    </div>
                `;
            });
        }

        // ==========================================
        // RETURN MODAL LOGIC
        // ==========================================
        function openReturnModal() {
            document.getElementById('form-return').reset();
            document.getElementById('modal-return').classList.remove('hidden');
        }

        async function submitReturn() {
            const btn = document.getElementById('btn-return-submit');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Traitement...';
            btn.disabled = true;

            const payload = {
                action: 'end_shift',
                return_odometer: document.getElementById('return_odometer').value
            };

            try {
                const res = await fetch('/api/v1/driver_dashboard_api.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
                });
                const result = await res.json();
                
                if(result.status === 'success') {
                    document.getElementById('modal-return').innerHTML = LPC.html`
                        <div class="bg-white rounded-t-3xl shadow-2xl w-full max-w-md p-8 text-center animate-slideUp">
                            <i class="fas fa-check-circle text-6xl text-emerald-500 mb-4"></i>
                            <h2 class="text-2xl font-black text-gray-900">Tournée Clôturée !</h2>
                            <p class="text-gray-500 font-bold mt-2">Votre véhicule est libéré. Passez à la caisse pour le débriefing.</p>
                            <button onclick="window.location.reload()" class="mt-6 w-full bg-gray-900 text-white font-bold py-3 rounded-xl">Fermer</button>
                        </div>
                    `;
                } else {
                    LPC.modal.alert(result.message);
                    btn.innerHTML = '<i class="fas fa-check"></i> Valider Retour';
                    btn.disabled = false;
                }
            } catch(e) {
                LPC.modal.alert("Erreur réseau. Veuillez réessayer.");
                btn.innerHTML = '<i class="fas fa-check"></i> Valider Retour';
                btn.disabled = false;
            }
        }

        document.addEventListener('DOMContentLoaded', fetchDriverRoute);
        let currentClientId = null;

        async function openConfirmModal(reference, clientName) {
            document.getElementById('confirm_reference').value = reference;
            document.getElementById('confirm_client_name').innerText = clientName;
            document.getElementById('confirm_bl_ref').innerText = reference;
            document.getElementById('modal-confirm').classList.remove('hidden');
            document.getElementById('success-confirm-overlay').classList.add('hidden');
            document.getElementById('confirm_cash').value = 0;

            const container = document.getElementById('confirm-items-container');
            container.innerHTML = '<div class="text-center text-gray-400 py-4"><i class="fas fa-spinner fa-spin"></i> Chargement...</div>';

            try {
                const res = await fetch(`/api/v1/driver_dashboard_api.php?action=get_bl_items&reference=${reference}`);
                const result = await res.json();
                
                if(result.status === 'success') {
                    container.innerHTML = '';
                    result.data.forEach(item => {
                        container.innerHTML += LPC.html`
                            <div class="bg-gray-50 border border-gray-200 p-4 rounded-xl confirm-item" data-id="${item.id}" data-max="${item.max_qty}">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="font-bold text-sm text-gray-800">${item.name}</span>
                                    <span class="text-[10px] font-black bg-gray-200 text-gray-600 px-2 py-1 rounded">Expédié: ${item.max_qty}</span>
                                </div>
                                <div class="flex items-center gap-3 mb-3">
                                    <label class="text-xs font-black text-gray-500">Qté Acceptée :</label>
                                    <input type="number" class="item-qty w-20 bg-white border border-gray-300 rounded-lg p-2 text-center font-black text-lg text-lpc-dark" value="${item.max_qty}" min="0" max="${item.max_qty}" oninput="checkReason(this)">
                                </div>
                                <div class="item-reason-container hidden">
                                    <select class="item-reason w-full bg-red-50 border border-red-200 text-red-800 text-xs font-bold rounded-lg p-2 outline-none">
                                        <option value="">-- Motif de rejet --</option>
                                        <option value="Bouteille percée/Casse">Bouteille percée / Casse</option>
                                        <option value="Refus client">Refus client</option>
                                        <option value="Erreur commande">Erreur de commande</option>
                                        <option value="Autre">Autre (Préciser en note)</option>
                                    </select>
                                </div>
                            </div>
                        `;
                    });
                }
            } catch(e) { container.innerHTML = '<div class="text-red-500 text-center text-xs font-bold">Erreur de chargement.</div>'; }
        }

        function checkReason(input) {
            const max = parseInt(input.closest('.confirm-item').getAttribute('data-max'));
            const val = parseInt(input.value) || 0;
            const reasonContainer = input.closest('.confirm-item').querySelector('.item-reason-container');
            
            if (val < max) {
                reasonContainer.classList.remove('hidden');
                reasonContainer.querySelector('select').required = true;
            } else {
                reasonContainer.classList.add('hidden');
                reasonContainer.querySelector('select').required = false;
            }
        }

        async function submitConfirmDelivery() {
            const btn = document.getElementById('btn-confirm-submit');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Traitement...';
            btn.disabled = true;

            const items = [];
            let isValid = true;

            document.querySelectorAll('.confirm-item').forEach(el => {
                const id = el.getAttribute('data-id');
                const max = parseInt(el.getAttribute('data-max'));
                const qty = parseInt(el.querySelector('.item-qty').value) || 0;
                const reason = el.querySelector('.item-reason').value;

                if (qty < max && !reason) isValid = false;

                items.push({ id: id, max_qty: max, delivered_qty: qty, reason: reason });
            });

            if (!isValid) {
                LPC.modal.alert("Veuillez spécifier le motif pour les quantités refusées.");
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Valider et Envoyer';
                btn.disabled = false;
                return;
            }

            const payload = {
                action: 'confirm_delivery',
                reference: document.getElementById('confirm_reference').value,
                cash_collected: document.getElementById('confirm_cash').value,
                items: items
            };

            try {
                const res = await fetch('/api/v1/driver_dashboard_api.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
                const result = await res.json();
                
                if (result.status === 'success') {
                    currentClientId = result.client_id;
                    document.getElementById('success-confirm-overlay').classList.remove('hidden');
                } else LPC.modal.alert(result.message);
            } catch(e) { LPC.modal.alert("Erreur réseau."); }

            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Valider et Envoyer';
            btn.disabled = false;
        }

        function goToEmpties() {
            window.location.href = `/modules/operations/empties_collection.php?lang=${encodeURIComponent(window.PAGE_DATA.v1)}&client_id=${currentClientId}`;
        }    
    