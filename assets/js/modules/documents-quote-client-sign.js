/**
 * assets/js/modules/documents-quote-client-sign.js — REMOVED (Sprint 11)
 * -----------------------------------------------------------------------------
 * Superseded by assets/js/modules/signature-share.js, which drives the
 * "Faire signer …" share sheet for EVERY document type rather than the devis
 * alone. Its markup now comes from
 * includes/components/signature_share_button.php.
 *
 * This file existed for about an hour: it was written for quote.php, and then
 * facture.php and bon_commande.php needed exactly the same thing, which is the
 * point at which it became a component instead of a third copy.
 *
 * Nothing references it. Safe to `git rm`.
 * -----------------------------------------------------------------------------
 */
console.warn(
    'documents-quote-client-sign.js is removed. The page should load ' +
    'assets/js/modules/signature-share.js instead. See docs/SIGNATURES.md.'
);
