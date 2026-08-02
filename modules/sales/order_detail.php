<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('sales.orders.view');
$lang = lpc_i18n_current_lang();
$user_role = $_SESSION['user_role'];
$so_id = (int) ($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détail Commande | LPC ERP</title>

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
    $pageTitle    = 'Détail de la Commande';
    $pageSubtitle = 'Ventes & Dispatch';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">
        <main role="main" id="main" class="lpc-page lpc-page-col" data-so-id="<?= (int) $so_id ?>">

            <div class="flex items-center justify-between mb-6">
                <a href="/modules/sales/orders.php" class="flex items-center gap-2 text-sm font-black text-gray-500 hover:text-gray-900 transition-colors">
                    <i class="fas fa-arrow-left"></i> Retour à toutes les commandes
                </a>
                <div class="flex gap-3" id="detail-header-actions"></div>
            </div>

            <div id="so-loading" class="py-24 text-center text-gray-400 font-bold animate-pulse">Chargement de la commande...</div>
            <div id="so-error" class="hidden py-24 text-center text-red-500 font-bold"></div>

            <div id="so-content" class="hidden space-y-6">

                <!-- HEADER CARD -->
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-3 mb-1">
                                <h1 class="text-2xl font-black text-gray-900" id="so-reference">—</h1>
                                <span id="so-status-badge"></span>
                            </div>
                            <p class="text-sm font-bold text-gray-500" id="so-client-name">—</p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Total TTC</p>
                            <p class="text-2xl font-black text-lpc-dark" id="so-total">—</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mt-6 pt-6 border-t border-gray-100">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Date</p>
                            <p class="text-sm font-bold text-gray-800 mt-1" id="so-date">—</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Statut Paiement</p>
                            <p class="text-sm font-bold mt-1" id="so-payment-status">—</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Créé par</p>
                            <p class="text-sm font-bold text-gray-800 mt-1" id="so-created-by">—</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Remise accordée</p>
                            <p class="text-sm font-black text-blue-700 mt-1" id="so-discount">—</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Facturation</p>
                            <p class="text-sm font-bold mt-1" id="so-invoice-status">—</p>
                        </div>
                    </div>

                    <?php /* Only rendered when the order is cancelled. Achats
                             shows the reversal the same way — a cancelled
                             document that does not say why it was cancelled is
                             the thing everyone ends up asking about. */ ?>
                    <div id="so-cancelled-banner" class="hidden mt-6 pt-6 border-t border-gray-100">
                        <div class="bg-red-50 border border-red-200 rounded-xl p-4">
                            <p class="text-xs font-black text-red-800 uppercase tracking-widest mb-1"><i class="fas fa-ban mr-1"></i>Commande annulée</p>
                            <p class="text-sm font-bold text-red-900" id="so-cancel-reason">—</p>
                            <p class="text-[10px] font-bold text-red-600 mt-1" id="so-cancel-meta">—</p>
                        </div>
                    </div>
                </div>

                <!-- FULFILMENT PROGRESS -->
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-xs font-black text-gray-800 uppercase tracking-widest"><i class="fas fa-truck-loading mr-2 text-gray-400"></i>État de Livraison</h3>
                        <span id="fulfilment-state-badge"></span>
                    </div>
                    <div class="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
                        <div id="fulfilment-progress-bar" class="bg-lpc-dark h-3 rounded-full transition-all" style="width:0%"></div>
                    </div>
                    <p class="text-xs font-bold text-gray-500 mt-2" id="fulfilment-progress-label">—</p>
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
                                    <th class="py-3 px-4 text-center">Commandé</th>
                                    <th class="py-3 px-4 text-center text-blue-700 bg-blue-50/50">Expédié</th>
                                    <th class="py-3 px-4 text-center text-emerald-700 bg-emerald-50/50">Accepté</th>
                                    <th class="py-3 px-4 text-right">Tarif</th>
                                    <th class="py-3 px-4 text-right">Prix Vendu</th>
                                    <th class="py-3 px-4 text-right">Remise</th>
                                    <th class="py-3 px-6 text-right">Total Ligne</th>
                                </tr>
                            </thead>
                            <tbody id="so-lines-body" class="divide-y divide-gray-100 text-sm"></tbody>
                        </table>
                    </div>
                    <div class="px-6 py-3 bg-gray-50 border-t border-gray-100">
                        <p class="text-[10px] font-bold text-gray-500">
                            <i class="fas fa-circle-info mr-1"></i>
                            « Remise » = réduction <strong>déclarée</strong> par l'opérateur sur cette ligne.
                            Un prix vendu égal au tarif signifie qu'il n'y a eu aucune remise —
                            y compris lorsque le tarif lui-même a été modifié à la saisie
                            (voir « Changements de prix » ci-dessous).
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- DELIVERIES -->
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                        <div class="bg-gray-900 px-6 py-3">
                            <h4 class="text-xs font-black text-white uppercase tracking-widest"><i class="fas fa-truck mr-2"></i>Bons de Livraison</h4>
                        </div>
                        <div id="so-deliveries" class="p-6 space-y-3 max-h-96 overflow-y-auto"></div>
                    </div>

                    <!-- INVOICES -->
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                        <div class="bg-emerald-600 px-6 py-3">
                            <h4 class="text-xs font-black text-white uppercase tracking-widest"><i class="fas fa-file-invoice-dollar mr-2"></i>Facturation &amp; Règlements</h4>
                        </div>
                        <div id="so-invoices" class="p-6 space-y-3 max-h-96 overflow-y-auto"></div>
                    </div>
                </div>

                <!-- PRICE CHANGES -->
                <?php /* The counterpart to the remise column. These are the
                         lines where the operator declared "this is the client's
                         new price" — deliberately a separate section, because
                         they are not discounts and must never be read as
                         money given away. Migration 062. */ ?>
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden" id="so-price-changes-card">
                    <div class="bg-blue-600 px-6 py-3">
                        <h4 class="text-xs font-black text-white uppercase tracking-widest"><i class="fas fa-tags mr-2"></i>Changements de prix client déclenchés par cette commande</h4>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-gray-50 border-b border-gray-200 text-[10px] uppercase text-gray-500 font-black tracking-widest">
                                <tr>
                                    <th class="py-3 px-6">Produit</th>
                                    <th class="py-3 px-4 text-right">Ancien prix</th>
                                    <th class="py-3 px-4 text-right">Nouveau prix</th>
                                    <th class="py-3 px-4">Par</th>
                                    <th class="py-3 px-6">Le</th>
                                </tr>
                            </thead>
                            <tbody id="so-price-changes-body" class="divide-y divide-gray-100 text-sm"></tbody>
                        </table>
                    </div>
                    <div class="px-6 py-3 bg-blue-50 border-t border-blue-100">
                        <p class="text-[10px] font-bold text-blue-800">
                            <i class="fas fa-shield-halved mr-1"></i>
                            Ce n'est pas une remise. Le prix du client a changé et s'applique désormais à ses prochaines commandes.
                        </p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>window.LPC_SO_ID = <?= (int) $so_id ?>;</script>
    <script src="<?= lpc_asset('/assets/js/modules/sales-order_detail.js') ?>" defer></script>
</body>
</html>
