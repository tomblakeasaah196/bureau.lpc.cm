<?php
/**
 * PUBLIC PORTAL: Generic counterparty signature page
 * -----------------------------------------------------------------------------
 * URL: /sign_document.php?type={facture|bon_commande|payslip}&token=…
 *
 * DESCRIPTION: One page that lets the counterparty sign any document whose
 * summary is "a party, some lines, a total". Full spec: docs/SIGNATURES.md.
 *
 * WHY GENERIC, WHEN CRE / BL / DEVIS EACH HAVE THEIR OWN PAGE
 *   Those three genuinely differ: the CRE shows a bottle-type grid, the BL
 *   lets the customer amend delivered quantities and cash paid BEFORE
 *   signing, and the devis carries validity dates and a link to the full
 *   proposal. A facture, a bon de commande and a bulletin de paie do not —
 *   they are read-only summaries with a total at the bottom. Three more
 *   near-identical files would have been three more places to fix the next
 *   bug, so they share this one.
 *
 *   The rule for adding a type: use this page unless the counterparty needs
 *   to EDIT something before signing, or the document has a shape a line
 *   table cannot express. Then, and only then, write a bespoke page.
 *
 * WHAT SIGNING DOES
 *   Records an external signature row and nothing else. For these three
 *   types the signature IS the whole effect — an acknowledgement of receipt
 *   or of agreement — so lpc_signature_side_effects_dispatch() no-ops. If a
 *   type later needs a status change, add a handler there, not here.
 * -----------------------------------------------------------------------------
 */

if (!defined('LPC_FORCE_LIGHT')) { define('LPC_FORCE_LIGHT', true); }
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/document_pdf.php';
require_once __DIR__ . '/../../includes/classes/DocumentSignature.php';

$type  = strtolower(trim((string) ($_GET['type'] ?? '')));
$token = (string) ($_GET['token'] ?? '');
$error = null;
$doc   = null;

/**
 * Per-type presentation. Kept in one array rather than scattered ifs so
 * adding a type is a single edit with everything about it in one place.
 */
$PRESENTATION = [
    'facture' => [
        'title'      => 'Accusé de réception',
        'subtitle'   => 'Facture',
        'party'      => 'Client',
        'consent'    => "Je reconnais avoir reçu la facture ci-dessus et en accepter le montant. Je consens à ce que mon adresse IP et l'horodatage soient enregistrés pour garantir l'authenticité de cet accusé.",
        'cta'        => 'Accuser réception & Signer',
        'showLines'  => true,
    ],
    'bon_commande' => [
        'title'      => 'Confirmation de commande',
        'subtitle'   => 'Bon de commande',
        'party'      => 'Fournisseur',
        'consent'    => "Je confirme accepter cette commande aux quantités et prix indiqués ci-dessus. Je consens à ce que mon adresse IP et l'horodatage soient enregistrés pour garantir l'authenticité de cette confirmation.",
        'cta'        => 'Confirmer la commande & Signer',
        'showLines'  => true,
    ],
    'payslip' => [
        'title'      => 'Accusé de réception',
        'subtitle'   => 'Bulletin de paie',
        'party'      => 'Salarié',
        'consent'    => "Je reconnais avoir reçu le bulletin de paie ci-dessus. Cette signature vaut accusé de réception et ne vaut pas renonciation à mes droits. Je consens à ce que mon adresse IP et l'horodatage soient enregistrés.",
        'cta'        => 'Accuser réception & Signer',
        'showLines'  => false,
    ],
];

