/**
 * assets/js/modules/documents-quote-sign.js — REMOVED (Sprint 11)
 * -----------------------------------------------------------------------------
 * Superseded by assets/js/modules/signature-internal.js, which drives the
 * "Signer côté LPC" button and modal for EVERY document type — devis, CRE,
 * BL, facture, bon de commande and bulletin de paie — instead of the devis
 * alone. See docs/SIGNATURES.md.
 *
 * The markup this file used to talk to (#btn-sign, #signModal,
 * #sign_modal_body, and the #lpc-page-data block carrying signerName /
 * signerRole) is now emitted by
 * includes/components/signature_sign_button.php.
 *
 * Nothing references this file. It is safe to `git rm`; the tombstone exists
 * only so a stale browser cache or a bookmarked deploy artefact fails loudly
 * rather than half-wiring a modal that no longer exists in the DOM.
 * -----------------------------------------------------------------------------
 */
console.warn(
    'documents-quote-sign.js is removed. The page should load ' +
    'assets/js/modules/signature-internal.js instead. See docs/SIGNATURES.md.'
);
