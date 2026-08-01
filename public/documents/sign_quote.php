<?php
/**
 * PUBLIC PORTAL: Signature du Devis — "Bon pour accord"
 * -----------------------------------------------------------------------------
 * DESCRIPTION: Mobile page a prospect opens from a WhatsApp/email link to
 * accept a proposition commerciale and sign it with their finger. Full spec:
 * docs/SIGNATURES.md.
 *
 * WHY IT LOOKS LIKE sign_cre.php
 *   Because it is the same flow with different figures on top: identity
 *   fields, a pad, a submit. It reuses assets/js/modules/signature-universal.js
 *   verbatim — the ONLY difference is docType 'quote' in the page data. Any
 *   future external-signing page should be this thin too; if it isn't, the
 *   shared module is the thing to extend, not this file to fork.
 *
 * WHAT SIGNING DOES
 *   Records an external signature row against the devis AND moves
 *   proposals.status to 'accepted' (see
 *   lpc_signature_side_effects_quote()). Both happen in one transaction.
 *
 * SUMMARY, NOT THE WHOLE OFFER
 *   This page shows what the signature attests to — reference, client, line
 *   items, the fiscal ladder, validity — not the full four-page proposal.
 *   A client who wants the complete document opens the link they were
 *   originally sent; there is a button for it below. The figures here are
 *   exactly the ones DocumentSignature::canonicalPayload_quote() hashes, so
 *   what is displayed is precisely what gets sealed.
 * -----------------------------------------------------------------------------
 */

if (!defined('LPC_FORCE_LIGHT')) { define('LPC_FORCE_LIGHT', true); }
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/document_pdf.php';

$token = (string) ($_GET['token'] ?? '');
$error = null;
$doc   = null;
$raw   = null;

if ($token === '') {
    $error = "Lien invalide ou expiré.";
} else {
    try {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("SELECT id, reference, status FROM proposals WHERE token = ? LIMIT 1");
        $stmt->execute([$token]);
        $raw = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$raw) {
            $error = "Devis introuvable.";
        } elseif ($raw['status'] === 'accepted') {
            $error = "Ce devis a déjà été signé et scellé.";
        } elseif ($raw['status'] === 'rejected') {
            $error = "Ce devis a été refusé.";
        } else {
            // Same loader the signature hash uses, so the figures shown here
            // and the figures sealed are guaranteed identical.
            $doc = lpc_signature_doc('quote', $token);
            if (!$doc) $error = "Devis illisible.";
        }
    } catch (Throwable $e) {
        error_log('sign_quote.php: ' . $e->getMessage());
        $error = "Erreur de connexion au serveur.";
    }
}

