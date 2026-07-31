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
                lbl_currency: "Currency:",
                lbl_client: "Billed To", lbl_notes: "Notes & Terms", lbl_payment_info: "Payment Information",
                lbl_client_niu: "TIN:", lbl_client_rccm: "Trade Reg.:",
                warn_no_niu: "Customer TIN missing — required for B2B deductibility",
                lbl_terms: "Delivery Notes (BL)", lbl_withholding: "Withholding at source",
                pay_reference_hint: "Please quote the invoice number as the payment reference.",
                tbl_desc: "Description", tbl_qty: "Qty", tbl_up: "Unit Price", tbl_total: "Amount",
                tot_sub: "Subtotal", tot_excise: "Excise duty", tot_grand: "TOTAL DUE",
                tot_precompte: "Withholding on purchases", tot_air: "AIR — Income tax instalment",
                tot_net_transfer: "Net transferable to supplier",
                tot_paid: "Amount Paid", tot_balance: "Balance Due",
                stamp_title: "Certified True Copy", legal_text: "This invoice is closed at the sum of:",
                status_paid: "PAID", status_partial: "PARTIAL", status_unpaid: "UNPAID"
            },
            fr: {
                btn_pdf: "Télécharger PDF", btn_email: "Email", btn_whatsapp: "WhatsApp",
                doc_title: "Facture", lbl_inv_num: "N° Facture :", lbl_date: "Date :", lbl_due_date: "Échéance :",
                lbl_currency: "Devise :",
                lbl_client: "Facturé à", lbl_notes: "Notes / Conditions", lbl_payment_info: "Informations de Paiement",
                lbl_client_niu: "NIU :", lbl_client_rccm: "RCCM :",
                warn_no_niu: "NIU client manquant — requis pour la déductibilité B2B",
                lbl_terms: "Bordereaux de Livraison", lbl_withholding: "Retenues à la source",
                pay_reference_hint: "Merci de préciser le N° de facture en motif du règlement.",
                tbl_desc: "Désignation", tbl_qty: "Qté", tbl_up: "P.U. (FCFA)", tbl_total: "Montant (FCFA)",
                tot_sub: "Total Hors Taxe (HT)", tot_excise: "Droit d'accises", tot_grand: "NET À PAYER (TTC)",
                tot_precompte: "Précompte sur achats", tot_air: "AIR — Acompte d'Impôt sur le Revenu",
                tot_net_transfer: "Net à virer au fournisseur",
                tot_paid: "Déjà Réglé (Avances)", tot_balance: "Reste à Payer",
                stamp_title: "Certifié Conforme", legal_text: "Arrêtée la présente facture à la somme de :",
                status_paid: "PAYÉE", status_partial: "PARTIEL", status_unpaid: "NON PAYÉE"
            }
        };

        // Fallback only. The backend spells the amount with intl's
        // NumberFormatter (see getAmountInWords in api/v1/get_invoice.php) and
        // sends it as invoice.amount_in_words; this runs only if that field is
        // ever missing, and prints the figure rather than a half-spelled string.
        function numberToWordsFR(num) {
            return new Intl.NumberFormat('fr-FR').format(Math.round(num)) + " francs CFA";
        }

        /**
         * Rates arrive as percentages. Print "19,25" and "5", not "19.25" and
         * "5.0000" — a French invoice uses a comma and no trailing zeros.
         */
        function trimRate(rate) {
            const n = Number(rate) || 0;
            return n.toLocaleString('fr-FR', { maximumFractionDigits: 2 });
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

        // Small helpers — `hidden` is the Tailwind display:none utility.
        const $ = (id) => document.getElementById(id);
        const show = (id, on) => { const e = $(id); if (e) e.classList.toggle('hidden', !on); };
        const setText = (id, v) => { const e = $(id); if (e) e.innerText = v; };

        // 3. INJECT DATA INTO DOM
        function injectData() {
            if(!apiData) return;
            const inv = apiData.invoice;
            const client = apiData.client;
            const stamp = apiData.stamp;
            const co = apiData.company || {};

            // Dates
            const fDate = new Date(inv.date).toLocaleDateString('fr-FR');
            const fDue = new Date(inv.due_date).toLocaleDateString('fr-FR');

            document.getElementById('nav-ref').innerText = inv.reference;
            document.getElementById('dyn_ref').innerText = inv.reference;
            document.getElementById('dyn_date').innerText = fDate;
            document.getElementById('dyn_due_date').innerText = fDue;

            // -- Seller letterhead. Formerly hardcoded, placeholder NIU included.
            setText('dyn_co_name', co.name || '');
            setText('dyn_co_address', (co.address_lines || []).join(' · '));
            setText('dyn_co_contact', co.contact || '');
            // RCCM · NIU · Capital · Centre des impôts — the statutory identifier
            // line. Built server-side by CompanyProfile::legalMentions() so the
            // PDF and the HTML can never disagree about it.
            const mentions = [co.legal_mentions, co.fiscal_regime_label]
                .filter(Boolean).join(' · ');
            setText('dyn_co_legal', mentions);

            // Client
            document.getElementById('dyn_client_name').innerText = client.name;
            document.getElementById('dyn_client_address').innerText = client.address || 'N/A';
            document.getElementById('dyn_client_phone').innerText = client.phone || 'N/A';
            document.getElementById('dyn_client_email').innerText = client.email || 'N/A';
            setText('dyn_client_niu', client.niu || '—');
            setText('dyn_client_rccm', client.rccm || '—');
            // Surface the gap rather than print a silent dash: a B2B invoice
            // without the buyer's NIU is not deductible for them.
            show('dyn_client_niu_warning', client.is_b2b && !client.niu);

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

            // -- Fiscal ladder ------------------------------------------------
            // HT → (+ accises) → TVA on that base → TTC, then the withholdings
            // that split the TTC between us and the DGI. Every optional row is
            // hidden when its amount is zero, so a plain exonerated water
            // invoice still reads as a clean four-line block.
            setText('dyn_subtotal', LPC.fmt.fcfa(inv.subtotal));

            const hasExcise = Number(inv.excise_amount) > 0;
            show('row_excise', hasExcise);
            if (hasExcise) {
                setText('dyn_excise_rate', trimRate(inv.excise_rate));
                setText('dyn_excise_amount', LPC.fmt.fcfa(inv.excise_amount));
            }

            setText('dyn_tva_rate', trimRate(inv.tva_rate));
            setText('dyn_tva_amount', LPC.fmt.fcfa(inv.tva_amount));

            // Print the legal basis whenever TVA is nil. "TVA (0%) — 0 FCFA"
            // on its own tells an auditor nothing.
            const exempt = Number(inv.tva_rate) <= 0 && inv.tva_exemption;
            show('row_tva_exemption', !!exempt);
            if (exempt) setText('dyn_tva_exemption', inv.tva_exemption);

            setText('dyn_grandtotal', LPC.fmt.fcfa(inv.total_amount));

            const hasPrecompte = Number(inv.precompte_amount) > 0;
            const hasAir       = Number(inv.air_amount) > 0;
            show('withholding_block', hasPrecompte || hasAir);
            show('row_precompte', hasPrecompte);
            show('row_air', hasAir);
            if (hasPrecompte) {
                setText('dyn_precompte_rate', trimRate(inv.precompte_rate));
                setText('dyn_precompte_amount', '- ' + LPC.fmt.fcfa(inv.precompte_amount));
            }
            if (hasAir) {
                setText('dyn_air_rate', trimRate(inv.air_rate));
                setText('dyn_air_amount', '- ' + LPC.fmt.fcfa(inv.air_amount));
            }
            setText('dyn_net_payable', LPC.fmt.fcfa(inv.net_payable));

            setText('dyn_paid_amount', LPC.fmt.fcfa(inv.paid_amount));
            setText('dyn_balance', LPC.fmt.fcfa(inv.balance));

            // -- Payment accounts ---------------------------------------------
            // One card per account the client may settle into — typically a
            // bank for the transfer and a MoMo number for a small balance.
            // These come from the invoice's frozen snapshot, not from live
            // settings, so a reprint shows what the client was actually told.
            const bankBlock = document.getElementById('dyn_bank_block');
            if (bankBlock) {
                const blocks = Array.isArray(co.payment_blocks) ? co.payment_blocks : [];
                const rendered = blocks
                    .filter(b => b && b.lines && Object.keys(b.lines).length)
                    .map(b => LPC.html`
                        <div class="pb-2 mb-2 border-b border-gray-200 last:border-0 last:pb-0 last:mb-0">
                            <p class="text-[10px] font-black text-gray-900 uppercase tracking-widest mb-1">${b.title}</p>
                            ${LPC.raw(Object.entries(b.lines).map(([label, value]) =>
                                LPC.html`<p><strong class="text-gray-900">${label}:</strong> ${value}</p>`).join(''))}
                        </div>`)
                    .join('');
                bankBlock.innerHTML = rendered ||
                    LPC.html`<p class="text-gray-400 italic">Coordonnées de règlement non renseignées.</p>`;
            }

            // -- Statutory footer ---------------------------------------------
            setText('dyn_legal_footer', co.footer || '');

            // Amount in words (Expect backend to provide this for perfection, else fallback to JS)
            document.getElementById('dyn_amount_words').innerText = inv.amount_in_words || numberToWordsFR(inv.total_amount);

            // Notes
            if(inv.notes) {
                document.getElementById('notes_container').classList.remove('hidden');
                document.getElementById('dyn_notes').innerText = inv.notes;
            }

            // BL references. Replaces the old boilerplate payment-terms
            // paragraph with the actual delivery notes this invoice was
            // raised from, so the client can reconcile it against their own
            // receiving stock — "BL-2607-045 (120), BL-2607-046 (85)".
            // Hidden entirely for invoices not generated from a BL batch.
            const deliveries = Array.isArray(apiData.deliveries) ? apiData.deliveries : [];
            if (deliveries.length) {
                const list = deliveries
                    .map(d => `${d.reference} (${LPC.fmt.int(d.total_qty)})`)
                    .join(', ');
                document.getElementById('terms_text').innerText = list;
                document.getElementById('bl_refs_container').classList.remove('hidden');
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
        // -------------------------------------------------------------------------
        // Server-side dompdf is the source of truth; html2canvas is the lifeboat.
        //
        // The old implementation was html2canvas-only, and it did not survive
        // contact with a real invoice. It rasterises whatever the browser has
        // painted at that instant into one tall JPEG and stretches it across a
        // single A4 page:
        //
        //   · The N° Facture / Date / Échéance box came out EMPTY on
        //     FAC-2607-6341 — html2canvas had begun the capture before those
        //     nodes had settled, and the screenshot kept the empty state.
        //   · pdfHeight is derived from the canvas aspect ratio and the image is
        //     placed at y=0 with no paging, so anything past 297 mm is simply
        //     cut off. A second page was impossible.
        //   · The output is a 567 KB bitmap: no selectable text, no copyable
        //     amounts, and unusable for a client's OCR/bookkeeping import.
        //
        // ?pdf=1 renders the same document through dompdf into real vector text
        // (~40 KB, searchable, paginated). We ask for that first and fall back
        // to the capture only if the server route fails outright — an offline
        // client on a Douala mobile connection still gets a file.
        // -------------------------------------------------------------------------
        async function generatePDF() {
            if (!apiData) return;
            const btn = document.getElementById('btn-download');
            const originalHTML = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = LPC.html`<i class="fas fa-spinner fa-spin"></i> Impression...`;

            const filename = `LPC_Facture_${apiData.invoice.reference}.pdf`;

            try {
                await downloadServerPDF(filename);
            } catch (serverError) {
                console.warn('[facture] dompdf indisponible, repli sur la capture client :', serverError);
                try {
                    await downloadCanvasPDF(filename);
                    LPC.toast(
                        "PDF généré en mode dégradé (image). Le texte ne sera pas sélectionnable.",
                        'warning'
                    );
                } catch (canvasError) {
                    console.error('[facture] échec des deux moteurs PDF :', canvasError);
                    LPC.modal.alert(
                        "Impossible de générer le PDF. Vérifiez votre connexion, puis réessayez."
                    );
                }
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
        }

        /** Primary path — dompdf via ?pdf=1. Vector text, paginated, ~40 KB. */
        async function downloadServerPDF(filename) {
            const url = `?token=${encodeURIComponent(invToken)}&pdf=1`;
            const response = await fetch(url, {
                headers: { 'Accept': 'application/pdf' },
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const blob = await response.blob();
            // A PHP fatal or an HTML error page still arrives with 200 on some
            // hosts. Trust the payload, not the status line.
            if (blob.type && blob.type.indexOf('pdf') === -1) {
                throw new Error(`type inattendu : ${blob.type}`);
            }
            if (blob.size < 1000) {
                throw new Error(`réponse trop courte : ${blob.size} octets`);
            }
            triggerDownload(blob, filename);
        }

        /** Fallback path — the original html2canvas capture, now multi-page. */
        async function downloadCanvasPDF(filename) {
            const element = document.getElementById('pdf-container');
            element.classList.add('force-a4-width');

            try {
                // Wait for webfonts before painting. Capturing mid-load is what
                // produced the blank invoice-number box in the old version.
                if (document.fonts && document.fonts.ready) {
                    await document.fonts.ready;
                }
                await new Promise(requestAnimationFrame);

                const canvas = await html2canvas(element, {
                    scale: 2,
                    useCORS: true,
                    backgroundColor: '#FFFFFF',
                    windowWidth: 794
                });

                const pdf = new jspdf.jsPDF('p', 'mm', 'a4');
                const pageW = pdf.internal.pageSize.getWidth();
                const pageH = pdf.internal.pageSize.getHeight();
                const imgH  = (canvas.height * pageW) / canvas.width;
                const imgData = canvas.toDataURL('image/jpeg', 0.92);

                // Slide the same image up by one page height per sheet, so an
                // invoice with 30 lines no longer loses everything below 297 mm.
                let remaining = imgH;
                let offset = 0;
                pdf.addImage(imgData, 'JPEG', 0, 0, pageW, imgH);
                remaining -= pageH;
                while (remaining > 1) {
                    offset += pageH;
                    pdf.addPage();
                    pdf.addImage(imgData, 'JPEG', 0, -offset, pageW, imgH);
                    remaining -= pageH;
                }

                pdf.save(filename);
            } finally {
                element.classList.remove('force-a4-width');
            }
        }

        function triggerDownload(blob, filename) {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            // Revoke on the next tick — Safari aborts the download if the object
            // URL disappears synchronously after the click.
            setTimeout(() => URL.revokeObjectURL(url), 4000);
        }
    