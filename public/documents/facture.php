<?php
// The rich HTML invoice below renders by default — it is the client-facing
// deliverable, shared by WhatsApp/email and exported by html2canvas + jsPDF.
// The condensed server-side dompdf render is served on demand via ?pdf=1.

// This page is a DOCUMENT, not an app screen: a customer opens it from a token
// link, and it is the exact DOM html2canvas rasterises into the PDF. It must
// therefore look identical for every recipient regardless of any internal
// user's theme preference. LPC_FORCE_LIGHT tells head_assets.php to pin the
// light theme and to omit lpc-theme.css entirely — see the block at the top of
// that file for why the dark stylesheet also broke PDF generation.
if (!defined('LPC_FORCE_LIGHT')) { define('LPC_FORCE_LIGHT', true); }
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/document_pdf.php';
if (($_GET['pdf'] ?? '') === '1') {
    lpc_serve_document_pdf('invoice');   // exits after streaming
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facture | LPC ERP</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="<?= lpc_asset('/assets/css/tailwind.css') ?>">
    

    <script src="/assets/vendor/html2canvas/html2canvas.min.js" integrity="sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H" crossorigin="anonymous"></script>
    <script src="/assets/vendor/jspdf/jspdf.umd.min.js" integrity="sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

    <style>
        body { background-color: #e5e7eb; color: #1F2937; -webkit-print-color-adjust: exact; }
        
        #document-capture { display: flex; justify-content: center; padding: 2rem 0; overflow-x: auto; }
        
        .a4-page {
            width: 210mm;
            min-height: 297mm;
            background: #FFFFFF;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            position: relative;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
        }

        /* Mobile PDF Override Class */
        .force-a4-width { width: 794px !important; min-width: 794px !important; max-width: 794px !important; }

        @media print {
            body { background: white; margin: 0; padding: 0; }
            .no-print { display: none !important; }
            #document-capture { padding: 0; display: block; overflow: visible; }
            .a4-page { box-shadow: none; margin: 0; width: 100%; min-height: 100vh; page-break-after: avoid; }
        }

        .modal { opacity: 0; visibility: hidden; transition: all 0.3s ease; }
        .modal.active { opacity: 1; visibility: visible; }
        .modal-content { transform: translateY(20px); transition: all 0.3s ease; }
        .modal.active .modal-content { transform: translateY(0); }
        
        /* Table Rules */
        .inv-table th { border-bottom: 2px solid #111827; }
        .inv-table td { border-bottom: 1px solid #E5E7EB; }

        /* Digital Stamp */
        .digital-stamp {
            border: 3px solid #005A2B;
            color: #005A2B;
            border-radius: 8px;
            padding: 10px 15px;
            transform: rotate(-2deg);
            display: inline-block;
            background-image: url('data:image/svg+xml;utf8,<svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="100%" fill="none" stroke="%23005A2B" stroke-width="1" stroke-dasharray="4" opacity="0.2"/></svg>');
        }
        
        /* Status Badges for Print */
        .status-paid { border: 2px solid #10B981; color: #10B981; background: #ECFDF5; }
        .status-partial { border: 2px solid #F59E0B; color: #F59E0B; background: #FFFBEB; }
        .status-unpaid { border: 2px solid #EF4444; color: #EF4444; background: #FEF2F2; }
    </style>
    <script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>"></script>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="min-h-screen flex flex-col font-sans antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <nav class="bg-white border-b border-gray-200 px-6 py-4 sticky top-0 z-40 shadow-sm no-print">
        <div class="max-w-7xl mx-auto flex justify-between items-center">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-gray-900 rounded-xl flex items-center justify-center text-white font-bold"><i class="fas fa-file-invoice-dollar text-lg"></i></div>
                <div>
                    <h1 class="text-xl font-black text-gray-900 leading-tight tracking-tight">Finance LPC</h1>
                    <span class="text-xs text-gray-500 font-bold uppercase tracking-widest" id="nav-ref">Chargement...</span>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <div class="bg-gray-100 p-1 rounded-lg border border-gray-200 flex">
                    <button onclick="setLang('fr')" id="btn-lang-fr" class="px-4 py-1.5 text-sm font-bold rounded-md bg-white shadow-sm text-gray-900 transition-all">FR</button>
                    <button onclick="setLang('en')" id="btn-lang-en" class="px-4 py-1.5 text-sm font-bold rounded-md text-gray-500 hover:text-gray-900 transition-all bg-transparent shadow-none">EN</button>
                </div>

                <div class="h-8 w-px bg-gray-200 mx-2"></div>

                <button onclick="openModal('whatsappModal')" class="flex items-center gap-2 px-4 py-2 bg-green-50 border border-green-200 text-green-700 rounded-lg font-bold text-sm hover:bg-green-100 transition-all">
                    <i class="fab fa-whatsapp text-lg"></i> <span data-i18n="btn_whatsapp">WhatsApp</span>
                </button>
                <button onclick="openModal('emailModal')" class="flex items-center gap-2 px-4 py-2 bg-gray-50 border border-gray-200 text-gray-700 rounded-lg font-bold text-sm hover:bg-gray-100 transition-all">
                    <i class="far fa-envelope"></i> <span data-i18n="btn_email">Email</span>
                </button>
                <button onclick="generatePDF()" id="btn-download" class="flex items-center gap-2 px-6 py-2 bg-gray-900 hover:bg-black text-white rounded-lg font-bold text-sm shadow-md transition-all">
                    <i class="fas fa-print"></i> <span data-i18n="btn_pdf">Télécharger PDF</span>
                </button>
            </div>
        </div>
    </nav>

    <main role="main" id="main" class="flex-1 w-full overflow-y-auto">
        <div id="document-capture">
            
            <div class="a4-page bg-white" id="pdf-container">
                
                <div class="h-3 w-full bg-lpc-dark shrink-0"></div>

                <!-- LETTERHEAD.
                     Everything here used to be four hardcoded lines, including a
                     NIU and an RC that were placeholders and went out on real
                     customer invoices. They now come from company_profile via
                     the API, editable at Administration → Paramètres → Entreprise.
                     Raison sociale + forme juridique, RCCM, NIU, capital social,
                     régime fiscal and centre des impôts are the identifiers a
                     Cameroonian invoice is required to carry. -->
                <div class="px-16 pt-12 pb-6 flex justify-between items-start shrink-0">
                    <div class="w-1/2 pr-6">
                        <img src="/assets/img/full_logo.svg" alt="Logo" class="h-16 w-auto mb-3" onerror="this.outerHTML='<h2 class=\\'text-3xl font-black text-lpc-dark\\'>LPC</h2>'">
                        <p class="text-sm font-black text-gray-900 leading-tight" id="dyn_co_name">...</p>
                        <p class="text-[10px] text-gray-500 mt-1.5 leading-relaxed font-bold uppercase tracking-wider" id="dyn_co_address">...</p>
                        <p class="text-[10px] text-gray-500 mt-1 leading-relaxed font-bold" id="dyn_co_contact">...</p>
                        <p class="text-[9px] text-gray-600 mt-2 leading-relaxed font-bold border-t border-gray-200 pt-2" id="dyn_co_legal">...</p>
                    </div>
                    <div class="w-1/2 text-right">
                        <h1 class="text-4xl font-black text-gray-900 uppercase tracking-tighter" data-i18n="doc_title">Facture</h1>
                        <div id="dyn_status_badge" class="mt-2 inline-block px-3 py-1 rounded text-xs font-black uppercase tracking-widest status-unpaid">
                            NON PAYÉE
                        </div>

                        <div class="mt-6 inline-block text-left bg-gray-50 p-4 rounded-xl border border-gray-200 min-w-[250px]">
                            <div class="flex justify-between items-center gap-6 mb-2">
                                <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest" data-i18n="lbl_inv_num">N° Facture :</span>
                                <span class="text-sm font-black text-gray-900" id="dyn_ref">...</span>
                            </div>
                            <div class="flex justify-between items-center gap-6 mb-2">
                                <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest" data-i18n="lbl_date">Date :</span>
                                <span class="text-sm font-bold text-gray-800" id="dyn_date">...</span>
                            </div>
                            <div class="flex justify-between items-center gap-6 mb-2">
                                <span class="text-[10px] font-black text-red-400 uppercase tracking-widest" data-i18n="lbl_due_date">Échéance :</span>
                                <span class="text-sm font-black text-red-600" id="dyn_due_date">...</span>
                            </div>
                            <div class="flex justify-between items-center gap-6 border-t border-gray-200 pt-2">
                                <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest" data-i18n="lbl_currency">Devise :</span>
                                <span class="text-xs font-bold text-gray-800">FCFA (XAF)</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="px-16 pb-6 shrink-0">
                    <div class="bg-gray-50 p-6 rounded-xl border border-gray-100">
                        <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 border-b-2 border-gray-200 inline-block pb-1" data-i18n="lbl_client">Facturé à</h3>
                        <p class="text-xl font-black text-gray-900" id="dyn_client_name">...</p>
                        <p class="text-sm font-medium text-gray-600 mt-1" id="dyn_client_address">...</p>
                        <p class="text-sm font-medium text-gray-600 mt-1">
                            <i class="fas fa-phone text-gray-400 text-xs mr-1"></i> <span id="dyn_client_phone">...</span>
                            <span class="mx-2 text-gray-300">|</span>
                            <i class="fas fa-envelope text-gray-400 text-xs mr-1"></i> <span id="dyn_client_email">...</span>
                        </p>
                        <!-- The buyer's NIU is what makes the transaction deductible
                             for them. Its absence is flagged rather than hidden, so
                             whoever issues the invoice sees the gap before the
                             client's accountant does. -->
                        <p class="text-xs font-bold text-gray-700 mt-2 pt-2 border-t border-gray-200" id="client_fiscal_line">
                            <span data-i18n="lbl_client_niu">NIU :</span> <span id="dyn_client_niu" class="font-mono">—</span>
                            <span class="mx-2 text-gray-300">|</span>
                            <span data-i18n="lbl_client_rccm">RCCM :</span> <span id="dyn_client_rccm" class="font-mono">—</span>
                        </p>
                        <p id="dyn_client_niu_warning" class="hidden mt-2 text-[10px] font-black uppercase tracking-widest text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1 inline-block">
                            <i class="fas fa-triangle-exclamation mr-1"></i>
                            <span data-i18n="warn_no_niu">NIU client manquant — requis pour la déductibilité B2B</span>
                        </p>
                    </div>
                </div>

                <div class="px-16 flex-1 flex flex-col">
                    <table class="w-full text-left inv-table">
                        <thead>
                            <tr>
                                <th class="py-3 px-2 text-[10px] uppercase text-gray-400 font-black tracking-widest" data-i18n="tbl_desc">Désignation</th>
                                <th class="py-3 px-2 text-[10px] uppercase text-gray-400 font-black tracking-widest text-center" data-i18n="tbl_qty">Qté</th>
                                <th class="py-3 px-2 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right" data-i18n="tbl_up">P.U. (FCFA)</th>
                                <th class="py-3 px-2 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right" data-i18n="tbl_total">Montant (FCFA)</th>
                            </tr>
                        </thead>
                        <tbody id="dyn_items_table" class="text-sm text-gray-900">
                            </tbody>
                    </table>

                    <div class="mt-8 flex justify-between items-start gap-12">
                        
                        <div class="w-1/2 space-y-6">
                            <div id="notes_container" class="hidden">
                                <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1" data-i18n="lbl_notes">Notes / Conditions</h4>
                                <p class="text-xs text-gray-600 font-medium whitespace-pre-line" id="dyn_notes"></p>
                            </div>
                            
                            <div>
                                <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2" data-i18n="lbl_payment_info">Informations de Paiement</h4>
                                <!-- Rendered from company_profile.bank_* / mobile_money_number.
                                     The IBAN printed here was previously a literal in the
                                     markup, so changing bank meant editing a PHP file. -->
                                <div id="dyn_bank_block" class="bg-gray-50 p-4 rounded-lg border border-gray-100 text-xs text-gray-600 space-y-1.5 font-medium">
                                </div>
                                <p class="mt-2 text-[10px] text-gray-400 italic" data-i18n="pay_reference_hint">Merci de préciser le N° de facture en motif du règlement.</p>
                            </div>

                            <div>
                                <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2" data-i18n="lbl_terms">Conditions de Règlement</h4>
                                <p class="text-[10px] text-gray-500 leading-relaxed font-medium" id="terms_text">
                                    Paiement exigible au plus tard à la date d'échéance indiquée.
                                    Passé ce délai, une pénalité de retard est applicable de plein droit,
                                    sans mise en demeure préalable. Les marchandises restent la propriété
                                    du vendeur jusqu'au paiement intégral du prix.
                                </p>
                            </div>
                        </div>

                        <div class="w-1/2">
                            <!-- FISCAL LADDER.
                                 The old block was HT → TVA → TTC, which cannot show
                                 how a Cameroonian total is actually built. Accises are
                                 assessed on the HT and then join the TVA base, so TVA
                                 applies to (HT + accises). Précompte and AIR are
                                 withholdings: they leave the TTC the client owes
                                 untouched and instead split it between us and the DGI —
                                 hence the separate "net à nous virer" line. Rows with a
                                 zero amount are hidden by injectData(). -->
                            <div class="bg-gray-50 p-5 rounded-xl border border-gray-200">
                                <div class="flex justify-between items-center mb-2.5">
                                    <span class="text-xs font-bold text-gray-600" data-i18n="tot_sub">Total Hors Taxe (HT)</span>
                                    <span class="text-sm font-black text-gray-900" id="dyn_subtotal">...</span>
                                </div>

                                <div class="flex justify-between items-center mb-2.5 hidden" id="row_excise">
                                    <span class="text-xs font-bold text-gray-600"><span data-i18n="tot_excise">Droit d'accises</span> (<span id="dyn_excise_rate">0</span>%)</span>
                                    <span class="text-sm font-black text-gray-900" id="dyn_excise_amount">...</span>
                                </div>

                                <div class="flex justify-between items-center mb-2.5">
                                    <span class="text-xs font-bold text-gray-600">TVA (<span id="dyn_tva_rate">0</span>%)</span>
                                    <span class="text-sm font-black text-gray-900" id="dyn_tva_amount">...</span>
                                </div>

                                <!-- A bare "TVA 0%" is the single most common reason a
                                     Cameroonian invoice gets rejected: the buyer cannot
                                     tell an exoneration from an omission. The basis is
                                     printed instead. -->
                                <p id="row_tva_exemption" class="hidden text-[9px] italic text-gray-500 leading-snug -mt-1 mb-2.5 pl-1 border-l-2 border-gray-300">
                                    <span id="dyn_tva_exemption"></span>
                                </p>

                                <div class="border-t-2 border-gray-900 pt-2.5 mb-2.5 flex justify-between items-center">
                                    <span class="text-sm font-black text-gray-900" data-i18n="tot_grand">NET À PAYER (TTC)</span>
                                    <span class="text-lg font-black text-gray-900" id="dyn_grandtotal">...</span>
                                </div>

                                <div id="withholding_block" class="hidden border-t border-dashed border-gray-300 pt-2.5 space-y-2 mb-2">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400" data-i18n="lbl_withholding">Retenues à la source</p>
                                    <div class="flex justify-between items-center hidden" id="row_precompte">
                                        <span class="text-[11px] font-bold text-gray-600"><span data-i18n="tot_precompte">Précompte sur achats</span> (<span id="dyn_precompte_rate">0</span>%)</span>
                                        <span class="text-xs font-black text-gray-700" id="dyn_precompte_amount">...</span>
                                    </div>
                                    <div class="flex justify-between items-center hidden" id="row_air">
                                        <span class="text-[11px] font-bold text-gray-600"><span data-i18n="tot_air">AIR — Acompte d'Impôt sur le Revenu</span> (<span id="dyn_air_rate">0</span>%)</span>
                                        <span class="text-xs font-black text-gray-700" id="dyn_air_amount">...</span>
                                    </div>
                                    <div class="flex justify-between items-center bg-gray-900 text-white px-3 py-2 rounded-lg">
                                        <span class="text-[10px] font-black uppercase tracking-widest" data-i18n="tot_net_transfer">Net à virer au fournisseur</span>
                                        <span class="text-sm font-black" id="dyn_net_payable">...</span>
                                    </div>
                                </div>

                                <div id="payment_history_block" class="border-t border-gray-200 pt-3 space-y-2 mt-2">
                                    <div class="flex justify-between items-center text-emerald-600">
                                        <span class="text-[10px] font-black uppercase tracking-widest" data-i18n="tot_paid">Déjà Réglé (Avances)</span>
                                        <span class="text-sm font-black" id="dyn_paid_amount">0 F</span>
                                    </div>
                                    <div class="flex justify-between items-center text-red-600 bg-red-50 p-2 rounded">
                                        <span class="text-xs font-black uppercase tracking-widest" data-i18n="tot_balance">Reste à Payer</span>
                                        <span class="text-base font-black" id="dyn_balance">...</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-auto pt-12 pb-8 grid grid-cols-3 gap-8 items-end">
                        <div class="col-span-1">
                            <div class="digital-stamp">
                                <p class="text-[10px] font-black uppercase tracking-widest border-b border-lpc-dark/30 pb-1 mb-1" data-i18n="stamp_title">Certifié Conforme</p>
                                <p class="text-[9px] font-bold mt-1">Par: <span id="dyn_creator" class="font-mono text-xs">...</span></p>
                                <p class="text-[9px] font-bold">Rôle: <span id="dyn_role" class="font-mono">...</span></p>
                                <p class="text-[9px] font-bold">Le: <span id="dyn_stamp_date" class="font-mono">...</span></p>
                                <p class="text-[8px] font-normal mt-1 opacity-70">Hash: <span id="dyn_hash" class="font-mono">...</span></p>
                            </div>
                        </div>
                        <div class="col-span-2 text-right">
                            <p class="text-xs italic text-gray-500 font-medium">
                                <span data-i18n="legal_text">Arrêtée la présente facture à la somme de</span><br>
                                <strong class="text-gray-900 text-sm not-italic" id="dyn_amount_words">...</strong>
                            </p>
                        </div>
                    </div>

                    <!-- Statutory footer. Required on every page of a Cameroonian
                         invoice and previously absent from the HTML view entirely
                         (the dompdf path already had it). Sourced from
                         company_profile so it can never drift from the letterhead. -->
                    <div class="pb-8 -mt-4">
                        <p class="text-[8px] text-gray-400 text-center leading-relaxed border-t border-gray-200 pt-3" id="dyn_legal_footer"></p>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="whatsappModal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm px-4">
        <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden">
            <div class="bg-green-600 px-6 py-4 flex justify-between items-center text-white">
                <h3 class="font-bold text-lg flex items-center gap-2"><i class="fab fa-whatsapp"></i> Envoyer au Client</h3>
                <button onclick="closeModal('whatsappModal')" class="text-green-200 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-6 space-y-5">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Numéro WhatsApp</label>
                    <input type="text" id="input_wa_phone" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-sm focus:ring-2 focus:ring-green-500 outline-none font-medium">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Message</label>
                    <textarea id="input_wa_body" rows="6" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-sm focus:ring-2 focus:ring-green-500 outline-none"></textarea>
                </div>
                <div class="pt-2 flex justify-end gap-3">
                    <button onclick="closeModal('whatsappModal')" class="px-5 py-2.5 rounded-lg text-sm font-bold text-gray-600 hover:bg-gray-100">Annuler</button>
                    <button onclick="sendWhatsApp()" class="px-6 py-2.5 rounded-lg text-sm font-bold text-white bg-green-600 hover:bg-green-700 flex items-center gap-2"><i class="fab fa-whatsapp"></i> Envoyer</button>
                </div>
            </div>
        </div>
    </div>

    <div id="emailModal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm px-4">
        <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-xl overflow-hidden">
            <div class="bg-gray-800 px-6 py-4 flex justify-between items-center text-white">
                <h3 class="font-bold text-lg flex items-center gap-2"><i class="far fa-envelope"></i> Email Client</h3>
                <button onclick="closeModal('emailModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Destinataire</label>
                    <input type="email" id="input_email_to" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-gray-800">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Objet</label>
                    <input type="text" id="input_email_subject" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm font-bold outline-none focus:ring-2 focus:ring-gray-800">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Message</label>
                    <textarea id="input_email_body" rows="8" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-gray-800"></textarea>
                </div>
                <div class="pt-2 flex justify-end gap-3">
                    <button onclick="closeModal('emailModal')" class="px-5 py-2.5 rounded-lg text-sm font-bold text-gray-600 hover:bg-gray-100">Annuler</button>
                    <button onclick="sendEmail()" class="px-6 py-2.5 rounded-lg text-sm font-bold text-white bg-gray-800 hover:bg-black flex items-center gap-2"><i class="fas fa-paper-plane"></i> Envoyer</button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= lpc_asset('/assets/js/modules/documents-facture.js') ?>" defer></script>
</body>
</html>