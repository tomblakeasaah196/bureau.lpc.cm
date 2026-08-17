<?php
/**
 * PUBLIC PORTAL: Digital Proof of Delivery (PoD)
 * -----------------------------------------------------------------------------
 * DESCRIPTION: Mobile interface for clients to verify quantities, add
 * observations, and sign a delivery on their phone. Full spec:
 * docs/SIGNATURES.md.
 *
 * WHAT CHANGED FROM THE SPRINT 7C VERSION
 *   1. OTP gate removed — customers sign directly, exactly like the CRE
 *      flow now does.
 *   2. Driver pad removed. The internal (LPC-side) signature now happens
 *      from inside the ERP as a hash-only attestation, same shape as the
 *      devis one-pager. See DocumentSignature::signInternal() + the
 *      "Signer côté LPC" button on the operator delivery detail page.
 *   3. Wire target is the unified /api/v1/signatures_controller.php with
 *      action=sign_external. The rejection flow still lives on the
 *      sales_controller (it flips business state, not a signature).
 *
 *   For BL the customer also confirms received quantities + empties returned
 *   + amount paid to driver + observations. Those extras ride on the same
 *   signature POST via the extraSignPayload() hook defined near the bottom.
 * -----------------------------------------------------------------------------
 */

if (!defined('LPC_FORCE_LIGHT')) { define('LPC_FORCE_LIGHT', true); }
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
                        /*
                         * SAFETY DEFAULT (bug fix: BL-2608-CB78 case) — never
                         * pre-fill the "vides rendus" field from delivery_items,
                         * even if a driver typed a value earlier. On a first
                         * delivery there are no empties to return, and the old
                         * default silently zeroed out the empties ledger by
                         * booking total_in = total_out at signature time. The
                         * customer must deliberately type a non-zero number for
                         * empties to be considered returned.
                         */
                        $default_empty = 0;
                    ?>
                        <div class="item-row bg-gray-50 border border-gray-200 p-4 rounded-xl overflow-hidden" data-id="<?php echo $i['item_id']; ?>">
                            <p class="font-black text-sm text-gray-800 mb-3"><?php echo htmlspecialchars($i['product_name']); ?></p>

                            <div class="flex items-center justify-between mb-3">
                                <label class="text-xs font-bold text-gray-600">Qté Reçue <span class="text-gray-400 font-normal">(Expédié: <?php echo $i['original_qty']; ?>)</span></label>
                                <input type="number" min="0" max="<?php echo $i['original_qty']; ?>" class="input-accepted w-20 text-center font-black text-lg bg-white border border-blue-300 rounded-lg py-1 focus:ring-2 focus:ring-blue-500 outline-none text-blue-700 shadow-sm" value="<?php echo $default_qty; ?>">
                            </div>

                            <?php if($i['empty_name']): ?>
                            <?php /*
                                 Amber banner + unmissable copy — a signed BL that
                                 books returned_empty_qty > 0 releases the client
                                 from a consigne, so the customer must SEE this
                                 field and consciously type a number. The old
                                 subtle green input line lost customers to the
                                 default and cleared the ledger silently.
                            */ ?>
                            <div class="mt-3 -mx-4 -mb-4 px-4 py-3 bg-amber-50 border-t-2 border-amber-300">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex-1 min-w-0">
                                        <label class="block text-sm font-black text-amber-900 uppercase tracking-wide">
                                            <i class="fas fa-recycle mr-1"></i>Vides rendus MAINTENANT
                                        </label>
                                        <p class="text-[11px] font-bold text-amber-700 mt-0.5 leading-tight">
                                            Combien d'emballages vides le client remet-il au chauffeur à l'instant&nbsp;? <span class="underline">0 par défaut.</span>
                                            Ne remplir que si des vides changent physiquement de mains maintenant.
                                        </p>
                                    </div>
                                    <input type="number" min="0" inputmode="numeric"
                                           class="input-empties w-24 text-center font-black text-xl bg-white border-2 border-amber-400 rounded-lg py-2 focus:ring-2 focus:ring-amber-500 outline-none text-amber-900 shadow-sm shrink-0"
                                           value="<?php echo $default_empty; ?>">
                                </div>
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
                <input type="text" id="sig_name"  placeholder="Votre Nom & Prénom *"    required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-blue-500 focus:bg-white transition-colors">
                <input type="text" id="sig_role"  placeholder="Votre Fonction / Poste *" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-blue-500 focus:bg-white transition-colors">
                <input type="tel"  id="sig_phone" value="<?php echo htmlspecialchars($delivery['client_phone']); ?>" placeholder="Numéro de Téléphone *" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-blue-500 focus:bg-white transition-colors">
            </div>

            <div>
                <div class="flex justify-between items-end mb-2">
                    <div>
                        <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Signature Digitale *</h3>
                        <p class="text-[9px] text-gray-400 font-medium">Je certifie l'exactitude des informations ci-dessus.</p>
                    </div>
                    <button type="button" id="sig_clear_btn" class="text-[10px] font-bold text-rose-500 hover:text-rose-700 uppercase"><i class="fas fa-eraser"></i> Effacer</button>
                </div>
                <div class="canvas-container border-blue-300">
                    <canvas id="signature-pad"></canvas>
                </div>
            </div>

            <div class="pt-4">
                <button id="sig_submit_btn" type="button" disabled class="w-full bg-gray-900 hover:bg-black text-white p-4 rounded-xl font-black text-sm shadow-xl flex items-center justify-center gap-2 active:scale-95 transition-transform disabled:opacity-40 disabled:cursor-not-allowed">
                    <i class="fas fa-lock text-lg"></i> Clôturer &amp; Sécuriser le BL
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
        'token'             => $token,
        'docType'           => 'bl',
        'csrf'              => Csrf::token(),
        'csrfField'         => '_csrf',
        'signedDocumentUrl' => '/bon_livraison.php?token=' . $token . '&autodownload=1',
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
    <!--
      BL-specific extras piggyback on the same signature POST. The universal
      module looks for window.extraSignPayload() and merges its return value
      into the outgoing body. Anything doc-type-specific lives here, not in
      the shared module.
    -->
    <script>
    window.extraSignPayload = function () {
        var items = [];
        document.querySelectorAll('#items_container .item-row').forEach(function (row) {
            var acc = row.querySelector('.input-accepted');
            var emp = row.querySelector('.input-empties');
            items.push({
                item_id:            row.dataset.id,
                accepted_qty:       acc ? Number(acc.value || 0) : 0,
                returned_empty_qty: emp ? Number(emp.value || 0) : 0
            });
        });
        var pay = document.getElementById('input_payment');
        var obs = document.getElementById('input_observation');
        return {
            items:              items,
            payment_collected:  pay ? Number(pay.value || 0) : 0,
            observations:       obs ? (obs.value || '').trim() : ''
        };
    };
    // Live character count on the observations field.
    (function () {
        var obs = document.getElementById('input_observation');
        var out = document.getElementById('char_count');
        if (!obs || !out) return;
        var update = function () { out.textContent = (obs.value.length || 0) + ' / 150'; };
        obs.addEventListener('input', update);
        update();
    })();
    </script>
<script src="<?= lpc_asset('/assets/js/modules/signature-universal.js') ?>" defer></script>
</main>
</body>
</html>
