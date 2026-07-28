<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/document_pdf.php';
// Quotes are the one document type that defaults to the rich 4-page HTML
// proposal — that page IS the client-facing deliverable (bilingual, shareable
// by WhatsApp/email, self-service PDF export). The server-side dompdf render is
// the condensed one-page "offre commerciale", served on demand via ?pdf=1.
// Every other document type still PDFs by default; see includes/functions/document_pdf.php.
if (($_GET['pdf'] ?? '') === '1') {
    lpc_serve_document_pdf('quote');   // exits after streaming
}

// Editable content (migration 030 + modules/crm/proposal_studio.php).
// Falls back to the original hardcoded strings if the table is missing, so
// this page can never render with holes in it. See the file header for why.
require_once __DIR__ . '/../../includes/functions/proposal_template.php';

/**
 * Echo a template value, HTML-escaped, in French.
 *
 * FR is rendered server-side because it is the default language: the first
 * paint, the print stylesheet and html2canvas' PDF capture all need real text
 * in the DOM. setLang() then swaps in either language client-side from the
 * same values, delivered as JSON below.
 */
function pq(string $key): string
{
    return htmlspecialchars(lpc_proposal_text($key, 'fr'), ENT_QUOTES, 'UTF-8');
}

$proposalLogos = lpc_proposal_logos();
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
                <img src="<?= pq('brand_logo_main') ?>" alt="<?= pq('brand_company_name') ?>" class="h-10 w-auto object-contain">
                <div>
                    <h1 class="text-xl font-bold text-gray-900 leading-tight"><?= pq('brand_company_name') ?></h1>
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
                    <i class="fab fa-whatsapp text-lg"></i> <span data-i18n="btn_whatsapp"><?= pq('btn_whatsapp') ?></span>
                </button>
                <button onclick="openModal('emailModal')" class="flex items-center gap-2 px-4 py-2 bg-blue-50 border border-blue-200 text-blue-700 rounded-lg font-bold text-sm hover:bg-blue-100 transition-all">
                    <i class="far fa-envelope"></i> <span data-i18n="btn_email"><?= pq('btn_email') ?></span>
                </button>
                <button onclick="generatePDF()" id="btn-download" class="flex items-center gap-2 px-6 py-2 bg-lpc-dark text-white rounded-lg font-bold text-sm hover:bg-[#004722] transition-all shadow-md">
                    <i class="fas fa-file-pdf"></i> <span data-i18n="btn_pdf"><?= pq('btn_pdf') ?></span>
                </button>

                <a href="?token=<?= htmlspecialchars($_GET['token'] ?? '', ENT_QUOTES, 'UTF-8') ?>&amp;pdf=1"
                   target="_blank" rel="noopener"
                   id="btn-offer"
                   class="flex items-center gap-2 px-6 py-2 bg-white border border-lpc-dark text-lpc-dark rounded-lg font-bold text-sm hover:bg-gray-50 transition-all shadow-sm">
                    <i class="fas fa-file-invoice"></i> <span data-i18n="btn_offer"><?= pq('btn_offer') ?></span>
                </a>
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
                        <img src="<?= pq('brand_logo_main') ?>" alt="<?= pq('brand_company_name') ?>" class="h-24 w-auto mb-6">
                        <div class="h-2 w-32 bg-lpc-light mb-6"></div>
                        <h2 class="text-3xl font-serif text-gray-800" data-i18n="cover_title"><?= pq('cover_title') ?></h2>
                        <p class="text-xl text-gray-500 mt-2 font-light" data-i18n="cover_subtitle"><?= pq('cover_subtitle') ?></p>
                    </div>

                    <div class="bg-gray-50 border-l-4 border-lpc-dark p-8 rounded-r-2xl mb-24 shadow-sm">
                        <p class="text-sm font-bold text-gray-400 uppercase tracking-widest mb-2" data-i18n="cover_prepared_for"><?= pq('cover_prepared_for') ?></p>
                        <h3 class="text-2xl font-bold text-gray-900 mb-1" id="cov_client_name">...</h3>
                        <p class="text-gray-600 font-medium">Attn: <span id="cov_client_contact">...</span></p>
                        <p class="text-gray-500 text-sm mt-1" id="cov_client_email">...</p>
                    </div>

                    <div class="mt-auto border-t border-gray-200 pt-8 flex justify-between">
                        <div>
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1" data-i18n="meta_date"><?= pq('meta_date') ?></p>
                            <p class="font-bold text-gray-800" id="cov_date">...</p>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1" data-i18n="meta_ref"><?= pq('meta_ref') ?></p>
                            <p class="font-bold text-gray-800" id="cov_ref">...</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1" data-i18n="cover_prepared_by"><?= pq('cover_prepared_by') ?></p>
                            <p class="font-bold text-lpc-dark" id="cov_sales_rep">...</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="a4-page">
    <header class="h-24 bg-lpc-dark text-white flex items-center px-16 justify-between shrink-0">
        <span class="font-bold tracking-widest text-sm uppercase" data-i18n="header_p2"><?= pq('header_p2') ?></span>
        <span class="text-lpc-light font-bold" id="head_ref_2">...</span>
    </header>

    <div class="px-16 py-5 flex-1 flex flex-col h-full bg-white text-gray-800">
    
        <div class="mb-5">
            <h3 class="text-xl font-serif font-bold text-gray-900 mb-2" data-i18n="sec1_title"><?= pq('sec1_title') ?></h3>
            <p class="text-sm text-gray-600 leading-normal mb-2 text-justify" data-i18n="sec1_p1"><?= pq('sec1_p1') ?></p>
            <p class="text-sm text-gray-600 leading-normal text-justify" data-i18n="sec1_p2"><?= pq('sec1_p2') ?></p>
        </div>
        
        <div class="mb-4">
        <h3 class="text-xl font-serif font-bold text-gray-900 mb-2" data-i18n="sec2_title"><?= pq('sec2_title') ?></h3>
        <p class="text-sm text-gray-600 leading-normal mb-3 text-justify" data-i18n="sec2_intro"><?= pq('sec2_intro') ?></p>
        
        <div class="mb-3">
            <h4 class="mt-5 text-sm font-bold text-gray-900 uppercase tracking-wider mb-2 border-b border-gray-200 pb-1" data-i18n="sec2_services"><?= pq('sec2_services') ?></h4>
            <ul class="grid grid-cols-2 gap-x-6 gap-y-1.5 text-xs text-gray-600 pl-1">
                <li class="flex items-start"><i class="fas fa-check text-lpc-light mt-0.5 mr-2"></i><span data-i18n="sec2_l1"><?= pq('sec2_l1') ?></span></li>
                <li class="flex items-start"><i class="fas fa-check text-lpc-light mt-0.5 mr-2"></i><span data-i18n="sec2_l2"><?= pq('sec2_l2') ?></span></li>
                <li class="flex items-start"><i class="fas fa-check text-lpc-light mt-0.5 mr-2"></i><span data-i18n="sec2_l3"><?= pq('sec2_l3') ?></span></li>
                <li class="flex items-start"><i class="fas fa-check text-lpc-light mt-0.5 mr-2"></i><span data-i18n="sec2_l4"><?= pq('sec2_l4') ?></span></li>
            </ul>
        </div>

        <p class="mt-5 text-sm text-gray-600 leading-normal text-justify" data-i18n="sec2_body"><?= pq('sec2_body') ?></p>
    </div>
        
        <div class="grid grid-cols-12 gap-6 mb-4 flex-1">
            
            <div class="col-span-7 flex flex-col gap-4">
                <div>
                    <h4 class="text-sm font-bold text-gray-900 uppercase tracking-wider mb-2 border-b border-gray-200 pb-1" data-i18n="sec2_gamme_title"><?= pq('sec2_gamme_title') ?></h4>
                    <ul class="space-y-1.5 text-xs text-gray-600">
                        <li class="flex items-center"><i class="fas fa-tint text-lpc-light mr-2.5"></i><span data-i18n="sec2_gamme_1"><?= pq('sec2_gamme_1') ?></span></li>
                        <li class="flex items-center"><i class="fas fa-tint text-lpc-light mr-2.5"></i><span data-i18n="sec2_gamme_3"><?= pq('sec2_gamme_3') ?></span></li>
                        <li class="flex items-center"><i class="fas fa-tint text-lpc-light mr-2.5"></i><span data-i18n="sec2_gamme_2"><?= pq('sec2_gamme_2') ?></span></li>
                        <li class="flex items-center"><i class="fas fa-tint text-lpc-light mr-2.5"></i><span data-i18n="sec2_gamme_4"><?= pq('sec2_gamme_4') ?></span></li>
                    </ul>
                </div>
                
                <div>
                    <h4 class="text-sm font-bold text-gray-900 uppercase tracking-wider mb-2 border-b border-gray-200 pb-1" data-i18n="sec2_av_title"><?= pq('sec2_av_title') ?></h4>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-green-50/60 p-2.5 rounded-lg border border-green-100">
                            <strong class="block text-xs text-lpc-dark mb-1" data-i18n="sec2_av_1_title"><?= pq('sec2_av_1_title') ?></strong>
                            <span class="text-xs text-gray-500 leading-snug block" data-i18n="sec2_av_1_desc"><?= pq('sec2_av_1_desc') ?></span>
                        </div>
                        <div class="bg-green-50/60 p-2.5 rounded-lg border border-green-100">
                            <strong class="block text-xs text-lpc-dark mb-1" data-i18n="sec2_av_2_title"><?= pq('sec2_av_2_title') ?></strong>
                            <span class="text-xs text-gray-500 leading-snug block" data-i18n="sec2_av_2_desc"><?= pq('sec2_av_2_desc') ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-span-5 bg-gray-50 p-4 rounded-xl border border-gray-200 flex flex-col justify-center h-fit">
                <ul class="space-y-4 text-xs">
                    <li class="flex items-start">
                        <div class="bg-white p-2 rounded shadow-sm mr-3 border border-gray-100 shrink-0"><i class="fas fa-truck-fast text-lpc-dark text-sm"></i></div>
                        <div>
                            <strong class="block text-sm text-gray-900 mb-0.5" data-i18n="sec2_b1_title"><?= pq('sec2_b1_title') ?></strong>
                            <span class="text-gray-500 leading-snug block" data-i18n="sec2_b1_desc"><?= pq('sec2_b1_desc') ?></span>
                        </div>
                    </li>
                    <li class="flex items-start">
                        <div class="bg-white p-2 rounded shadow-sm mr-3 border border-gray-100 shrink-0"><i class="fas fa-boxes text-lpc-dark text-sm"></i></div>
                        <div>
                            <strong class="block text-sm text-gray-900 mb-0.5" data-i18n="sec2_b2_title"><?= pq('sec2_b2_title') ?></strong>
                            <span class="text-gray-500 leading-snug block" data-i18n="sec2_b2_desc"><?= pq('sec2_b2_desc') ?></span>
                        </div>
                    </li>
                    <li class="flex items-start">
                        <div class="bg-white p-2 rounded shadow-sm mr-3 border border-gray-100 shrink-0"><i class="fas fa-user-tie text-lpc-dark text-sm"></i></div>
                        <div>
                            <strong class="block text-sm text-gray-900 mb-0.5" data-i18n="sec2_b3_title"><?= pq('sec2_b3_title') ?></strong>
                            <span class="text-gray-500 leading-snug block" data-i18n="sec2_b3_desc"><?= pq('sec2_b3_desc') ?></span>
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <div class="mt-auto pt-4 border-t border-gray-200">
            <h4 class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-3 text-center" data-i18n="sec2_clients"><?= pq('sec2_clients') ?></h4>
            <div class="flex flex-wrap justify-center items-center gap-x-6 gap-y-3">
                <?php foreach ($proposalLogos as $logo): ?>
                    <img src="<?= htmlspecialchars($logo['src'], ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($logo['alt'], ENT_QUOTES, 'UTF-8') ?>"
                         class="h-8 w-auto object-contain">
                <?php endforeach; ?>
            </div>
        </div>
    </div>
            
    <footer class="h-16 bg-gray-50 border-t border-gray-200 flex items-center justify-between px-16 text-xs text-gray-400 shrink-0">
        <span data-i18n="brand_legal_line"><?= pq('brand_legal_line') ?></span>
        <span>Page 2 / 4</span>
    </footer>