$fdate = static function ($v): string {
    if (!$v) return '—';
    $t = strtotime((string) $v);
    return $t ? date('d/m/Y', $t) : (string) $v;
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Signature du Devis | La Petite Cour</title>

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
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>
<main id="main" role="main" class="w-full">

    <div class="w-full max-w-md mx-auto bg-white rounded-3xl shadow-xl overflow-hidden relative">

        <div class="bg-lpc-dark px-6 py-8 text-center">
            <div class="flex justify-center mb-4">
                <img src="/assets/img/full_logo.svg" alt="LPC Logo" class="h-20 w-auto brightness-0 invert">
            </div>
            <h1 class="text-xl font-black text-white">La Petite Cour</h1>
            <p class="text-xs font-bold text-green-200 uppercase tracking-widest mt-1">Bon pour Accord</p>
        </div>

        <?php if ($error): ?>
        <div class="p-8 text-center space-y-4">
            <div class="text-5xl text-gray-300 mb-4">
                <i class="fas <?= ($raw && $raw['status'] === 'accepted') ? 'fa-check-circle text-green-500' : 'fa-exclamation-triangle text-amber-500'; ?>"></i>
            </div>
            <h2 class="text-lg font-black text-gray-900"><?= htmlspecialchars($error) ?></h2>
            <p class="text-sm text-gray-500 font-medium">Vous pouvez fermer cette page.</p>
        </div>
        <?php else: ?>

        <div class="p-6 space-y-6" id="main_form_area">

            <div class="bg-gray-50 p-4 rounded-xl border border-gray-100 text-sm">
                <div class="flex justify-between border-b border-gray-200 pb-2 mb-2">
                    <span class="text-gray-500 font-bold">Réf. Devis:</span>
                    <span class="font-black text-lpc-dark"><?= htmlspecialchars($doc['reference']) ?><?= (int) ($doc['revision'] ?? 1) > 1 ? ' rév. ' . (int) $doc['revision'] : '' ?></span>
                </div>
                <div class="flex justify-between border-b border-gray-200 pb-2 mb-2">
                    <span class="text-gray-500 font-bold">Client:</span>
                    <span class="font-black text-gray-900 text-right"><?= htmlspecialchars($doc['client']['name'] ?? '') ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500 font-bold">Valable jusqu'au:</span>
                    <span class="font-bold text-rose-600"><?= htmlspecialchars($fdate($doc['valid_until'] ?? '')) ?></span>
                </div>
            </div>

            <div>
                <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Objet de l'offre</h3>
                <div class="border border-gray-200 rounded-xl overflow-hidden">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="bg-gray-50 text-gray-500">
                                <th class="text-left font-bold p-2">Désignation</th>
                                <th class="text-right font-bold p-2 w-12">Qté</th>
                                <th class="text-right font-bold p-2 w-20">P.U.</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($doc['items'] ?? []) as $it): ?>
                            <tr class="border-t border-gray-100">
                                <td class="p-2 font-bold text-gray-800"><?= htmlspecialchars($it['name'] ?? '') ?></td>
                                <td class="p-2 text-right font-mono"><?= htmlspecialchars(lpc_fcfa($it['qty'] ?? 0, '')) ?></td>
                                <td class="p-2 text-right font-mono"><?= htmlspecialchars(lpc_fcfa($it['unit_price'] ?? 0, '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($doc['items'])): ?>
                            <tr><td colspan="3" class="p-4 text-center text-gray-400">Aucune ligne.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 space-y-1 text-sm">
                    <div class="flex justify-between text-gray-600">
                        <span>Sous-total HT</span>
                        <span class="font-mono"><?= htmlspecialchars(lpc_fcfa($doc['totals']['subtotal'] ?? 0)) ?></span>
                    </div>
                    <?php if (($doc['totals']['excise'] ?? 0) > 0): ?>
                    <div class="flex justify-between text-gray-600">
                        <span>Droit d'accises</span>
                        <span class="font-mono"><?= htmlspecialchars(lpc_fcfa($doc['totals']['excise'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (($doc['totals']['tax'] ?? 0) > 0): ?>
                    <div class="flex justify-between text-gray-600">
                        <span>TVA</span>
                        <span class="font-mono"><?= htmlspecialchars(lpc_fcfa($doc['totals']['tax'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="flex justify-between pt-2 mt-1 border-t-2 border-gray-800 font-black text-base text-gray-900">
                        <span>Total TTC</span>
                        <span class="font-mono"><?= htmlspecialchars(lpc_fcfa($doc['totals']['grand_total'] ?? 0)) ?></span>
                    </div>
                    <?php if (!empty($doc['totals']['words'])): ?>
                        <p class="text-[10px] italic text-gray-400 pt-1"><?= htmlspecialchars($doc['totals']['words']) ?></p>
                    <?php endif; ?>
                </div>

                <a href="/quote.php?token=<?= htmlspecialchars($token, ENT_QUOTES) ?>" target="_blank" rel="noopener"
                   class="mt-3 w-full inline-flex items-center justify-center gap-2 bg-white border border-gray-200 text-gray-600 text-xs font-bold py-2.5 rounded-xl hover:bg-gray-50 transition-colors">
                    <i class="fas fa-file-lines"></i> Lire la proposition complète
                </a>
            </div>

            <hr class="border-gray-200">

            <div class="space-y-3">
                <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Identification du Signataire</h3>
                <input type="text" id="sig_name"  placeholder="Votre Nom & Prénom *"    required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-lpc-dark focus:bg-white">
                <input type="text" id="sig_role"  placeholder="Votre Fonction / Poste *" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-lpc-dark focus:bg-white">
                <input type="tel"  id="sig_phone" value="<?= htmlspecialchars($doc['client']['phone'] ?? '') ?>" placeholder="Numéro de Téléphone *" required class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-lpc-dark focus:bg-white">
            </div>

            <div>
                <div class="flex justify-between items-end mb-2">
                    <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Signature — Bon pour Accord *</h3>
                    <button type="button" id="sig_clear_btn" class="text-[10px] font-bold text-rose-500 hover:text-rose-700 uppercase"><i class="fas fa-eraser"></i> Effacer</button>
                </div>
                <div class="canvas-container">
                    <canvas id="signature-pad"></canvas>
                </div>
                <p class="text-[9px] text-gray-400 mt-2 text-justify font-medium leading-tight">
                    En signant, j'accepte l'offre commerciale ci-dessus aux conditions qui y figurent, ainsi que les conditions générales de service, le barème des consignes et les annexes qui y sont incorporés. Je consens à ce que mon adresse IP et l'horodatage soient enregistrés pour garantir l'authenticité de cette acceptation.
                </p>
            </div>

            <div class="space-y-3 pt-2">
                <button id="sig_submit_btn" type="button" disabled class="w-full bg-lpc-dark hover:bg-green-800 text-white p-4 rounded-xl font-black text-sm shadow-xl flex items-center justify-center gap-2 active:scale-95 transition-transform disabled:opacity-40 disabled:cursor-not-allowed">
                    <i class="fas fa-check-circle text-lg"></i> Bon pour Accord — Signer
                </button>
                <button id="show_reject_btn" type="button" class="w-full bg-white text-gray-500 hover:text-rose-600 p-3 rounded-xl font-bold text-xs flex items-center justify-center gap-2 transition-colors">
                    <i class="fas fa-comment-dots"></i> Je souhaite discuter de cette offre
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
                <h3 class="font-black text-gray-900 text-lg mb-2"><i class="fas fa-comment-dots text-amber-500"></i> Discuter de l'offre</h3>
                <p class="text-xs text-gray-600 font-medium mb-4">Dites-nous ce qui doit être ajusté (volumes, prix, délais…). Votre conseiller reviendra vers vous avec une offre révisée.</p>
                <textarea id="reject_reason" rows="3" placeholder="Ex: Le volume mensuel est trop élevé pour nous..." class="w-full bg-gray-50 border border-gray-200 rounded-xl p-3 text-sm font-bold outline-none focus:border-amber-500 mb-4"></textarea>
                <div class="flex gap-3">
                    <button id="cancel_reject_btn"  type="button" class="flex-1 bg-gray-100 text-gray-600 py-3 rounded-xl font-bold text-sm">Annuler</button>
                    <button id="reject_confirm_btn" type="button" class="flex-1 bg-amber-500 text-white py-3 rounded-xl font-bold text-sm shadow-md">Envoyer</button>
                </div>
            </div>
        </div>

    </div>

    <script type="application/json" id="lpc-page-data"><?= json_encode([
        'token'           => $token,
        'docType'         => 'quote',
        'csrf'            => Csrf::token(),
        'csrfField'       => '_csrf',
        'successRedirect' => '/quote.php?token=' . $token . '&pdf=1',
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/signature-universal.js') ?>" defer></script>
</main>
</body>
</html>
