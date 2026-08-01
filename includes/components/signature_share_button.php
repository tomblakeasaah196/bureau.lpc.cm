<?php
/**
 * includes/components/signature_share_button.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — "Faire signer le client" : the share sheet that hands the
 * COUNTERPARTY a link to sign a document themselves. Full spec:
 * docs/SIGNATURES.md.
 *
 * NOT TO BE CONFUSED WITH signature_sign_button.php
 *   That one is an LPC staffer attesting to our own figures from inside the
 *   ERP — internal party, no image, no client involved. This one sends the
 *   client somewhere to draw their own signature. Both can sit in the same
 *   nav; they are different acts and read differently on purpose.
 *
 * WHY A COMPONENT
 *   The first version of this was written inline on quote.php. Copying that
 *   modal onto facture.php and bon_commande.php would have been the third and
 *   fourth copies of the same 60 lines — exactly the duplication this whole
 *   signature system exists to kill. One component, one JS module, every type.
 *
 * WHAT IT EMITS — or nothing at all
 *   Renders the button, modal, a #lpc-share-data JSON block and the script
 *   tag. If the viewer is not logged in or lacks
 *   signatures.{type}.external.sign, it emits NOTHING: no markup, no token.
 *   The permission gates who may INVITE a counterparty to sign; the signing
 *   endpoint itself is token-gated, since the client has no account.
 *
 * INPUTS
 *   $share_type   string  a DocumentSignature::TYPES slug
 *   $share_token  string  the document's public access token
 * Optional:
 *   $share_ref    string  reference, used to personalise the WhatsApp message
 *   $share_label  string  button text (default "Faire signer le client")
 *   $share_class  string  extra classes for the button
 *   $share_who    string  who is being asked to sign — "le client" (default),
 *                         "le fournisseur", "le salarié". Wording only.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

require_once __DIR__ . '/../classes/DocumentSignature.php';

(static function (): void {
    $type  = isset($GLOBALS['share_type'])  ? (string) $GLOBALS['share_type']  : '';
    $token = isset($GLOBALS['share_token']) ? (string) $GLOBALS['share_token'] : '';
    $ref   = isset($GLOBALS['share_ref'])   ? (string) $GLOBALS['share_ref']   : '';
    $extra = isset($GLOBALS['share_class']) ? (string) $GLOBALS['share_class'] : '';
    $who   = isset($GLOBALS['share_who'])   ? (string) $GLOBALS['share_who']   : 'le client';
    $label = isset($GLOBALS['share_label']) ? (string) $GLOBALS['share_label'] : ('Faire signer ' . $who);

    if ($type === '' || $token === '')                    return;
    if (!in_array($type, DocumentSignature::TYPES, true)) return;
    if (empty($_SESSION['user_id']))                      return;
    if (!class_exists('Rbac'))                            return;
    if (!Rbac::hasPermission(DocumentSignature::permissionFor($type, 'external'))) return;

    // Where the counterparty lands. CRE, BL and the devis each have a bespoke
    // page because their content genuinely differs — the CRE shows a bottle
    // grid, the BL lets the customer amend quantities before signing. The
    // rest share one generic summary page.
    static $pageFor = [
        'quote' => '/sign_quote.php?token=',
        'cre'   => '/sign_cre.php?token=',
        'bl'    => '/sign_bl.php?token=',
    ];
    $signPath = $pageFor[$type] ?? ('/sign_document.php?type=' . rawurlencode($type) . '&token=');

    $scheme  = ((($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? 'https://' : 'http://');
    $origin  = defined('APP_URL') && APP_URL !== '' ? rtrim(APP_URL, '/') : $scheme . ($_SERVER['HTTP_HOST'] ?? 'bureau.lpc.cm');
    $signUrl = $origin . $signPath . rawurlencode($token);

    $e = static function ($v): string {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };
    ?>
<button type="button" id="lpc-share-btn"
        class="flex items-center gap-2 px-4 py-2 bg-lpc-dark hover:bg-green-800 text-white rounded-lg font-bold text-sm shadow-md transition-all no-print <?= $e($extra) ?>">
    <i class="fas fa-signature"></i> <span><?= $e($label) ?></span>
</button>

<div id="lpcShareModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-gray-900/60 backdrop-blur-sm px-4 no-print"
     role="dialog" aria-modal="true" aria-labelledby="lpc_share_title">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-gray-100">
        <div class="bg-lpc-dark px-6 py-4 flex justify-between items-center text-white">
            <h3 id="lpc_share_title" class="font-bold text-lg flex items-center gap-2">
                <i class="fas fa-signature"></i> <?= $e($label) ?>
            </h3>
            <button type="button" id="lpc_share_close" aria-label="Fermer"
                    class="text-gray-300 hover:text-white transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <div class="p-6 space-y-5">
            <p class="text-sm text-gray-600">
                Envoyez ce lien à <?= $e($who) ?>. Le récapitulatif du document s'affichera et
                la signature se fait directement depuis le téléphone — aucun compte n'est requis.
            </p>

            <div>
                <label for="lpc_share_link" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1">Lien de signature</label>
                <div class="flex gap-2">
                    <input type="text" id="lpc_share_link" readonly
                           class="flex-1 bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-xs font-mono text-gray-700 outline-none">
                    <button type="button" id="lpc_share_copy"
                            class="px-4 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold text-xs transition-colors">
                        Copier
                    </button>
                </div>
            </div>

            <div>
                <label for="lpc_share_phone" class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1">Numéro WhatsApp</label>
                <div class="flex gap-2">
                    <input type="tel" id="lpc_share_phone" placeholder="ex. 237690000000"
                           class="flex-1 bg-gray-50 border border-gray-200 rounded-lg p-2.5 text-sm font-bold outline-none focus:border-lpc-dark focus:bg-white">
                    <button type="button" id="lpc_share_wa"
                            class="px-4 rounded-lg bg-green-600 hover:bg-green-700 text-white font-bold text-xs shadow-sm transition-colors flex items-center gap-2">
                        <i class="fab fa-whatsapp text-base"></i> Envoyer
                    </button>
                </div>
                <p class="text-[10px] text-gray-400 mt-1">Indicatif pays sans le «&nbsp;+&nbsp;».</p>
            </div>

            <div class="border-t border-gray-100 pt-4 text-center">
                <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest mb-2">Ou faites scanner ce code</p>
                <div id="lpc_share_qr" class="flex justify-center"></div>
                <p class="text-[10px] text-gray-400 mt-2">La signature se fait sur le téléphone du signataire.</p>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="lpc-share-data"><?= json_encode([
    'signUrl'   => $signUrl,
    'reference' => $ref,
    'docType'   => $type,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script src="/assets/vendor/qrcodejs/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous"></script>
<script src="<?= lpc_asset('/assets/js/modules/signature-share.js') ?>" defer></script>
    <?php
})();

unset($share_type, $share_token, $share_ref, $share_label, $share_class, $share_who);
