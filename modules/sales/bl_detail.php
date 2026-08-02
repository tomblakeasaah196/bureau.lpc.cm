<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('sales.deliveries.view');
$lang = lpc_i18n_current_lang();
$user_role = $_SESSION['user_role'];
$bl_id = (int) ($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détail Bon de Livraison | LPC ERP</title>

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
    $pageTitle    = 'Détail du Bon de Livraison';
    $pageSubtitle = 'Ventes & Dispatch';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">
        <main role="main" id="main" class="lpc-page lpc-page-col" data-bl-id="<?= (int) $bl_id ?>">

            <div class="flex items-center justify-between mb-6">
                <a href="/modules/sales/orders.php#dispatch" class="flex items-center gap-2 text-sm font-black text-gray-500 hover:text-gray-900 transition-colors">
                    <i class="fas fa-arrow-left"></i> Retour au dispatch
                </a>
                <div class="flex gap-3" id="detail-header-actions"></div>
            </div>

            <div id="bl-loading" class="py-24 text-center text-gray-400 font-bold animate-pulse">Chargement du bon de livraison...</div>
            <div id="bl-error" class="hidden py-24 text-center text-red-500 font-bold"></div>

            <div id="bl-content" class="hidden space-y-6">

                <!-- HEADER CARD -->
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-3 mb-1">
                                <h1 class="text-2xl font-black text-gray-900" id="bl-reference">—</h1>
                                <span id="bl-status-badge"></span>
                            </div>
                            <p class="text-sm font-bold text-gray-500" id="bl-client-name">—</p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Cash collecté</p>
                            <p class="text-2xl font-black text-emerald-600" id="bl-cash">—</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mt-6 pt-6 border-t border-gray-100">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Date de sortie</p>
                            <p class="text-sm font-bold text-gray-800 mt-1" id="bl-date">—</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Commande</p>
                            <p class="text-sm font-bold mt-1" id="bl-so-ref">—</p>
                        </div>
                        <?php /* Acheminement is INTERNAL. It appears here and in
                                 the dispatch list, and never on the customer's
                                 bon de livraison — the client's document states
                                 what was delivered and to whom, not how LPC
                                 moved it. Migration 061. */ ?>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Acheminement <i class="fas fa-eye-slash text-gray-300" title="Information interne — absente du BL client"></i></p>
                            <p class="text-sm font-bold text-gray-800 mt-1" id="bl-mode">—</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Créé par</p>
                            <p class="text-sm font-bold text-gray-800 mt-1" id="bl-created-by">—</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Facture</p>
                            <p class="text-sm font-bold mt-1" id="bl-invoice">—</p>
                        </div>
                    </div>

                    <div id="bl-rejection-banner" class="hidden mt-6 pt-6 border-t border-gray-100">
                        <div class="bg-red-50 border border-red-200 rounded-xl p-4">
                            <p class="text-xs font-black text-red-800 uppercase tracking-widest mb-1"><i class="fas fa-triangle-exclamation mr-1"></i>Refusé par le client</p>
                            <p class="text-sm font-bold text-red-900" id="bl-rejection-reason">—</p>
                        </div>
                    </div>
                </div>

                <!-- SIGNATURE TRAIL -->
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
                    <h3 class="text-xs font-black text-gray-800 uppercase tracking-widest mb-4"><i class="fas fa-signature mr-2 text-gray-400"></i>Chaîne de signature</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="bl-signatures"></div>
                </div>

                <!-- LINES -->
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="bg-gray-900 px-6 py-3 flex items-center justify-between">
                        <h4 class="text-xs font-black text-white uppercase tracking-widest">Lignes livrées</h4>
                        <span id="bl-short-badge" class="hidden text-[10px] font-black uppercase px-2 py-1 rounded bg-red-500 text-white"></span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-gray-50 border-b border-gray-200 text-[10px] uppercase text-gray-500 font-black tracking-widest">
                                <tr>
                                    <th class="py-3 px-6">Produit</th>
                                    <th class="py-3 px-4 text-center">Expédié</th>
                                    <th class="py-3 px-4 text-center text-emerald-700 bg-emerald-50/50">Accepté</th>
                                    <th class="py-3 px-4 text-center text-red-700 bg-red-50/50">Écart</th>
                                    <th class="py-3 px-4 text-center text-amber-700 bg-amber-50/50">Vides rendus</th>
                                    <th class="py-3 px-6 text-right">Prix Unit.</th>
                                </tr>
                            </thead>
                            <tbody id="bl-lines-body" class="divide-y divide-gray-100 text-sm"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>window.LPC_BL_ID = <?= (int) $bl_id ?>;</script>
    <script src="<?= lpc_asset('/assets/js/modules/sales-bl_detail.js') ?>" defer></script>
</body>
</html>
