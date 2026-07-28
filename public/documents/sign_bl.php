<?php
/**
 * PUBLIC PORTAL: Digital Proof of Delivery (PoD)
 * DESCRIPTION: Mobile interface for clients to review quantities, add observations, and sign biometrically alongside the driver.
 */
// Bootstrap: env + DB + hardened session cookies. Public/token-gated page — no Rbac gate here.
require_once __DIR__ . '/../../includes/bootstrap.php';
$token = $_GET['token'] ?? '';
$error = null;
$delivery = null;
$items = [];

if (empty($token)) {
    $error = "Lien de livraison invalide.";
} else {
    try {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            SELECT d.id, d.reference, d.payment_collected, d.status, c.name as client_name, c.phone as client_phone 
            FROM deliveries d
            JOIN clients c ON d.client_id = c.id
            WHERE d.token = ?
        ");
        $stmt->execute([$token]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) {
            $error = "Document introuvable.";
        } elseif ($delivery['status'] === 'completed') {
            $error = "Cette livraison a déjà été signée et clôturée.";
        } else {
            // Fetch items
            $stmtItems = $db->prepare("
                SELECT di.id as item_id, di.quantity as original_qty, di.delivered_quantity, di.returned_empty_qty, di.unit_price, 
                       p.name as product_name, pe.name as empty_name
                FROM delivery_items di 
                JOIN products p ON di.product_id = p.id 
                LEFT JOIN products pe ON p.linked_empty_id = pe.id
                WHERE di.delivery_id = ?
            ");
            $stmtItems->execute([$delivery['id']]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
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
    <title>Signature Bon de Livraison | La Petite Cour</title>
    <link rel="stylesheet" href="<?= lpc_asset('/assets/css/tailwind.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    <script src="/assets/vendor/signature_pad/signature_pad.umd.min.js" integrity="sha384-SKrWXOuD3tayW46k6CjYf2mKcUXo0AUV/IVlgNBWYl/d6BIHJ4f4i8f1UCLH7E3W" crossorigin="anonymous"></script>

    
    <style>
        body { background-color: #f3f4f6; }
        .canvas-container { position: relative; width: 100%; height: 180px; border-radius: 0.75rem; border: 2px dashed #cbd5e1; background-color: #ffffff; overflow: hidden; touch-action: none; }
        canvas { width: 100%; height: 100%; }
        input[type="number"]::-webkit-inner-spin-button, 
        input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    </style>
    <script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>"></script>
    <!-- Sprint 5: signature-canvas PNG downscaler before POST. -->
    <script src="<?= lpc_asset('/assets/js/lpc-image-compress.js') ?>"></script>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="font-sans text-gray-800 antialiased flex flex-col items-center justify-center min-h-screen p-4">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>
<main id="main" role="main" class="w-full">


    <div class="w-full max-w-lg bg-white rounded-3xl shadow-xl overflow-hidden relative">
        <div class="bg-blue-600 px-6 py-6 text-center">
            <div class="flex justify-center mb-3">
                <img src="/assets/img/full_logo.svg" alt="LPC Logo" class="h-16 w-auto brightness-0 invert" onerror="this.outerHTML='<h1 class=\\'text-3xl font-black text-white\\'>LPC</h1>'">
            </div>
            <h1 class="text-lg font-black text-white">La Petite Cour</h1>
            <p class="text-[10px] font-bold text-blue-200 uppercase tracking-widest mt-1">Validation de Livraison Interactive</p>
        </div>

        <?php if ($error): ?>
        <div class="p-8 text-center space-y-4">
            <div class="text-5xl text-gray-300 mb-4">
                <i class="fas <?php echo ($delivery && $delivery['status'] == 'completed') ? 'fa-check-circle text-green-500' : 'fa-exclamation-triangle text-amber-500'; ?>"></i>
            </div>
            <h2 class="text-lg font-black text-gray-900"><?php echo htmlspecialchars($error); ?></h2>
            <p class="text-sm text-gray-500 font-medium">Vous pouvez fermer cette page.</p>
        </div>
        <?php else: ?>

        <div class="p-6 space-y-6" id="main_form_area">
            
            <div class="bg-blue-50 p-4 rounded-xl border border-blue-100 text-sm">
                <div class="flex justify-between border-b border-blue-200 pb-2 mb-2">
                    <span class="text-blue-700 font-bold">Réf. BL:</span>
                    <span class="font-black text-blue-900"><?php echo htmlspecialchars($delivery['reference']); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-blue-700 font-bold">Client:</span>
                    <span class="font-black text-gray-900 text-right"><?php echo htmlspecialchars($delivery['client_name']); ?></span>
                </div>
            </div>

            <div>
                <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 border-b border-gray-200 pb-1">Vérification des Quantités</h3>
                <p class="text-xs text-gray-500 mb-4 font-medium">Veuillez ajuster les quantités réellement reçues et les emballages rendus.</p>
                
                <div class="space-y-4" id="items_container">
                    <?php foreach($items as $i): 
                        $default_qty = $i['delivered_quantity'] !== null ? $i['delivered_quantity'] : $i['original_qty'];
                        $default_empty = $i['returned_empty_qty'] !== null ? $i['returned_empty_qty'] : 0;
                    ?>
                        <div class="item-row bg-gray-50 border border-gray-200 p-4 rounded-xl" data-id="<?php echo $i['item_id']; ?>">
                            <p class="font-black text-sm text-gray-800 mb-3"><?php echo htmlspecialchars($i['product_name']); ?></p>
                            
                            <div class="flex items-center justify-between mb-3">
                                <label class="text-xs font-bold text-gray-600">Qté Reçue <span class="text-gray-400 font-normal">(Expédié: <?php echo $i['original_qty']; ?>)</span></label>
                                <input type="number" min="0" max="<?php echo $i['original_qty']; ?>" class="input-accepted w-20 text-center font-black text-lg bg-white border border-blue-300 rounded-lg py-1 focus:ring-2 focus:ring-blue-500 outline-none text-blue-700 shadow-sm" value="<?php echo $default_qty; ?>">
                            </div>

                            <?php if($i['empty_name']): ?>
                            <div class="flex items-center justify-between pt-3 border-t border-gray-200">
                                <label class="text-xs font-bold text-gray-600">Vides Rendus <span class="text-amber-500 text-[10px] uppercase block">Emballages remis au chauffeur</span></label>
                                <input type="number" min="0" class="input-empties w-20 text-center font-black text-lg bg-white border border-emerald-300 rounded-lg py-1 focus:ring-2 focus:ring-emerald-500 outline-none text-emerald-700 shadow-sm" value="<?php echo $default_empty; ?>">
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-emerald-50 p-5 rounded-xl border border-emerald-200">
                <label class="block text-[10px] font-black text-emerald-700 uppercase tracking-widest mb-2 text-center">Montant Versé au Chauffeur (FCFA)</label>
                <div class="relative max-w-xs mx-auto">
                    <input type="number" id="input_payment" class="w-full bg-white border-2 border-emerald-400 rounded-xl p-3 text-2xl font-black text-center text-emerald-900 outline-none focus:border-emerald-600 shadow-inner" value="<?php echo (float)$delivery['payment_collected'] > 0 ? (float)$delivery['payment_collected'] : ''; ?>" placeholder="0">
                </div>
                <p class="text-[9px] font-bold text-emerald-600/70 mt-2 text-center">Laissez à 0 si paiement différé ou par virement bancaire.</p>
            </div>

            <div class="bg-gray-50 p-4 rounded-xl border border-gray-200">
                <div class="flex justify-between items-center mb-2">
                    <label class="block text-[10px] font-black text-gray-600 uppercase tracking-widest">Réserves & Observations</label>
                    <span id="char_count" class="text-[9px] font-bold text-gray-400">0 / 150</span>
                </div>
                <textarea id="input_observation" maxlength="150" rows="3" placeholder="Signalez toute anomalie (colis abîmé, manquant...)" class="w-full bg-white border border-gray-300 rounded-xl p-3 text-sm font-medium outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 resize-none transition-colors"></textarea>
            </div>

            <hr class="border-gray-200">

            <div class="space-y-3">
                <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Identification du Réceptionnaire</h3>
                <input type="text" id="sig_name" placeholder="Votre Nom & Prénom *" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-blue-500 focus:bg-white transition-colors">
                <input type="text" id="sig_role" placeholder="Votre Fonction / Poste *" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-blue-500 focus:bg-white transition-colors">
                <input type="tel" id="sig_phone" value="<?php echo htmlspecialchars($delivery['client_phone']); ?>" placeholder="Numéro de Téléphone *" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-blue-500 focus:bg-white transition-colors">
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

            <div id="sig_gate" class="space-y-6 pt-2 hidden">
                <div class="bg-blue-50/50 p-3 rounded-xl border border-blue-100">
                    <div class="flex justify-between items-end mb-2">
                        <div>
                            <h3 class="text-[10px] font-black text-blue-800 uppercase tracking-widest">Visa Chauffeur *</h3>
                            <p class="text-[9px] text-blue-600 font-medium">À signer par le livreur LPC</p>
                        </div>
                        <button type="button" onclick="driverPad.clear()" class="text-[10px] font-bold text-rose-500 hover:text-rose-700 uppercase"><i class="fas fa-eraser"></i> Effacer</button>
                    </div>
                    <div class="canvas-container border-blue-300">
                        <canvas id="driver-pad"></canvas>
                    </div>
                </div>

                <div class="bg-amber-50/50 p-3 rounded-xl border border-amber-100">
                    <div class="flex justify-between items-end mb-2">
                        <div>
                            <h3 class="text-[10px] font-black text-amber-800 uppercase tracking-widest">Visa Client *</h3>
                            <p class="text-[9px] text-amber-600 font-medium">Je certifie l'exactitude des informations ci-dessus.</p>
                        </div>
                        <button type="button" onclick="clientPad.clear()" class="text-[10px] font-bold text-rose-500 hover:text-rose-700 uppercase"><i class="fas fa-eraser"></i> Effacer</button>
                    </div>
                    <div class="canvas-container border-amber-300">
                        <canvas id="client-pad"></canvas>
                    </div>
                </div>
            </div>

            <div class="pt-4">
                <button id="sig_submit_btn" onclick="submitDelivery()" disabled class="w-full bg-gray-900 hover:bg-black text-white p-4 rounded-xl font-black text-sm shadow-xl flex items-center justify-center gap-2 active:scale-95 transition-transform disabled:opacity-40 disabled:cursor-not-allowed">
                    <i class="fas fa-lock text-lg"></i> Clôturer & Sécuriser le BL
                </button>
            </div>

        </div>
        <?php endif; ?>

        <div id="loading_overlay" class="hidden absolute inset-0 bg-white/90 backdrop-blur-sm z-50 flex flex-col items-center justify-center">
            <i class="fas fa-circle-notch fa-spin text-4xl text-blue-600 mb-4"></i>
            <p class="text-sm font-black text-gray-800">Sécurisation cryptographique en cours...</p>
        </div>

    </div>

    <script type="application/json" id="lpc-page-data"><?= json_encode([
        'v1'          => $token,
        'token'       => $token,
        'csrf'        => Csrf::token(),
        'csrfField'   => '_csrf',
        'otpEnabled'  => strtolower((string) env('SIGNER_OTP_ENABLE', 'true')) !== 'false',
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/documents-sign_bl.js') ?>" defer></script>
</main>
</body>
</html>