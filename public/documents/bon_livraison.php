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
// The HTML page below IS the client-facing deliverable: it is what the customer
// opens from the WhatsApp/email token link, what they sign, and what
// html2canvas + jsPDF rasterise when they tap Download. It renders by default.
// The condensed server-side dompdf render stays available on demand via ?pdf=1
// — same opt-in contract as quote.php.
if (($_GET['pdf'] ?? '') === '1') {
    lpc_serve_document_pdf('delivery');   // exits after streaming
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bordereau de Livraison | LPC ERP</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="<?= lpc_asset('/assets/css/tailwind.css') ?>">
    

    <script src="/assets/vendor/html2canvas/html2canvas.min.js" integrity="sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H" crossorigin="anonymous"></script>
    <script src="/assets/vendor/jspdf/jspdf.umd.min.js" integrity="sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk" crossorigin="anonymous"></script>
    <script src="/assets/vendor/qrcodejs/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

    <style>
        body { background-color: #e5e7eb; color: #1F2937; -webkit-print-color-adjust: exact; }
        
        #document-capture { display: flex; justify-content: center; padding: 2rem 0; overflow-x: auto; }
        
        .a4-page {
            width: 100%; /* Fluid on mobile */
            max-width: 210mm; /* Caps at A4 on desktop */
            min-height: auto;
            background: #FFFFFF;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            position: relative;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            margin: 0 auto;
        }

        /* Mobile PDF Override Class */
        .force-a4-width { width: 794px !important; min-width: 794px !important; max-width: 794px !important; }

        @media print {
            body { background: white; margin: 0; padding: 0; }
            .no-print { display: none !important; }
            #document-capture { padding: 0; display: block; overflow: visible; }
            .a4-page { box-shadow: none; margin: 0; width: 100%; height: 100vh; page-break-after: avoid; }
        }

        .modal { opacity: 0; visibility: hidden; transition: all 0.3s ease; }
        .modal.active { opacity: 1; visibility: visible; }
        .modal-content { transform: translateY(20px); transition: all 0.3s ease; }
        .modal.active .modal-content { transform: translateY(0); }
        
        /* Table Rules */
        .bl-table th { border-bottom: 2px solid #111827; }
        .bl-table td { border-bottom: 1px solid #E5E7EB; }

        /* Digital Stamp (Updated to LPC Green) */
        .digital-stamp {
            border: 3px solid #005A2B;
            color: #005A2B;
            border-radius: 8px;
            padding: 10px 15px;
            transform: rotate(-3deg);
            display: inline-block;
            background-image: url('data:image/svg+xml;utf8,<svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="100%" fill="none" stroke="%23005A2B" stroke-width="1" stroke-dasharray="4" opacity="0.4"/></svg>');
        }

        /* Mobile View Fix: Native Zoom */
        @media screen and (max-width: 820px) {
            #document-capture {
                padding: 1rem;
                justify-content: flex-start;
                /* Flawlessly recalculates the physical layout size */
                zoom: calc((100vw - 2rem) / 794);
            }
        }
    </style>
    <script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>"></script>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="min-h-screen flex flex-col font-sans antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <nav class="bg-white border-b border-gray-200 px-4 md:px-6 py-4 sticky top-0 z-40 shadow-sm no-print">
        <div class="max-w-7xl mx-auto flex justify-between items-start md:items-center">
            
            <div class="flex items-center gap-3 md:gap-4 mt-1 md:mt-0">
                <img src="/assets/img/full_logo.svg" alt="LPC Logo" class="h-11 w-auto object-contain" onerror="this.style.display='none'">
                <div>
                    <h1 class="text-lg md:text-xl font-black text-gray-900 leading-tight tracking-tight">LA PETITE COUR</h1>
                    <span class="text-[10px] md:text-xs text-lpc-light font-bold uppercase tracking-widest" id="nav-ref">Chargement...</span>
                </div>
            </div>

            <div class="flex items-start md:items-center gap-3">
                
                <div class="bg-gray-100 p-1 rounded-lg border border-gray-200 flex">
                    <button onclick="setLang('fr')" id="btn-lang-fr" class="px-3 py-1.5 md:px-4 text-xs md:text-sm font-bold rounded-md bg-white shadow-sm text-lpc-dark transition-all">FR</button>
                    <button onclick="setLang('en')" id="btn-lang-en" class="px-3 py-1.5 md:px-4 text-xs md:text-sm font-bold rounded-md text-gray-500 hover:text-gray-900 transition-all bg-transparent shadow-none">EN</button>
                </div>

                <div class="h-8 w-px bg-gray-200 mx-2 hidden md:block"></div>

                <div class="flex flex-col md:flex-row gap-2 relative no-print" id="action-toolbar">
                    <button onclick="generatePDF()" id="btn-download" class="flex justify-center items-center w-10 h-10 md:w-auto md:h-auto md:px-6 md:py-2 bg-gray-800 hover:bg-black text-white rounded-lg font-bold text-sm shadow-md transition-all" title="Imprimer" aria-label="Imprimer">
                        <i class="fas fa-print text-lg"></i> <span class="hidden md:inline md:ml-2" data-i18n="btn_pdf">Imprimer BL</span>
                    </button>
                    
                    <div class="relative inline-block text-left group" id="signature-dropdown-container">
                        <button class="flex justify-center items-center w-10 h-10 md:w-auto md:h-auto md:px-6 md:py-2 bg-lpc-dark hover:bg-green-800 text-white rounded-lg font-bold text-sm shadow-md transition-all group-hover:ring-2 ring-green-300">
                            <i class="fas fa-signature text-lg"></i> <span class="hidden md:inline md:ml-2">Faire Signer</span>
                        </button>
                        
                        <div class="absolute right-0 mt-2 w-56 origin-top-right bg-white border border-gray-200 divide-y divide-gray-100 rounded-xl shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50">
                            <div class="p-2">
                                <a href="javascript:void(0)" onclick="openDirectSignature()" class="flex items-center gap-3 px-3 py-2.5 text-sm text-gray-700 hover:bg-green-50 hover:text-green-700 rounded-lg font-bold">
                                    <i class="fas fa-tablet-alt text-lg w-5 text-center"></i> Sur cet appareil
                                </a>
                                <a href="javascript:void(0)" onclick="openModal('qrModal')" class="flex items-center gap-3 px-3 py-2.5 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-700 rounded-lg font-bold">
                                    <i class="fas fa-qrcode text-lg w-5 text-center"></i> Afficher QR Code
                                </a>
                            </div>
                            <div class="p-2">
                                <a href="javascript:void(0)" onclick="openModal('whatsappModal')" class="flex items-center gap-3 px-3 py-2.5 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-600 rounded-lg font-bold">
                                    <i class="fab fa-whatsapp text-lg w-5 text-center"></i> Envoyer WhatsApp
                                </a>
                                <a href="javascript:void(0)" onclick="openModal('emailModal')" class="flex items-center gap-3 px-3 py-2.5 text-sm text-gray-700 hover:bg-gray-100 rounded-lg font-bold">
                                    <i class="far fa-envelope text-lg w-5 text-center"></i> Envoyer Email
                                </a>
                            </div>
                        </div>
                    </div>

                    <?php
                    // Universal internal signature. Distinct from "Faire Signer"
                    // above: that invites the CLIENT to sign externally on a
                    // device; this is LPC attesting to the delivery from inside
                    // the ERP. Renders nothing unless the viewer holds
                    // signatures.bl.internal.sign. See docs/SIGNATURES.md.
                    $sign_btn_type  = 'bl';
                    $sign_btn_token = (string) ($_GET['token'] ?? '');
                    $sign_btn_class = 'justify-center';
                    require __DIR__ . '/../../includes/components/signature_sign_button.php';
                    ?>
                </div>

            </div>
        </div>
    </nav>

    <main role="main" id="main" class="flex-1 w-full overflow-y-auto">
        <div id="document-capture">
            
            <div class="a4-page bg-white" id="pdf-container">
                
                <div class="h-3 w-full bg-lpc-dark shrink-0"></div>

                <div class="px-4 md:px-16 pt-8 md:pt-12 pb-8 flex flex-col md:flex-row justify-between items-start shrink-0 border-b border-gray-100 gap-6">
                <div class="w-full md:w-1/2">
                    <img src="/assets/img/full_logo.svg" alt="LPC Logo" class="h-32 w-auto mb-4" onerror="this.outerHTML='<h2 class=\\'text-3xl font-black text-lpc-dark\\'>LPC</h2>'">
                    <p class="text-[10px] text-gray-500 mt-2 leading-relaxed font-bold uppercase tracking-wider">
                        1030 Avenue Douala Manga Bell<br>
                        B.P. 5120 Douala, Cameroun<br>
                        Tél: +237 696 291 800 | operations@lpc.cm
                    </p>
                </div>
                <div class="w-full md:w-1/2 md:text-right">
                    <h1 class="text-3xl font-black text-gray-900 uppercase tracking-tighter" data-i18n="doc_title">Bordereau de Livraison</h1>
                    <p class="text-xs text-lpc-light font-bold uppercase tracking-widest mt-1" data-i18n="doc_subtitle">Delivery Note</p>
                    
                    <div class="mt-6 ml-0 md:ml-auto text-left bg-gray-50 p-4 rounded-xl border border-gray-200 w-full md:w-64">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest" data-i18n="lbl_bl_num">N° BL :</span>
                                <span class="text-sm font-black text-lpc-dark" id="dyn_ref">...</span>
                            </div>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest" data-i18n="lbl_cmd_num">N° Cmd :</span>
                                <span class="text-sm font-bold text-gray-800" id="dyn_so_ref">...</span>
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
                        <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 border-b-2 border-gray-100 inline-block pb-1" data-i18n="lbl_client">Client / Livré à</h3>
                        <p class="text-xl font-black text-gray-900" id="dyn_client_name">...</p>
                        <p class="text-sm font-medium text-gray-600 mt-1" id="dyn_client_address">...</p>
                        <p class="text-sm font-medium text-gray-600 mt-1"><i class="fas fa-phone text-gray-400 text-xs mr-1"></i> <span id="dyn_client_phone">...</span></p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 h-full flex flex-col">
                        <h3 class="text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Réserves & Observations</h3>
                        <div class="flex-1 overflow-hidden">
                            <p class="text-xs font-bold text-gray-800 italic leading-relaxed" id="dyn_observation">Aucune observation.</p>
                        </div>
                    </div>
                </div>

                <div class="px-4 md:px-16 flex-1 flex flex-col">
                    <table class="w-full text-left bl-table mt-4">
                        <thead>
                            <tr>
                                <th class="py-3 px-2 text-[10px] uppercase text-gray-400 font-black tracking-widest w-2/3" data-i18n="tbl_desc">Désignation des Marchandises</th>
                                <th class="py-3 px-2 text-[10px] uppercase text-gray-400 font-black tracking-widest text-center" data-i18n="tbl_qty_sent">Qté Expédiée</th>
                                <th class="py-3 px-2 text-[10px] uppercase text-gray-400 font-black tracking-widest text-center" data-i18n="tbl_qty_rec">Qté Reçue</th>
                            </tr>
                        </thead>
                        <tbody id="dyn_items_table" class="text-sm text-gray-900">
                            </tbody>
                    </table>

                    <div class="mt-auto pt-12 pb-8">
                        
                        <div class="mb-8 bg-amber-50 border border-amber-200 border-l-4 border-l-amber-500 p-4 rounded-r-xl flex items-center gap-4">
                            <i class="fas fa-exclamation-triangle text-amber-500 text-3xl"></i>
                            <div>
                                <p class="text-[10px] font-black text-amber-800 uppercase tracking-widest" data-i18n="lbl_instruction_title">Action Requise au Déchargement</p>
                                <p class="text-sm font-bold text-amber-900 mt-1" data-i18n="lbl_instruction_body">Collecter impérativement les emballages vides et faire signer le Certificat de Restitution d'Emballages (C.R.E.) par le client.</p>
                            </div>
                        </div>

                        <?php
                        // Sprint 11: #dynamic-signatures-area used to be filled
                        // by documents-bon_livraison.js from get_bl.php, which
                        // read deliveries.signature_image / .driver_signature_image
                        // / .digital_hash. Those legacy columns are no longer
                        // written — migration 055 moved signatures into
                        // document_signatures — so a newly signed BL would have
                        // rendered an empty box.
                        //
                        // The shared partial reads the new table and prints the
                        // client's drawn signature alongside LPC's internal
                        // attestation, with hash fragments and verification QRs.
                        // See docs/SIGNATURES.md.
                        $sig_doc = lpc_signature_doc('bl', (string) ($_GET['token'] ?? ''));
                        if ($sig_doc) {
                            $sig_type    = 'bl';
                            $sig_doc_id  = (int) ($sig_doc['record_id'] ?? 0);
                            $sig_context = 'html';
                            $sig_labels  = [
                                'internal' => 'Pour La Petite Cour',
                                'external' => 'Le réceptionnaire',
                            ];
                            $sig_placeholders = [
                                'external' => array_filter([$sig_doc['client']['name'] ?? '']),
                            ];
                            require __DIR__ . '/../../includes/components/signature_block.php';
                        }
                        ?>
                    </div>
                </div>
            </div> </div>
    </main>

    <div id="whatsappModal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm px-4">
        <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-gray-100">
            <div class="bg-green-600 px-6 py-4 flex justify-between items-center text-white">
                <h3 class="font-bold text-lg flex items-center gap-2"><i class="fab fa-whatsapp"></i> Envoyer au Client</h3>
                <button onclick="closeModal('whatsappModal')" class="text-green-200 hover:text-white transition-colors"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-6 space-y-5">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Numéro WhatsApp Client</label>
                    <input type="text" id="input_wa_phone" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-sm focus:ring-2 focus:ring-green-500 outline-none transition-all font-medium text-gray-900">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Message</label>
                    <textarea id="input_wa_body" rows="6" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-sm focus:ring-2 focus:ring-green-500 outline-none transition-all text-gray-800"></textarea>
                </div>
                <div class="pt-2 flex justify-end gap-3">
                    <button onclick="closeModal('whatsappModal')" class="px-5 py-2.5 rounded-lg text-sm font-bold text-gray-600 hover:bg-gray-100 transition-colors">Annuler</button>
                    <button onclick="sendWhatsApp()" class="px-6 py-2.5 rounded-lg text-sm font-bold text-white bg-green-600 hover:bg-green-700 shadow-md flex items-center gap-2 transition-all"><i class="fab fa-whatsapp text-lg"></i> Envoyer</button>
                </div>
            </div>
        </div>
    </div>

    <div id="emailModal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm px-4">
        <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-xl overflow-hidden border border-gray-100">
            <div class="bg-gray-800 px-6 py-4 flex justify-between items-center text-white">
                <h3 class="font-bold text-lg flex items-center gap-2"><i class="far fa-envelope"></i> Email Client</h3>
                <button onclick="closeModal('emailModal')" class="text-gray-400 hover:text-white transition-colors"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Destinataire</label>
                    <input type="email" id="input_email_to" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-gray-800">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Objet</label>
                    <input type="text" id="input_email_subject" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm font-bold text-gray-800 outline-none focus:ring-2 focus:ring-gray-800">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Message</label>
                    <textarea id="input_email_body" rows="8" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-gray-800"></textarea>
                </div>
                <div class="pt-2 flex justify-end gap-3">
                    <button onclick="closeModal('emailModal')" class="px-5 py-2.5 rounded-lg text-sm font-bold text-gray-600 hover:bg-gray-100">Annuler</button>
                    <button onclick="sendEmail()" class="px-6 py-2.5 rounded-lg text-sm font-bold text-white bg-gray-800 hover:bg-black shadow-md flex items-center gap-2"><i class="fas fa-paper-plane"></i> Envoyer</button>
                </div>
            </div>
        </div>
    </div>
    
    <div id="qrModal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm px-4">
        <div class="modal-content bg-white rounded-3xl shadow-2xl w-full max-w-sm overflow-hidden border border-gray-100 text-center">
            <div class="bg-blue-600 px-6 py-4 flex justify-between items-center text-white">
                <h3 class="font-bold text-lg flex items-center gap-2"><i class="fas fa-qrcode"></i> Scanner pour signer</h3>
                <button onclick="closeModal('qrModal')" class="text-blue-200 hover:text-white transition-colors"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-8 flex flex-col items-center">
                <div class="bg-white p-3 rounded-xl shadow-inner border border-gray-200 mb-4 inline-block" id="qrcode-container"></div>
                <p class="text-sm font-bold text-gray-600">Demandez au client de scanner ce code avec son téléphone pour vérifier et signer.</p>
            </div>
        </div>
    </div>

    <script src="<?= lpc_asset('/assets/js/modules/documents-bon_livraison.js') ?>" defer></script>
</body>
</html>