<?php
/**
 * api/v1/proposal_signature_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 10 · backend for the devis one-pager's internal
 * digital sign-off (migration 048, includes/classes/DocumentSignature.php).
 *
 * Every action resolves the document the same way the PDF renderer does —
 * lpc_load_document($db, 'quote', $token) — so the figures a signature hashes
 * are always exactly the figures lpc_render_quote_pdf_html() would print.
 *
 * Actions:
 *   status   (GET, public/token-gated)  → current signature state for a devis
 *   sign     (POST, crm.proposals.sign) → sign the CURRENT figures as the
 *                                          logged-in user
 *   revoke   (POST, crm.proposals.sign) → revoke a signature (does not delete
 *                                          it — public/verify.php must keep
 *                                          answering "revoked", not 404)
 *
 * `status` is deliberately NOT permission-gated beyond the token: it reveals
 * who signed and when, which is exactly what the printed stamp and the public
 * /verify/{token} page already show anyone holding the PDF. The token itself
 * is the credential, same trust model as get_proposal.php and quote.php.
 * `sign` and `revoke` DO require crm.proposals.sign — attesting to a document
 * is a different level of authority from merely viewing one.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/document_pdf.php';
require_once __DIR__ . '/../../includes/classes/DocumentSignature.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/** Uniform JSON exit — same shape as proposal_template_controller.php. */
function ps_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Uniform failure. Never leaks internals to the client. */
function ps_fail(string $msg, int $code = 400): void
{
    ps_out(['status' => 'error', 'message' => $msg], $code);
}

/**
 * JSON body (what documents-quote-sign.js sends) with a classic form-POST
 * fallback. $_POST is only populated by PHP for form-encoded bodies, so a
 * fetch() with Content-Type: application/json needs php://input instead —
 * same idiom as signer_otp_controller.php / Csrf::submitted().
 */
function ps_payload(): array
{
    $raw  = file_get_contents('php://input') ?: '';
    $json = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($json) ? $json : $_POST;
}

/**
 * Resolve a devis by its public token, in the exact shape the PDF template
 * hashes and renders. 404s (rather than throwing) on an unknown token — the
 * same "let the caller render its own not-found" contract lpc_serve_document_pdf
 * uses.
 */
function ps_load_doc(PDO $db, string $token): array
{
    $token = trim($token);
    if ($token === '') {
        ps_fail('Token manquant.');
    }
    $doc = lpc_load_document($db, 'quote', $token);
    if (!$doc) {
        ps_fail('Devis introuvable.', 404);
    }
    return $doc;
}

/** The public shape of a signature row — never leaks ip/user_agent/user id. */
function ps_public_signature(array $row, string $verifyBase): array
{
    return [
        'signer_name'   => $row['signer_name'],
        'signer_role'   => $row['signer_role'],
        'signed_at'     => $row['signed_at'],
        'hash_prefix'   => substr($row['content_hash'], 0, 16),
        'verify_token'  => $row['verify_token'],
        'verify_url'    => rtrim($verifyBase, '/') . '/verify/' . $row['verify_token'],
        'revoked'       => !empty($row['revoked_at']),
    ];
}

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    error_log('proposal_signature_controller: DB unavailable: ' . $e->getMessage());
    ps_fail('Service indisponible.', 503);
}

$verifyBase = defined('APP_URL') ? APP_URL : 'https://bureau.lpc.cm';

switch ($action) {

    // -------------------------------------------------------------------------
    // status — current signature state for a devis. Public (token-gated).
    // -------------------------------------------------------------------------
    case 'status':
        $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
        $doc   = ps_load_doc($db, $token);

        $active = DocumentSignature::getActive('quote', (int) $doc['record_id'], $doc);
        $latest = DocumentSignature::latestAny('quote', (int) $doc['record_id']);

        // "stale" — signed once, then the devis changed, so the old signature
        // no longer matches. Only meaningful to show internally (a client
        // holding the PDF just sees "unsigned"); can_sign gates it so a
        // prospect who happened to load this page never sees it either.
        $canSign = Rbac::hasPermission('crm.proposals.sign');
        $stale   = $canSign && $latest && (!$active || $active['id'] !== $latest['id']);

        ps_out([
            'status'    => 'success',
            'can_sign'  => $canSign,
            'signed'    => (bool) $active,
            'signature' => $active ? ps_public_signature($active, $verifyBase) : null,
            'stale'     => $stale,
            'stale_signature' => $stale ? ps_public_signature($latest, $verifyBase) : null,
        ]);
        break;

    // -------------------------------------------------------------------------
    // sign — attest to the CURRENT figures as the logged-in user.
    // -------------------------------------------------------------------------
    case 'sign':
        Rbac::requirePermission('crm.proposals.sign');
        Csrf::requireValid();

        $token  = (string) (ps_payload()['token'] ?? '');
        $doc    = ps_load_doc($db, $token);
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        try {
            $row = DocumentSignature::sign('quote', (int) $doc['record_id'], $doc, $userId);
        } catch (RuntimeException $ex) {
            // sign() throws with user-facing French messages already (invalid
            // profile, DB failure) — safe to surface directly.
            ps_fail($ex->getMessage(), 422);
        } catch (Throwable $e) {
            error_log('proposal_signature sign failed: ' . $e->getMessage());
            ps_fail('La signature n\'a pas pu être enregistrée.', 500);
        }

        ps_out([
            'status'    => 'success',
            'message'   => 'Devis signé.',
            'signature' => ps_public_signature($row, $verifyBase),
        ]);
        break;

    // -------------------------------------------------------------------------
    // revoke — invalidate a signature without deleting it.
    // -------------------------------------------------------------------------
    case 'revoke':
        Rbac::requirePermission('crm.proposals.sign');
        Csrf::requireValid();

        $payload = ps_payload();
        $sigId   = (int) ($payload['signature_id'] ?? 0);
        $reason  = trim((string) ($payload['reason'] ?? ''));
        if ($sigId <= 0) {
            ps_fail('Signature introuvable.');
        }

        $ok = DocumentSignature::revoke($sigId, (int) ($_SESSION['user_id'] ?? 0), $reason);
        if (!$ok) {
            ps_fail("La révocation a échoué (signature déjà révoquée ou introuvable).", 422);
        }

        ps_out(['status' => 'success', 'message' => 'Signature révoquée.']);
        break;

    // -------------------------------------------------------------------------
    default:
        ps_fail('Action inconnue.', 400);
}
