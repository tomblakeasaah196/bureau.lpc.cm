<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('inventory.procurement.view');
$lang = lpc_i18n_current_lang();
$user_role = $_SESSION['user_role'];
$po_id = (int) ($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détail Bon de Commande | LPC ERP</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    <script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>"></script>

    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    </style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

    <?php
    $pageTitle    = 'Détail du Bon de Commande';
    $pageSubtitle = 'Gestion des Achats';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">
        <main role="main" id="main" class="lpc-page lpc-page-col" data-po-id="<?= (int) $po_id ?>">

            <div class="flex items-center justify-between mb-6">
                <a href="/modules/inventory/procurement.php" class="flex items-center gap-2 text-sm font-black text-gray-500 hover:text-gray-900 transition-colors">
                    <i class="fas fa-arrow-left"></i> Retour à toutes les commandes
                </a>
                <div class="flex gap-3" id="detail-header-actions"></div>
            </div>

            <div id="po-loading" class="py-24 text-center text-gray-400 font-bold animate-pulse">Chargement du bon de commande...</div>
            <div id="po-error" class="hidden py-24 text-center text-red-500 font-bold"></div>

            <div id="po-content" class="hidden space-y-6">

                <!-- HEADER CARD -->
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-3 mb-1">
                                <h1 class="text-2xl font-black text-gray-900" id="po-reference">—</h1>
                                <span id="po-status-badge"></span>
                            </div>
                            <p class="text-sm font-bold text-gray-500" id="po-supplier-name">—</p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Net à Payer</p>
                            <p class="text-2xl font-black text-lpc-dark" id="po-total">—</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mt-6 pt-6 border-t border-gray-100">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Date d'achat</p>
                            <p class="text-sm font-bold text-gray-800 mt-1" id="po-date">—</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Statut Paiement</p>
                            <p class="text-sm font-bold mt-1" id="po-payment-status">—</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Lieu de Livraison</p>
                            <p class="text-sm font-bold text-gray-800 mt-1" id="po-delivery-place">—</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Créé par</p>
                            <p class="text-sm font-bold text-gray-800 mt-1" id="po-created-by">—</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Ristourne Gagnée</p>
                            <p class="text-sm font-black text-amber-600 mt-1" id="po-rebate-earned">—</p>
                        </div>
                    </div>
                </div>

                <!-- RECEPTION PROGRESS -->
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-xs font-black text-gray-800 uppercase tracking-widest"><i class="fas fa-truck-loading mr-2 text-gray-400"></i>État de Réception</h3>
                        <span id="reception-state-badge"></span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
                        <div id="reception-progress-bar" class="bg-lpc-dark h-3 rounded-full transition-all" style="width:0%"></div>
                    </div>
                    <p class="text-xs font-bold text-gray-500 mt-2" id="reception-progress-label">—</p>
                </div>

                <!-- LINES -->
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="bg-gray-900 px-6 py-3">
                        <h4 class="text-xs font-black text-white uppercase tracking-widest">Lignes de Commande</h4>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-gray-50 border-b border-gray-200 text-[10px] uppercase text-gray-500 font-black tracking-widest">
                                <tr>
                                    <th class="py-3 px-6">Produit</th>
                                    <th class="py-3 px-4">Catégorie</th>
                                    <th class="py-3 px-4 text-center">Commandé</th>
                                    <th class="py-3 px-4 text-center text-emerald-700 bg-emerald-50/50">Reçu</th>
                                    <th class="py-3 px-4 text-center text-amber-700 bg-amber-50/50">Reste</th>
                                    <th class="py-3 px-4 text-right">Prix Unit.</th>
                                    <th class="py-3 px-6 text-right">Total Ligne</th>
                                </tr>
                            </thead>
                            <tbody id="po-lines-body" class="divide-y divide-gray-100 text-sm"></tbody>
                        </table>
                    </div>
                </div>

                <!-- RISTOURNE PAR CATÉGORIE -->
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="bg-amber-500 px-6 py-3">
                        <h4 class="text-xs font-black text-white uppercase tracking-widest"><i class="fas fa-gift mr-2"></i>Ristourne par Catégorie (paliers)</h4>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-gray-50 border-b border-gray-200 text-[10px] uppercase text-gray-500 font-black tracking-widest">
                                <tr>
                                    <th class="py-3 px-6">Catégorie</th>
                                    <th class="py-3 px-4 text-right">Montant TTC</th>
                                    <th class="py-3 px-4 text-right">Montant HT</th>
                                    <th class="py-3 px-4 text-center">Taux Palier</th>
                                    <th class="py-3 px-4 text-center">Retenue Fiscale</th>
                                    <th class="py-3 px-6 text-right">Ristourne Gagnée</th>
                                </tr>
                            </thead>
                            <tbody id="po-category-rebates-body" class="divide-y divide-gray-100 text-sm"></tbody>
                        </table>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- RECEPTION HISTORY / TIMELINE -->
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                        <div class="bg-gray-900 px-6 py-3">
                            <h4 class="text-xs font-black text-white uppercase tracking-widest"><i class="fas fa-history mr-2"></i>Historique de Réception</h4>
                        </div>
                        <div id="reception-timeline" class="p-6 space-y-4 max-h-96 overflow-y-auto"></div>
                    </div>

                    <!-- RISTOURNE LEDGER FOR THIS ORDER -->
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                        <div class="bg-amber-500 px-6 py-3 flex items-center justify-between">
                            <h4 class="text-xs font-black text-white uppercase tracking-widest"><i class="fas fa-gift mr-2"></i>Ristourne liée à cette commande</h4>
                        </div>
                        <div id="po-rebate-ledger" class="p-6 space-y-3 max-h-96 overflow-y-auto"></div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>window.LPC_PO_ID = <?= (int) $po_id ?>;</script>
    <script src="<?= lpc_asset('/assets/js/modules/inventory-po_detail.js') ?>" defer></script>
</body>
</html>
