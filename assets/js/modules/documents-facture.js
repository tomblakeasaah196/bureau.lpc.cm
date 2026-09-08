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
                doc_title: "Invoice", lbl_inv_num: "Invoice No:", lbl_date: "Issue Date:",
                lbl_client: "Billed To", lbl_notes: "Notes & Terms", lbl_payment_info: "Payment Information",
                lbl_client_niu: "TIN:", lbl_client_rccm: "Trade Reg.:",
                lbl_terms: "Delivery Notes (BL)", lbl_withholding: "Withholding at source",
                tbl_desc: "Description", tbl_qty: "Qty", tbl_up: "Unit Price", tbl_total: "Amount",
                tot_sub: "Subtotal", tot_excise: "Excise duty", tot_grand: "TOTAL DUE",
                tot_precompte: "Withholding on purchases", tot_air: "AIR — Income tax instalment",
                tot_net_transfer: "Net transferable to supplier",
                tot_paid: "Amount Paid", tot_balance: "Balance Due",
                legal_text: "This invoice is closed at the sum of:"
            },
            fr: {
                btn_pdf: "Télécharger PDF", btn_email: "Email", btn_whatsapp: "WhatsApp",
                doc_title: "Facture", lbl_inv_num: "N° Facture :", lbl_date: "Date :",
                lbl_client: "Facturé à", lbl_notes: "Notes / Conditions", lbl_payment_info: "Informations de Paiement",
                lbl_client_niu: "NIU :", lbl_client_rccm: "RCCM :",
                lbl_terms: "Bordereaux de Livraison", lbl_withholding: "Retenues à la source",
                tbl_desc: "Désignation", tbl_qty: "Qté", tbl_up: "P.U. (FCFA)", tbl_total: "Montant (FCFA)",
                tot_sub: "Total Hors Taxe (HT)", tot_excise: "Droit d'accises", tot_grand: "NET À PAYER (TTC)",
                tot_precompte: "Précompte sur achats", tot_air: "AIR — Acompte d'Impôt sur le Revenu",
                tot_net_transfer: "Net à virer au fournisseur",
                tot_paid: "Déjà Réglé", tot_balance: "Reste à Payer",
                legal_text: "Arrêtée la présente facture à la somme de :"
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

        // Small helpers — `hidden` is the Tailwind display:none utility.
        const $ = (id) => document.getElementById(id);
        const show = (id, on) => { const e = $(id); if (e) e.classList.toggle('hidden', !on); };
        const setText = (id, v) => { const e = $(id); if (e) e.innerText = v; };

        /**
         * Print "A · B · C" on ONE line when it fits, and on TWO deliberate
         * lines when it does not.
         *
         * Left to wrap by itself, a joined string strands the separator at the
         * fold. The letterhead came out as
         *
         *     1030, Avenue Douala Manga Bell · B.P. 5120 ·
         *     Douala Littoral · Cameroun
         *
         *     Tél. +237 696 291 800 / +237 233 42 02 81 · info@lpc.cm ·
         *     https://lpc.cm
         *
         * — a "·" dangling at the end of both lines, which reads as a typo, and
         * a URL orphaned under a very long phone line. Breaking at a separator
         * ourselves CONSUMES it, so no line can begin or end on one.
         *
         * Fill the first line as far as it goes; then, rather than leave a
         * single segment stranded below, move one segment down when the first
         * line can spare it and the second still fits. On this profile that
         * gives street / city the way a reader expects an address to break, and
         * puts the two digital contacts together under the phones.
         *
         * Falls back to the plain joined line where there is no layout to
         * measure — the id-contract test runs injectData() against a stub DOM.
         */
        function setSegmentedLine(id, segments) {
            const el = $(id);
            if (!el) return;
            const parts = segments.map(s => String(s == null ? '' : s).trim()).filter(Boolean);
            el.innerText = parts.join(' · ');
            if (parts.length < 2) return;
            if (typeof getComputedStyle !== 'function' || typeof el.getBoundingClientRect !== 'function') return;

            const lh = parseFloat(getComputedStyle(el).lineHeight) || 0;
            const fitsOneLine = () => el.getBoundingClientRect().height < lh * 1.5;
            if (!lh || fitsOneLine()) return;      // it did not wrap — leave it alone

            // Largest prefix that still occupies a single line.
            let split = 1;
            for (let i = parts.length - 1; i >= 1; i--) {
                el.innerText = parts.slice(0, i).join(' · ');
                if (fitsOneLine()) { split = i; break; }
            }
            // A lone segment below reads as an orphan; give it company when the
            // first line can spare one and the second line still fits.
            if (parts.length - split === 1 && split > 1) {
                el.innerText = parts.slice(split - 1).join(' · ');
                if (fitsOneLine()) split -= 1;
            }
            // Assigning innerText turns the newline into a real line break.
            el.innerText = parts.slice(0, split).join(' · ') + '\n' + parts.slice(split).join(' · ');
        }

        // 3. INJECT DATA INTO DOM
        function injectData() {
            if(!apiData) return;
            const inv = apiData.invoice;
            const client = apiData.client;
            const stamp = apiData.stamp;
            const co = apiData.company || {};

            // Dates. inv.due_date is deliberately NOT printed any more — the
            // Échéance and Devise rows were dropped from the document. The field
            // still arrives in the payload and still drives the overdue status
            // and the reminders, so nothing downstream changed.
            const fDate = new Date(inv.date).toLocaleDateString('fr-FR');

            document.getElementById('nav-ref').innerText = inv.reference;
            document.getElementById('dyn_ref').innerText = inv.reference;
            document.getElementById('dyn_date').innerText = fDate;

            // -- Seller letterhead. Formerly hardcoded, placeholder NIU included.
            setText('dyn_co_name', co.name || '');
            // address_lines already arrives structured; the contact line arrives
            // pre-joined from CompanyProfile::contactLine(), so it is split back
            // into its segments to give the same treatment.
            setSegmentedLine('dyn_co_address', co.address_lines || []);
            setSegmentedLine('dyn_co_contact', String(co.contact || '').split('·'));

            // Client. A dash, not the literal "N/A": this is a document a
            // customer reads, and every one of these fields is optional in the
            // CRM. get_invoice.php now resolves the NIU across both columns the
            // clients table carries (niu, then the legacy tax_id), so a number
            // saved through any version of the client form reaches the invoice.
            const orDash = v => (v === null || v === undefined || String(v).trim() === '') ? '—' : String(v).trim();
            document.getElementById('dyn_client_name').innerText = client.name;
            document.getElementById('dyn_client_address').innerText = orDash(client.address);
            document.getElementById('dyn_client_phone').innerText = orDash(client.phone);
            document.getElementById('dyn_client_email').innerText = orDash(client.email);
            setText('dyn_client_niu', orDash(client.niu));
            setText('dyn_client_rccm', orDash(client.rccm));

            // The status badge that used to be painted here (PAYÉE / PARTIEL /
            // NON PAYÉE) was removed from the document. inv.status is untouched
            // and still drives every internal view and the reminders.

            // Items
            const tbody = document.getElementById('dyn_items_table');
            tbody.innerHTML = '';
            apiData.items.forEach(item => {
                tbody.innerHTML += LPC.html`
                    <tr>
                        <td class="py-2.5 px-2 font-bold text-gray-900">${item.description}</td>
                        <td class="py-2.5 px-2 text-center font-black text-gray-700">${item.quantity}</td>
                        <td class="py-2.5 px-2 text-right text-gray-800">${LPC.fmt.int(item.unit_price)}</td>
                        <td class="py-2.5 px-2 text-right font-black text-gray-900">${LPC.fmt.int(item.total_price)}</td>
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

            // The exoneration basis ("… art. 128 CGI") used to print under a nil
            // TVA. Removed from the document on request; inv.tva_exemption still
            // arrives in the payload for whoever wants it back.

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
            // RCCM · NIU · capital social · régime fiscal used to sit under the
            // logo (#dyn_co_legal); they print here now. company_profile.footer
            // repeats some of them — on this profile the RCCM number appears in
            // both, once bare and once behind its "RCCM" label — so the two
            // sources are merged segment by segment and a segment whose digits
            // are already on the line is dropped rather than printed twice.
            const seen = [];
            // Two spellings of one identifier are still one identifier. On this
            // profile the structured rccm_number reads "CM-DLA-03-2026-B-01777"
            // while the hand-typed document_footer_fr carries the same number as
            // "CM-DLA-03-2026-8-01777" — a typed 8 for a B — so comparing the
            // characters exactly saw two different segments and printed the RCCM
            // twice. Fold the glyph pairs that get confused when a registration
            // number is transcribed by hand (O/0, I/L/1, B/8, S/5, Z/2) before
            // comparing. Only the COMPARISON is folded: what prints is the first
            // spelling seen, and the structured field is always read first, so
            // the authoritative value is the one that survives.
            const norm = s => s.toUpperCase().replace(/[^A-Z0-9]/g, '')
                .replace(/O/g, '0').replace(/[IL]/g, '1')
                .replace(/B/g, '8').replace(/S/g, '5').replace(/Z/g, '2');
            for (const chunk of [co.name, co.legal_mentions, co.fiscal_regime_label, co.footer]) {
                for (const raw of String(chunk || '').split('·')) {
                    const part = raw.trim();
                    if (!part) continue;
                    const k = norm(part);
                    // Short segments are compared exactly; anything long enough to
                    // be an identifier also matches when one form contains the
                    // other ("CM-DLA-03-2026-B-01777" inside "RCCM CM-DLA-…").
                    const dup = seen.some(s => k.length >= 6 || s.k.length >= 6
                        ? (s.k.includes(k) || k.includes(s.k))
                        : s.k === k);
                    if (!dup) seen.push({ k, part });
                }
            }
            setText('dyn_legal_footer', seen.map(s => s.part).join(' · '));

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

            // The old "Certifié Conforme" issuer stamp lived here; its DOM
            // nodes (#dyn_creator / #dyn_role / #dyn_stamp_date / #dyn_hash)
            // were removed with the stamp itself. apiData.stamp is still
            // sent by get_invoice.php — kept in case another surface (audit
            // log, admin view) wants it — but the customer-facing document
            // relies on the shared signature_block for LPC attestation now.
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
        }

        // 6. PDF GENERATOR
        // -------------------------------------------------------------------------
        // dompdf primary path DISABLED — see the dated note below. Client-facing
        // download is html2canvas + jsPDF WYSIWYG only for now, the same engine
        // every other document type (devis, BC, BL, CRE) already uses. Nothing
        // was deleted: downloadServerPDF() is still defined a few lines down,
        // just not called. To bring dompdf back as the primary path, uncomment
        // the `await downloadServerPDF(filename);` line (and the try/catch
        // around it) inside generatePDF() below and remove the direct
        // downloadCanvasPDF() call that currently replaces it.
        //
        // Original (2607) rationale for making dompdf primary, kept for
        // context — the specific bugs it cites are believed fixed (see
        // downloadCanvasPDF()'s document.fonts.ready + rAF wait and its
        // multi-page slicing loop), but re-verify against a real multi-page
        // invoice before relying on canvas-only in production again:
        //
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
        // ---------------------------------------------------------------------
        // AUTODOWNLOAD — a client landing here from the "Télécharger le
        // document signé" button on sign_document.php's success screen
        // (?autodownload=1) skips the extra tap and gets the PDF right away.
        // Fires once; generatePDF() now goes straight to the html2canvas path
        // (see the dompdf-disabled note above) and still has its own
        // LPC.modal.alert if that fails, so the client can retry from the
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
            if (!apiData) return;
            const btn = document.getElementById('btn-download');
            const originalHTML = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = LPC.html`<i class="fas fa-spinner fa-spin"></i> Impression...`;

            const filename = `LPC_Facture_${apiData.invoice.reference}.pdf`;

            try {
                // dompdf primary path — commented out, not deleted. Restore by
                // uncommenting this line and the catch/fallback below it, then
                // removing the direct downloadCanvasPDF() call that follows.
                // await downloadServerPDF(filename);
                await downloadCanvasPDF(filename);
            } catch (canvasError) {
                console.error('[facture] échec de la capture html2canvas :', canvasError);
                LPC.modal.alert(
                    "Impossible de générer le PDF. Vérifiez votre connexion, puis réessayez."
                );
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
        }

        /**
         * dompdf path — vector text, paginated, ~40 KB. DORMANT: generatePDF()
         * no longer calls this (see the notes above and inside generatePDF()).
         * Left in place, untouched and callable, so re-enabling dompdf is a
         * one-line change rather than rewriting this function from scratch.
         */
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

        // How far the capture may be scaled down to keep an invoice on a single
        // sheet. 0.80 keeps the smallest type on the document (8 px statutory
        // footer, 10 px labels) at roughly 6.4 px / 4.8 pt — still legible in
        // print. Below that the document paginates instead of shrinking.
        const ONE_PAGE_MIN_SCALE = 0.80;

        /**
         * Fallback path — the original html2canvas capture. One A4 page for a
         * normal invoice, scaled to fit for a slightly longer one, paginated
         * only when the content genuinely cannot be read at one page.
         */
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

                if (imgH <= pageH + 1) {
                    // The normal invoice. The template is budgeted so that four
                    // line items still land inside 297 mm at full size.
                    pdf.addImage(imgData, 'JPEG', 0, 0, pageW, imgH);
                } else if (pageH / imgH >= ONE_PAGE_MIN_SCALE) {
                    // A longer invoice that only just overflows: shrink it onto
                    // ONE sheet rather than sending a second, near-empty page —
                    // a customer should never receive a 4-line invoice split in
                    // two. The floor below stops this from producing unreadable
                    // 5 pt type on a genuinely long document.
                    const fit = pageH / imgH;
                    pdf.addImage(imgData, 'JPEG', (pageW - pageW * fit) / 2, 0, pageW * fit, pageH);
                } else {
                    // Genuinely long: slide the same image up by one page height
                    // per sheet, so an invoice with 30 lines no longer loses
                    // everything below 297 mm.
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
    