<?php

// This page is a DOCUMENT, not an app screen: a customer opens it from a token
// link, and it is the exact DOM html2canvas rasterises into the PDF. It must
// therefore look identical for every recipient regardless of any internal
// user's theme preference. LPC_FORCE_LIGHT tells head_assets.php to pin the
// light theme and to omit lpc-theme.css entirely — see the block at the top of
// that file for why the dark stylesheet also broke PDF generation.
if (!defined('LPC_FORCE_LIGHT')) { define('LPC_FORCE_LIGHT', true); }
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/document_pdf.php';
// The HTML page below is the deliverable suppliers receive from the token link,
// and the DOM html2canvas + jsPDF capture on Download. It renders by default.
// The condensed server-side dompdf render stays available on demand via ?pdf=1
// — same opt-in contract as quote.php.
if (($_GET['pdf'] ?? '') === '1') {
    lpc_serve_document_pdf('po');   // exits after streaming
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon de Commande | LPC ERP</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="<?= lpc_asset('/assets/css/tailwind.css') ?>">
    

    <script src="/assets/vendor/html2canvas/html2canvas.min.js" integrity="sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H" crossorigin="anonymous"></script>
    <script src="/assets/vendor/jspdf/jspdf.umd.min.js" integrity="sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

    <style>
        body { background-color: #e5e7eb; color: #1F2937; -webkit-print-color-adjust: exact; }
        
        /* Strict Single A4 Page Styling */
        #document-capture { display: flex; justify-content: center; padding: 2rem 0; }
        
        .a4-page {
            width: 100%; /* Fluid on mobile */
            max-width: 210mm; /* Caps at A4 on desktop */
            min-height: auto;
            background: #FFFFFF;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            position: relative;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            margin: 0 auto;
        }

        /* Mobile PDF Override Class */
        .force-a4-width { width: 794px !important; min-width: 794px !important; max-width: 794px !important; }

        /* Print formatting */
        @media print {
            body { background: white; margin: 0; padding: 0; }
            .no-print { display: none !important; }
            #document-capture { padding: 0; display: block; }
            .a4-page { box-shadow: none; margin: 0; width: 100%; height: 100vh; page-break-after: avoid; }
        }

        /* Modals */
        .modal { opacity: 0; visibility: hidden; transition: all 0.3s ease; }
        .modal.active { opacity: 1; visibility: visible; }
        .modal-content { transform: translateY(20px); transition: all 0.3s ease; }
        .modal.active .modal-content { transform: translateY(0); }
        
        /* Invoice Table Rules */
        .po-table th { border-bottom: 2px solid #1F2937; }
        .po-table td { border-bottom: 1px solid #E5E7EB; }
    </style>
    <script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>"></script>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="min-h-screen flex flex-col font-sans antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <nav class="bg-white border-b border-gray-200 px-4 md:px-6 py-4 sticky top-0 z-40 shadow-sm no-print">
        <div class="max-w-7xl mx-auto flex justify-between items-start md:items-center">
            
            <div class="flex items-center gap-3 md:gap-4 mt-1 md:mt-0">
                <img src="/assets/img/small_logo.svg" alt="LPC Logo" class="h-10 w-auto object-contain" onerror="this.outerHTML='<div class=\\'w-10 h-10 bg-lpc-dark rounded-xl flex items-center justify-center text-white font-bold text-xl\\'>LPC</div>'">
                <div>
                    <h1 class="text-lg md:text-xl font-black text-gray-900 leading-tight tracking-tight">La Petite Cour</h1>
                    <span class="text-[10px] md:text-xs text-lpc-dark font-bold uppercase tracking-widest" id="nav-ref">Chargement...</span>
                </div>
            </div>

            <div class="flex items-start md:items-center gap-3">
                <div class="bg-gray-100 p-1 rounded-lg border border-gray-200 flex">
                    <button onclick="setLang('fr')" id="btn-lang-fr" class="px-3 py-1.5 md:px-4 text-xs md:text-sm font-bold rounded-md bg-white shadow-sm text-lpc-dark transition-all">FR</button>
                    <button onclick="setLang('en')" id="btn-lang-en" class="px-3 py-1.5 md:px-4 text-xs md:text-sm font-bold rounded-md text-gray-500 hover:text-gray-900 transition-all bg-transparent shadow-none">EN</button>
                </div>

                <div class="h-8 w-px bg-gray-200 mx-2 hidden md:block"></div>

                <div class="flex flex-col md:flex-row gap-2">
                    <button onclick="openModal('whatsappModal')" class="flex justify-center items-center w-10 h-10 md:w-auto md:h-auto md:px-4 md:py-2 bg-green-50 border border-green-200 text-green-700 rounded-lg font-bold text-sm hover:bg-green-100 transition-all" title="WhatsApp" aria-label="WhatsApp">
                        <i class="fab fa-whatsapp text-lg"></i> <span class="hidden md:inline md:ml-2" data-i18n="btn_whatsapp">WhatsApp</span>
                    </button>
                    <button onclick="openModal('emailModal')" class="flex justify-center items-center w-10 h-10 md:w-auto md:h-auto md:px-4 md:py-2 bg-blue-50 border border-blue-200 text-blue-700 rounded-lg font-bold text-sm hover:bg-blue-100 transition-all" title="Email" aria-label="Email">
                        <i class="far fa-envelope text-lg"></i> <span class="hidden md:inline md:ml-2" data-i18n="btn_email">Email</span>
                    </button>
                    <button onclick="generatePDF()" id="btn-download" class="flex justify-center items-center w-10 h-10 md:w-auto md:h-auto md:px-6 md:py-2 bg-gray-900 text-white rounded-lg font-bold text-sm hover:bg-black transition-all shadow-md" title="Télécharger PDF" aria-label="Télécharger PDF">
                        <i class="fas fa-file-pdf text-red-400 text-lg"></i> <span class="hidden md:inline md:ml-2" data-i18n="btn_pdf">Télécharger PDF</span>
                    </button>

                    <?php
                    // Two distinct signature actions. Each component renders
                    // nothing unless the viewer holds its permission.
                    // See docs/SIGNATURES.md.
                    $lpcPoToken = (string) ($_GET['token'] ?? '');

                    // Ask the SUPPLIER to confirm the order.
                    $share_type  = 'bon_commande';
                    $share_token = $lpcPoToken;
                    $share_who   = 'le fournisseur';
                    $share_class = 'justify-center';
                    require __DIR__ . '/../../includes/components/signature_share_button.php';

                    // LPC attests to its own commitment, from inside the ERP.
                    $sign_btn_type  = 'bon_commande';
                    $sign_btn_token = $lpcPoToken;
                    $sign_btn_class = 'justify-center';
                    require __DIR__ . '/../../includes/components/signature_sign_button.php';
                    ?>
                </div>
            </div>
        </div>
    </nav>

    <main role="main" id="main" class="flex-1 w-full overflow-y-auto">
        <div id="document-capture">
            
            <div class="a4-page bg-white">
                
                <div class="h-4 w-full bg-lpc-dark shrink-0"></div>

                <div class="px-4 md:px-16 pt-8 md:pt-12 pb-8 flex flex-col md:flex-row justify-between items-start shrink-0 border-b border-gray-100 gap-6">
                    <div class="w-full md:w-1/2">
                        <img src="/assets/img/full_logo.svg" alt="LPC Logo" class="h-16 w-auto mb-4" onerror="this.style.display='none'">
                        <h2 class="text-sm font-black text-gray-900 tracking-wide">ETS. LA PETITE COUR (LPC)</h2>
                        <p class="text-xs text-gray-500 mt-1 leading-relaxed">
                            Entrée Compagnie de Gendarmerie de Ndogbong<br>
                            B.P. 5120 Douala, Cameroun<br>
                            NIU: PO8811272062QA | RC/DLA/2019/A/A/687<br>
                            Tél: +237 696 291 800 | Email: operations@lpc.cm
                        </p>
                    </div>
                    <div class="w-full md:w-1/2 md:text-right">
                        <h1 class="text-3xl font-black text-lpc-dark uppercase tracking-tighter" data-i18n="doc_title">Bon de Commande</h1>
                        <p class="text-xs text-gray-400 font-bold uppercase tracking-widest mt-1" data-i18n="doc_subtitle">Purchase Order</p>
                        
                        <div class="mt-6 ml-0 md:ml-auto text-left bg-gray-50 p-4 rounded-xl border border-gray-200 w-full md:w-64">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest" data-i18n="lbl_po_num">N° Cde :</span>
                                <span class="text-sm font-black text-gray-900" id="dyn_ref">...</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest" data-i18n="lbl_date">Date :</span>
                                <span class="text-sm font-bold text-gray-800" id="dyn_date">...</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="px-4 md:px-16 py-8 grid grid-cols-1 md:grid-cols-2 gap-8 md:gap-12 shrink-0">
                    <div>
                        <h3 class="text-xs font-black text-lpc-dark uppercase tracking-widest mb-3 border-b-2 border-lpc-light inline-block pb-1" data-i18n="lbl_supplier">Fournisseur / Supplier</h3>
                        <p class="text-lg font-black text-gray-900" id="dyn_sup_name">...</p>
                        <p class="text-sm text-gray-600 mt-1" id="dyn_sup_address">...</p>
                        <p class="text-sm text-gray-600 mt-1"><i class="fas fa-phone text-gray-400 text-xs mr-1"></i> <span id="dyn_sup_phone">...</span></p>
                        <p class="text-sm text-gray-600"><i class="fas fa-envelope text-gray-400 text-xs mr-1"></i> <span id="dyn_sup_email">...</span></p>
                    </div>
                    <div>
                        <h3 class="text-xs font-black text-gray-400 uppercase tracking-widest mb-3 border-b-2 border-gray-200 inline-block pb-1" data-i18n="lbl_delivery">Lieu de Livraison / Ship To</h3>
                        <p class="text-sm font-black text-gray-900">Entrepôt La Petite Cour </p>
                        <p class="text-sm text-gray-600 mt-1">Entrée, Compagnie Ndogbong, Douala</p>
                        <p class="text-sm text-gray-600 mt-1 font-bold">Attn: Directeur des Opérations</p>
                    </div>
                </div>

                <div class="px-4 md:px-16 flex-1 flex flex-col">
                    <table class="w-full text-left po-table mt-4">
                        <thead>
                            <tr>
                                <th class="py-3 px-2 text-[10px] uppercase text-gray-400 font-black tracking-widest w-1/2" data-i18n="tbl_desc">Désignation</th>
                                <th class="py-3 px-2 text-[10px] uppercase text-gray-400 font-black tracking-widest text-center" data-i18n="tbl_qty">Qté</th>
                                <th class="py-3 px-2 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right" data-i18n="tbl_pu">Prix Unitaire</th>
                                <th class="py-3 px-2 text-[10px] uppercase text-gray-400 font-black tracking-widest text-right" data-i18n="tbl_total">Montant</th>
                            </tr>
                        </thead>
                        <tbody id="dyn_items_table" class="text-sm text-gray-800">
                            </tbody>
                    </table>

                    <div class="mt-auto pt-8 flex flex-col md:flex-row justify-between items-start md:items-end pb-8 gap-6">
                        <div class="w-full md:w-1/2 md:pr-12">
                            <p class="text-xs text-gray-500 italic" data-i18n="txt_note">Note : Veuillez inclure la référence de ce bon de commande sur toutes vos factures et bons de livraison.</p>
                            <div class="mt-4">
                                <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest block mb-1" data-i18n="lbl_pay_terms">Condition de Paiement</span>
                                <span class="text-sm font-bold text-gray-900 bg-gray-100 px-3 py-1.5 rounded-lg border border-gray-200" id="dyn_payment">...</span>
                            </div>
                        </div>
                        
                        <div class="w-full md:w-1/2 bg-gray-50 rounded-xl p-5 border border-gray-200">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-xs font-bold text-gray-500 uppercase tracking-wider" data-i18n="lbl_subtotal">Sous-Total</span>
                                <span class="text-sm font-bold text-gray-800" id="dyn_subtotal">...</span>
                            </div>
                            <div class="flex justify-between items-center mb-3 text-emerald-600" id="discount_row" style="display: none;">
                                <span class="text-xs font-bold uppercase tracking-wider"><span data-i18n="lbl_discount">Remise</span> <span id="dyn_discount_note" class="text-[10px] ml-1 font-normal italic"></span></span>
                                <span class="text-sm font-bold" id="dyn_discount">...</span>
                            </div>
                            <div class="border-t-2 border-gray-900 pt-3 flex justify-between items-center">
                                <span class="text-sm font-black text-gray-900 uppercase tracking-widest" data-i18n="lbl_grandtotal">NET À PAYER (FCFA)</span>
                                <span class="text-xl font-black text-lpc-dark" id="dyn_grandtotal">...</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="px-4 md:px-16 pt-8 pb-12 border-t border-gray-200 shrink-0">
                    <?php
                    // Sprint 11: the two hand-built "Établi par / Visa Direction"
                    // columns are now the shared signature partial, so a signed
                    // bon de commande shows its stamp, hash and QR here exactly
                    // as every other document does. See docs/SIGNATURES.md.
                    $sig_doc = lpc_signature_doc('bon_commande', (string) ($_GET['token'] ?? ''));
                    if ($sig_doc) {
                        $sig_type    = 'bon_commande';
                        $sig_doc_id  = (int) ($sig_doc['record_id'] ?? 0);
                        $sig_context = 'html';
                        $sig_labels  = [
                            'internal' => 'Établi par / Visa Direction',
                            'external' => 'Le fournisseur',
                        ];
                        $sig_placeholders = [
                            'internal' => ['La Direction Générale'],
                            'external' => array_filter([$sig_doc['client']['name'] ?? '']),
                        ];
                        require __DIR__ . '/../../includes/components/signature_block.php';
                    }
                    ?>
                </div>

            </div> </div>
    </main>

    <div id="whatsappModal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm px-4">
        <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-gray-100">
            <div class="bg-green-600 px-6 py-4 flex justify-between items-center text-white">
                <h3 class="font-bold text-lg flex items-center gap-2"><i class="fab fa-whatsapp"></i> Envoyer au Fournisseur</h3>
                <button onclick="closeModal('whatsappModal')" class="text-green-200 hover:text-white transition-colors"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-6 space-y-5">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Numéro WhatsApp Fournisseur</label>
                    <input type="text" id="input_wa_phone" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-sm focus:ring-2 focus:ring-green-500 outline-none transition-all font-medium text-gray-900">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Message (Modifiable)</label>
                    <textarea id="input_wa_body" rows="6" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-sm focus:ring-2 focus:ring-green-500 outline-none transition-all text-gray-800"></textarea>
                </div>
                <div class="pt-2 flex justify-end gap-3">
                    <button onclick="closeModal('whatsappModal')" class="px-5 py-2.5 rounded-lg text-sm font-bold text-gray-600 hover:bg-gray-100 transition-colors">Annuler</button>
                    <button onclick="sendWhatsApp()" class="px-6 py-2.5 rounded-lg text-sm font-bold text-white bg-green-600 hover:bg-green-700 shadow-md flex items-center gap-2 transition-all"><i class="fab fa-whatsapp text-lg"></i> Ouvrir WhatsApp</button>
                </div>
            </div>
        </div>
    </div>

    <div id="emailModal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm px-4">
        <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-xl overflow-hidden border border-gray-100">
            <div class="bg-blue-600 px-6 py-4 flex justify-between items-center text-white">
                <h3 class="font-bold text-lg flex items-center gap-2"><i class="far fa-envelope"></i> Email Fournisseur</h3>
                <button onclick="closeModal('emailModal')" class="text-blue-200 hover:text-white transition-colors"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Destinataire (À)</label>
                    <input type="email" id="input_email_to" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition-all">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Objet</label>
                    <input type="text" id="input_email_subject" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition-all font-bold text-gray-800">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Corps du message</label>
                    <textarea id="input_email_body" rows="8" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition-all text-gray-800"></textarea>
                </div>
                <div class="pt-2 flex justify-end gap-3">
                    <button onclick="closeModal('emailModal')" class="px-5 py-2.5 rounded-lg text-sm font-bold text-gray-600 hover:bg-gray-100 transition-colors">Annuler</button>
                    <button onclick="sendEmail()" class="px-6 py-2.5 rounded-lg text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 shadow-md flex items-center gap-2 transition-all"><i class="fas fa-paper-plane"></i> Lancer le client Mail</button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= lpc_asset('/assets/js/modules/documents-bon_commande.js') ?>" defer></script>
</body>
</html>