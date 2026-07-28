<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/document_pdf.php';
lpc_serve_document_pdf('quote');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>La Petite Cour - Enterprise Proposal</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Merriweather:ital,wght@0,300;0,400;0,700;1,400&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="<?= lpc_asset('/assets/css/tailwind.css') ?>">
    

    <script src="/assets/vendor/html2canvas/html2canvas.min.js" integrity="sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H" crossorigin="anonymous"></script>
    <script src="/assets/vendor/jspdf/jspdf.umd.min.js" integrity="sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

    <style>
        body { background-color: #e5e7eb; color: #1F2937; -webkit-print-color-adjust: exact; }
        
        /* Multi-Page A4 Styling */
        #document-capture { display: flex; flex-direction: column; gap: 2rem; align-items: center; padding: 2rem 0; }
        
        .a4-page {
            width: 210mm;
            height: 297mm; /* Exact A4 Height */
            background: #FFFFFF;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            position: relative;
            box-sizing: border-box;
            overflow: hidden;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
        }

        /* Print formatting */
        @media print {
            body { background: white; margin: 0; padding: 0; }
            .no-print { display: none !important; }
            #document-capture { gap: 0; padding: 0; display: block; }
            .a4-page { box-shadow: none; margin: 0; page-break-after: always; height: 297mm; }
        }

        /* Modals */
        .modal { opacity: 0; visibility: hidden; transition: all 0.3s ease; }
        .modal.active { opacity: 1; visibility: visible; }
        .modal-content { transform: translateY(20px); transition: all 0.3s ease; }
        .modal.active .modal-content { transform: translateY(0); }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #F3F4F6; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        
        /* Table borders for PDF accuracy */
        .pdf-table th, .pdf-table td { border: 1px solid #E5E7EB; }
    </style>
    <script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>"></script>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="min-h-screen flex flex-col font-sans selection:bg-lpc-light selection:text-white">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <nav class="bg-white border-b border-gray-200 px-6 py-4 sticky top-0 z-40 shadow-sm no-print">
        <div class="max-w-7xl mx-auto flex justify-between items-center">
            <div class="flex items-center gap-4">
                <img src="/assets/img/full_logo.svg" alt="LPC Logo" class="h-10 w-auto object-contain">
                <div>
                    <h1 class="text-xl font-bold text-gray-900 leading-tight">La Petite Cour</h1>
                    <span class="text-xs text-gray-500 font-bold uppercase tracking-widest" id="nav-ref">Chargement...</span>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <div class="bg-gray-100 p-1 rounded-lg border border-gray-200 flex">
                    <button onclick="setLang('fr')" id="btn-lang-fr" class="px-4 py-1.5 text-sm font-bold rounded-md bg-white shadow-sm text-lpc-dark transition-all">FR</button>
                    <button onclick="setLang('en')" id="btn-lang-en" class="px-4 py-1.5 text-sm font-bold rounded-md text-gray-500 hover:text-gray-900 transition-all">EN</button>
                </div>

                <div class="h-8 w-px bg-gray-200 mx-2"></div>

                <button onclick="openModal('whatsappModal')" class="flex items-center gap-2 px-4 py-2 bg-green-50 border border-green-200 text-green-700 rounded-lg font-bold text-sm hover:bg-green-100 transition-all">
                    <i class="fab fa-whatsapp text-lg"></i> <span data-i18n="btn_whatsapp">WhatsApp</span>
                </button>
                <button onclick="openModal('emailModal')" class="flex items-center gap-2 px-4 py-2 bg-blue-50 border border-blue-200 text-blue-700 rounded-lg font-bold text-sm hover:bg-blue-100 transition-all">
                    <i class="far fa-envelope"></i> <span data-i18n="btn_email">Email</span>
                </button>
                <button onclick="generatePDF()" id="btn-download" class="flex items-center gap-2 px-6 py-2 bg-lpc-dark text-white rounded-lg font-bold text-sm hover:bg-[#004722] transition-all shadow-md">
                    <i class="fas fa-file-pdf"></i> <span data-i18n="btn_pdf">Générer PDF</span>
                </button>
            </div>
        </div>
    </nav>

    <main role="main" id="main" class="flex-1 w-full overflow-y-auto">
        <div id="document-capture">
            
            <div class="a4-page">
                <div class="absolute top-0 right-0 w-64 h-64 bg-lpc-light rounded-bl-full opacity-20"></div>
                <div class="absolute bottom-0 left-0 w-96 h-96 bg-lpc-dark rounded-tr-full opacity-10"></div>
                
                <div class="flex-1 flex flex-col justify-center px-24 py-20 z-10">
                    <div class="mb-24">
                        <img src="/assets/img/full_logo.svg" alt="La Petite Cour" class="h-24 w-auto mb-6">
                        <div class="h-2 w-32 bg-lpc-light mb-6"></div>
                        <h2 class="text-3xl font-serif text-gray-800" data-i18n="cover_title">Proposition Commerciale</h2>
                        <p class="text-xl text-gray-500 mt-2 font-light" data-i18n="cover_subtitle">Solutions d'Hydratation d'Entreprise</p>
                    </div>

                    <div class="bg-gray-50 border-l-4 border-lpc-dark p-8 rounded-r-2xl mb-24 shadow-sm">
                        <p class="text-sm font-bold text-gray-400 uppercase tracking-widest mb-2" data-i18n="cover_prepared_for">Préparé pour :</p>
                        <h3 class="text-2xl font-bold text-gray-900 mb-1" id="cov_client_name">...</h3>
                        <p class="text-gray-600 font-medium">Attn: <span id="cov_client_contact">...</span></p>
                        <p class="text-gray-500 text-sm mt-1" id="cov_client_email">...</p>
                    </div>

                    <div class="mt-auto border-t border-gray-200 pt-8 flex justify-between">
                        <div>
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1" data-i18n="meta_date">Date</p>
                            <p class="font-bold text-gray-800" id="cov_date">...</p>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1" data-i18n="meta_ref">Référence</p>
                            <p class="font-bold text-gray-800" id="cov_ref">...</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1" data-i18n="cover_prepared_by">Préparé par</p>
                            <p class="font-bold text-lpc-dark" id="cov_sales_rep">...</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="a4-page">
    <header class="h-24 bg-lpc-dark text-white flex items-center px-16 justify-between shrink-0">
        <span class="font-bold tracking-widest text-sm uppercase" data-i18n="header_p2">Profil & Contexte</span>
        <span class="text-lpc-light font-bold" id="head_ref_2">...</span>
    </header>

    <div class="px-16 py-5 flex-1 flex flex-col h-full bg-white text-gray-800">
    
        <div class="mb-5">
            <h3 class="text-xl font-serif font-bold text-gray-900 mb-2" data-i18n="sec1_title">1. Résumé Exécutif</h3>
            <p class="text-sm text-gray-600 leading-normal mb-2 text-justify" data-i18n="sec1_p1">La santé, le bien-être et la productivité de vos collaborateurs dépendent d'une hydratation de qualité supérieure. LA PETITE COUR a le plaisir de vous soumettre cette proposition formelle pour devenir votre partenaire privilégié en approvisionnement d'eau minérale naturelle.</p>
            <p class="text-sm text-gray-600 leading-normal text-justify" data-i18n="sec1_p2">Notre approche repose sur trois piliers : une qualité d'eau irréprochable (certifiée ANOR), une logistique de pointe garantissant zéro rupture de stock, et un service client dédié aux comptes corporatifs.</p>
        </div>
        
        <div class="mb-4">
        <h3 class="text-xl font-serif font-bold text-gray-900 mb-2" data-i18n="sec2_title">2. À Propos de La Petite Cour</h3>
        <p class="text-sm text-gray-600 leading-normal mb-3 text-justify" data-i18n="sec2_intro">LA PETITE COUR se spécialise dans la fourniture et la distribution d'eau potable de première qualité, destinée spécifiquement aux milieux professionnels. Nous avons conçu une offre flexible et évolutive, adaptée à la réalité des bureaux, espaces de coworking, ateliers, commerces et établissements recevant du public.</p>
        
        <div class="mb-3">
            <h4 class="mt-5 text-sm font-bold text-gray-900 uppercase tracking-wider mb-2 border-b border-gray-200 pb-1" data-i18n="sec2_services">Nos services incluent :</h4>
            <ul class="grid grid-cols-2 gap-x-6 gap-y-1.5 text-xs text-gray-600 pl-1">
                <li class="flex items-start"><i class="fas fa-check text-lpc-light mt-0.5 mr-2"></i><span data-i18n="sec2_l1">L'installation, la maintenance et le suivi des équipements</span></li>
                <li class="flex items-start"><i class="fas fa-check text-lpc-light mt-0.5 mr-2"></i><span data-i18n="sec2_l2">Un service à la clientèle personnalisé et réactif</span></li>
                <li class="flex items-start"><i class="fas fa-check text-lpc-light mt-0.5 mr-2"></i><span data-i18n="sec2_l3">Des solutions écoresponsables favorisant le recyclage</span></li>
                <li class="flex items-start"><i class="fas fa-check text-lpc-light mt-0.5 mr-2"></i><span data-i18n="sec2_l4">La gestion des stocks et l'adaptation des volumes</span></li>
            </ul>
        </div>

        <p class="mt-5 text-sm text-gray-600 leading-normal text-justify" data-i18n="sec2_body">Avec pour mission de redéfinir la distribution B2B au Cameroun, LA PETITE COUR est distributeur corporate agréé par Sources du Pays, dédié exclusivement à la fourniture des marques Supermont et Opur aux professionnels.</p>
    </div>
        
        <div class="grid grid-cols-12 gap-6 mb-4 flex-1">
            
            <div class="col-span-7 flex flex-col gap-4">
                <div>
                    <h4 class="text-sm font-bold text-gray-900 uppercase tracking-wider mb-2 border-b border-gray-200 pb-1" data-i18n="sec2_gamme_title">Notre Gamme & Équipements</h4>
                    <ul class="space-y-1.5 text-xs text-gray-600">
                        <li class="flex items-center"><i class="fas fa-tint text-lpc-light mr-2.5"></i><span data-i18n="sec2_gamme_1">Bonbonnes consignées (10L, 20L) pour distributeurs</span></li>
                        <li class="flex items-center"><i class="fas fa-tint text-lpc-light mr-2.5"></i><span data-i18n="sec2_gamme_3">Fontaines réfrigérées, tempérées et systèmes sans contact</span></li>
                        <li class="flex items-center"><i class="fas fa-tint text-lpc-light mr-2.5"></i><span data-i18n="sec2_gamme_2">Bouteilles individuelles (33cl, 50cl, 1L, 1.5L)</span></li>
                        <li class="flex items-center"><i class="fas fa-tint text-lpc-light mr-2.5"></i><span data-i18n="sec2_gamme_4">Accessoires (gobelets recyclables, supports, kits d'entretien)</span></li>
                    </ul>
                </div>
                
                <div>
                    <h4 class="text-sm font-bold text-gray-900 uppercase tracking-wider mb-2 border-b border-gray-200 pb-1" data-i18n="sec2_av_title">Avantages pour votre Entreprise</h4>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-green-50/60 p-2.5 rounded-lg border border-green-100">
                            <strong class="block text-xs text-lpc-dark mb-1" data-i18n="sec2_av_1_title">Santé et Bien-être</strong>
                            <span class="text-xs text-gray-500 leading-snug block" data-i18n="sec2_av_1_desc">Une bonne hydratation améliore la concentration et l'efficacité.</span>
                        </div>
                        <div class="bg-green-50/60 p-2.5 rounded-lg border border-green-100">
                            <strong class="block text-xs text-lpc-dark mb-1" data-i18n="sec2_av_2_title">Image et Attractivité</strong>
                            <span class="text-xs text-gray-500 leading-snug block" data-i18n="sec2_av_2_desc">Un espace bien équipé valorise votre marque employeur.</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-span-5 bg-gray-50 p-4 rounded-xl border border-gray-200 flex flex-col justify-center h-fit">
                <ul class="space-y-4 text-xs">
                    <li class="flex items-start">
                        <div class="bg-white p-2 rounded shadow-sm mr-3 border border-gray-100 shrink-0"><i class="fas fa-truck-fast text-lpc-dark text-sm"></i></div>
                        <div>
                            <strong class="block text-sm text-gray-900 mb-0.5" data-i18n="sec2_b1_title">Logistique Multimodale</strong>
                            <span class="text-gray-500 leading-snug block" data-i18n="sec2_b1_desc">Camions anti-UV et tricycles pour une livraison rapide, même en zone dense.</span>
                        </div>
                    </li>
                    <li class="flex items-start">
                        <div class="bg-white p-2 rounded shadow-sm mr-3 border border-gray-100 shrink-0"><i class="fas fa-boxes text-lpc-dark text-sm"></i></div>
                        <div>
                            <strong class="block text-sm text-gray-900 mb-0.5" data-i18n="sec2_b2_title">Stock Tampon</strong>
                            <span class="text-gray-500 leading-snug block" data-i18n="sec2_b2_desc">Capacité de stockage dédiée pour pallier aux éventuelles pénuries d'usine.</span>
                        </div>
                    </li>
                    <li class="flex items-start">
                        <div class="bg-white p-2 rounded shadow-sm mr-3 border border-gray-100 shrink-0"><i class="fas fa-user-tie text-lpc-dark text-sm"></i></div>
                        <div>
                            <strong class="block text-sm text-gray-900 mb-0.5" data-i18n="sec2_b3_title">Accompagnement Dédié</strong>
                            <span class="text-gray-500 leading-snug block" data-i18n="sec2_b3_desc">Conseiller unique, audits réguliers et maintenance technique de vos équipements.</span>
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <div class="mt-auto pt-4 border-t border-gray-200">
            <h4 class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-3 text-center" data-i18n="sec2_clients">Ils nous font confiance</h4>
            <div class="flex flex-wrap justify-center items-center gap-x-6 gap-y-3">
                <img src="/assets/img/prometal.png" alt="Prometal" class="h-8 w-auto object-contain">
                <img src="/assets/img/logo-smart.webp" alt="Smart Logistics" class="h-8 w-auto object-contain">
                <img src="/assets/img/acep_logo.svg" alt="ACEP" class="h-7 w-auto object-contain">
                <img src="/assets/img/profab.png" alt="Profab" class="h-8 w-auto object-contain">
                <img src="/assets/img/cafe_vienna.svg" alt="Cafe Vienna" class="h-12 w-auto object-contain">
                <img src="/assets/img/base_cameroon.svg" alt="Base Cameroon" class="h-10 w-auto object-contain">
            </div>
        </div>
    </div>
            
    <footer class="h-16 bg-gray-50 border-t border-gray-200 flex items-center justify-between px-16 text-xs text-gray-400 shrink-0">
        <span>LA PETITE COUR | RC/DLA/2019/A/A/687</span>
        <span>Page 2 / 4</span>
    </footer>
</div>

            <div class="a4-page">
                <header class="h-24 bg-lpc-dark text-white flex items-center px-16 justify-between shrink-0">
                    <span class="font-bold tracking-widest text-sm uppercase" data-i18n="header_p3">Offre Commerciale</span>
                    <span class="text-lpc-light font-bold" id="head_ref_3">...</span>
                </header>

                <div class="px-16 py-12 flex-1">
                    <h3 class="text-2xl font-serif font-bold text-gray-900 mb-8" data-i18n="sec3_title">3. Détails de l'Offre et Tarification</h3>
                    
                    <div class="rounded-xl overflow-hidden border border-gray-200 mb-6">
                        <table class="w-full text-left border-collapse pdf-table">
                            <thead class="bg-gray-100 text-xs uppercase text-gray-600 font-bold">
                                <tr>
                                    <th class="py-4 px-6 w-1/2" data-i18n="tbl_desc">Désignation du Produit</th>
                                    <th class="py-4 px-6 text-center" data-i18n="tbl_qty">Qté (Mensuelle)</th>
                                    <th class="py-4 px-6 text-right" data-i18n="tbl_price">Prix Unitaire</th>
                                    <th class="py-4 px-6 text-right" data-i18n="tbl_total">Prix Total</th>
                                </tr>
                            </thead>
                            <tbody id="dyn_items_table" class="bg-white text-sm text-gray-800 divide-y divide-gray-200">
                                </tbody>
                            <tfoot class="bg-gray-50">
                                <tr>
                                    <td colspan="3" class="py-5 px-6 text-right font-bold text-gray-900 uppercase text-xs tracking-wider" data-i18n="tbl_grand">
                                        Montant Total Mensuel Estimé (FCFA) :
                                    </td>
                                    <td class="py-5 px-6 text-right font-black text-lg text-lpc-dark" id="dyn_total_amount">
                                        ...
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <p class="text-xs text-gray-500 italic mb-10" data-i18n="tbl_note">* Note : La facturation réelle se fera sur la base des bons de livraison (BL) signés. Les prix sont exprimés en Francs CFA.</p>

                    <h4 class="text-sm font-bold text-gray-900 uppercase tracking-widest mb-4 border-b pb-2" data-i18n="sec4_title">4. Accord de Niveau de Service (SLA)</h4>
                    <div class="grid grid-cols-2 gap-x-12 gap-y-6 text-sm">
                        <div class="flex flex-col border-b border-gray-100 pb-2">
                            <span class="text-gray-500 text-xs font-bold uppercase" data-i18n="sla_freq">Fréq. de Livraison</span>
                            <span class="font-bold text-gray-900 mt-1" id="dyn_delivery_frequency">...</span>
                        </div>
                        <div class="flex flex-col border-b border-gray-100 pb-2">
                            <span class="text-gray-500 text-xs font-bold uppercase" data-i18n="sla_buffer">Stock de Sécurité Alloué</span>
                            <span class="font-bold text-gray-900 mt-1"><span id="dyn_buffer_stock_weeks">...</span> <span data-i18n="sla_weeks">Semaines</span></span>
                        </div>
                        <div class="flex flex-col border-b border-gray-100 pb-2">
                            <span class="text-gray-500 text-xs font-bold uppercase" data-i18n="sla_pay">Modalités de Paiement</span>
                            <span class="font-bold text-gray-900 mt-1" id="dyn_payment_terms">...</span>
                        </div>
                        <div class="flex flex-col border-b border-gray-100 pb-2">
                            <span class="text-gray-500 text-xs font-bold uppercase" data-i18n="sla_empties">Gestion des Emballages (Vides)</span>
                            <span class="font-bold text-gray-900 mt-1" id="dyn_empties_policy">...</span>
                        </div>
                        <div class="flex flex-col border-b border-gray-100 pb-2 col-span-2">
                            <span class="text-gray-500 text-xs font-bold uppercase" data-i18n="sla_validity">Validité de l'Offre</span>
                            <span class="font-bold text-gray-900 mt-1"><span id="dyn_validity_days">...</span> <span data-i18n="meta_days">Jours à compter de la date d'émission</span></span>
                        </div>
                    </div>
                </div>

                <footer class="h-16 bg-gray-50 border-t border-gray-200 flex items-center justify-between px-16 text-xs text-gray-400 shrink-0">
                    <span>LA PETITE COUR | info@lpc.cm</span>
                    <span>Page 3 / 4</span>
                </footer>
            </div>

            <div class="a4-page">
                <header class="h-24 bg-lpc-dark text-white flex items-center px-16 justify-between shrink-0">
                    <span class="font-bold tracking-widest text-sm uppercase" data-i18n="header_p4">Conditions & Signatures</span>
                    <span class="text-lpc-light font-bold" id="head_ref_4">...</span>
                </header>

                <div class="px-16 py-12 flex-1 flex flex-col">
                    <h3 class="text-2xl font-serif font-bold text-gray-900 mb-6" data-i18n="sec5_title">5. Conditions Générales de Service</h3>
                    
                    <div class="space-y-5 text-xs text-gray-600 leading-relaxed text-justify mb-8">
                        <p><strong class="text-gray-800" data-i18n="tc_1_title">Article 1 : Gestion Écoresponsable des Emballages.</strong> <span data-i18n="tc_1_body">...</span></p>
                        
                        <p><strong class="text-gray-800" data-i18n="tc_2_title">Article 2 : Maintien du Parc Matériel.</strong> <span data-i18n="tc_2_body">...</span></p>

                        <p><strong class="text-gray-800" data-i18n="tc_3_title">Article 3 : Facturation et Souplesse.</strong> <span data-i18n="tc_3_body">...</span></p>
                        
                        <p><strong class="text-gray-800" data-i18n="tc_4_title">Article 4 : Transparence et Évolutivité.</strong> <span data-i18n="tc_4_body">...</span></p>

                        <p><strong class="text-gray-800" data-i18n="tc_5_title">Article 5 : Installation et Continuité.</strong> <span data-i18n="tc_5_body">...</span></p>

                        <p><strong class="text-gray-800" data-i18n="tc_6_title">Article 6 : Engagement de Satisfaction.</strong> <span data-i18n="tc_6_body">...</span></p>
                    </div>

                    <div class="mt-auto grid grid-cols-2 gap-16 pt-8 border-t border-gray-200">
                        <div class="bg-gray-50 border border-gray-200 p-6 rounded-xl relative">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1" data-i18n="sig_lpc">Pour La Petite Cour :</p>
                            <p class="font-bold text-gray-900 text-sm mb-12" id="sig_role_lpc" data-i18n="sig_role_lpc">La Direction Commerciale</p>
                            
                            <div class="absolute top-12 right-6 opacity-30 pointer-events-none transform -rotate-12">
                                <h4 class="text-xl font-serif text-lpc-dark italic border-2 border-lpc-dark rounded-full px-3 py-0.5">Approuvé</h4>
                            </div>
                            
                            <p class="text-[9px] text-gray-500 uppercase tracking-wider mb-1" data-i18n="sig_prep">Préparé par :</p>
                            <div class="border-b border-gray-900 w-full mb-2"></div>
                            <p class="font-bold text-lpc-dark text-sm" id="sig_sales_rep">...</p>
                            <p class="text-[10px] text-gray-400 mt-1" id="sig_date_lpc">...</p>
                        </div>

                        <div class="p-6 flex flex-col">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-auto" data-i18n="sig_client">Bon pour Accord (Le Client) :</p>
                            
                            <div class="mt-16">
                                <div class="border-b border-gray-300 w-full mb-2"></div>
                                <p class="font-bold text-gray-900 text-sm leading-tight" id="sig_client_name">...</p>
                                <p class="text-[10px] text-gray-500 leading-tight mt-0.5" id="sig_client_title">...</p>
                                <p class="text-[10px] text-gray-400 mt-2" data-i18n="sig_date_client">Date et Cachet :</p>
                            </div>
                        </div>
                    </div>
                </div>

                <footer class="h-16 bg-lpc-dark flex items-center justify-center px-16 text-xs text-gray-300 shrink-0">
                    <p class="tracking-widest">LA PETITE COUR | B.P. 5120 DOUALA | +237 696 291 800 | +237 679 535 564</p>
                </footer>
            </div>

        </div> </main>

    <div id="whatsappModal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm px-4">
        <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-gray-100">
            <div class="bg-green-600 px-6 py-4 flex justify-between items-center text-white">
                <h3 class="font-bold text-lg flex items-center gap-2"><i class="fab fa-whatsapp"></i> Partager via WhatsApp</h3>
                <button onclick="closeModal('whatsappModal')" class="text-green-200 hover:text-white transition-colors"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-6 space-y-5">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Numéro WhatsApp du Client</label>
                    <input type="text" id="input_wa_phone" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none transition-all font-medium text-gray-900">
                    <p class="text-[10px] text-gray-400 mt-1">Format international requis (ex: +2376...)</p>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Message (Modifiable)</label>
                    <textarea id="input_wa_body" rows="6" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500 outline-none transition-all text-gray-800"></textarea>
                </div>
                <div class="pt-2 flex justify-end gap-3">
                    <button onclick="closeModal('whatsappModal')" class="px-5 py-2.5 rounded-lg text-sm font-bold text-gray-600 hover:bg-gray-100 transition-colors">Annuler</button>
                    <button onclick="sendWhatsApp()" class="px-6 py-2.5 rounded-lg text-sm font-bold text-white bg-green-600 hover:bg-green-700 shadow-md flex items-center gap-2 transition-all hover:-translate-y-0.5"><i class="fab fa-whatsapp text-lg"></i> Ouvrir WhatsApp</button>
                </div>
            </div>
        </div>
    </div>

    <div id="emailModal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm px-4">
        <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-xl overflow-hidden border border-gray-100">
            <div class="bg-blue-600 px-6 py-4 flex justify-between items-center text-white">
                <h3 class="font-bold text-lg flex items-center gap-2"><i class="far fa-envelope"></i> Envoyer par Email</h3>
                <button onclick="closeModal('emailModal')" class="text-blue-200 hover:text-white transition-colors"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Destinataire (À)</label>
                    <input type="email" id="input_email_to" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Objet</label>
                    <input type="text" id="input_email_subject" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all font-bold text-gray-800">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Corps du message</label>
                    <textarea id="input_email_body" rows="8" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all text-gray-800"></textarea>
                </div>
                <div class="pt-2 flex justify-end gap-3">
                    <button onclick="closeModal('emailModal')" class="px-5 py-2.5 rounded-lg text-sm font-bold text-gray-600 hover:bg-gray-100 transition-colors">Annuler</button>
                    <button onclick="sendEmail()" class="px-6 py-2.5 rounded-lg text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 shadow-md flex items-center gap-2 transition-all hover:-translate-y-0.5"><i class="fas fa-paper-plane"></i> Lancer le client Mail</button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= lpc_asset('/assets/js/modules/documents-quote.js') ?>" defer></script>
</body>
</html>