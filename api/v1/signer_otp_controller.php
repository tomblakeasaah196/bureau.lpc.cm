<?php
/**
 * api/v1/signer_otp_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7C · Deliverable 1.
 *
 * Endpoints:
 *   POST ?action=request_otp   {token, phone}
 *       → SignerOtp::issue → always returns a generic {status:'success'} so
 *         we never leak whether the (token, phone) tuple exists. Rate-limited.
 *   POST ?action=verify_otp    {token, phone, code}
 *       → SignerOtp::verify. On success returns
 *         {status:'success', verified:true}; on failure
 *         {status:'error', code:'otp_invalid'}.
 *
 * These are the ONLY 'PUBLIC' endpoints that also require Csrf::requireValid()
 * — the sign_bl.php / sign_cre.php pages emit a bootstrap CSRF token via
 * Csrf::token() (both sides are under our control, so the token exchange
 * still works even though the visitor is unauthenticated). Every other
 * public-token endpoint is documented in scripts/tests/csrf_public_pages.md.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/Mail.php';
require_once __DIR__ . '/../../includes/classes/SignerOtp.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Méthode non autorisée.']);
    exit;
}

// CSRF gate — both actions are state-changing.
Csrf::requireValid();

// Payload — accept JSON body (preferred) or form fields.
$raw     = file_get_contents('php://input') ?: '';
$json    = $raw !== '' ? json_decode($raw, true) : null;
$payload = is_array($json) ? $json : $_POST;

$action = $_GET['action'] ?? ($payload['action'] ?? '');
$ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Coarse per-IP rate limit so a token-URL harvester can't spray. The
// per-phone bucket lives inside SignerOtp::issue.
RateLimiter::guard('signer_otp_ip', $ip, 20, 15);

$token = trim((string) ($payload['token'] ?? ''));
$phone = trim((string) ($payload['phone'] ?? ''));
$code  = trim((string) ($payload['code']  ?? ''));

if ($action === 'request_otp') {
    if ($token === '' || $phone === '') {
        echo json_encode(['status' => 'success']); // generic — no leak
        exit;
    }
    // Resolve doc type + id from the token so we never trust the client-
    // supplied docType. Two lookups; whichever hits wins.
    try {
        $db = Database::getInstance()->getConnection();
        $docType = null; $docId = 0;
        $q = $db->prepare("SELECT id FROM deliveries WHERE token = ? LIMIT 1");
        $q->execute([$token]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r) { $docType = 'delivery'; $docId = (int) $r['id']; }
        else {
            $q = $db->prepare("SELECT id FROM cre_documents WHERE token = ? LIMIT 1");
            $q->execute([$token]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if ($r) { $docType = 'cre'; $docId = (int) $r['id']; }
        }
    } catch (Throwable $e) {
        error_log('signer_otp_controller lookup: ' . $e->getMessage());
    }

    if ($docType !== null) {
        // Fire-and-forget; response is ALWAYS generic success.
        SignerOtp::issue($token, $docType, $docId, $phone);
    }
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'verify_otp') {
    $ok = SignerOtp::verify($token, $phone, $code);
    if ($ok) {
        echo json_encode(['status' => 'success', 'verified' => true]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['status' => 'error', 'code' => 'otp_invalid',
                      'message' => 'Code invalide ou expiré.']);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Action inconnue.']);
