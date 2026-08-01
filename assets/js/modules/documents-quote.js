/**
 * assets/js/modules/documents-quote.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from public/documents/quote.php (Sprint 6 D2).
 *
 * Original block was ~26,153 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        // 1. GLOBAL STATE
        let apiData = null;
        let currentLang = 'fr'; // Default
        // The public link to the quote. Built from the token alone rather than
        // window.location.href: this page can be reached with &html=1 (legacy
        // debug param) or other transient query state, and none of that belongs
        // in a link pasted into a client's WhatsApp or inbox.
        const currentUrl = (function () {
            const token = new URLSearchParams(window.location.search).get('token') || '';
            const base  = window.location.origin + window.location.pathname;
            return token ? base + '?token=' + encodeURIComponent(token) : base;
        })();

        // 2. BILINGUAL CONTENT — supplied by the server, editable in
        //    modules/crm/proposal_studio.php (table proposal_template_settings).
        //
        //    This used to be a ~130-line hardcoded object. Every wording change
        //    meant a commit and a deploy, and the same strings also existed in
        //    the PHP template, so the two drifted. Both now read the one source.
        //
        //    If the JSON block is missing (very old cached HTML), we degrade to
        //    an empty dictionary: the server-rendered French text already in the
        //    DOM stays put and only the EN toggle stops working. Never blank.
        const dictionary = (function () {
            var el = document.getElementById('lpc-proposal-template');
            if (!el) return { fr: {}, en: {} };
            try {
                var parsed = JSON.parse(el.textContent);
                return { fr: parsed.fr || {}, en: parsed.en || {} };
            } catch (e) {
                console.error('proposal template payload unreadable', e);
                return { fr: {}, en: {} };
            }
        })();

        // SLA Translation Mapper
        const slaTranslations = {
            'Hebdomadaire': { fr: 'Hebdomadaire', en: 'Weekly' },
            'Bi-Mensuel': { fr: 'Bi-Mensuel', en: 'Bi-Weekly' },
            'Mensuel': { fr: 'Mensuel', en: 'Monthly' },
            'Sur Demande': { fr: 'Sur Demande', en: 'On-Demand' },
            'Paiement à la Livraison': { fr: 'Paiement à la Livraison', en: 'Payment on Delivery' },
            'Net 15 Jours': { fr: 'Net 15 Jours', en: 'Payment: Net 15 Days' },
            'Net 30 Jours': { fr: 'Net 30 Jours', en: 'Payment: Net 30 Days' },
            'Échange 1-pour-1 (100%)': { fr: 'Échange 1-pour-1 (100%)', en: 'Bottle Exchange: 1-for-1 Swap (100%)' },
            'Retour Minimum Exigé (90%)': { fr: 'Retour Minimum Exigé (90%)', en: 'Minimum Return Required (90%)' },
            'Retour Minimum Exigé (80%)': { fr: 'Retour Minimum Exigé (80%)', en: 'Minimum Return Required (80%)' },
            'Retour Minimum Exigé (70%)': { fr: 'Retour Minimum Exigé (70%)', en: 'Minimum Return Required (70%)' },
            'Consigne Payée (Facturée)': { fr: 'Consigne Payée (Facturée)', en: 'Paid Bottle Deposit (Invoiced)' }
        };

        // 3. DATA FETCHING
        const urlParams = new URLSearchParams(window.location.search);
        const proposalToken = urlParams.get('token');

        async function fetchProposalData() {
            if (!proposalToken) {
                LPC.modal.alert("Lien de devis invalide ou manquant. / Invalid or missing proposal link.");
                return;
            }

            try {
                // Fetch live data from the API
                const response = await fetch(`/api/v1/get_proposal.php?token=${proposalToken}`);
                const result = await response.json();

                if (result.status === 'success') {
                    apiData = result.data; // Store globally
                    
                    injectDataIntoDOM(); 
                    setLang(apiData.proposal.language || 'fr'); // Auto-translate based on DB pref
                    updateModals(); // Fill WA/Email inputs
                    
                } else {
                    document.getElementById('document-capture').innerHTML = LPC.html`<div class="p-10 text-center text-red-600 font-bold text-xl">Erreur: ${result.message}</div>`;
                }
            } catch (error) {
                console.error("Fetch failed:", error);
                document.getElementById('document-capture').innerHTML = LPC.html`<div class="p-10 text-center text-red-600 font-bold text-xl">Erreur de connexion au serveur.</div>`;
            }
        }

        // 4. INJECT DATA INTO HTML
        function formatCurrency(amount) {
            return LPC.fmt.int(amount);
        }

        function injectDataIntoDOM() {
            if(!apiData) return;
            
            const p = apiData.proposal;
            const c = apiData.client;
            const u = apiData.user;
            
            const formattedDate = new Date(p.date).toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' });

            // Top Nav & Headers
            document.getElementById('nav-ref').innerText = p.reference;
            document.getElementById('head_ref_2').innerText = p.reference;
            document.getElementById('head_ref_3').innerText = p.reference;
            document.getElementById('head_ref_4').innerText = p.reference;

            // Cover Page
            document.getElementById('cov_client_name').innerText = c.company_name;
            document.getElementById('cov_client_contact').innerText = c.contact_name || 'Direction / Achats';
            document.getElementById('cov_client_email').innerText = c.email || c.phone || '';
            document.getElementById('cov_date').innerText = formattedDate;
            document.getElementById('cov_ref').innerText = p.reference;
            document.getElementById('cov_sales_rep').innerText = u.name;

            // SLA Details (With Dynamic Translation)
            const lang = p.language || 'fr';
            document.getElementById('dyn_delivery_frequency').innerText = slaTranslations[p.delivery_frequency] ? slaTranslations[p.delivery_frequency][lang] : p.delivery_frequency;
            document.getElementById('dyn_payment_terms').innerText = slaTranslations[p.payment_terms] ? slaTranslations[p.payment_terms][lang] : p.payment_terms;
            document.getElementById('dyn_empties_policy').innerText = slaTranslations[p.empties_policy] ? slaTranslations[p.empties_policy][lang] : p.empties_policy;
            
            document.getElementById('dyn_buffer_stock_weeks').innerText = p.buffer_stock_weeks;
            document.getElementById('dyn_validity_days').innerText = p.validity_days;

            // Signatures — Sprint 11: the signature area is now rendered
            // server-side by includes/components/signature_block.php (see
            // docs/SIGNATURES.md), because deciding whether a signature is
            // still valid means hashing the document on the server. These
            // four elements no longer exist on the page.
            //
            // The assignments are kept, guarded, rather than deleted: if a
            // cached copy of the OLD quote.php is served to a browser while
            // this newer JS runs, the elements ARE there and should still be
            // filled. Unguarded, getElementById returning null would throw
            // and abort everything below — including the line-items table.
            const setIfPresent = function (id, value) {
                const el = document.getElementById(id);
                if (el) el.innerText = value;
            };
            setIfPresent('sig_sales_rep', u.name);
            setIfPresent('sig_date_lpc', formattedDate);
            setIfPresent('sig_client_name', c.company_name);
            setIfPresent('sig_client_title', c.contact_name || 'Direction Générale');

            // Table Injection
            const tbody = document.getElementById('dyn_items_table');
            tbody.innerHTML = '';
            apiData.items.forEach(item => {
                // Escaped by the inner LPC.html, then marked raw for the outer
                // template. See documents-bon_commande.js for the reasoning.
                const formatHtml = item.format ? LPC.raw(LPC.html`<div class="text-xs text-gray-500 mt-1">${item.format}</div>`) : '';
                // pack_note (migration 047) — "pack de 12 bouteilles". Without
                // this, a packed product's unit_price reads as a per-bottle
                // price with nothing correcting that impression; see the
                // migration file for the full reasoning.
                const packHtml = item.pack_note
                    ? LPC.raw(LPC.html`<div class="text-xs font-bold text-indigo-600 mt-1">${item.pack_note}</div>`)
                    : '';
                tbody.innerHTML += LPC.html`
                    <tr>
                        <td class="py-4 px-6 border-b border-gray-100">
                            <div class="font-bold text-gray-900">${item.desc}</div>
                            ${formatHtml}
                            ${packHtml}
                        </td>
                        <td class="py-4 px-6 border-b border-gray-100 text-center font-medium">${item.qty}</td>
                        <td class="py-4 px-6 border-b border-gray-100 text-right text-gray-700">${formatCurrency(item.unit_price)}</td>
                        <td class="py-4 px-6 border-b border-gray-100 text-right font-bold text-gray-900">${formatCurrency(item.total_price)}</td>
                    </tr>
                `;
            });
            document.getElementById('dyn_total_amount').innerText = formatCurrency(p.total_amount);
        }

        // ---- Template helpers ---------------------------------------------
        /** One template string in the language currently displayed. */
        function t(key) {
            const d = dictionary[currentLang] || dictionary.fr || {};
            return d[key] || (dictionary.fr && dictionary.fr[key]) || '';
        }

        /**
         * Substitute {contact} {reference} {link} {sender} in a share message.
         * Unknown placeholders are left alone rather than blanked, so a typo in
         * the Studio is visible to whoever made it instead of quietly deleting
         * half a sentence in front of a client.
         */
        function fillTemplate(tpl, vars) {
            return Object.keys(vars).reduce(function (acc, k) {
                return acc.split('{' + k + '}').join(vars[k] == null ? '' : vars[k]);
            }, tpl || '');
        }

        // 5. MODAL LOGIC & SHARING
        function updateModals() {
            if(!apiData) return;
            const c = apiData.client;
            const p = apiData.proposal;
            const u = apiData.user;

            // WhatsApp
            // Clean phone number (remove spaces, ensure international format)
            let phone = c.phone ? c.phone.replace(/[^0-9]/g, '') : '';
            if(phone && !phone.startsWith('237')) phone = '237' + phone; 
            document.getElementById('input_wa_phone').value = phone;
            
            document.getElementById('input_wa_body').value =
                fillTemplate(t('share_wa_body'), {
                    contact:   c.contact_name || '',
                    reference: p.reference,
                    link:      currentUrl,
                    sender:    u.name
                });

            // Email
            document.getElementById('input_email_to').value = c.email || '';
            document.getElementById('input_email_subject').value =
                fillTemplate(t('share_email_subject'), {
                    contact:   c.company_name || c.contact_name || '',
                    reference: p.reference,
                    link:      currentUrl,
                    sender:    u.name
                });
            
document.getElementById('input_email_body').value =
                fillTemplate(t('share_email_body'), {
                    contact:   c.contact_name || 'Madame, Monsieur',
                    reference: p.reference,
                    link:      currentUrl,
                    sender:    u.name
                });
        }

        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        function sendWhatsApp() {
            const phone = document.getElementById('input_wa_phone').value.replace(/[^0-9]/g, '');
            if(!phone) { LPC.modal.alert("Veuillez entrer un numéro de téléphone."); return; }
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

            // Translate all static elements
            // textContent, never innerHTML: these strings are editable from the
            // Proposal Studio, which makes them stored user input. README §6.4.
            // A missing key leaves the server-rendered text in place rather
            // than blanking the element.
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                const val = dictionary[lang] && dictionary[lang][key];
                if (val) { el.textContent = val; }
            });
        }

        // 7. PDF GENERATOR — the WYSIWYG capture of the 4-page proposal
        // ---------------------------------------------------------------------
        // TWO BUTTONS, TWO DELIBERATELY DIFFERENT DOCUMENTS. Do not merge them.
        //
        //   #btn-download (this)  html2canvas + jsPDF over the four .a4-page
        //                         blocks. Pixel-identical to what the prospect
        //                         is looking at: the arcs, the gradients, the
        //                         reference logos, whichever language they
        //                         toggled to. Raster, so the text in it is not
        //                         selectable.
        //   #btn-offer            a plain link to ?pdf=1 — dompdf's one-page
        //                         offre commerciale, vector text, selectable
        //                         and searchable. See
        //                         lpc_render_quote_pdf_html().
        //
        // An earlier pass pointed THIS button at ?pdf=1 as its primary path and
        // demoted the capture to a fallback, on the grounds that `pdftotext`
        // extracted four characters from DEV-2607-841B because all four pages
        // were 1588×2246 JPEGs. The diagnosis was right and the remedy was
        // wrong: it deleted the WYSIWYG deliverable instead of adding the
        // machine-readable one, and left both buttons serving the same file.
        //
        // Selectable text is a real requirement and it belongs to the one-pager,
        // which is what a buyer pastes into a budget sheet. Looking exactly like
        // the page you are reading is also a real requirement, and it belongs
        // here. Neither substitutes for the other.
        // ---------------------------------------------------------------------
        async function generatePDF() {
            if(!apiData) { LPC.modal.alert("Attendez le chargement des données / Wait for data to load"); return; }

            const btn = document.getElementById('btn-download');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = LPC.html`<i class="fas fa-spinner fa-spin"></i> Traitement...`;
            btn.classList.add('opacity-75', 'cursor-not-allowed');

            try {
                // Wait for webfonts before painting; capturing mid-load is what
                // blanked blocks in the invoice capture.
                if (document.fonts && document.fonts.ready) {
                    await document.fonts.ready;
                }

                // Initialize PDF (A4 Portrait)
                const pdf = new jspdf.jsPDF('p', 'mm', 'a4');
                const pdfWidth = pdf.internal.pageSize.getWidth();
                const pdfHeight = pdf.internal.pageSize.getHeight();

                // Select all the A4 page div blocks
                const pages = document.querySelectorAll('.a4-page');
                if (!pages.length) throw new Error('aucun bloc .a4-page à capturer');

                // Loop through each DOM page, capture it, and add it to the PDF
                for (let i = 0; i < pages.length; i++) {
                    const canvas = await html2canvas(pages[i], {
                        scale: 2, // 2x resolution for crisp text
                        useCORS: true,
                        logging: false,
                        backgroundColor: '#FFFFFF'
                    });

                    const imgData = canvas.toDataURL('image/jpeg', 1.0);

                    if (i > 0) {
                        pdf.addPage(); // Add a new blank page for page 2, 3, etc.
                    }

                    // Add the captured image to the current PDF page
                    pdf.addImage(imgData, 'JPEG', 0, 0, pdfWidth, pdfHeight);
                }

                // Download the final assembled PDF
                pdf.save(`LPC_Devis_${apiData.proposal.reference}.pdf`);

            } catch (error) {
                // The capture runs entirely in the browser, so a failure here is
                // memory or a tainted canvas — not the network. Point at the
                // one-pager, which is served by PHP and unaffected.
                console.error('[devis] échec de la capture html2canvas :', error);
                LPC.modal.alert(
                    "Erreur lors de la génération du PDF. Essayez sur un ordinateur, "
                    + "ou utilisez « Offre commerciale » pour la version d'une page.\n\n"
                    + "Error generating PDF. Try on a desktop, or use “Commercial Offer” "
                    + "for the one-page version."
                );
            } finally {
                // Restore button state
                btn.innerHTML = originalHTML;
                btn.classList.remove('opacity-75', 'cursor-not-allowed');
            }
        }

        // Init App
        window.onload = fetchProposalData;
    