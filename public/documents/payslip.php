<?php
/**
 * public/documents/payslip.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 4 (parallel) · public payslip PDF.
 *
 * URL: /public/documents/payslip.php?token=…
 *
 * Streams a server-side PDF built by PdfRenderer + the payslip template in
 * includes/functions/document_pdf.php. Token TTL is enforced there (rejected
 * if hr_payslips.token_expires_at is in the past).
 *
 * No RBAC gate — token is the credential. Access to a token means access to
 * the payslip, per the same model as facture.php / bon_livraison.php.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/document_pdf.php';

// If a caller passes ?html=1 we render a tiny HTML fallback so accountants
// can visually verify amounts without downloading the PDF.
if (empty($_GET['html']) || $_GET['html'] !== '1') {
    lpc_serve_document_pdf('payslip');   // exits after streaming, or 404/500
    exit;                                 // safety in case something in the
                                          // dispatch path returned without exit
}

// -----------------------------------------------------------------------------
// HTML debug view.
// -----------------------------------------------------------------------------
$token = (string) ($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(404);
    echo "Token manquant.";
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $doc = lpc_load_document($db, 'payslip', $token);
} catch (Throwable $e) {
    error_log('payslip.php: ' . $e->getMessage());
    http_response_code(500);
    echo "Erreur serveur.";
    exit;
}

if (!$doc) {
    http_response_code(404);
    echo "Bulletin introuvable ou lien expiré.";
    exit;
}

// Reuse the exact PDF-body renderer for the HTML view — one source of truth.
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
echo lpc_render_document_html('payslip', $doc);

// -----------------------------------------------------------------------------
// Universal internal signature.
//
// This page has no nav bar — the default response is a streamed PDF, and the
// ?html=1 view is the raw document body. So the affordance is appended after
// the document rather than placed in chrome that does not exist. It is fixed
// to the top-right and carries .no-print, so it never appears in a printed or
// PDF-exported payslip.
//
// Reaching it: open /public/documents/payslip.php?token=…&html=1 while logged
// in with signatures.payslip.internal.sign. Renders nothing otherwise — an
// employee opening their own payslip link sees exactly what they saw before.
// See docs/SIGNATURES.md.
// -----------------------------------------------------------------------------
// Ask the SALARIÉ to acknowledge receipt. Sits under the internal button.
$share_type  = 'payslip';
$share_token = $token;
$share_who   = 'le salarié';
$share_label = 'Faire accuser réception';
$share_class = 'fixed top-20 right-5 z-50 shadow-2xl';
require __DIR__ . '/../../includes/components/signature_share_button.php';

// HR attests to the figures, from inside the ERP.
$sign_btn_type  = 'payslip';
$sign_btn_token = $token;
$sign_btn_class = 'fixed top-5 right-5 z-50 shadow-2xl';
require __DIR__ . '/../../includes/components/signature_sign_button.php';