if ($token === '' || $type === '') {
    $error = "Lien invalide ou expiré.";
} elseif (!isset($PRESENTATION[$type])) {
    // Deliberately not "unknown type": an anonymous visitor gets the same
    // answer for a malformed type as for one this page does not serve.
    $error = "Lien invalide ou expiré.";
} else {
    try {
        $doc = lpc_signature_doc($type, $token);
        if (!$doc) {
            $error = "Document introuvable.";
        } else {
            $already = DocumentSignature::getActiveByParty(
                $type, (int) ($doc['record_id'] ?? 0), $doc, 'external'
            );
            if ($already) $error = "Ce document a déjà été signé et scellé.";
        }
    } catch (Throwable $e) {
        error_log('sign_document.php: ' . $e->getMessage());
        $error = "Erreur de connexion au serveur.";
    }
}

$P = $PRESENTATION[$type] ?? null;
$e = static function ($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $e($P['subtitle'] ?? 'Signature') ?> | La Petite Cour</title>

    <link rel="stylesheet" href="<?= lpc_asset('/assets/css/tailwind.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">
    <script src="/assets/vendor/signature_pad/signature_pad.umd.min.js" integrity="sha384-SKrWXOuD3tayW46k6CjYf2mKcUXo0AUV/IVlgNBWYl/d6BIHJ4f4i8f1UCLH7E3W" crossorigin="anonymous"></script>

    <style>
        body { background-color: #f3f4f6; }
        .canvas-container { position: relative; width: 100%; height: 190px; border-radius: 0.75rem; border: 2px dashed #cbd5e1; background-color: #ffffff; overflow: hidden; touch-action: none; }
        canvas { width: 100%; height: 100%; }
    </style>
    <script src="<?= lpc_asset('/assets/js/lpc-dom.js') ?>"></script>
    <script src="<?= lpc_asset('/assets/js/lpc-image-compress.js') ?>"></script>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="font-sans text-gray-800 antialiased flex flex-col items-center justify-center min-h-screen p-4">
<a href="#main" class="lpc-skip-link"><?= $e(__t('ui.a11y.skip_to_content')) ?></a>
<main id="main" role="main" class="w-full">

    <div class="w-full max-w-md mx-auto bg-white rounded-3xl shadow-xl overflow-hidden relative">

        <div class="bg-lpc-dark px-6 py-8 text-center">
            <div class="flex justify-center mb-4">
                <img src="/assets/img/full_logo.svg" alt="LPC Logo" class="h-20 w-auto brightness-0 invert">
            </div>
            <h1 class="text-xl font-black text-white">La Petite Cour</h1>
            <p class="text-xs font-bold text-green-200 uppercase tracking-widest mt-1">
                <?= $e($P['title'] ?? 'Signature') ?>
            </p>
        </div>

        <?php if ($error): ?>
        <div class="p-8 text-center space-y-4">
            <div class="text-5xl text-gray-300 mb-4">
                <i class="fas fa-exclamation-triangle text-amber-500"></i>
            </div>
            <h2 class="text-lg font-black text-gray-900"><?= $e($error) ?></h2>
            <p class="text-sm text-gray-500 font-medium">Vous pouvez fermer cette page.</p>
        </div>
        <?php else: ?>

        <div class="p-6 space-y-6" id="main_form_area">

            <div class="bg-gray-50 p-4 rounded-xl border border-gray-100 text-sm">
                <div class="flex justify-between border-b border-gray-200 pb-2 mb-2">
                    <span class="text-gray-500 font-bold"><?= $e($P['subtitle']) ?> N°:</span>
                    <span class="font-black text-lpc-dark"><?= $e($doc['reference'] ?? '') ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500 font-bold"><?= $e($P['party']) ?>:</span>
                    <span class="font-black text-gray-900 text-right">
                        <?= $e($doc['client']['name'] ?? ($doc['employee']['name'] ?? '')) ?>
                    </span>
                </div>
            </div>

            <?php if ($P['showLines'] && !empty($doc['items'])): ?>
            <div>
                <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Détail</h3>
                <div class="border border-gray-200 rounded-xl overflow-hidden">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="bg-gray-50 text-gray-500">
                                <th class="text-left font-bold p-2">Désignation</th>
                                <th class="text-right font-bold p-2 w-12">Qté</th>
                                <th class="text-right font-bold p-2 w-20">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($doc['items'] as $it): ?>
                            <tr class="border-t border-gray-100">
                                <td class="p-2 font-bold text-gray-800"><?= $e($it['name'] ?? '') ?></td>
                                <td class="p-2 text-right font-mono"><?= $e(lpc_fcfa($it['qty'] ?? 0, '')) ?></td>
                                <td class="p-2 text-right font-mono"><?= $e(lpc_fcfa($it['total'] ?? 0, '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-between pt-3 mt-2 border-t-2 border-gray-800 font-black text-base text-gray-900">
                    <span>Total</span>
                    <span class="font-mono"><?= $e(lpc_fcfa($doc['totals']['grand_total'] ?? 0)) ?></span>
                </div>
                <?php if (!empty($doc['totals']['words'])): ?>
                    <p class="text-[10px] italic text-gray-400 pt-1"><?= $e($doc['totals']['words']) ?></p>
                <?php endif; ?>
            </div>
            <?php elseif ($type === 'payslip'): ?>
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-center">
                <p class="text-[10px] font-black text-emerald-700 uppercase tracking-widest">Net à payer</p>
                <p class="text-3xl font-black text-emerald-900 mt-1 font-mono">
                    <?= $e(lpc_fcfa($doc['raw']['net_pay'] ?? 0)) ?>
                </p>
                <p class="text-[10px] text-emerald-600 font-bold mt-1">
                    Période <?= $e(sprintf('%02d/%04d', (int) ($doc['raw']['month'] ?? 0), (int) ($doc['raw']['year'] ?? 0))) ?>
                </p>
            </div>
            <?php endif; ?>

            <hr class="border-gray-200">

            <div class="space-y-3">
                <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Identification du Signataire</h3>
                <input type="text" id="sig_name"  placeholder="Votre Nom & Prénom *"    required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-lpc-dark focus:bg-white">
                <input type="text" id="sig_role"  placeholder="Votre Fonction / Poste *" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-lpc-dark focus:bg-white">
                <input type="tel"  id="sig_phone" value="<?= $e($doc['client']['phone'] ?? '') ?>" placeholder="Numéro de Téléphone *" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-lpc-dark focus:bg-white">
            </div>

            <div>
                <div class="flex justify-between items-end mb-2">
                    <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Signature *</h3>
                    <button type="button" id="sig_clear_btn" class="text-[10px] font-bold text-rose-500 hover:text-rose-700 uppercase"><i class="fas fa-eraser"></i> Effacer</button>
                </div>
                <div class="canvas-container">
                    <canvas id="signature-pad"></canvas>
                </div>
                <p class="text-[9px] text-gray-400 mt-2 text-justify font-medium leading-tight">
                    <?= $e($P['consent']) ?>
                </p>
            </div>

            <div class="pt-2">
                <button id="sig_submit_btn" type="button" disabled class="w-full bg-lpc-dark hover:bg-green-800 text-white p-4 rounded-xl font-black text-sm shadow-xl flex items-center justify-center gap-2 active:scale-95 transition-transform disabled:opacity-40 disabled:cursor-not-allowed">
                    <i class="fas fa-check-circle text-lg"></i> <?= $e($P['cta']) ?>
                </button>
            </div>

        </div>
        <?php endif; ?>

        <div id="loading_overlay" class="hidden absolute inset-0 bg-white/90 backdrop-blur-sm z-50 flex flex-col items-center justify-center">
            <i class="fas fa-circle-notch fa-spin text-4xl text-lpc-dark mb-4"></i>
            <p class="text-sm font-black text-gray-800">Sécurisation du document...</p>
        </div>

    </div>

    <script type="application/json" id="lpc-page-data"><?= json_encode([
        'token'     => $token,
        'docType'   => $type,
        'csrf'      => Csrf::token(),
        'csrfField' => '_csrf',
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/signature-universal.js') ?>" defer></script>
</main>
</body>
</html>
