/**
 * assets/js/modules/documents-bon_commande.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from public/documents/bon_commande.php (Sprint 6 D2).
 *
 * Original block was ~11,986 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        // 1. GLOBAL STATE
        let apiData = null;
        let currentLang = 'fr'; 
        const currentUrl = window.location.href; 

        // 2. BILINGUAL DICTIONARY
        const dictionary = {
            en: {
                btn_pdf: "Download PDF", btn_email: "Email", btn_whatsapp: "WhatsApp",
                doc_title: "Purchase Order", doc_subtitle: "Inventory Procurement",
                lbl_po_num: "PO No:", lbl_date: "Date:", lbl_supplier: "Supplier / Vendor", lbl_delivery: "Ship To",
                tbl_desc: "Item Description", tbl_qty: "Qty", tbl_pu: "Unit Price", tbl_total: "Amount",
                txt_note: "Note: Please include this PO number on all invoices and delivery notes.",
                lbl_pay_terms: "Payment Terms", lbl_subtotal: "Subtotal", lbl_discount: "Discount", lbl_grandtotal: "TOTAL AMOUNT (FCFA)",
                sig_creator: "Prepared By:", sig_auth: "Authorized By (Management):"
            },
            fr: {
                btn_pdf: "Télécharger PDF", btn_email: "Email", btn_whatsapp: "WhatsApp",
                doc_title: "Bon de Commande", doc_subtitle: "Approvisionnement Stock",
                lbl_po_num: "N° Cde :", lbl_date: "Date :", lbl_supplier: "Fournisseur / Supplier", lbl_delivery: "Lieu de Livraison / Ship To",
                tbl_desc: "Désignation", tbl_qty: "Qté", tbl_pu: "Prix Unitaire", tbl_total: "Montant",
                txt_note: "Note : Veuillez inclure la référence de ce bon de commande sur toutes vos factures et bons de livraison.",
                lbl_pay_terms: "Condition de Paiement", lbl_subtotal: "Sous-Total", lbl_discount: "Remise", lbl_grandtotal: "NET À PAYER (FCFA)",
                sig_creator: "Établi par :", sig_auth: "Visa Direction / Finance :"
            }
        };

        // 3. DATA FETCHING
        const urlParams = new URLSearchParams(window.location.search);
        const poToken = urlParams.get('token');

        async function fetchPOData() {
            if (!poToken) {
                document.getElementById('document-capture').innerHTML = LPC.html`<div class="p-10 text-center text-red-600 font-bold text-xl mt-20">Lien invalide ou manquant. / Invalid link.</div>`;
                return;
            }

            try {
                // Fetch live data from API
                const response = await fetch(`/api/v1/get_po.php?token=${poToken}`);
                const result = await response.json();

                if (result.status === 'success') {
                    apiData = result.data; 
                    injectDataIntoDOM(); 
                    setLang('fr'); // Default to FR for suppliers
                    updateModals(); 
                } else {
                    document.getElementById('document-capture').innerHTML = LPC.html`<div class="p-10 text-center text-red-600 font-bold text-xl mt-20">Erreur: ${result.message}</div>`;
                }
            } catch (error) {
                document.getElementById('document-capture').innerHTML = LPC.html`<div class="p-10 text-center text-red-600 font-bold text-xl mt-20">Erreur de connexion au serveur.</div>`;
            }
        }

        // 4. INJECT DATA INTO HTML
        function formatCurrency(amount) {
            return LPC.fmt.int(amount);
        }

        function injectDataIntoDOM() {
            if(!apiData) return;
            
            const po = apiData.po;
            const sup = apiData.supplier;
            const creator = apiData.creator;
            
            const formattedDate = new Date(po.date).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });

            // Headers
            document.getElementById('nav-ref').innerText = po.reference;
            document.getElementById('dyn_ref').innerText = po.reference;
            document.getElementById('dyn_date').innerText = formattedDate;

            // Supplier Info
            document.getElementById('dyn_sup_name').innerText = sup.name;
            document.getElementById('dyn_sup_address').innerText = sup.address || 'Adresse non spécifiée';
            document.getElementById('dyn_sup_phone').innerText = sup.phone || 'N/A';
            document.getElementById('dyn_sup_email').innerText = sup.email || 'N/A';

            // Payment Term
            document.getElementById('dyn_payment').innerText = po.payment_status === 'paid' ? 'Payé (Cash/Virement)' : 'À Crédit (Non Payé)';
            if(po.payment_status === 'paid') document.getElementById('dyn_payment').classList.add('text-green-700', 'bg-green-50', 'border-green-200');

            // Signatures — Sprint 11: the signature area is now rendered
            // server-side by includes/components/signature_block.php (see
            // docs/SIGNATURES.md), so #dyn_creator_name no longer exists.
            // Guarded rather than deleted so a browser holding a cached copy
            // of the OLD bon_commande.php still gets it filled; unguarded,
            // the null would throw and abort the items table below.
            const creatorEl = document.getElementById('dyn_creator_name');
            if (creatorEl) creatorEl.innerText = creator.name;

            // Table Injection
            const tbody = document.getElementById('dyn_items_table');
            tbody.innerHTML = '';
            apiData.items.forEach(item => {
                // item.format is supplier-supplied text, so it is escaped by the
                // inner LPC.html first; LPC.raw then marks the finished snippet
                // safe for the outer template. Wrapping the bare string in
                // LPC.raw instead would fix the display bug by opening an
                // injection hole — the inner escape is what makes it safe.
                const formatHtml = item.format ? LPC.raw(LPC.html`<span class="text-xs text-gray-500 ml-1">(${item.format})</span>`) : '';
                tbody.innerHTML += LPC.html`
                    <tr>
                        <td class="py-4 px-2">
                            <div class="font-bold text-gray-900">${item.desc} ${formatHtml}</div>
                        </td>
                        <td class="py-4 px-2 text-center font-bold text-gray-700">${item.qty}</td>
                        <td class="py-4 px-2 text-right text-gray-600">${formatCurrency(item.unit_price)}</td>
                        <td class="py-4 px-2 text-right font-black text-gray-900">${formatCurrency(item.total_price)}</td>
                    </tr>
                `;
            });

            // Financials
            document.getElementById('dyn_subtotal').innerText = formatCurrency(po.subtotal);
            
            if(parseFloat(po.discount_amount) > 0) {
                document.getElementById('discount_row').style.display = 'flex';
                document.getElementById('dyn_discount').innerText = '-' + formatCurrency(po.discount_amount);
                if(po.discount_note) document.getElementById('dyn_discount_note').innerText = `(${po.discount_note})`;
            }

            document.getElementById('dyn_grandtotal').innerText = formatCurrency(po.total_amount);
        }

        // 5. MODAL LOGIC & SHARING
        function updateModals() {
            if(!apiData) return;
            const sup = apiData.supplier;
            const po = apiData.po;

            // WhatsApp
            let phone = sup.phone ? sup.phone.replace(/[^0-9]/g, '') : '';
            if(phone && !phone.startsWith('237') && phone.length <= 9) phone = '237' + phone; 
            document.getElementById('input_wa_phone').value = phone;
            
            const waText = `Bonjour,\n\nVeuillez trouver ci-joint notre Bon de Commande (${po.reference}).\n\nLien sécurisé : ${currentUrl}\n\nMerci de confirmer la réception et préparer la livraison.\n\nCordialement,\nLa Petite Cour (LPC)`;
            document.getElementById('input_wa_body').value = waText;

            // Email
            document.getElementById('input_email_to').value = sup.email || '';
            document.getElementById('input_email_subject').value = `Bon de Commande LPC : ${po.reference}`;
            
            const emailText = `Bonjour,\n\nVeuillez trouver via le lien sécurisé ci-dessous notre Bon de Commande officiel.\n\n📄 Consulter / Télécharger le BC : ${currentUrl}\n\nMerci de bien vouloir traiter cette commande et nous confirmer les délais de livraison.\n\nCordialement,\n\nDépartement des Achats\nLa Petite Cour`;
            document.getElementById('input_email_body').value = emailText;
        }

        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        function sendWhatsApp() {
            const phone = document.getElementById('input_wa_phone').value.replace(/[^0-9]/g, '');
            if(!phone) { LPC.modal.alert("Numéro de téléphone invalide."); return; }
            const text = encodeURIComponent(document.getElementById('input_wa_body').value);
            window.open(`https://wa.me/${phone}?text=${text}`, '_blank');
            closeModal('whatsappModal');
        }

        function sendEmail() {
            const to = document.getElementById('input_email_to').value;
            const sub = encodeURIComponent(document.getElementById('input_email_subject').value);
            const body = encodeURIComponent(document.getElementById('input_email_body').value);
            window.location.href = `mailto:${to}?subject=${sub}&body=${body}`;
            closeModal('emailModal');
        }

        // 6. LANGUAGE SWITCHER
        function setLang(lang) {
            currentLang = lang;
            
            // Toggle Button Styles
            if(lang === 'en') {
                document.getElementById('btn-lang-en').className = "px-4 py-1.5 text-sm font-bold rounded-md bg-white shadow-sm text-lpc-dark transition-all";
                document.getElementById('btn-lang-fr').className = "px-4 py-1.5 text-sm font-bold rounded-md text-gray-500 hover:text-gray-900 transition-all bg-transparent shadow-none";
            } else {
                document.getElementById('btn-lang-fr').className = "px-4 py-1.5 text-sm font-bold rounded-md bg-white shadow-sm text-lpc-dark transition-all";
                document.getElementById('btn-lang-en').className = "px-4 py-1.5 text-sm font-bold rounded-md text-gray-500 hover:text-gray-900 transition-all bg-transparent shadow-none";
            }

            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (dictionary[lang][key]) el.innerHTML = dictionary[lang][key]; 
            });
        }

        // 7. SINGLE-PAGE PDF GENERATOR
        async function generatePDF() {
            if(!apiData) return;
            
            const btn = document.getElementById('btn-download');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = LPC.html`<i class="fas fa-spinner fa-spin"></i> Traitement...`;
            btn.classList.add('opacity-75', 'cursor-not-allowed');

            const element = document.querySelector('.a4-page');
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
                pdf.save(`LPC_BC_${apiData.po.reference}.pdf`);

            } catch (error) {
                element.classList.remove('force-a4-width');
                wrapper.style.zoom = '';
                console.error("PDF Generation failed:", error);
                LPC.modal.alert("Erreur lors de la génération du PDF.");
            } finally {
                btn.innerHTML = originalHTML;
                btn.classList.remove('opacity-75', 'cursor-not-allowed');
            }
        }

        // ---------------------------------------------------------------------
        // AUTODOWNLOAD — a client landing here from the "Télécharger le
        // document signé" button on sign_document.php's success screen
        // (?autodownload=1) skips the extra tap and gets the PDF right away.
        // Fires once; if generatePDF() throws, its own catch already shows
        // the LPC.modal.alert fallback and the client can retry from the
        // download button in this same tab.
        // ---------------------------------------------------------------------
        let __autodownloadFired = false;
        async function maybeAutodownload() {
            if (__autodownloadFired) return;
            if (new URLSearchParams(window.location.search).get('autodownload') !== '1') return;
            __autodownloadFired = true;
            if (document.fonts && document.fonts.ready) {
                await document.fonts.ready;
            }
            generatePDF();
        }

        // Init
        window.onload = async function () {
            await fetchPOData();
            maybeAutodownload();
        };