</div>

            <div class="a4-page">
                <header class="h-24 bg-lpc-dark text-white flex items-center px-16 justify-between shrink-0">
                    <span class="font-bold tracking-widest text-sm uppercase" data-i18n="header_p3"><?= pq('header_p3') ?></span>
                    <span class="text-lpc-light font-bold" id="head_ref_3">...</span>
                </header>

                <div class="px-16 py-12 flex-1">
                    <h3 class="text-2xl font-serif font-bold text-gray-900 mb-8" data-i18n="sec3_title"><?= pq('sec3_title') ?></h3>
                    
                    <div class="rounded-xl overflow-hidden border border-gray-200 mb-6">
                        <table class="w-full text-left border-collapse pdf-table">
                            <thead class="bg-gray-100 text-xs uppercase text-gray-600 font-bold">
                                <tr>
                                    <th class="py-4 px-6 w-1/2" data-i18n="tbl_desc"><?= pq('tbl_desc') ?></th>
                                    <th class="py-4 px-6 text-center" data-i18n="tbl_qty"><?= pq('tbl_qty') ?></th>
                                    <th class="py-4 px-6 text-right" data-i18n="tbl_price"><?= pq('tbl_price') ?></th>
                                    <th class="py-4 px-6 text-right" data-i18n="tbl_total"><?= pq('tbl_total') ?></th>
                                </tr>
                            </thead>
                            <tbody id="dyn_items_table" class="bg-white text-sm text-gray-800 divide-y divide-gray-200">
                                </tbody>
                            <tfoot class="bg-gray-50">
                                <tr>
                                    <td colspan="3" class="py-5 px-6 text-right font-bold text-gray-900 uppercase text-xs tracking-wider" data-i18n="tbl_grand"><?= pq('tbl_grand') ?></td>
                                    <td class="py-5 px-6 text-right font-black text-lg text-lpc-dark" id="dyn_total_amount">
                                        ...
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <p class="text-xs text-gray-500 italic mb-10" data-i18n="tbl_note"><?= pq('tbl_note') ?></p>

                    <h4 class="text-sm font-bold text-gray-900 uppercase tracking-widest mb-4 border-b pb-2" data-i18n="sec4_title"><?= pq('sec4_title') ?></h4>
                    <div class="grid grid-cols-2 gap-x-12 gap-y-6 text-sm">
                        <div class="flex flex-col border-b border-gray-100 pb-2">
                            <span class="text-gray-500 text-xs font-bold uppercase" data-i18n="sla_freq"><?= pq('sla_freq') ?></span>
                            <span class="font-bold text-gray-900 mt-1" id="dyn_delivery_frequency">...</span>
                        </div>
                        <div class="flex flex-col border-b border-gray-100 pb-2">
                            <span class="text-gray-500 text-xs font-bold uppercase" data-i18n="sla_buffer"><?= pq('sla_buffer') ?></span>
                            <span class="font-bold text-gray-900 mt-1"><span id="dyn_buffer_stock_weeks">...</span> <span data-i18n="sla_weeks"><?= pq('sla_weeks') ?></span></span>
                        </div>
                        <div class="flex flex-col border-b border-gray-100 pb-2">
                            <span class="text-gray-500 text-xs font-bold uppercase" data-i18n="sla_pay"><?= pq('sla_pay') ?></span>
                            <span class="font-bold text-gray-900 mt-1" id="dyn_payment_terms">...</span>
                        </div>
                        <div class="flex flex-col border-b border-gray-100 pb-2">
                            <span class="text-gray-500 text-xs font-bold uppercase" data-i18n="sla_empties"><?= pq('sla_empties') ?></span>
                            <span class="font-bold text-gray-900 mt-1" id="dyn_empties_policy">...</span>
                        </div>
                        <div class="flex flex-col border-b border-gray-100 pb-2 col-span-2">
                            <span class="text-gray-500 text-xs font-bold uppercase" data-i18n="sla_validity"><?= pq('sla_validity') ?></span>
                            <span class="font-bold text-gray-900 mt-1"><span id="dyn_validity_days">...</span> <span data-i18n="meta_days"><?= pq('meta_days') ?></span></span>
                        </div>
                    </div>
                </div>

                <footer class="h-16 bg-gray-50 border-t border-gray-200 flex items-center justify-between px-16 text-xs text-gray-400 shrink-0">
                    <span data-i18n="brand_footer_email"><?= pq('brand_footer_email') ?></span>
                    <span>Page 3 / 4</span>
                </footer>
            </div>

            <div class="a4-page">
                <header class="h-24 bg-lpc-dark text-white flex items-center px-16 justify-between shrink-0">
                    <span class="font-bold tracking-widest text-sm uppercase" data-i18n="header_p4"><?= pq('header_p4') ?></span>
                    <span class="text-lpc-light font-bold" id="head_ref_4">...</span>
                </header>

                <div class="px-16 py-12 flex-1 flex flex-col">
                    <h3 class="text-2xl font-serif font-bold text-gray-900 mb-6" data-i18n="sec5_title"><?= pq('sec5_title') ?></h3>
                    
                    <div class="space-y-5 text-xs text-gray-600 leading-relaxed text-justify mb-8">
                        <p><strong class="text-gray-800" data-i18n="tc_1_title"><?= pq('tc_1_title') ?></strong> <span data-i18n="tc_1_body"><?= pq('tc_1_body') ?></span></p>
                        
                        <p><strong class="text-gray-800" data-i18n="tc_2_title"><?= pq('tc_2_title') ?></strong> <span data-i18n="tc_2_body"><?= pq('tc_2_body') ?></span></p>

                        <p><strong class="text-gray-800" data-i18n="tc_3_title"><?= pq('tc_3_title') ?></strong> <span data-i18n="tc_3_body"><?= pq('tc_3_body') ?></span></p>
                        
                        <p><strong class="text-gray-800" data-i18n="tc_4_title"><?= pq('tc_4_title') ?></strong> <span data-i18n="tc_4_body"><?= pq('tc_4_body') ?></span></p>

                        <p><strong class="text-gray-800" data-i18n="tc_5_title"><?= pq('tc_5_title') ?></strong> <span data-i18n="tc_5_body"><?= pq('tc_5_body') ?></span></p>

                        <p><strong class="text-gray-800" data-i18n="tc_6_title"><?= pq('tc_6_title') ?></strong> <span data-i18n="tc_6_body"><?= pq('tc_6_body') ?></span></p>
                    </div>

                    <div class="mt-auto grid grid-cols-2 gap-16 pt-8 border-t border-gray-200">
                        <div class="bg-gray-50 border border-gray-200 p-6 rounded-xl relative">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1" data-i18n="sig_lpc"><?= pq('sig_lpc') ?></p>
                            <p class="font-bold text-gray-900 text-sm mb-12" id="sig_role_lpc" data-i18n="sig_role_lpc"><?= pq('sig_role_lpc') ?></p>
                            
                            <div class="absolute top-12 right-6 opacity-30 pointer-events-none transform -rotate-12">
                                <h4 class="text-xl font-serif text-lpc-dark italic border-2 border-lpc-dark rounded-full px-3 py-0.5" data-i18n="sig_stamp"><?= pq('sig_stamp') ?></h4>
                            </div>
                            
                            <p class="text-[9px] text-gray-500 uppercase tracking-wider mb-1" data-i18n="sig_prep"><?= pq('sig_prep') ?></p>
                            <div class="border-b border-gray-900 w-full mb-2"></div>
                            <p class="font-bold text-lpc-dark text-sm" id="sig_sales_rep">...</p>
                            <p class="text-[10px] text-gray-400 mt-1" id="sig_date_lpc">...</p>
                        </div>

                        <div class="p-6 flex flex-col">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-auto" data-i18n="sig_client"><?= pq('sig_client') ?></p>
                            
                            <div class="mt-16">
                                <div class="border-b border-gray-300 w-full mb-2"></div>
                                <p class="font-bold text-gray-900 text-sm leading-tight" id="sig_client_name">...</p>
                                <p class="text-[10px] text-gray-500 leading-tight mt-0.5" id="sig_client_title">...</p>
                                <p class="text-[10px] text-gray-400 mt-2" data-i18n="sig_date_client"><?= pq('sig_date_client') ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <footer class="h-16 bg-lpc-dark flex items-center justify-center px-16 text-xs text-gray-300 shrink-0">
                    <p class="tracking-widest" data-i18n="brand_footer_contact"><?= pq('brand_footer_contact') ?></p>
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

    <script type="application/json" id="lpc-proposal-template">
<?php
// JSON_HEX_TAG (and friends) are load-bearing here, not decoration: these
// values are editable from the Proposal Studio, so an admin could otherwise
// close this <script> block from inside a template string. Same flag set the
// rest of the codebase uses for lpc-page-data blocks.
echo json_encode(
    lpc_proposal_dictionary(),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);
?>
    </script>
    <script src="<?= lpc_asset('/assets/js/modules/documents-quote.js') ?>" defer></script>
</body>
</html>