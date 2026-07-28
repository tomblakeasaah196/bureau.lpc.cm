<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('crm.clients.view');
$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __t('ui.base_clients_devis'); ?> | LPC ERP</title>
    
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    
    <style>
        .modal { transition: opacity 0.3s ease, visibility 0.3s ease; }
        .modal-content { transition: transform 0.3s ease; }
        .modal.hidden { opacity: 0; visibility: hidden; pointer-events: none; }
        .modal:not(.hidden) .modal-content { transform: scale(1); }
        .modal.hidden .modal-content { transform: scale(0.95); }
        /* Custom scrollbar for the modal */
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 4px; }
    </style>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
    <script>(function(){try{if(localStorage.getItem('lpc.sidebar.collapsed')==='true')document.documentElement.classList.add('lpc-collapsed');}catch(e){}})();</script>
    <link rel="stylesheet" href="/assets/css/lpc-shell.css">
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = __t('ui.base_clients_devis');
    $pageSubtitle = __t('ui.g_rez_vos_clients_b2b_b2c_et_g_n_rez_des');
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">

        <div class="lpc-toolbar">
            <button onclick="openNewClientModal()" class="bg-lpc-dark hover:bg-[#004722] text-white px-4 md:px-5 py-2.5 rounded-xl font-bold shadow-md transition-all flex items-center shrink-0">
                <svg class="w-5 h-5 md:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                <span class="hidden md:inline"><?php echo __t('ui.nouveau_client'); ?></span>
            </button>
        </div>

        <main role="main" id="main" class="lpc-page">
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-white p-5 rounded-2xl border border-lpc-border shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider"><?php echo __t('ui.total_clients_actifs'); ?></p>
                    <h3 class="text-2xl font-black text-gray-900 mt-1" id="kpi-clients">...</h3>
                </div>
                <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                </div>
            </div>
            <div class="bg-white p-5 rounded-2xl border border-lpc-border shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider"><?php echo __t('ui.dette_financi_re_ar'); ?></p>
                    <h3 class="text-2xl font-black text-red-600 mt-1" id="kpi-ar">...</h3>
                </div>
                <div class="w-12 h-12 bg-red-50 text-red-600 rounded-full flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
            </div>
            <div class="bg-white p-5 rounded-2xl border border-lpc-border shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider"><?php echo __t('ui.dette_emballages_actifs'); ?></p>
                    <h3 class="text-2xl font-black text-amber-600 mt-1" id="kpi-bottles">...</h3>
                </div>
                <div class="w-12 h-12 bg-amber-50 text-amber-600 rounded-full flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-lpc-border shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-lpc-border bg-gray-50 flex justify-between items-center">
                <h2 class="font-bold text-gray-800"><?php echo __t('ui.r_pertoire_clients'); ?></h2>
                <input type="text" id="search-client" onkeyup="filterClients()" placeholder="<?php echo __t('ui.rechercher_un_client'); ?>" class="border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none w-64 transition-all">
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-white border-b border-lpc-border text-xs uppercase text-gray-500 font-bold">
                        <tr>
                            <th class="py-4 px-6">Client / Société</th>
                            <th class="py-4 px-6">Contact & Zone</th>
                            <th class="py-4 px-6 text-right">Dette Financière</th>
                            <th class="py-4 px-6 text-center">Dette Emballages</th>
                            <th class="py-4 px-6 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="clients-tbody" class="divide-y divide-gray-100">
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="py-4 px-6">
                                <div class="font-extrabold text-gray-900 text-base">Prometal S.A.</div>
                                <div class="text-xs text-lpc-light font-bold uppercase tracking-wide">Corporate B2B</div>
                            </td>
                            <td class="py-4 px-6">
                                <div class="text-sm font-medium text-gray-800">Jean-Paul Etou</div>
                                <div class="text-xs text-gray-500">Zone Industrielle, Bassa</div>
                            </td>
                            <td class="py-4 px-6 text-right">
                                <div class="text-sm font-bold text-red-600">450,000 FCFA</div>
                                <div class="text-xs text-gray-400">Plafond: 2M FCFA</div>
                            </td>
                            <td class="py-4 px-6 text-center">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800">
                                    15 Vides
                                </span>
                            </td>
                            <td class="py-4 px-6 text-right space-x-2">
                                <button onclick="openProposalModal(1, 'Prometal S.A.')" class="bg-lpc-light hover:bg-green-600 text-white px-3 py-1.5 rounded-lg text-sm font-bold shadow-sm transition-colors inline-flex items-center">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 012 2z"></path></svg>
                                    Devis
                                </button>
                                <button class="text-gray-400 hover:text-lpc-dark transition-colors px-2 py-1.5">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    </div>

    <div id="clientModal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 backdrop-blur-sm p-4">
        <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden flex flex-col max-h-full">
            <div class="bg-lpc-dark px-6 py-4 flex justify-between items-center text-white shrink-0">
                <h3 class="font-bold text-lg"><?php echo __t('ui.ajouter_un_client'); ?></h3>
                <button onclick="closeModal('clientModal')" class="text-white/70 hover:text-white"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
            <div class="p-6 overflow-y-auto custom-scrollbar">
                <form id="form-add-client" class="space-y-4">
    <input type="hidden" id="edit_client_id" value="">
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Société / Nom complet *</label>
            <input type="text" id="new_client_name" required class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none">
        </div>
        
        <div>
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Type de Client</label>
            <select id="new_client_type" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none bg-white">
                <option value="B2B">Corporate (B2B)</option>
                <option value="B2C">Individuel (B2C)</option>
                <option value="Distributor">Distributeur</option>
            </select>
        </div>

        <div id="status-field-wrapper" class="hidden">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Statut du Client</label>
            <select id="new_client_status" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none bg-white font-bold">
                <option value="prospect">Prospect</option>
                <option value="active" class="text-green-600">Actif</option>
                <option value="inactive" class="text-red-600">Inactif</option>
            </select>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Personne de Contact</label>
            <input type="text" id="new_client_contact" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Téléphone</label>
            <input type="tel" id="new_client_phone" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none">
        </div>
        
        <div class="md:col-span-2">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Email Professionnel</label>
            <input type="email" id="new_client_email" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none">
        </div>

        <div class="md:col-span-2">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Adresse Complète</label>
            <input type="text" id="new_client_address" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none">
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Numéro Contribuable (NIU)</label>
            <input type="text" id="new_client_niu" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Registre de Commerce (RCCM)</label>
            <input type="text" id="new_client_rc" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none">
        </div>

        <div class="md:col-span-2">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Plafond de Crédit (FCFA)</label>
            <input type="number" id="new_client_credit" value="0" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none font-bold text-gray-900">
        </div>
    </div>
