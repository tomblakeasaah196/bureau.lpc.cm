/**
 * assets/js/modules/documents-sign_bl.js — REMOVED (Sprint 11)
 * -----------------------------------------------------------------------------
 * Superseded by assets/js/modules/signature-universal.js, which serves every
 * external signing page (CRE, BL, and any future document type) from one
 * module. This file's original contents drove the Sprint 7C phone-OTP gate
 * and the dual driver/client signature pads, both retired by migration 050 —
 * the LPC-side signature is now an in-ERP attestation. See docs/SIGNATURES.md.
 *
 * Nothing references this file. It is safe to `git rm`; the tombstone exists
 * only so a stale browser cache or a bookmarked deploy artefact fails loudly
 * rather than silently re-enabling a flow that no longer has a backend.
 * -----------------------------------------------------------------------------
 */
console.warn(
    'documents-sign_bl.js is removed. The page should load ' +
    'assets/js/modules/signature-universal.js instead. See docs/SIGNATURES.md.'
);
