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
        const currentUrl = window.location.href; // The public link to the quote

        // 2. BILINGUAL DICTIONARY (Functions used for dynamic text)
        const dictionary = {
            en: {
                btn_pdf: "Generate PDF", btn_email: "Email", btn_whatsapp: "WhatsApp",
                cover_title: "Commercial Proposal", cover_subtitle: "Corporate Hydration Solutions",
                cover_prepared_for: "Prepared For:", cover_prepared_by: "Prepared By",
                meta_ref: "Reference", meta_date: "Date", meta_validity: "Validity", meta_days: "Days from issue date",
                header_p2: "Profile & Context", header_p3: "Commercial Offer", header_p4: "Terms & Signatures",
                
                sec1_title: "1. Executive Summary",
                sec1_p1: "The health, well-being, and productivity of your workforce depend on premium hydration. LA PETITE COUR is pleased to submit this formal proposal to become your preferred partner for natural mineral water supply.",
                sec1_p2: "Our approach rests on three pillars: impeccable water quality (ANOR certified), state-of-the-art logistics ensuring zero stock-outs, and dedicated corporate customer service.",
                
                sec2_title: "2. About La Petite Cour",
                sec2_intro: "LA PETITE COUR specializes in the supply and distribution of premium drinking water, designed specifically for professional environments. We have designed a flexible and scalable offer, adapted to the reality of offices, coworking spaces, workshops, shops, and public establishments.",
                sec2_services: "Our services include:",
                sec2_l1: "Installation, maintenance, and monitoring of distribution equipment",
                sec2_l2: "Personalized and responsive customer service",
                sec2_l3: "Eco-responsible solutions promoting recycling and reducing the environmental footprint",
                sec2_l4: "Inventory management and volume adaptation based on your workforce evolution ",
                sec2_body: "Driven by a mission to redefine B2B distribution in Cameroon, LA PETITE COUR is an authorized corporate distributor for Sources du Pays, exclusively supplying the Supermont and Opur brands to professional environments.",
                
                sec2_gamme_title: "Our Products & Equipment",
                sec2_gamme_1: "Reusable bottles (10L, 20L) for water dispensers.",
                sec2_gamme_2: "Individual bottles (33cl, 50cl, 1L, 1.5L).",
                sec2_gamme_3: "Refrigerated, ambient, and contactless water dispensers.",
                sec2_gamme_4: "Accessories (recyclable cups, stands, maintenance kits).",

                sec2_av_title: "Corporate Advantages",
                sec2_av_1_title: "Health & Well-being",
                sec2_av_1_desc: "Proper hydration improves focus and workplace efficiency.",
                sec2_av_2_title: "Brand Image",
                sec2_av_2_desc: "A well-equipped workspace enhances your employer brand.",

                sec2_b1_title: "Multimodal Logistics", sec2_b1_desc: "Anti-UV trucks and tricycles for rapid delivery, even in dense urban zones.",
                sec2_b2_title: "Buffer Stock", sec2_b2_desc: "Dedicated storage capacity to mitigate potential factory shortages.",
                sec2_b3_title: "Dedicated Support", sec2_b3_desc: "Single point of contact, regular audits, and technical maintenance of equipment.",
                sec2_clients: "They Trust Us",
                
                sec3_title: "3. Offer Details & Pricing",
                tbl_desc: "Product Description", tbl_qty: "Est. Monthly Qty", tbl_price: "Unit Price", tbl_total: "Total Price",
                tbl_grand: "Estimated Monthly Total (FCFA):",
                tbl_note: "* Note: Actual billing will be based on signed delivery notes (BL). Prices are in CFA Francs.",
                
                sec4_title: "4. Service Level Agreement (SLA)",
                sla_freq: "Delivery Frequency", sla_buffer: "Allocated Buffer Stock", sla_weeks: "Weeks",
                sla_pay: "Payment Terms", sla_empties: "Empties Management", sla_validity: "Offer Validity",
                
                sec5_title: "5. General Terms of Service",
                tc_1_title: "Article 1: Eco-Responsible Packaging.", tc_1_body: "As part of our environmental commitment, 10L and 20L bottles are reusable and provided for your convenience. To ensure a smooth rotation, we apply a 1-for-1 exchange principle. We rely on our collaborative partnership for the proper care of these containers.",
                tc_2_title: "Article 2: Equipment Maintenance.", tc_2_body: "To guarantee impeccable hygiene, bottles must contain only water. In the event of loss or significant damage to a container, replacement fees at the current rate may be applied to help us maintain a high-quality fleet.",
                tc_3_title: "Article 3: Invoicing and Flexibility.", tc_3_body: "Invoicing is based on delivery notes (BL) validated by your team. We grant standard payment terms (as detailed in the SLA) to facilitate your accounting. Regular, joint monitoring helps avoid delays that could impact service fluidity.",
                tc_4_title: "Article 4: Transparency and Scalability.", tc_4_body: "Our pricing policy is designed to be transparent and competitive. Rates may benefit from a sliding scale based on your evolving volumes and the duration of our collaboration. There are absolutely no hidden fees.",
                tc_5_title: "Article 5: Setup and Service Continuity.", tc_5_body: "Our logistics team adapts to your schedule to ensure deliveries do not disrupt your operations. Our technicians handle installation, regular dispenser maintenance, and guarantee maximum responsiveness to prevent any stock-outs.",
                tc_6_title: "Article 6: Satisfaction Commitment.", tc_6_body: "Your satisfaction is at the heart of our approach. A dedicated advisor is assigned to analyze your needs, adjust volumes, and intervene rapidly for any specific requests. We guarantee the prompt replacement of any defective equipment.",
                
                sig_lpc: "For La Petite Cour:", sig_role_lpc: "Sales Department", sig_prep: "Prepared By:", 
                sig_client: "Agreed & Accepted (The Client):", sig_date_client: "Date & Stamp:"
            },
            fr: {
                btn_pdf: "Générer PDF", btn_email: "Email", btn_whatsapp: "WhatsApp",
                cover_title: "Proposition Commerciale", cover_subtitle: "Solutions d'Hydratation d'Entreprise",
                cover_prepared_for: "Préparé pour :", cover_prepared_by: "Préparé par",
                meta_ref: "Référence", meta_date: "Date", meta_validity: "Validité", meta_days: "Jours à compter de l'émission",
                header_p2: "Profil & Contexte", header_p3: "Offre Commerciale", header_p4: "Conditions & Signatures",
                
                sec1_title: "1. Résumé Exécutif",
                sec1_p1: "La santé, le bien-être et la productivité de vos collaborateurs dépendent d'une hydratation de qualité supérieure. LA PETITE COUR a le plaisir de vous soumettre cette proposition formelle pour devenir votre partenaire privilégié en approvisionnement d'eau minérale naturelle.",
                sec1_p2: "Notre approche repose sur trois piliers : une qualité d'eau irréprochable (certifiée ANOR), une logistique de pointe garantissant zéro rupture de stock, et un service client dédié aux comptes corporatifs.",
                
                sec2_title: "2. À Propos de La Petite Cour",
                sec2_intro: "LA PETITE COUR se spécialise dans la fourniture et la distribution d'eau potable de première qualité, destinée spécifiquement aux milieux professionnels. Nous avons conçu une offre flexible et évolutive, adaptée à la réalité des bureaux, espaces de coworking, ateliers, commerces et établissements recevant du public.",
                sec2_services: "Nos services incluent :",
                sec2_l1: "L'installation, la maintenance et le suivi des équipements de distribution",
                sec2_l2: "Un service à la clientèle personnalisé et réactif",
                sec2_l3: "Des solutions écoresponsables favorisant le recyclage et la réduction de l'empreinte environnementale",
                sec2_l4: "La gestion des stocks et l'adaptation des volumes en fonction de l'évolution de vos effectifs",
                sec2_body: "Avec pour mission de redéfinir la distribution B2B au Cameroun, LA PETITE COUR est distributeur corporate agréé par Sources du Pays, dédié exclusivement à la fourniture des marques Supermont et Opur aux professionnels.",
                
                sec2_gamme_title: "Notre Gamme & Équipements",
                sec2_gamme_1: "Bonbonnes consignées (10L, 20L) pour distributeurs",
                sec2_gamme_2: "Bouteilles individuelles (33cl, 50cl, 1L, 1.5L)",
                sec2_gamme_3: "Fontaines réfrigérées, tempérées et systèmes sans contact",
                sec2_gamme_4: "Accessoires (gobelets recyclables, supports, kits d'entretien)",

                sec2_av_title: "Avantages pour votre Entreprise",
                sec2_av_1_title: "Santé et Bien-être",
                sec2_av_1_desc: "Une bonne hydratation améliore la concentration et l'efficacité.",
                sec2_av_2_title: "Image et Attractivité",
                sec2_av_2_desc: "Un espace bien équipé valorise votre marque employeur.",

                sec2_b1_title: "Logistique Multimodale", sec2_b1_desc: "Camions anti-UV et tricycles pour une livraison rapide, même en zone dense.",
                sec2_b2_title: "Stock Tampon", sec2_b2_desc: "Capacité de stockage dédiée pour pallier aux éventuelles pénuries d'usine.",
                sec2_b3_title: "Accompagnement Dédié", sec2_b3_desc: "Conseiller unique, audits réguliers et maintenance technique de vos équipements.",
                sec2_clients: "Ils nous font confiance",
                
                sec3_title: "3. Détails de l'Offre et Tarification",
                tbl_desc: "Désignation du Produit", tbl_qty: "Qté (Mensuelle)", tbl_price: "Prix Unitaire", tbl_total: "Prix Total",
                tbl_grand: "Montant Total Mensuel Estimé (FCFA) :",
                tbl_note: "* Note : La facturation réelle se fera sur la base des bons de livraison (BL) signés. Les prix sont exprimés en Francs CFA.",
                
                sec4_title: "4. Accord de Niveau de Service (SLA)",
                sla_freq: "Fréq. de Livraison", sla_buffer: "Stock de Sécurité Alloué", sla_weeks: "Semaines",
                sla_pay: "Modalités de Paiement", sla_empties: "Gestion des Emballages (Vides)", sla_validity: "Validité de l'Offre",
                
                sec5_title: "5. Conditions Générales de Service",
                tc_1_title: "Article 1 : Gestion Écoresponsable des Emballages.", tc_1_body: "Dans le cadre de notre démarche environnementale, les bonbonnes de 10L et 20L sont réutilisables et mises à votre disposition. Pour assurer une rotation fluide, nous appliquons un principe d'échange (1 vide pour 1 plein). Nous comptons sur notre partenariat pour le bon soin de ces contenants.",
                tc_2_title: "Article 2 : Maintien du Parc Matériel.", tc_2_body: "Afin de garantir une hygiène irréprochable, les bonbonnes ne doivent contenir que de l'eau. En cas de perte ou de détérioration majeure d'un contenant, des frais de remplacement au tarif en vigueur pourront être appliqués afin d'assurer le renouvellement qualitatif de notre flotte.",
                tc_3_title: "Article 3 : Facturation et Souplesse.", tc_3_body: "La facturation s'effectue sur la base des bons de livraison (BL) dûment validés par vos équipes. Nous accordons des délais de paiement standards (précisés dans le SLA) pour faciliter votre comptabilité. Un suivi régulier et conjoint permet d'éviter tout retard pouvant impacter la fluidité du service.",
                tc_4_title: "Article 4 : Transparence et Évolutivité.", tc_4_body: "Notre politique tarifaire est conçue pour être transparente et compétitive. Les tarifs peuvent bénéficier d'une dégressivité selon l'évolution de vos volumes et la durée de notre collaboration. Absolument aucun frais caché n'est appliqué.",
                tc_5_title: "Article 5 : Installation et Continuité.", tc_5_body: "Notre service logistique s'adapte à vos horaires pour garantir des livraisons sans perturbation de votre activité. Nos techniciens assurent l'installation, l'entretien régulier des fontaines et une réactivité maximale pour vous éviter toute rupture de stock.",
                tc_6_title: "Article 6 : Engagement de Satisfaction.", tc_6_body: "Votre satisfaction est au cœur de notre démarche. Un conseiller dédié vous est affecté pour analyser vos besoins, ajuster les volumes et intervenir rapidement en cas de requête spécifique. Nous garantissons le remplacement immédiat de tout équipement défectueux.",                
                sig_lpc: "Pour La Petite Cour :", sig_role_lpc: "Le Responsable Commerciale", sig_prep: "Préparé par :",
                sig_client: "Bon pour Accord (Le Client) :", sig_date_client: "Date et Cachet :"
            }
        };

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

            // Signatures
            document.getElementById('sig_sales_rep').innerText = u.name;
            document.getElementById('sig_date_lpc').innerText = formattedDate;
            document.getElementById('sig_client_name').innerText = c.company_name;
            document.getElementById('sig_client_title').innerText = c.contact_name || 'Direction Générale';

            // Table Injection
            const tbody = document.getElementById('dyn_items_table');
            tbody.innerHTML = '';
            apiData.items.forEach(item => {
                const formatHtml = item.format ? `<div class="text-xs text-gray-500 mt-1">${item.format}</div>` : '';
                tbody.innerHTML += LPC.html`
                    <tr>
                        <td class="py-4 px-6 border-b border-gray-100">
                            <div class="font-bold text-gray-900">${item.desc}</div>
                            ${formatHtml}
                        </td>
                        <td class="py-4 px-6 border-b border-gray-100 text-center font-medium">${item.qty}</td>
                        <td class="py-4 px-6 border-b border-gray-100 text-right text-gray-700">${formatCurrency(item.unit_price)}</td>
                        <td class="py-4 px-6 border-b border-gray-100 text-right font-bold text-gray-900">${formatCurrency(item.total_price)}</td>
                    </tr>
                `;
            });
            document.getElementById('dyn_total_amount').innerText = formatCurrency(p.total_amount);
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
            
            const waText = `Bonjour ${c.contact_name || ''},\n\nVoici le lien sécurisé vers la proposition commerciale de La Petite Cour (${p.reference}).\n\nLien : ${currentUrl}\n\nN'hésitez pas si vous avez des questions.\n\nCordialement,\n${u.name}`;
            document.getElementById('input_wa_body').value = waText;

            // Email
            document.getElementById('input_email_to').value = c.email || '';
            document.getElementById('input_email_subject').value = `Proposition Commerciale : ${c.company_name} / La Petite Cour`;
            
            const emailText = `Bonjour ${c.contact_name || 'Madame, Monsieur'},\n\nVeuillez trouver via le lien sécurisé ci-dessous notre proposition commerciale technique et financière concernant l'approvisionnement en eau minérale de vos locaux.\n\n📄 Consulter le Devis en ligne : ${currentUrl}\n\n(Note: Vous pouvez télécharger le document en PDF directement depuis ce lien).\n\nNous restons à votre entière disposition pour tout échange technique ou commercial.\n\nCordialement,\n\n${u.name}\nLa Petite Cour`;
            document.getElementById('input_email_body').value = emailText;
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
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (dictionary[lang][key]) {
                    el.innerHTML = dictionary[lang][key]; // innerHTML allows bold tags inside translation strings
                }
            });
        }

        // 7. MULTI-PAGE PDF GENERATOR (The core fix)
        async function generatePDF() {
            if(!apiData) { LPC.modal.alert("Attendez le chargement des données / Wait for data to load"); return; }
            
            const btn = document.getElementById('btn-download');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = LPC.html`<i class="fas fa-spinner fa-spin"></i> Traitement...`;
            btn.classList.add('opacity-75', 'cursor-not-allowed');

            try {
                // Initialize PDF (A4 Portrait)
                const pdf = new jspdf.jsPDF('p', 'mm', 'a4');
                const pdfWidth = pdf.internal.pageSize.getWidth();
                const pdfHeight = pdf.internal.pageSize.getHeight();
                
                // Select all the A4 page div blocks
                const pages = document.querySelectorAll('.a4-page');

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
                console.error("PDF Generation failed:", error);
                LPC.modal.alert("Erreur lors de la génération du PDF. Essayez sur un ordinateur / Error generating PDF.");
            } finally {
                // Restore button state
                btn.innerHTML = originalHTML;
                btn.classList.remove('opacity-75', 'cursor-not-allowed');
            }
        }

        // Init App
        window.onload = fetchProposalData;
    