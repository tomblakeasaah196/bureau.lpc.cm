<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('inventory.stock.audit');
require_once __DIR__ . '/../../includes/functions/document_pdf.php';
lpc_serve_document_pdf('audit');   // returns immediately if ?html=1 / no token

$token = $_GET['token'] ?? '';
$report = null;
$items = [];

if (empty($token)) {
    die("Lien invalide ou document introuvable.");
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Fetch Report Header
    $stmt = $db->prepare("
        SELECT r.*, u.first_name, u.last_name, u.email 
        FROM inventory_reports r
        JOIN users u ON r.operator_id = u.id
        WHERE r.token = ?
    ");
    $stmt->execute([$token]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        die("Document d'inventaire introuvable.");
    }

    // Fetch Report Lines
    $stmtItems = $db->prepare("
        SELECT ri.*, p.name as product_name, p.category 
        FROM inventory_report_items ri
        JOIN products p ON ri.product_id = p.id
        WHERE ri.report_id = ?
        ORDER BY p.category ASC, p.name ASC
    ");
    $stmtItems->execute([$report['id']]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Erreur DB: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Inventaire - <?php echo htmlspecialchars($report['reference']); ?></title>
    <link rel="stylesheet" href="<?= lpc_asset('/assets/css/tailwind.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    
    <script src="/assets/vendor/html2pdf/html2pdf.bundle.min.js" integrity="sha384-Yv5O+t3uE3hunW8uyrbpPW3iw6/5/Y7HitWJBLgqfMoA36NogMmy+8wWZMpn3HWc" crossorigin="anonymous"></script>

    <style>
        body { background-color: #e5e7eb; font-family: 'Inter', sans-serif; }
        .a4-container { 
            width: 210mm; 
            min-height: 297mm; 
            margin: 2rem auto; 
            background: white; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            padding: 20mm;
            position: relative;
        }
        .print-btn { position: fixed; top: 20px; right: 20px; z-index: 50; }
        @media print {
            body { background: white; margin: 0; padding: 0; }
            .a4-container { box-shadow: none; margin: 0; padding: 10mm; width: 100%; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="text-gray-900">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>
<main id="main" role="main" class="w-full">


    <button onclick="downloadPDF()" class="no-print print-btn bg-gray-900 hover:bg-black text-white px-6 py-3 rounded-xl font-black shadow-2xl flex items-center gap-2 transition-all">
        <i class="fas fa-file-pdf text-red-500"></i> Télécharger PDF
    </button>

    <div class="a4-container" id="inventory-document">
        
        <div class="flex justify-between items-start border-b-2 border-gray-800 pb-6 mb-8">
            <div class="flex items-center gap-4">
                <img src="/assets/img/full_logo.svg" alt="LPC Logo" class="h-16 w-auto" onerror="this.style.display='none'">
                <div>
                    <h1 class="text-2xl font-black tracking-tighter text-[#005A2B]">LA PETITE COUR</h1>
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-widest mt-1"><?= htmlspecialchars(__t('ui.x.production_distribution_d_eau')) ?></p>
                </div>
            </div>
            <div class="text-right">
                <h2 class="text-xl font-black text-gray-900 uppercase"><?= htmlspecialchars(__t('ui.x.rapport_d_inventaire')) ?></h2>
                <h3 class="text-sm font-bold text-gray-500 uppercase"><?= htmlspecialchars(__t('ui.x.physique_regularisation')) ?></h3>
                <p class="text-lg font-black text-red-600 mt-2"><?php echo htmlspecialchars($report['reference']); ?></p>
                <p class="text-xs font-bold text-gray-500 mt-1">À date du : <?php echo date('d/m/Y H:i', strtotime($report['created_at'])); ?></p>
            </div>
        </div>

        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 mb-8 flex justify-between items-center">
            <div>
                <h4 class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1"><?= htmlspecialchars(__t('ui.x.operateur_certifiant')) ?></h4>
                <p class="font-black text-sm text-gray-900"><i class="fas fa-user-shield text-gray-400 mr-2"></i> <?php echo htmlspecialchars($report['first_name'] . ' ' . $report['last_name']); ?></p>
            </div>
            <div class="text-right">
                <h4 class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1"><?= htmlspecialchars(__t('ui.x.statut_du_rapport')) ?></h4>
                <p class="font-black text-sm text-green-600 uppercase"><i class="fas fa-lock mr-1"></i> <?= htmlspecialchars(__t('ui.x.verrouille_applique')) ?></p>
            </div>
        </div>

        <div class="mb-10">
            <h4 class="text-xs font-black uppercase tracking-widest text-gray-800 mb-4"><?= htmlspecialchars(__t('ui.x.detail_des_ecarts_nouveau_stock_d_ouvert')) ?></h4>
            <table class="w-full text-left border-collapse border border-gray-800">
                <thead class="bg-gray-800 text-white">
                    <tr>
                        <th class="py-3 px-4 text-[10px] font-black uppercase tracking-widest border-r border-gray-700"><?= htmlspecialchars(__t('ui.x.produit')) ?></th>
                        <th class="py-3 px-4 text-[10px] font-black uppercase tracking-widest text-center border-r border-gray-700 w-32"><?= htmlspecialchars(__t('ui.x.qte_theorique')) ?></th>
                        <th class="py-3 px-4 text-[10px] font-black uppercase tracking-widest text-center border-r border-gray-700 w-32"><?= htmlspecialchars(__t('ui.x.ecart_constate')) ?></th>
                        <th class="py-3 px-4 text-[10px] font-black uppercase tracking-widest text-center bg-gray-900 w-40 text-blue-300"><?= htmlspecialchars(__t('ui.x.nouveau_solde')) ?><br><?= htmlspecialchars(__t('ui.x.physique')) ?></th>
                    </tr>
                </thead>
                <tbody class="text-sm">
                    <?php foreach($items as $item): 
                        $diffClass = $item['difference'] > 0 ? 'text-green-600' : ($item['difference'] < 0 ? 'text-red-600' : 'text-gray-400');
                        $diffSign = $item['difference'] > 0 ? '+' : '';
                    ?>
                    <tr class="border-b border-gray-200">
                        <td class="py-3 px-4 font-bold text-gray-700 border-r border-gray-800">
                            <?php echo htmlspecialchars($item['product_name']); ?>
                            <span class="block text-[9px] text-gray-400 uppercase mt-0.5"><?php echo htmlspecialchars($item['category']); ?></span>
                        </td>
                        <td class="py-3 px-4 font-bold text-center text-gray-500 border-r border-gray-800"><?php echo $item['theoretical_qty']; ?></td>
                        <td class="py-3 px-4 font-black text-center <?php echo $diffClass; ?> border-r border-gray-800">
                            <?php echo $diffSign . $item['difference']; ?>
                        </td>
                        <td class="py-3 px-4 font-black text-center text-lg bg-blue-50/50"><?php echo $item['physical_qty']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="border-2 border-gray-800 rounded-lg p-6 relative break-inside-avoid">
            <h4 class="absolute -top-3 left-4 bg-white px-2 text-[10px] font-black uppercase tracking-widest text-gray-500"><?= htmlspecialchars(__t('ui.x.certification_scellement_numerique')) ?></h4>
            
            <div class="grid grid-cols-3 gap-6 mt-2">
                <div class="col-span-2">
                    <p class="text-[10px] text-justify text-gray-500 font-medium mb-4 leading-relaxed">
                        L'opérateur certifie sur l'honneur l'exactitude des quantités physiques renseignées dans ce rapport. La validation de ce document a automatiquement écrasé les stocks théoriques précédents pour établir les nouveaux soldes d'ouverture. L'empreinte cryptographique ci-dessous garantit l'intégrité de cette déclaration.
                    </p>
                    <div class="space-y-1 bg-gray-50 p-3 rounded border border-gray-100">
                        <p class="text-[9px] font-mono text-gray-600"><strong><?= htmlspecialchars(__t('ui.x.ip_operateur')) ?></strong> <?php echo htmlspecialchars($report['ip_address']); ?></p>
                        <p class="text-[9px] font-mono text-gray-600"><strong><?= htmlspecialchars(__t('ui.x.horodatage_serveur')) ?></strong> <?php echo htmlspecialchars($report['created_at']); ?></p>
                        <p class="text-[9px] font-mono text-gray-400 break-all leading-tight mt-2"><strong>Empreinte Cryptographique Unique (SHA-256):</strong><br><?php echo htmlspecialchars($report['digital_hash']); ?></p>
                    </div>
                </div>

                <div class="text-center flex flex-col justify-end pb-2">
                    <p class="text-xs font-black uppercase text-gray-800 border-b border-gray-200 pb-2 mb-2 inline-block"><?= htmlspecialchars(__t('ui.x.approbation_systeme')) ?></p>
                    <p class="text-[10px] font-bold text-gray-500 uppercase"><?php echo htmlspecialchars($report['first_name'] . ' ' . $report['last_name']); ?></p>
                    <div class="mt-4 inline-flex items-center justify-center border-2 border-green-600 text-green-600 rounded-full w-16 h-16 transform -rotate-12 mx-auto">
                        <i class="fas fa-check-double text-2xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="absolute bottom-10 left-0 right-0 text-center">
            <p class="text-[9px] font-bold text-gray-400"><?= htmlspecialchars(__t('ui.x.genere_par_lpc_erp_ce_document_fait_offi')) ?></p>
        </div>

    </div>

    <script type="application/json" id="lpc-page-data"><?= json_encode(['v1' => $report['reference']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/inventory-print_audit.js') ?>" defer></script>
</main>
</body>
</html>