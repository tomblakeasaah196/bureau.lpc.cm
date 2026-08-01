<?php
/**
 * PRINTABLE DOCUMENT: Certificat de Restitution d'Emballages (CRE)
 * DESCRIPTION: Generates the legally binding A4 PDF for Empties Returns.
 */
// Bootstrap: env + DB + hardened session cookies. Public/token-gated page — no Rbac gate here.
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/document_pdf.php';
// The HTML certificate below renders by default — it is what the client opens
// from the signature confirmation link and what html2canvas + jsPDF export.
// The condensed server-side dompdf render stays available on demand via ?pdf=1.
if (($_GET['pdf'] ?? '') === '1') {
    lpc_serve_document_pdf('cre');   // exits after streaming
}
$token = $_GET['token'] ?? '';
$cre = null;
$items = [];

if (empty($token)) {
    die("Lien invalide ou document introuvable.");
}

try {
    $pdo = Database::getInstance()->getConnection();
    
    // Fetch Document and related info
    $stmt = $pdo->prepare("
        SELECT d.*, c.name as client_name, c.address as client_address, 
               s.name as site_name, s.address as site_address, 
               u.first_name as op_first_name, u.last_name as op_last_name,
               r.name as op_role
        FROM cre_documents d
        JOIN clients c ON d.client_id = c.id
        LEFT JOIN client_sites s ON d.site_id = s.id
        JOIN users u ON d.operator_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE d.token = ? AND d.status = 'signed'
    ");
    $stmt->execute([$token]);
    $cre = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cre) {
        die("Document introuvable ou non encore signé par le client.");
    }

    // Sprint-4-Batch-B: classify via bottle_size + has_cork columns instead
    // of strpos on name. Backfill in migration 014 handles legacy rows.
    $stmtItems = $pdo->prepare("
        SELECT ci.quantity, p.bottle_size, p.has_cork
          FROM cre_items ci
          JOIN products p ON ci.product_id = p.id
         WHERE ci.cre_document_id = ? AND ci.quantity > 0
    ");
    $stmtItems->execute([$cre['id']]);
    $rawItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $total_bottles = 0;
    foreach($rawItems as $i) {
        if ($i['bottle_size'] === '20L' && $i['has_cork']) {
            $items['20L_cork'] = ($items['20L_cork'] ?? 0) + $i['quantity'];
        } elseif ($i['bottle_size'] === '20L') {
            $items['20L_nocork'] = ($items['20L_nocork'] ?? 0) + $i['quantity'];
        } elseif ($i['bottle_size'] === '10L' && $i['has_cork']) {
            $items['10L_cork'] = ($items['10L_cork'] ?? 0) + $i['quantity'];
        } elseif ($i['bottle_size'] === '10L') {
            $items['10L_nocork'] = ($items['10L_nocork'] ?? 0) + $i['quantity'];
        }
        $total_bottles += $i['quantity'];
    }

} catch (Exception $e) {
    // Show the actual error to help debugging, instead of a generic message
    die("Erreur DB: " . $e->getMessage());
}

// Helper for formatting labels
$labels = [
    '20L_cork' => 'Bouteilles 20L (Avec Bouchons)',
    '20L_nocork' => 'Bouteilles 20L (SANS Bouchons)',
    '10L_cork' => 'Bouteilles 10L (Avec Bouchons)',
    '10L_nocork' => 'Bouteilles 10L (SANS Bouchons)'
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>CRE - <?php echo htmlspecialchars($cre['reference']); ?></title>
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
        .print-btn {
            position: fixed; top: 20px; right: 20px; z-index: 50;
        }
        /* The internal-signature button sits directly under the download
           button rather than beside it: this page has no nav bar, and both
           are position:fixed, so sharing .print-btn would stack them on top
           of one another. 20px + the download button's ~48px height + an
           8px gap = 76px. */
        .sign-btn {
            position: fixed; top: 76px; right: 20px; z-index: 50;
        }
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

    <?php
    // Universal internal signature. The CRE's floating-button layout has no
    // nav bar, so the affordance sits beside the download button — same
    // `print-btn` positioning class, nudged clear of it. Renders nothing
    // unless the viewer holds signatures.cre.internal.sign.
    // See docs/SIGNATURES.md.
    $sign_btn_type  = 'cre';
    $sign_btn_token = (string) ($_GET['token'] ?? '');
    $sign_btn_class = 'sign-btn shadow-2xl';
    require __DIR__ . '/../../includes/components/signature_sign_button.php';
    ?>

   <div class="a4-container" id="cre-document">
    <div class="flex justify-between items-start border-b-2 border-gray-800 pb-6 mb-8">
        <div class="flex items-center gap-4">
            <img src="/assets/img/full_logo.svg" alt="LPC Logo" class="h-16 w-auto">
            <div>
                <h1 class="text-2xl font-black tracking-tighter text-[#005A2B]">LA PETITE COUR</h1>
                <p class="text-xs font-bold text-gray-500 uppercase tracking-widest mt-1">Production & Distribution d'Eau</p>
            </div>
        </div>
            <div class="text-right">
                <h2 class="text-xl font-black text-gray-900 uppercase">Certificat de Restitution</h2>
                <h3 class="text-sm font-bold text-gray-500 uppercase">D'Emballages (CRE)</h3>
                <p class="text-lg font-black text-red-600 mt-2"><?php echo htmlspecialchars($cre['reference']); ?></p>
                <p class="text-xs font-bold text-gray-500 mt-1">Date: <?php echo date('d/m/Y', strtotime($cre['signed_at'])); ?></p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-8 mb-8">
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                <h4 class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-3 border-b border-gray-200 pb-2">Informations Client</h4>
                <p class="font-black text-sm text-gray-900"><?php echo htmlspecialchars($cre['client_name']); ?></p>
                <?php if($cre['site_name']): ?>
                    <p class="font-bold text-xs text-blue-700 mt-1"><i class="fas fa-map-marker-alt w-4"></i> Site : <?php echo htmlspecialchars($cre['site_name']); ?></p>
                <?php endif; ?>
                <p class="text-xs text-gray-600 mt-1"><i class="fas fa-user-tie w-4"></i> Signataire : <?php echo htmlspecialchars($cre['signatory_name']); ?></p>
                <p class="text-xs text-gray-600 mt-1"><i class="fas fa-phone w-4"></i> Tél : <?php echo htmlspecialchars($cre['signatory_phone']); ?></p>
            </div>

            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                <h4 class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-3 border-b border-gray-200 pb-2">Intervenant LPC</h4>
                <p class="font-black text-sm text-gray-900"><?php echo htmlspecialchars($cre['op_first_name'] . ' ' . $cre['op_last_name']); ?></p>
                <p class="text-xs text-gray-600 mt-1 uppercase font-bold tracking-wider"><?php echo htmlspecialchars($cre['op_role'] ?? 'Opérateur'); ?></p>
            </div>
        </div>

        <div class="mb-10">
            <h4 class="text-xs font-black uppercase tracking-widest text-gray-800 mb-4">Détail des Emballages Restitués</h4>
            <table class="w-full text-left border-collapse border border-gray-800">
                <thead class="bg-gray-100 border-b border-gray-800">
                    <tr>
                        <th class="py-3 px-4 text-xs font-black uppercase text-gray-800 border-r border-gray-800">Type d'Emballage Consigné</th>
                        <th class="py-3 px-4 text-xs font-black uppercase text-gray-800 text-center w-48">Quantité (Unités)</th>
                    </tr>
                </thead>
                <tbody class="text-sm">
                    <?php foreach($items as $type => $qty): ?>
                    <tr class="border-b border-gray-200">
                        <td class="py-3 px-4 font-bold text-gray-700 border-r border-gray-800"><?php echo $labels[$type]; ?></td>
                        <td class="py-3 px-4 font-black text-center text-lg"><?php echo $qty; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-800 text-white">
                    <tr>
                        <td class="py-3 px-4 font-black text-right uppercase tracking-widest">Total Restitué :</td>
                        <td class="py-3 px-4 font-black text-center text-xl"><?php echo $total_bottles; ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="border-2 border-gray-800 rounded-lg p-6 relative break-inside-avoid">
            <h4 class="absolute -top-3 left-4 bg-white px-2 text-[10px] font-black uppercase tracking-widest text-gray-500">Scellement Numérique & Signatures</h4>
            
            <p class="text-xs text-justify text-gray-500 font-medium mb-4 mt-2 leading-relaxed">
                Le signataire reconnaît avoir restitué les quantités exactes mentionnées ci-dessus. Ce document a été signé électroniquement. L'adresse IP, l'horodatage et la signature biométrique constituent une preuve légale d'approbation.
            </p>

            <?php
            // Sprint 11: this box used to read cre_documents.signatory_name /
            // .signature_image / .digital_hash / .ip_address directly. Those
            // legacy columns are no longer written — migration 055 moved
            // signatures into document_signatures — so reading them would show
            // "Signature non disponible" on every newly signed CRE.
            //
            // The shared partial reads the new table, and prints the client's
            // drawn signature, the signer's identity, the timestamp, the hash
            // fragment and a verification QR. See docs/SIGNATURES.md.
            $sig_doc = lpc_signature_doc('cre', (string) ($_GET['token'] ?? ''));
            if ($sig_doc) {
                $sig_type    = 'cre';
                $sig_doc_id  = (int) ($sig_doc['record_id'] ?? 0);
                $sig_context = 'html';
                $sig_labels  = ['internal' => 'Pour La Petite Cour', 'external' => 'Signature du client'];
                $sig_placeholders = [
                    'external' => array_filter([$sig_doc['client']['name'] ?? '']),
                ];
                require __DIR__ . '/../../includes/components/signature_block.php';
            }

            // Historical fallback: CREs signed BEFORE migration 055 have no
            // document_signatures row, only the legacy columns. Reprinting one
            // of those must still show what it showed the day it was signed.
            elseif (!empty($cre['signature_image'])) {
                ?>
                <div class="grid grid-cols-2 gap-8">
                    <div class="space-y-1">
                        <p class="text-[9px] font-mono text-gray-600"><strong>IP Client:</strong> <?= htmlspecialchars((string) $cre['ip_address']) ?></p>
                        <p class="text-[9px] font-mono text-gray-600"><strong>Horodatage:</strong> <?= htmlspecialchars((string) $cre['signed_at']) ?></p>
                        <p class="text-[9px] font-mono text-gray-400 break-all leading-tight mt-2"><strong>Empreinte Cryptographique (SHA-256):</strong><br><?= htmlspecialchars((string) $cre['digital_hash']) ?></p>
                    </div>
                    <div class="text-center">
                        <p class="text-xs font-black uppercase text-gray-800 border-b border-gray-200 pb-2 mb-2 inline-block">Signature du Client</p>
                        <p class="text-[10px] font-bold text-gray-500"><?= htmlspecialchars((string) $cre['signatory_name']) ?> (<?= htmlspecialchars((string) $cre['signatory_role']) ?>)</p>
                        <div class="mt-2 flex justify-center">
                            <img src="<?= htmlspecialchars((string) $cre['signature_image']) ?>" alt="Signature du client" class="max-h-32 object-contain">
                        </div>
                    </div>
                </div>
                <?php
            }
            ?>
        </div>

        <div class="absolute bottom-10 left-0 right-0 text-center">
            <p class="text-[9px] font-bold text-gray-400">Document généré automatiquement par LPC ERP - Ce document fait foi de bon de retour pour la mise à jour des soldes de consignes.</p>
        </div>

    </div>

    <script type="application/json" id="lpc-page-data"><?= json_encode(['v1' => $cre['reference']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/documents-print_cre.js') ?>" defer></script>
</main>
</body>
</html>