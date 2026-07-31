<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('inventory.procurement.view');
$lang = lpc_i18n_current_lang();
$user_role = $_SESSION['user_role'];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration des Ristournes | LPC ERP</title>

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
    // Deliberately no sidebar nav entry for this page (per product decision) —
    // it's reached only via the "Configurer les Ristournes" button on the
    // Achats & Dépenses page. admin_sidebar.php still renders the normal
    // sidebar (with nothing marked active here), topbar stays consistent.
    $pageTitle    = 'Configuration des Ristournes';
    $pageSubtitle = 'Paliers par fournisseur et par catégorie';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">
        <main role="main" id="main" class="lpc-page lpc-page-col">

            <div class="flex items-center justify-between mb-6">
                <a href="/modules/inventory/procurement.php" class="flex items-center gap-2 text-sm font-black text-gray-500 hover:text-gray-900 transition-colors">
                    <i class="fas fa-arrow-left"></i> Retour aux Achats &amp; Dépenses
                </a>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- LEFT: supplier picker + shared tax settings -->
                <div class="lg:col-span-1 space-y-6">
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Fournisseur</label>
                        <select id="supplier_select" onchange="onSupplierChange(this.value)" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm font-bold text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light">
                            <option value="">Sélectionner un fournisseur...</option>
                        </select>
                        <p class="text-xs text-gray-400 font-bold mt-3">N'importe quel fournisseur peut recevoir une configuration de ristourne par catégorie — ce n'est pas réservé à un fournisseur en particulier.</p>
                    </div>

                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
                        <h3 class="text-xs font-black text-gray-800 uppercase tracking-widest mb-1"><i class="fas fa-percentage mr-2 text-gray-400"></i>Taux Fiscaux Partagés</h3>
                        <p class="text-xs text-gray-400 font-bold mb-4">Utilisés pour tous les fournisseurs : convertir le prix TTC en HT, et retenir la part fiscale sur la ristourne calculée.</p>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">TVA (%)</label>
                                <input type="number" id="tax_vat_rate" step="0.01" min="0" max="100" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm font-black text-right outline-none focus:ring-2 focus:ring-lpc-light">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Précompte (%)</label>
                                <input type="number" id="tax_precompte_rate" step="0.01" min="0" max="100" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm font-black text-right outline-none focus:ring-2 focus:ring-lpc-light">
                            </div>
                            <button onclick="saveTaxSettings()" class="w-full py-2.5 bg-gray-900 hover:bg-black text-white rounded-lg font-bold text-sm shadow-sm"><i class="fas fa-save mr-2"></i>Enregistrer les taux</button>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: per-category ladders for the selected supplier -->
                <div class="lg:col-span-2">
                    <div id="no-supplier-placeholder" class="bg-white rounded-2xl border border-dashed border-gray-300 p-16 text-center text-gray-400 font-bold">
                        <i class="fas fa-arrow-left mr-2"></i> Choisissez un fournisseur pour configurer ses paliers de ristourne.
                    </div>
                    <div id="categories-container" class="hidden space-y-6"></div>
                </div>
            </div>

        </main>
    </div>

    <script src="<?= lpc_asset('/assets/js/modules/inventory-rebate_config.js') ?>" defer></script>
</body>
</html>
