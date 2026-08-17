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
        // Client-facing signing URL. Built early so updateModals() (called from
        // window.onload) can reference it — the WhatsApp/Email share templates
        // must point recipients at the interactive sign_bl.php portal, NOT at
        // this read-only document view. Redeclaring the same value later would
        // shadow-throw under `const`, so the historical assignment below is
        // gone; the QR modal reads this same constant.
        const signUrl = window.location.origin + '/sign_bl.php?token=' + (new URLSearchParams(window.location.search)).get('token');

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
                    if (document.fonts && document.fonts.ready) {
                        await document.fonts.ready;
                    }
                    maybeAutodownload();
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
            //
            // Sprint 12 (empties-visibility fix) — render the new "Vides
            // Rendus" column. The column is populated ONLY for products
            // that carry a consigne (item.has_consigne); a pure full-bottle
            // line prints a dash so it is unambiguous that no empty was
            // returned. delivered_qty comes from the API as either a
            // number (customer signed and confirmed) or null (unsigned;
            // renderer prints a dotted line to be filled in by hand).
            const tbody = document.getElementById('dyn_items_table');
            tbody.innerHTML = '';
            apiData.items.forEach(item => {
                // Escaped by the inner LPC.html, then marked raw for the outer
                // template. See documents-bon_commande.js for the reasoning.
                const formatHtml = item.format ? LPC.raw(LPC.html`<span class="text-xs text-gray-500 ml-1">(${item.format})</span>`) : '';

                // Prefer the signed delivered_qty when available; otherwise
                // fall back to the shipped qty (only when the customer has
                // completed the signature — pre-signature we print a dotted
                // fill-in line so a paper delivery still has a place to
                // write the number).
                const receivedNumber = (typeof item.delivered_qty === 'number')
                    ? item.delivered_qty
                    : item.qty;
                const receivedHtml = isCompleted
                    ? LPC.raw(LPC.html`<span class="font-black text-lg text-blue-700">${receivedNumber}</span>`)
                    : LPC.raw(`<div class="w-16 h-8 border-b-2 border-dotted border-gray-400 mx-auto"></div>`);

                // Empties column. Only render a number for lines with a
                // consigne; for a non-consigné product (Jus 30L, etc.) a
                // dash keeps the row visually consistent without inviting
                // the reader to think the field was "left blank".
                let emptiesHtml;
                if (!item.has_consigne) {
                    emptiesHtml = LPC.raw(`<span class="text-gray-300 text-sm">—</span>`);
                } else if (isCompleted) {
                    const n = Number(item.returned_empty_qty || 0);
                    // A non-zero return releases the client from a consigne,
                    // so it is printed BOLD amber to draw the eye during any
                    // dispute review. Zero returns are printed in a muted
                    // amber so the reader still sees the field was consciously
                    // recorded as "no empties handed back".
                    emptiesHtml = n > 0
                        ? LPC.raw(LPC.html`<span class="font-black text-lg text-amber-700">${n}</span>`)
                        : LPC.raw(`<span class="font-bold text-sm text-amber-400">0</span>`);
                } else {
                    // Pre-signature: dotted fill-in, matching the Qté Reçue
                    // column's visual language.
                    emptiesHtml = LPC.raw(`<div class="w-16 h-8 border-b-2 border-dotted border-amber-400 mx-auto"></div>`);
                }

                tbody.innerHTML += LPC.html`
                    <tr>
                        <td class="py-4 px-2">
                            <div class="font-bold text-gray-900">${item.name} ${formatHtml}</div>
                        </td>
                        <td class="py-4 px-2 text-center font-black text-lg text-gray-800">${item.qty}</td>
                        <td class="py-4 px-2 text-center border-l border-gray-100">
                            ${receivedHtml}
                        </td>
                        <td class="py-4 px-2 text-center border-l border-gray-100 bg-amber-50/30">
                            ${emptiesHtml}
                        </td>
                    </tr>
                `;
            });

            // Dynamic Signatures Area — Sprint 11: this block is now rendered
            // server-side by includes/components/signature_block.php, which
            // reads document_signatures rather than the retired
            // deliveries.signature_image / .driver_signature_image columns
            // this code was built against. See docs/SIGNATURES.md.
            //
            // The builder below is kept, guarded, rather than deleted: a
            // browser holding a cached copy of the OLD bon_livraison.php still
            // has #dynamic-signatures-area, and should still get something in
            // it. When the element is absent (the normal case now) everything
            // here is skipped — unguarded, the sigArea.innerHTML assignment at
            // the end would throw and abort the rest of the render.
            const sigArea = document.getElementById('dynamic-signatures-area');
            if (!sigArea) return;

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
            
            // Shared links must land recipients on sign_bl.php, not on this
            // read-only document. The doc view's "Faire Signer" dropdown is a
            // staff-side control; a client opening it has no way to actually
            // sign. See docs/SIGNATURES.md and bon_livraison.php nav gating.
            const waText = `Bonjour,\n\nVotre commande (Réf: ${bl.sales_order_ref}) est arrivée.\n\nMerci de vérifier les quantités et de signer la livraison ici :\n${signUrl}\n\nLPC Logistique`;
            document.getElementById('input_wa_body').value = waText;

            document.getElementById('input_email_to').value = client.email || '';
            document.getElementById('input_email_subject').value = `Signature Bon de Livraison LPC : ${bl.sales_order_ref}`;
            document.getElementById('input_email_body').value = `Bonjour,\n\nVotre commande a été livrée.\nVeuillez vérifier les quantités reçues et signer votre Bon de Livraison via le lien sécurisé ci-dessous :\n\n✍️  Signer le BL : ${signUrl}\n\nCordialement,\nLogistique LPC`;
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

        // ---------------------------------------------------------------------
        // AUTODOWNLOAD — a client landing here from the "Télécharger le
        // document signé" button on sign_bl.php's success screen
        // (?autodownload=1) skips the extra tap and gets the PDF right away.
        // Fires once; if generatePDF() throws, its own catch already shows
        // the LPC.modal.alert fallback and the client can retry from the
        // download button in this same tab.
        // ---------------------------------------------------------------------
        let __autodownloadFired = false;
        function maybeAutodownload() {
            if (__autodownloadFired) return;
            if (new URLSearchParams(window.location.search).get('autodownload') !== '1') return;
            __autodownloadFired = true;
            generatePDF();
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
        
        // signUrl is declared once at the top of the module (see block above);
        // this is just the click handler both the toolbar button and the
        // recipient-mode "Signer" button call into.
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
    