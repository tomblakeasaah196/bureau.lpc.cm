/**
 * assets/js/modules/documents-bon_livraison.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from public/documents/bon_livraison.php (Sprint 6 D2).
 *
 * Original block was ~17,537 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        // 1. GLOBAL STATE & DICTIONARY
        let apiData = null;
        let currentLang = 'fr'; 
        const currentUrl = window.location.href; 

        const dictionary = {
            en: {
                btn_pdf: "Print DN", btn_email: "Email", btn_whatsapp: "WhatsApp",
                doc_title: "Delivery Note", doc_subtitle: "Dispatch Document",
                lbl_bl_num: "DN No:", lbl_cmd_num: "Order No:", lbl_date: "Date:",
                lbl_client: "Ship To / Client", lbl_logistics: "Logistics Details",
                lbl_driver: "Driver:", lbl_vehicle: "Vehicle:",
                tbl_desc: "Goods Description", tbl_qty_sent: "Qty Dispatched", tbl_qty_rec: "Qty Received",
                lbl_instruction_title: "Required Action at Unloading",
                lbl_instruction_body: "You must collect empty packaging and ensure the Empties Return Certificate (C.R.E.) is signed by the client.",
                stamp_title: "Dispatch Verified", sig_driver: "Driver Signature", sig_client: "Client Signature & Stamp",
                sig_client_sub: "Signature confirms receipt in good condition."
            },
            fr: {
                btn_pdf: "Imprimer BL", btn_email: "Email", btn_whatsapp: "WhatsApp",
                doc_title: "Bordereau de Livraison", doc_subtitle: "Document Logistique",
                lbl_bl_num: "N° BL :", lbl_cmd_num: "N° Cmd :", lbl_date: "Date :",
                lbl_client: "Client / Livré à", lbl_logistics: "Détails Logistiques",
                lbl_driver: "Chauffeur:", lbl_vehicle: "Véhicule:",
                tbl_desc: "Désignation des Marchandises", tbl_qty_sent: "Qté Expédiée", tbl_qty_rec: "Qté Reçue",
                lbl_instruction_title: "Action Requise au Déchargement",
                lbl_instruction_body: "Collecter impérativement les emballages vides et faire signer le Certificat de Restitution d'Emballages (C.R.E.) par le client.",
                stamp_title: "Vérification Départ", sig_driver: "Visa Chauffeur", sig_client: "Visa Client & Cachet",
                sig_client_sub: "La signature confirme la réception en bon état."
            }
        };

        // 2. FETCH DATA
        const urlParams = new URLSearchParams(window.location.search);
        const blToken = urlParams.get('token');

        window.onload = async () => {
            if (!blToken) {
                document.getElementById('document-capture').innerHTML = LPC.html`<div class="p-10 text-center text-red-600 font-bold text-xl mt-20">Lien invalide. / Invalid link.</div>`;
                return;
            }
            try {
                const response = await fetch(`/api/v1/get_bl.php?token=${blToken}`);
                const result = await response.json();
                if (result.status === 'success') {
                    apiData = result.data; 
                    injectData(); 
                    setLang('fr'); 
                    updateModals(); 
                } else {
                    document.getElementById('document-capture').innerHTML = LPC.html`<div class="p-10 text-center text-red-600 font-bold text-xl mt-20">Erreur: ${result.message}</div>`;
                }
            } catch (e) {
                document.getElementById('document-capture').innerHTML = LPC.html`<div class="p-10 text-center text-red-600 font-bold text-xl mt-20">Erreur Serveur.</div>`;
            }
        };

        // 3. INJECT DATA
        function injectData() {
            if(!apiData) return;
            const bl = apiData.delivery;
            const client = apiData.client;
            const log = apiData.logistics;
            const stamp = apiData.stamp;
            
            const formattedDate = new Date(bl.date).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });

            // Header Info
            document.getElementById('nav-ref').innerText = bl.reference;
            document.getElementById('dyn_ref').innerText = bl.reference;
            document.getElementById('dyn_so_ref').innerText = bl.sales_order_ref;
            document.getElementById('dyn_date').innerText = formattedDate;

            // Client Info
            document.getElementById('dyn_client_name').innerText = client.name;
            document.getElementById('dyn_client_address').innerText = client.address || 'Adresse non spécifiée';
            document.getElementById('dyn_client_phone').innerText = client.phone || 'N/A';

            // Observation Info
            const obs = apiData.delivery.client_observation;
            if (obs && obs.trim() !== '') {
                document.getElementById('dyn_observation').innerText = `« ${obs} »`;
                document.getElementById('dyn_observation').classList.add('text-amber-700');
            } else {
                document.getElementById('dyn_observation').innerText = "Aucune anomalie signalée lors de la livraison.";
                document.getElementById('dyn_observation').classList.add('text-gray-500');
            }



            const isCompleted = apiData.signatures && apiData.signatures.status === 'completed';

            // Hide the signing toolbar if already completed
            if (isCompleted) {
                document.getElementById('signature-dropdown-container').style.display = 'none';
            }

            // Table Injection (Dynamic Qty if completed)
            const tbody = document.getElementById('dyn_items_table');
            tbody.innerHTML = '';
            apiData.items.forEach(item => {
                const formatHtml = item.format ? `<span class="text-xs text-gray-500 ml-1">(${item.format})</span>` : '';
                // If completed, we assume item.qty is what was received for visual purposes on this simple view, 
                // but realistically your backend might send accepted_qty. We'll show the solid number if completed.
                const receivedHtml = isCompleted 
                    ? `<span class="font-black text-lg text-blue-700">${item.qty}</span>` 
                    : `<div class="w-16 h-8 border-b-2 border-dotted border-gray-400 mx-auto"></div>`;

                tbody.innerHTML += LPC.html`
                    <tr>
                        <td class="py-4 px-2">
                            <div class="font-bold text-gray-900">${item.name} ${formatHtml}</div>
                        </td>
                        <td class="py-4 px-2 text-center font-black text-lg text-gray-800">${item.qty}</td>
                        <td class="py-4 px-2 text-center border-l border-gray-100">
                            ${receivedHtml}
                        </td>
                    </tr>
                `;
            });

            // Dynamic Signatures Area
            const sigArea = document.getElementById('dynamic-signatures-area');
            
            // Departure Stamp (Removed 'truncate', added 'break-words' and JS substring)
            let sigHTML = `
                <div class="col-span-1 flex flex-col items-center justify-end h-full">
                    <div class="h-12 mb-1 w-full"></div>
                    <div class="digital-stamp w-full max-w-[220px] h-[110px] flex flex-col justify-center" style="transform: none; padding: 10px; text-align: left;">
                        <p class="text-[9px] text-center font-black uppercase tracking-widest border-b border-green-900/30 pb-1 mb-1">Vérification Départ</p>
                        <p class="text-[8px] font-bold mt-1">Par: <span class="font-mono text-[9px] break-words leading-tight">${stamp.created_by.substring(0, 25)}</span></p>
                        <p class="text-[8px] font-bold">Le: <span class="font-mono">${stamp.timestamp}</span></p>
                        <p class="text-[5px] font-normal mt-1 opacity-70 break-all leading-tight">Hash: <span class="font-mono">${stamp.hash}</span></p>
                    </div>
                </div>
            `;

            if (isCompleted) {
                // Show Dual Signatures (Removed 'truncate', relying on JS substring and standard wrapping)
                const sigs = apiData.signatures;
                const log = apiData.logistics;
                
                sigHTML += `
                    <div class="col-span-1 flex flex-col items-center justify-end h-full w-full">
                        <div class="h-12 w-full flex justify-center mb-1">
                            <img alt="" src="${sigs.driver_img}" class="h-full w-auto object-contain" style="mix-blend-mode: multiply;" onerror="this.style.display='none'">
                        </div>
                        <div class="digital-stamp w-full max-w-[220px] h-[110px] flex flex-col justify-center" style="border-color: #2563EB; color: #2563EB; background-image: none; transform: none; padding: 10px; text-align: left;">
                            <p class="text-[9px] text-center font-black uppercase border-b border-blue-200 pb-1 mb-1">Signature Chauffeur</p>
                            <p class="text-[8px] font-bold">Par: <span class="font-mono text-[9px] break-words leading-tight">${(log.driver_name || 'N/A').substring(0, 25)}</span></p>
                            <p class="text-[8px] font-bold">Le: <span class="font-mono">${sigs.driver_date}</span></p>
                            <p class="text-[5px] font-normal mt-1 opacity-70 break-all leading-tight">Hash: <span class="font-mono">${sigs.driver_hash || ''}</span></p>
                        </div>
                    </div>
                    <div class="col-span-1 flex flex-col items-center justify-end h-full w-full">
                        <div class="h-12 w-full flex justify-center mb-1">
                            <img alt="" src="${sigs.client_img}" class="h-full w-auto object-contain" style="mix-blend-mode: multiply;" onerror="this.style.display='none'">
                        </div>
                        <div class="digital-stamp w-full max-w-[220px] h-[110px] flex flex-col justify-center" style="border-color: #D97706; color: #D97706; background-image: none; transform: none; padding: 10px; text-align: left;">
                            <p class="text-[9px] text-center font-black uppercase border-b border-amber-200 pb-1 mb-1">Réception Client</p>
                            <p class="text-[8px] font-bold">Par: <span class="font-mono text-[9px] break-words leading-tight">${sigs.client_name ? sigs.client_name.substring(0, 25) : 'N/A'}</span></p>
                            <p class="text-[8px] font-bold">Le: <span class="font-mono">${sigs.client_date}</span></p>
                            <p class="text-[5px] font-normal mt-1 opacity-70 break-all leading-tight">Hash: <span class="font-mono">${sigs.client_hash || ''}</span></p>
                        </div>
                    </div>
                `;
            } else {
                // Show empty dotted lines perfectly aligned
                sigHTML += `
                    <div class="col-span-1 flex flex-col items-center justify-end h-full w-full">
                        <div class="w-full max-w-[220px] text-center border-t border-gray-300 pt-3 mt-4">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Visa Chauffeur</p>
                        </div>
                    </div>
                    <div class="col-span-1 flex flex-col items-center justify-end h-full w-full">
                        <div class="w-full max-w-[220px] text-center border-t border-gray-300 pt-3 mt-4">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Visa Client & Cachet</p>
                        </div>
                    </div>
                `;
            }
            sigArea.innerHTML = sigHTML;
        }

        // 4. MODALS & COMMS
        function updateModals() {
            const client = apiData.client;
            const bl = apiData.delivery;

            let phone = client.phone ? client.phone.replace(/[^0-9]/g, '') : '';
            if(phone && !phone.startsWith('237') && phone.length <= 9) phone = '237' + phone; 
            document.getElementById('input_wa_phone').value = phone;
            
            const waText = `Bonjour,\n\nVotre commande (Réf: ${bl.sales_order_ref}) est en route !\n\nLien de suivi / BL : ${currentUrl}\n\nMerci de préparer la réception.\nLPC Logistique`;
            document.getElementById('input_wa_body').value = waText;

            document.getElementById('input_email_to').value = client.email || '';
            document.getElementById('input_email_subject').value = `Expédition Commande LPC : ${bl.sales_order_ref}`;
            document.getElementById('input_email_body').value = `Bonjour,\n\nVotre commande a été expédiée.\nVeuillez consulter votre Bordereau de Livraison via le lien sécurisé ci-dessous:\n\n📄 Consulter le BL : ${currentUrl}\n\nCordialement,\nLogistique LPC`;
        }

        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        function sendWhatsApp() {
            const phone = document.getElementById('input_wa_phone').value.replace(/[^0-9]/g, '');
            if(!phone) return LPC.modal.alert("Numéro invalide.");
            window.open(`https://wa.me/${phone}?text=${encodeURIComponent(document.getElementById('input_wa_body').value)}`, '_blank');
            closeModal('whatsappModal');
        }

        function sendEmail() {
            const to = document.getElementById('input_email_to').value;
            window.location.href = `mailto:${to}?subject=${encodeURIComponent(document.getElementById('input_email_subject').value)}&body=${encodeURIComponent(document.getElementById('input_email_body').value)}`;
            closeModal('emailModal');
        }

        // 5. LANGUAGE
        function setLang(lang) {
            currentLang = lang;
            if(lang === 'en') {
                document.getElementById('btn-lang-en').className = "px-4 py-1.5 text-sm font-bold rounded-md bg-white shadow-sm text-lpc-dark";
                document.getElementById('btn-lang-fr').className = "px-4 py-1.5 text-sm font-bold rounded-md text-gray-500 hover:text-gray-900 bg-transparent";
            } else {
                document.getElementById('btn-lang-fr').className = "px-4 py-1.5 text-sm font-bold rounded-md bg-white shadow-sm text-lpc-dark";
                document.getElementById('btn-lang-en').className = "px-4 py-1.5 text-sm font-bold rounded-md text-gray-500 hover:text-gray-900 bg-transparent";
            }

            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (dictionary[lang][key]) el.innerHTML = dictionary[lang][key]; 
            });
        }

        async function generatePDF() {
            if(!apiData) return;
            const btn = document.getElementById('btn-download');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = LPC.html`<i class="fas fa-spinner fa-spin"></i> Impression...`;
            
            const element = document.getElementById('pdf-container');
            const wrapper = document.getElementById('document-capture');
            
            // Suspend mobile scaling and force desktop dimensions
            element.classList.add('force-a4-width');
            wrapper.style.zoom = '1'; 

            try {
                const canvas = await html2canvas(element, {
                    scale: 2, 
                    useCORS: true,
                    logging: false,
                    backgroundColor: '#FFFFFF',
                    windowWidth: 794 // Forces html2canvas to ignore mobile screen sizes
                });

                // Restore mobile view instantly
                element.classList.remove('force-a4-width');
                wrapper.style.zoom = '';

                const imgData = canvas.toDataURL('image/jpeg', 1.0);
                const pdf = new jspdf.jsPDF('p', 'mm', 'a4');
                const pdfWidth = pdf.internal.pageSize.getWidth();
                const pdfHeight = (canvas.height * pdfWidth) / canvas.width;
                
                pdf.addImage(imgData, 'JPEG', 0, 0, pdfWidth, pdfHeight);
                pdf.save(`LPC_BL_${apiData.delivery.reference}.pdf`);

            } catch (error) {
                element.classList.remove('force-a4-width');
                wrapper.style.zoom = '';
                LPC.modal.alert("Erreur lors de la génération du PDF.");
            } finally {
                btn.innerHTML = originalHTML;
            }
        }
        
        const signUrl = window.location.origin + '/sign_bl.php?token=' + blToken;
        
        function openDirectSignature() {
            window.location.href = signUrl;
        }

        // Generate QR Code dynamically when modal opens
        function openModal(id) { 
            document.getElementById(id).classList.add('active'); 
            if(id === 'qrModal') {
                const qrContainer = document.getElementById('qrcode-container');
                qrContainer.innerHTML = ''; // Clear previous
                new QRCode(qrContainer, {
                    text: signUrl,
                    width: 200,
                    height: 200,
                    colorDark : "#000000",
                    colorLight : "#ffffff",
                    correctLevel : QRCode.CorrectLevel.H
                });
            }
        }
    