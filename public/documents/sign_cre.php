<?php
/**
 * PUBLIC PORTAL: Signature du CRE (Certificat de Restitution d'Emballages)
 * DESCRIPTION: Mobile interface for clients to verify quantities and sign biometrically.
 */
// Bootstrap: env + DB + hardened session cookies. Public/token-gated page — no Rbac gate here.

// This page is a DOCUMENT, not an app screen: a customer opens it from a token
// link, and it is the exact DOM html2canvas rasterises into the PDF. It must
// therefore look identical for every recipient regardless of any internal
// user's theme preference. LPC_FORCE_LIGHT tells head_assets.php to pin the
// light theme and to omit lpc-theme.css entirely — see the block at the top of
// that file for why the dark stylesheet also broke PDF generation.
if (!defined('LPC_FORCE_LIGHT')) { define('LPC_FORCE_LIGHT', true); }
require_once __DIR__ . '/../../includes/bootstrap.php';
$token = $_GET['token'] ?? '';
$error = null;
$cre = null;
$items = [];

if (empty($token)) {
    $error = "Lien invalide ou expiré.";
} else {
    try {
        $pdo = Database::getInstance()->getConnection();
        
        // 1. Fetch CRE Document Details
        $stmt = $pdo->prepare("
            SELECT d.*, c.name as client_name, s.name as site_name, u.first_name as op_name 
            FROM cre_documents d
            JOIN clients c ON d.client_id = c.id
            LEFT JOIN client_sites s ON d.site_id = s.id
            JOIN users u ON d.operator_id = u.id
            WHERE d.token = ?
        ");
        $stmt->execute([$token]);
        $cre = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cre) {
            $error = "Document introuvable.";
        } elseif ($cre['status'] === 'signed') {
            $error = "Ce document a déjà été signé et scellé.";
        } elseif ($cre['status'] === 'rejected') {
            $error = "Ce document a été annulé/refusé.";
        } else {
            // 2. Sprint-4-Batch-B: classify via products.bottle_size + has_cork,
            //    NOT strpos on name. Migration 014 backfilled these columns.
            $stmtItems = $pdo->prepare("
                SELECT ci.quantity, p.bottle_size, p.has_cork
                  FROM cre_items ci
                  JOIN products p ON ci.product_id = p.id
                 WHERE ci.cre_document_id = ? AND ci.quantity > 0
            ");
            $stmtItems->execute([$cre['id']]);
            $itemsRaw = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            foreach($itemsRaw as $i) {
                if ($i['bottle_size'] === '20L' && $i['has_cork']) {
                    $items['20L_cork'] = ($items['20L_cork'] ?? 0) + $i['quantity'];
                } elseif ($i['bottle_size'] === '20L') {
                    $items['20L_nocork'] = ($items['20L_nocork'] ?? 0) + $i['quantity'];
                } elseif ($i['bottle_size'] === '10L' && $i['has_cork']) {
                    $items['10L_cork'] = ($items['10L_cork'] ?? 0) + $i['quantity'];
                } elseif ($i['bottle_size'] === '10L') {
                    $items['10L_nocork'] = ($items['10L_nocork'] ?? 0) + $i['quantity'];
                }
            }
        }
    } catch (Exception $e) {
        $error = "Erreur de connexion au serveur.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Signature CRE | La Petite Cour</title>
    
    <link rel="stylesheet" href="<?= lpc_asset('/assets/css/tailwind.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    
    <script src="/assets/vendor/signature_pad/signature_pad.umd.min.js" integrity="sha384-SKrWXOuD3tayW46k6CjYf2mKcUXo0AUV/IVlgNBWYl/d6BIHJ4f4i8f1UCLH7E3W" crossorigin="anonymous"></script>

    
    <style>
        body { background-color: #f3f4f6; }
        .canvas-container { position: relative; width: 100%; height: 200px; border-radius: 0.75rem; border: 2px dashed #cbd5e1; background-color: #ffffff; overflow: hidden; touch-action: none; }
        canvas { width: 100%; height: 100%; }
    </style>
    <script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>"></script>
    <!-- Sprint 5: signature-canvas PNG downscaler before POST. -->
    <script src="<?= lpc_asset('/assets/js/lpc-image-compress.js') ?>"></script>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="font-sans text-gray-800 antialiased flex flex-col items-center justify-center min-h-screen p-4">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>
<main id="main" role="main" class="w-full">


    <div class="w-full max-w-md bg-white rounded-3xl shadow-xl overflow-hidden relative">
        
        <div class="bg-lpc-dark px-6 py-8 text-center">
            <div class="flex justify-center mb-4">
                <img src="/assets/img/full_logo.svg" alt="LPC Logo" class="h-20 w-auto brightness-0 invert">
            </div>
            <h1 class="text-xl font-black text-white">La Petite Cour</h1>
            <p class="text-xs font-bold text-green-200 uppercase tracking-widest mt-1">Validation de Retour d'Emballages</p>
        </div>

        <?php if ($error): ?>
        <div class="p-8 text-center space-y-4">
            <div class="text-5xl text-gray-300 mb-4">
                <i class="fas <?php echo ($cre && $cre['status'] == 'signed') ? 'fa-check-circle text-green-500' : 'fa-exclamation-triangle text-amber-500'; ?>"></i>
            </div>
            <h2 class="text-lg font-black text-gray-900"><?php echo htmlspecialchars($error); ?></h2>
            <p class="text-sm text-gray-500 font-medium">Vous pouvez fermer cette page.</p>
        </div>
        <?php else: ?>

        <div class="p-6 space-y-6" id="main_form_area">
            
            <div class="bg-gray-50 p-4 rounded-xl border border-gray-100 text-sm">
                <div class="flex justify-between border-b border-gray-200 pb-2 mb-2">
                    <span class="text-gray-500 font-bold">Réf. Document:</span>
                    <span class="font-black text-lpc-dark"><?php echo htmlspecialchars($cre['reference']); ?></span>
                </div>
                <div class="flex justify-between border-b border-gray-200 pb-2 mb-2">
                    <span class="text-gray-500 font-bold">Client:</span>
                    <span class="font-black text-gray-900 text-right"><?php echo htmlspecialchars($cre['client_name']); ?></span>
                </div>
                <?php if($cre['site_name']): ?>
                <div class="flex justify-between border-b border-gray-200 pb-2 mb-2">
                    <span class="text-gray-500 font-bold">Site/Succursale:</span>
                    <span class="font-bold text-blue-700 text-right"><?php echo htmlspecialchars($cre['site_name']); ?></span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between">
                    <span class="text-gray-500 font-bold">Agent LPC:</span>
                    <span class="font-bold text-gray-700"><?php echo htmlspecialchars($cre['op_name']); ?></span>
                </div>
            </div>

            <div>
                <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Bouteilles Restituées (Vides)</h3>
                <div class="grid grid-cols-2 gap-3">
                    <?php 
                    $total = 0;
                    foreach(['20L_cork'=>'20L (Bouchon)', '20L_nocork'=>'20L (Sans Bouchon)', '10L_cork'=>'10L (Bouchon)', '10L_nocork'=>'10L (Sans Bouchon)'] as $key => $label) {
                        $qty = $items[$key] ?? 0;
                        if ($qty > 0) {
                            $total += $qty;
                            $color = strpos($key, 'nocork') !== false ? 'text-rose-600 bg-rose-50 border-rose-100' : 'text-lpc-dark bg-green-50 border-green-100';
                            echo "<div class='border $color p-3 rounded-xl text-center shadow-sm'>
                                    <p class='text-[10px] font-bold uppercase'>$label</p>
                                    <p class='text-2xl font-black'>$qty</p>
                                  </div>";
                        }
                    }
                    ?>
                </div>
                <p class="text-center mt-3 text-sm font-black text-gray-800">Total: <?php echo $total; ?> bouteilles</p>
            </div>

            <hr class="border-gray-200">

            <div class="space-y-3">
                <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Identification du Signataire</h3>
                <input type="text" id="sig_name" placeholder="Votre Nom & Prénom *" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-lpc-dark focus:bg-white">
                <input type="text" id="sig_role" placeholder="Votre Fonction / Poste *" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-lpc-dark focus:bg-white">
                <input type="tel" id="sig_phone" placeholder="Numéro de Téléphone *" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-lpc-dark focus:bg-white">
            </div>

            <!-- Sprint 7C · Deliverable 1 · SignerOtp gate -->
            <div id="otp_gate" class="bg-indigo-50 p-5 rounded-xl border border-indigo-200 space-y-3">
                <div class="flex items-center gap-3">
                    <i class="fas fa-mobile-screen text-2xl text-indigo-600"></i>
                    <div class="flex-1">
                        <h3 class="text-[11px] font-black text-indigo-800 uppercase tracking-widest">Vérification du signataire</h3>
                        <p class="text-[10px] text-indigo-600 font-medium">Nous allons envoyer un code à 6 chiffres à votre numéro pour prouver votre identité.</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="button" id="otp_request_btn" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-xs uppercase tracking-widest py-3 rounded-lg shadow-sm transition-colors">
                        <i class="fas fa-paper-plane mr-1"></i> Envoyer le code
                    </button>
                    <button type="button" id="otp_resend_btn" class="hidden text-[10px] font-bold text-indigo-700 hover:text-indigo-900 px-2 uppercase">Renvoyer</button>
                </div>
                <div id="otp_code_row" class="hidden space-y-2">
                    <label class="text-[10px] font-black text-indigo-700 uppercase tracking-widest">Entrez le code reçu</label>
                    <div class="flex gap-2">
                        <input type="text" id="otp_code_input" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" placeholder="123456" class="flex-1 bg-white border-2 border-indigo-300 rounded-lg p-3 text-center text-xl font-black tracking-[0.5em] text-indigo-900 outline-none focus:border-indigo-600">
                        <button type="button" id="otp_verify_btn" class="bg-indigo-800 hover:bg-indigo-900 text-white font-black text-xs uppercase tracking-widest px-4 rounded-lg shadow-sm transition-colors">
                            Valider
                        </button>
                    </div>
                    <p id="otp_msg" class="text-[10px] font-bold text-indigo-700 min-h-[14px]"></p>
                </div>
                <div id="otp_ok_banner" class="hidden bg-emerald-100 text-emerald-800 rounded-lg p-2 text-[11px] font-black uppercase tracking-widest text-center">
                    <i class="fas fa-check-circle mr-1"></i> Identité confirmée
                </div>
            </div>

            <div id="sig_gate" class="hidden">
                <div class="flex justify-between items-end mb-2">
                    <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Signature Digitale *</h3>
                    <button type="button" onclick="signaturePad.clear()" class="text-[10px] font-bold text-rose-500 hover:text-rose-700 uppercase"><i class="fas fa-eraser"></i> Effacer</button>
                </div>
                <div class="canvas-container">
                    <canvas id="signature-pad"></canvas>
                </div>
                <p class="text-[9px] text-gray-400 mt-2 text-justify font-medium leading-tight">
                    En signant ce document, je certifie exactes les quantités mentionnées ci-dessus et je consens à ce que mon adresse IP et l'horodatage soient enregistrés pour garantir l'authenticité de cette transaction.
                </p>
            </div>

            <div class="space-y-3 pt-2">
                <button id="sig_submit_btn" onclick="submitSignature()" disabled class="w-full bg-lpc-dark hover:bg-green-800 text-white p-4 rounded-xl font-black text-sm shadow-xl flex items-center justify-center gap-2 active:scale-95 transition-transform disabled:opacity-40 disabled:cursor-not-allowed">
                    <i class="fas fa-check-circle text-lg"></i> Confirmer et Signer
                </button>
                <button onclick="showRejectModal()" class="w-full bg-white text-gray-500 hover:text-rose-600 p-3 rounded-xl font-bold text-xs flex items-center justify-center gap-2 transition-colors">
                    <i class="fas fa-flag"></i> Il y a une erreur de quantité
                </button>
            </div>

        </div>
        <?php endif; ?>

        <div id="loading_overlay" class="hidden absolute inset-0 bg-white/90 backdrop-blur-sm z-50 flex flex-col items-center justify-center">
            <i class="fas fa-circle-notch fa-spin text-4xl text-lpc-dark mb-4"></i>
            <p class="text-sm font-black text-gray-800">Sécurisation du document...</p>
        </div>

        <div id="reject_modal" class="hidden absolute inset-0 bg-gray-900/90 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl p-6 w-full shadow-2xl">
                <h3 class="font-black text-rose-600 text-lg mb-2"><i class="fas fa-exclamation-triangle"></i> Signaler une erreur</h3>
                <p class="text-xs text-gray-600 font-medium mb-4">Si les quantités affichées sont incorrectes, veuillez expliquer brièvement l'erreur. Ce document sera annulé et l'agent LPC devra en générer un nouveau.</p>
                <textarea id="reject_reason" rows="3" placeholder="Ex: J'ai rendu 50 bouteilles, pas 40..." class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-rose-500 mb-4"></textarea>
                <div class="flex gap-3">
                    <button onclick="document.getElementById('reject_modal').classList.add('hidden')" class="flex-1 bg-gray-100 text-gray-600 py-3 rounded-xl font-bold text-sm">Annuler</button>
                    <button onclick="submitRejection()" class="flex-1 bg-rose-600 text-white py-3 rounded-xl font-bold text-sm shadow-md">Refuser le CRE</button>
                </div>
            </div>
        </div>

    </div>

    <script type="application/json" id="lpc-page-data"><?= json_encode([
        'v1'          => $token,
        'token'       => $token,
        'csrf'        => Csrf::token(),
        'csrfField'   => '_csrf',
        'otpEnabled'  => strtolower((string) env('SIGNER_OTP_ENABLE', 'true')) !== 'false',
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/documents-sign_cre.js') ?>" defer></script>
</main>
</body>
</html>