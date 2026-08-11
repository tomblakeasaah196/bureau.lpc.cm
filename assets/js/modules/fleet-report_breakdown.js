/**
 * assets/js/modules/fleet-report_breakdown.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/fleet/report_breakdown.php (Sprint 6 D2).
 *
 * Original block was ~2,375 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        window.onload = async () => {
            const sel = document.getElementById('maint_vehicle_id');
            try {
                const res = await fetch('/api/v1/fleet_controller.php?action=read&tab=flotte');
                const result = await res.json();
                if(result.status === 'success') {
                    sel.innerHTML = '<option value="">-- Choisir --</option>';
                    // For breakdowns, they can select even if it's not strictly 'active' just in case
                    result.data.vehicles.forEach(v => {
                        sel.innerHTML += LPC.html`<option value="${v.id}">${v.plate_number}</option>`;
                    });
                }
            } catch(e) { sel.innerHTML = '<option value="">Erreur de chargement</option>'; }
        };

        async function submitBreakdown() {
            // Sprint 9 fix: this used to send action:'log_maintenance', which
            // requires 'fleet.vehicles.maintenance' — a permission the driver
            // role does not hold (only operations/admin do). This page is
            // gated by 'fleet.breakdown.report' instead (which drivers DO
            // hold), and now calls the matching backend action — see the
            // Sprint 9 comment on the 'report_breakdown' handler in
            // fleet_controller.php for the full story. service_type is still
            // forced server-side regardless of what's sent here.
            const payload = {
                action: 'report_breakdown',
                vehicle_id: document.getElementById('maint_vehicle_id').value,
                service_type: 'repair', // FORCED TYPE (also re-forced server-side)
                total_cost: document.getElementById('maint_cost').value || 0,
                description: document.getElementById('maint_desc').value,
                odometer_at_service: document.getElementById('maint_odo').value,
                next_service_odometer: null, // Not applicable for sudden breakdowns
                new_expiry_date: null
            };

            const btn = document.getElementById('btn-submit');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi...';
            btn.disabled = true;

            try {
                const res = await fetch('/api/v1/fleet_controller.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
                const result = await res.json();
                if (result.status === 'success') {
                    LPC.modal.alert("Alerte de panne envoyée au bureau logistique !");
                    window.location.href = '/modules/dashboard/views/driver_dashboard.php';
                } else {
                    LPC.modal.alert(result.message);
                }
            } catch(e) { LPC.modal.alert("Erreur réseau."); }
            
            btn.innerHTML = '<i class="fas fa-bullhorn"></i> Signaler la Panne';
            btn.disabled = false;
        }
    