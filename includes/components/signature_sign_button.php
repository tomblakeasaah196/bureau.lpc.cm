<?php
/**
 * includes/components/signature_sign_button.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the "Signer côté LPC" affordance, for EVERY document type.
 * Full spec: docs/SIGNATURES.md.
 *
 * WHAT THIS EMITS
 *   1. a nav button (#lpc-sign-btn) whose label reflects current state
 *   2. a modal shell (#lpcSignModal) the JS fills in
 *   3. a <script type="application/json" id="lpc-sign-data"> block
 *   4. the <script src> for assets/js/modules/signature-internal.js
 *
 *   All four, or none of them: if the viewer is not logged in, or lacks
 *   signatures.{type}.internal.sign, this file renders nothing at all. The
 *   button is not merely hidden with CSS — an unauthorised viewer never
 *   receives the markup, the token, or the CSRF value. The controller
 *   re-checks the permission on every call regardless; this is defence in
 *   depth, not the gate itself.
 *
 * WHY id="lpc-sign-data" AND NOT "lpc-page-data"
 *   Several host pages (print_cre.php, payroll_finance.php) already emit
 *   their own #lpc-page-data for unrelated purposes. Two elements sharing
 *   that id would leave the winner to document order and silently break one
 *   of the two consumers. A dedicated id keeps this component independent of
 *   whatever else the host page is doing.
 *
 * USAGE — two lines in the host page's nav:
 *     <?php $sign_btn_type = 'facture'; $sign_btn_token = $token;
 *           require __DIR__ . '/../../includes/components/signature_sign_button.php'; ?>
 *
 * INPUTS
 *   $sign_btn_type   string  a DocumentSignature::TYPES slug
 *   $sign_btn_token  string  the document's public access token
 * Optional:
 *   $sign_btn_class  string  extra classes for the button (layout tweaks per page)
 *   $sign_btn_inline bool    true = render button only, no modal/script. For
 *                            pages that call this twice (mobile + desktop nav).
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

require_once __DIR__ . '/../classes/DocumentSignature.php';

(static function (): void {
    $type  = isset($GLOBALS['sign_btn_type'])  ? (string) $GLOBALS['sign_btn_type']  : '';
    $token = isset($GLOBALS['sign_btn_token']) ? (string) $GLOBALS['sign_btn_token'] : '';
    $extra = isset($GLOBALS['sign_btn_class']) ? (string) $GLOBALS['sign_btn_class'] : '';

    if ($type === '' || $token === '')                      return;
    if (!in_array($type, DocumentSignature::TYPES, true))   return;
    if (empty($_SESSION['user_id']))                        return;
    if (!class_exists('Rbac'))                              return;

    // Permission gate. Migration 050 introduced signatures.{type}.internal.sign
    // for every document type; the devis had already shipped under its own
    // crm.proposals.sign in migration 048. Both are accepted for 'quote' so
    // an install that granted the old one — and has not yet re-run
    // scripts/sync_permissions.php — does not silently lose its sign button
    // on upgrade. Drop the legacy row from this map once every environment
    // has been synced.
    static $legacyPerm = ['quote' => 'crm.proposals.sign'];
    $allowed = Rbac::hasPermission(DocumentSignature::permissionFor($type, 'internal'))
        || (isset($legacyPerm[$type]) && Rbac::hasPermission($legacyPerm[$type]));
    if (!$allowed) return;

    if (!class_exists('UserProfile')) {
        require_once __DIR__ . '/../classes/UserProfile.php';
    }

    // Current state decides the button's colour and label so an operator can
    // see at a glance whether this document still carries a valid internal
    // attestation — without opening the modal.
    $signed = false;
    try {
        $db  = Database::getInstance()->getConnection();
        $doc = lpc_load_document($db, [
            'quote' => 'quote', 'cre' => 'cre', 'bl' => 'delivery',
            'facture' => 'invoice', 'bon_commande' => 'po', 'payslip' => 'payslip',
        ][$type] ?? $type, $token);
        if ($doc) {
            $signed = (bool) DocumentSignature::getActiveByParty(
                $type, (int) ($doc['record_id'] ?? 0), $doc, 'internal'
            );
        }
    } catch (Throwable $e) {
        // A failed status probe must never stop the page rendering — fall
        // back to the neutral "Signature" label and let the modal resolve it.
        error_log('signature_sign_button: state probe failed: ' . $e->getMessage());
    }

    $e = static function ($v): string {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };

    $palette = $signed
        ? 'bg-green-50 border-green-200 text-green-800 hover:bg-green-100'
        : 'bg-amber-50 border-amber-200 text-amber-800 hover:bg-amber-100';
    ?>
<button type="button" id="lpc-sign-btn"
        class="flex items-center gap-2 px-4 py-2 border rounded-lg font-bold text-sm transition-all no-print <?= $e($palette) ?> <?= $e($extra) ?>">
    <i class="fas fa-stamp"></i>
    <span id="lpc-sign-btn-label"><?= $signed ? 'Signé' : 'Signer côté LPC' ?></span>
</button>

<div id="lpcSignModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-gray-900/60 backdrop-blur-sm px-4 no-print"
     role="dialog" aria-modal="true" aria-labelledby="lpc_sign_modal_title">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-gray-100">
        <div class="bg-lpc-dark px-6 py-4 flex justify-between items-center text-white">
            <h3 id="lpc_sign_modal_title" class="font-bold text-lg flex items-center gap-2">
                <i class="fas fa-stamp"></i> Signature électronique interne
            </h3>
            <button type="button" id="lpc_sign_modal_close" aria-label="Fermer"
                    class="text-gray-300 hover:text-white transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="p-6 space-y-4" id="lpc_sign_modal_body">
            <p class="text-sm text-gray-500">Chargement…</p>
        </div>
    </div>
</div>

<script type="application/json" id="lpc-sign-data"><?= json_encode([
    'token'      => $token,
    'docType'    => $type,
    'csrf'       => Csrf::token(),
    'csrfField'  => '_csrf',
    'signerName' => UserProfile::displayName(),
    'signerRole' => UserProfile::roleLabel(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script src="<?= lpc_asset('/assets/js/modules/signature-internal.js') ?>" defer></script>
    <?php
})();

unset($sign_btn_type, $sign_btn_token, $sign_btn_class, $sign_btn_inline);
