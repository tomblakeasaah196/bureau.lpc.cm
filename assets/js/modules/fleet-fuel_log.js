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
                    document.getElementById('display_plate').innerText = result.data.plate_number;
                    
                    lastOdometer = parseInt(result.data.current_odometer);
                    document.getElementById('last_odo_display').innerText = lastOdometer;
                    
                    // Set strict min/max bounds
                    const odoInput = document.getElementById('fuel_odometer');
                    odoInput.min = lastOdometer;
                    odoInput.max = lastOdometer + 10;
                    odoInput.value = lastOdometer; // Prefill
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

            // 2. Strict Odometer Validation
            const odoInput = parseInt(document.getElementById('fuel_odometer').value);
            if (odoInput < lastOdometer) {
                return LPC.modal.alert(`Erreur: L'odomètre ne peut pas reculer. Le dernier relevé était ${lastOdometer} Km.`);
            }
            if ((odoInput - lastOdometer) > 10) {
                return LPC.modal.alert(`Erreur: Vous ne pouvez pas ajouter plus de 10 Km au compteur (Max: ${lastOdometer + 10} Km). Veuillez contacter la logistique.`);
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
    