</form>
            </div>
            <div class="bg-gray-50 px-6 py-4 border-t border-gray-100 flex justify-end gap-3 shrink-0">
                <button onclick="closeModal('clientModal')" class="px-5 py-2 rounded-xl text-sm font-bold text-gray-600 hover:bg-gray-200 transition-colors">Annuler</button>
                <button onclick="submitClient()" class="px-5 py-2 rounded-xl text-sm font-bold text-white bg-lpc-dark hover:bg-[#004722] shadow-md transition-colors">Enregistrer le Client</button>
            </div>
        </div>
    </div>

    <div id="proposalModal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm p-2 md:p-6">
        <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-4xl h-full md:h-auto md:max-h-full overflow-hidden flex flex-col border border-gray-200">
            
            <div class="bg-white border-b border-gray-100 px-6 py-4 flex justify-between items-center shrink-0">
                <div>
                    <h3 class="font-extrabold text-xl text-gray-900 flex items-center">
                        <svg class="w-6 h-6 mr-2 text-lpc-light" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 012 2z"></path></svg>
                        Générer un Devis
                    </h3>
                    <p class="text-sm text-gray-500 font-medium mt-0.5">Pour: <span id="prop_client_name" class="font-bold text-lpc-dark">Sélectionnez un client</span></p>
                </div>
                <button onclick="closeModal('proposalModal')" class="p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 rounded-full transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>

            <div class="p-6 overflow-y-auto custom-scrollbar flex-1 bg-gray-50/50">
                <form id="form-proposal" class="space-y-8">
                    <input type="hidden" id="prop_client_id" value="">

                    <div>
                        <h4 class="text-xs font-black text-gray-400 uppercase tracking-widest mb-3 border-b border-gray-200 pb-2">1. Métadonnées & SLA Logistique</h4>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Langue du Document</label>
                                <select id="prop_lang" class="w-full bg-white border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-lpc-light outline-none font-medium">
                                    <option value="fr">Français (FR)</option>
                                    <option value="en">English (EN)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Validité du Devis</label>
                                <select id="prop_validity" class="w-full bg-white border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-lpc-light outline-none font-medium">
                                    <option value="15">15 Jours</option>
                                    <option value="30" selected>30 Jours</option>
                                    <option value="60">60 Jours</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Fréquence de Livraison</label>
                                <select id="prop_frequency" class="w-full bg-white border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-lpc-light outline-none font-medium">
                                    <option value="Hebdomadaire">Hebdomadaire (Weekly)</option>
                                    <option value="Bi-Mensuel">Bi-Mensuel (Bi-Weekly)</option>
                                    <option value="Mensuel">Mensuel (Monthly)</option>
                                    <option value="Sur Demande">Sur Demande (On-Demand)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Stock Tampon (Semaines)</label>
                                <input type="number" id="prop_buffer" value="2" min="0" class="w-full bg-white border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-lpc-light outline-none font-bold">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Conditions de Paiement</label>
                                <select id="prop_payment" class="w-full bg-white border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-lpc-light outline-none font-medium">
                                    <option value="Paiement à la Livraison">Paiement à la Livraison</option>
                                    <option value="Net 15 Jours">Net 15 Jours</option>
                                    <option value="Net 30 Jours" selected>Net 30 Jours</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Politique des Emballages</label>
                                <select id="prop_empties" class="w-full bg-white border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-lpc-light outline-none font-medium">
                                    <option value="Échange 1-pour-1 (100%)">Échange 1-pour-1 (100%)</option>
                                    <option value="Retour Minimum Exigé (90%)">Retour Minimum Exigé (90%)</option>
                                    <option value="Retour Minimum Exigé (80%)">Retour Minimum Exigé (80%)</option>
                                    <option value="Retour Minimum Exigé (70%)">Retour Minimum Exigé (70%)</option>
                                    <option value="Consigne Payée (Facturée)">Consigne Payée (Facturée)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between items-end mb-3 border-b border-gray-200 pb-2">
                            <h4 class="text-xs font-black text-gray-400 uppercase tracking-widest">2. Offre Commerciale (Lignes)</h4>
                            <button type="button" onclick="addProposalRow()" class="text-lpc-light hover:text-green-600 text-xs font-bold flex items-center uppercase tracking-wide transition-colors">
                                + Ajouter une ligne
                            </button>
                        </div>
                        
                        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm">
                            <table class="w-full text-left">
                                <thead class="bg-gray-50 border-b border-gray-200 text-xs uppercase text-gray-500 font-bold">
                                    <tr>
                                        <th class="py-3 px-4 w-1/2">Produit</th>
                                        <th class="py-3 px-4 w-24 text-center">Qté</th>
                                        <th class="py-3 px-4 text-right">Prix Unitaire</th>
                                        <th class="py-3 px-4 w-10"></th>
                                    </tr>
                                </thead>
                                <tbody id="proposal-items-tbody" class="divide-y divide-gray-100">
                                    <tr class="item-row">
                                        <td class="p-2">
                                            <select class="prod-id w-full bg-transparent text-sm font-bold text-gray-800 outline-none p-2 border border-transparent focus:border-gray-300 rounded-lg">
                                                <option value="1">Opur 20L (Recharge d'Eau)</option>
                                                <option value="2">Supermont 10L (Recharge d'Eau)</option>
                                                <option value="3">Emballage Vide 20L (Vente/Consigne)</option>
                                            </select>
                                        </td>
                                        <td class="p-2">
                                            <input type="number" class="prod-qty w-full border border-gray-300 rounded-lg p-2 text-sm text-center font-bold focus:ring-2 focus:ring-lpc-light outline-none" value="100" min="1">
                                        </td>
                                        <td class="p-2">
                                            <input type="number" class="prod-price w-full border border-gray-300 rounded-lg p-2 text-sm text-right font-bold focus:ring-2 focus:ring-lpc-light outline-none" value="1000">
                                        </td>
                                        <td class="p-2 text-center">
                                            <button type="button" onclick="this.closest('tr').remove()" class="text-red-300 hover:text-red-500 p-1">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </form>
            </div>
            
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-between items-center shrink-0">
                <div class="text-xs text-gray-500 font-medium"><i class="fas fa-lock text-gray-400 mr-1"></i> Ce devis sera sécurisé par un token unique.</div>
                <div class="flex gap-3">
                    <button onclick="closeModal('proposalModal')" class="px-5 py-2.5 rounded-xl text-sm font-bold text-gray-600 hover:bg-gray-100 transition-colors">Annuler</button>
                    <button onclick="submitProposal()" class="px-6 py-2.5 rounded-xl text-sm font-extrabold text-white bg-lpc-light hover:bg-green-600 shadow-lg shadow-lpc-light/20 transition-all flex items-center">
                        Créer & Obtenir le Lien
                        <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="/assets/js/modules/crm-clients.js" defer></script>
</body>
</html>