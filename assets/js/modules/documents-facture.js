/**
 * assets/js/modules/documents-facture.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from public/documents/facture.php (Sprint 6 D2).
 *
 * Original block was ~11,466 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        // 1. STATE & DICTIONARY
        let apiData = null;
        let currentLang = 'fr'; 
        const currentUrl = window.location.href; 

        const dictionary = {
            en: {
                btn_pdf: "Download PDF", btn_email: "Email", btn_whatsapp: "WhatsApp",
                doc_title: "Invoice", lbl_inv_num: "Invoice No:", lbl_date: "Issue Date:", lbl_due_date: "Due Date:",
                lbl_client: "Billed To", lbl_notes: "Notes & Terms", lbl_payment_info: "Payment Information",
                tbl_desc: "Description", tbl_qty: "Qty", tbl_up: "Unit Price", tbl_total: "Amount",
                tot_sub: "Subtotal", tot_grand: "TOTAL DUE", tot_paid: "Amount Paid", tot_balance: "Balance Due",
                stamp_title: "Certified True Copy", legal_text: "This invoice is closed at the sum of:",
                status_paid: "PAID", status_partial: "PARTIAL", status_unpaid: "UNPAID"
            },
            fr: {
                btn_pdf: "Télécharger PDF", btn_email: "Email", btn_whatsapp: "WhatsApp",
                doc_title: "Facture", lbl_inv_num: "N° Facture :", lbl_date: "Date :", lbl_due_date: "Échéance :",
                lbl_client: "Facturé à", lbl_notes: "Notes / Conditions", lbl_payment_info: "Informations de Paiement",
                tbl_desc: "Désignation", tbl_qty: "Qté", tbl_up: "P.U. (FCFA)", tbl_total: "Montant (FCFA)",
                tot_sub: "Total Hors Taxe (HT)", tot_grand: "NET À PAYER (TTC)", tot_paid: "Déjà Réglé (Avances)", tot_balance: "Reste à Payer",
                stamp_title: "Certifié Conforme", legal_text: "Arrêtée la présente facture à la somme de :",
                status_paid: "PAYÉE", status_partial: "PARTIEL", status_unpaid: "NON PAYÉE"
            }
        };

        // Standard JS Number to Words (French simplified for FCFA)
        function numberToWordsFR(num) {
            // A full robust implementation would go here. For brevity in this snippet, returning a placeholder/simple string.
            // In a real app, you might pass this string directly from the PHP backend which has robust NumberFormatter classes.
            return num + " Francs CFA"; 
        }

        // 2. BOOTSTRAP
        const urlParams = new URLSearchParams(window.location.search);
        const invToken = urlParams.get('token');

        window.onload = async () => {
            if (!invToken) {
                document.getElementById('document-capture').innerHTML = LPC.html`<div class="p-10 text-center text-red-600 font-bold text-xl mt-20">Lien de facture invalide.</div>`;
                return;
            }
            try {
                // Fetch from Backend (To be built next)
                const response = await fetch(`/api/v1/get_invoice.php?token=${invToken}`);
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

        // 3. INJECT DATA INTO DOM
        function injectData() {
            if(!apiData) return;
            const inv = apiData.invoice;
            const client = apiData.client;
            const stamp = apiData.stamp;
            
            // Dates
            const fDate = new Date(inv.date).toLocaleDateString('fr-FR');
            const fDue = new Date(inv.due_date).toLocaleDateString('fr-FR');

            document.getElementById('nav-ref').innerText = inv.reference;
            document.getElementById('dyn_ref').innerText = inv.reference;
            document.getElementById('dyn_date').innerText = fDate;
            document.getElementById('dyn_due_date').innerText = fDue;

            // Client
            document.getElementById('dyn_client_name').innerText = client.name;
            document.getElementById('dyn_client_address').innerText = client.address || 'N/A';
            document.getElementById('dyn_client_phone').innerText = client.phone || 'N/A';
            document.getElementById('dyn_client_email').innerText = client.email || 'N/A';

            // Status Badge
            const badge = document.getElementById('dyn_status_badge');
            badge.className = `mt-2 inline-block px-3 py-1 rounded text-xs font-black uppercase tracking-widest status-${inv.status}`;
            badge.setAttribute('data-status-key', `status_${inv.status}`);

            // Items
            const tbody = document.getElementById('dyn_items_table');
            tbody.innerHTML = '';
            apiData.items.forEach(item => {
                tbody.innerHTML += LPC.html`
                    <tr>
                        <td class="py-4 px-2 font-bold text-gray-900">${item.description}</td>
                        <td class="py-4 px-2 text-center font-black text-gray-700">${item.quantity}</td>
                        <td class="py-4 px-2 text-right text-gray-800">${LPC.fmt.int(item.unit_price)}</td>
                        <td class="py-4 px-2 text-right font-black text-gray-900">${LPC.fmt.int(item.total_price)}</td>
                    </tr>
                `;
            });

            // Math Totals
            document.getElementById('dyn_subtotal').innerText = LPC.fmt.fcfa(inv.subtotal);
            document.getElementById('dyn_tva_rate').innerText = inv.tva_rate;
            document.getElementById('dyn_tva_amount').innerText = LPC.fmt.fcfa(inv.tva_amount);
            document.getElementById('dyn_grandtotal').innerText = LPC.fmt.fcfa(inv.total_amount);
            
            document.getElementById('dyn_paid_amount').innerText = LPC.fmt.fcfa(inv.paid_amount);
            document.getElementById('dyn_balance').innerText = LPC.fmt.fcfa(inv.balance);

            // Amount in words (Expect backend to provide this for perfection, else fallback to JS)
            document.getElementById('dyn_amount_words').innerText = inv.amount_in_words || numberToWordsFR(inv.total_amount);

            // Notes
            if(inv.notes) {
                document.getElementById('notes_container').classList.remove('hidden');
                document.getElementById('dyn_notes').innerText = inv.notes;
            }

            // Stamp
            document.getElementById('dyn_creator').innerText = stamp.created_by;
            document.getElementById('dyn_role').innerText = stamp.role;
            document.getElementById('dyn_stamp_date').innerText = stamp.timestamp;
            document.getElementById('dyn_hash').innerText = stamp.hash;
        }

        // 4. MODALS & COMMS
        function updateModals() {
            const client = apiData.client;
            const inv = apiData.invoice;

            let phone = client.phone ? client.phone.replace(/[^0-9]/g, '') : '';
            if(phone && !phone.startsWith('237') && phone.length <= 9) phone = '237' + phone; 
            document.getElementById('input_wa_phone').value = phone;
            
            const waText = `Bonjour,\n\nVeuillez trouver ci-joint votre facture (Réf: ${inv.reference}) d'un montant de ${LPC.fmt.int(inv.total_amount)} FCFA.\n\nLien sécurisé : ${currentUrl}\n\nMerci de votre confiance.\nFinance LPC`;
            document.getElementById('input_wa_body').value = waText;

            document.getElementById('input_email_to').value = client.email || '';
            document.getElementById('input_email_subject').value = `Facture LPC : ${inv.reference}`;
            document.getElementById('input_email_body').value = waText;
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

        // 5. LANGUAGE SUPPORT
        function setLang(lang) {
            currentLang = lang;
            const isEn = lang === 'en';
            
            document.getElementById('btn-lang-en').className = isEn ? "px-4 py-1.5 text-sm font-bold rounded-md bg-white shadow-sm text-gray-900" : "px-4 py-1.5 text-sm font-bold rounded-md text-gray-500 hover:text-gray-900 bg-transparent";
            document.getElementById('btn-lang-fr').className = isEn ? "px-4 py-1.5 text-sm font-bold rounded-md text-gray-500 hover:text-gray-900 bg-transparent" : "px-4 py-1.5 text-sm font-bold rounded-md bg-white shadow-sm text-gray-900";

            // Static Text
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (dictionary[lang][key]) el.innerHTML = dictionary[lang][key]; 
            });

            // Dynamic Badge Status Translation
            const badge = document.getElementById('dyn_status_badge');
            const statusKey = badge.getAttribute('data-status-key');
            if(statusKey && dictionary[lang][statusKey]) {
                badge.innerText = dictionary[lang][statusKey];
            }
        }

        // 6. PDF GENERATOR
        async function generatePDF() {
            if(!apiData) return;
            const btn = document.getElementById('btn-download');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = LPC.html`<i class="fas fa-spinner fa-spin"></i> Impression...`;
            
            const element = document.getElementById('pdf-container');
            element.classList.add('force-a4-width'); // Force sizing

            try {
                const canvas = await html2canvas(element, { scale: 2, useCORS: true, backgroundColor: '#FFFFFF' });
                element.classList.remove('force-a4-width');

                const imgData = canvas.toDataURL('image/jpeg', 1.0);
                const pdf = new jspdf.jsPDF('p', 'mm', 'a4');
                const pdfWidth = pdf.internal.pageSize.getWidth();
                const pdfHeight = (canvas.height * pdfWidth) / canvas.width;
                
                pdf.addImage(imgData, 'JPEG', 0, 0, pdfWidth, pdfHeight);
                pdf.save(`LPC_Facture_${apiData.invoice.reference}.pdf`);
            } catch (error) {
                element.classList.remove('force-a4-width');
                LPC.modal.alert("Erreur lors de la génération du PDF.");
            } finally {
                btn.innerHTML = originalHTML;
            }
        }
    