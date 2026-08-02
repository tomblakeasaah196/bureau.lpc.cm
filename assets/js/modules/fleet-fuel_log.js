/**
 * assets/js/modules/fleet-fuel_log.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/fleet/fuel_log.php (Sprint 6 D2).
 *
 * Original block was ~5,669 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        let lastOdometer = 0;

        window.onload = async () => {
            try {
                // Fetch ONLY the vehicle assigned to this driver today
                const res = await fetch('/api/v1/fleet_controller.php?action=get_my_vehicle');
                const result = await res.json();
                
                if(result.status === 'success' && result.data) {
                    document.getElementById('form-fuel').classList.remove('hidden');
                    document.getElementById('fuel_vehicle_id').value = result.data.vehicle_id;
                    // Drivers recognise the vehicle by name; the plate is the tie-breaker.
                    const vehName = (result.data.make_model || '').trim();
                    document.getElementById('display_plate').innerText = result.data.plate_number;
                    const nameEl = document.getElementById('display_vehicle_name');
                    if (nameEl) nameEl.innerText = vehName || '—';

                    lastOdometer = parseInt(result.data.current_odometer);
                    document.getElementById('last_odo_display').innerText = lastOdometer;

                    // Lower bound only. The old `max = lastOdometer + 10` cap mirrored a
                    // server rule that was relaxed in Sprint 4 (long-haul refuels are
                    // legitimate); the backend now owns the jump limits.
                    const odoInput = document.getElementById('fuel_odometer');
                    odoInput.min = lastOdometer;
                    odoInput.removeAttribute('max');
                    odoInput.value = ''; // Optional — leave blank to keep the last reading
                } else {
                    document.getElementById('no-vehicle-error').classList.remove('hidden');
                    document.getElementById('error-msg').innerText = result.message;
                }
            } catch(e) { 
                document.getElementById('no-vehicle-error').classList.remove('hidden');
                document.getElementById('error-msg').innerText = "Erreur de connexion réseau.";
            }
        };

        function previewImage(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('receipt-preview').src = e.target.result;
                    document.getElementById('receipt-preview').classList.remove('hidden');
                    document.getElementById('camera-icon').classList.add('hidden');
                    document.getElementById('camera-text').classList.add('hidden');
                }
                reader.readAsDataURL(file);
            }
        }

        function calcFuelCost() {
            const l = parseFloat(document.getElementById('fuel_liters').value) || 0;
            const p = parseFloat(document.getElementById('fuel_price').value) || 0;
            document.getElementById('fuel_total_display').innerText = LPC.fmt.fcfa(l * p);
        }

        async function submitFuel() {
            // 1. Strict File Validation
            const fileInput = document.getElementById('receipt_image');
            if (fileInput.files.length === 0) {
                return LPC.modal.alert("Bien vouloir prendre une photo du ticket (reçu) !");
            }

            // 2. Odometer Validation — the reading is now OPTIONAL. Blank means
            //    "I didn't note the counter"; the server keeps the last known value.
            //    We still refuse a reading that would rewind history. The old
            //    +10 km ceiling is gone: the server's FLEET_ODOMETER_JUMP_MAX owns it.
            const odoRaw = document.getElementById('fuel_odometer').value.trim();
            const odoInput = odoRaw === '' ? '' : parseInt(odoRaw, 10);
            if (odoRaw !== '' && (Number.isNaN(odoInput) || odoInput < lastOdometer)) {
                return LPC.modal.alert(`Erreur: L'odomètre ne peut pas reculer. Le dernier relevé était ${lastOdometer} Km.`);
            }

            // 3. Show Loading Overlay
            document.getElementById('loading-overlay').classList.remove('hidden');

            // Sprint 5: compress the receipt in-browser before upload. 20 MB HEIC
            // from phone cameras used to time out on 3G — this typically drops
            // them to <500 KB. Server still re-validates via Uploads::saveUploaded.
            let receipt = fileInput.files[0];
            try {
                if (window.LPC && LPC.compress && LPC.compress.compressToBlob) {
                    receipt = await LPC.compress.compressToBlob(receipt, { maxDim: 1600, quality: 0.72 });
                }
            } catch (e) { console.warn('receipt compression skipped:', e); }

            const formData = new FormData();
            formData.append('action', 'log_fuel');
            formData.append('vehicle_id', document.getElementById('fuel_vehicle_id').value);
            formData.append('gas_station', document.getElementById('gas_station').value);
            formData.append('fuel_type', document.getElementById('fuel_type').value);
            formData.append('odometer_reading', odoInput);
            formData.append('liters', document.getElementById('fuel_liters').value);
            formData.append('cost_per_liter', document.getElementById('fuel_price').value);
            formData.append('receipt_image', receipt, receipt.name || 'receipt.jpg');

            try {
                const res = await fetch('/api/v1/fleet_controller.php', { method: 'POST', body: formData });
                const result = await res.json();
                
                if (result.status === 'success') {
                    LPC.modal.alert("Ticket enregistré avec succès !");
                    window.location.href = '/modules/dashboard/views/driver_dashboard.php';
                } else {
                    LPC.modal.alert("Erreur: " + result.message);
                    document.getElementById('loading-overlay').classList.add('hidden'); // Hide overlay on error
                }
            } catch(e) { 
                LPC.modal.alert("Erreur réseau. Veuillez réessayer."); 
                document.getElementById('loading-overlay').classList.add('hidden');
            }
        }
    