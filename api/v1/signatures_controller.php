<?php
/**
 * api/v1/signatures_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the single HTTP endpoint every signature action goes
 * through. Full design in docs/SIGNATURES.md.
 *
 * ACTIONS
 *   sign_internal   POST · authenticated · requires signatures.{type}.internal.sign
 *                        · body: { type, token, _csrf }
 *                        · resolves doc by token, signs with the session user
 *                        · returns { status, signature: {...}, verify_url }
 *
 *   sign_external   POST · unauthenticated (token IS the credential)
 *                        · body: { type, token, signatory_name, signatory_role,
 *                                  signatory_phone, signature_image, _csrf }
 *                        · returns { status, signature: {...}, verify_url }
 *
 *   revoke          POST · authenticated · requires signatures.{type}.internal.sign
 *                        · body: { signature_id, reason, _csrf }
 *                        · returns { status }
 *
 *   status          GET  · public (token-gated)
 *                        · query: type=…&token=…
 *                        · returns { internal, external } — one per party,
 *                          each with signer identity, timestamp, hash prefix,
 *                          verify url, revoked flag; nulls if not signed.
 *
 * WHY THIS FILE REPLACES proposal_signature_controller.php
 *   proposal_signature_controller was quote-only. That file is kept as a
 *   thin alias for backward compatibility with the existing devis front-end,
 *   but every new caller should hit /api/v1/signatures_controller.php with
 *   ?type=quote instead. Same for cre_controller.php/sign_cre and
 *   sales_controller.php/sign_bl, which now proxy their sign actions here.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/DocumentSignature.php';
require_once __DIR__ . '/../../includes/classes/RateLimiter.php';
require_once __DIR__ . '/../../includes/functions/document_pdf.php';
require_once __DIR__ . '/../../includes/functions/signature_side_effects.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// -----------------------------------------------------------------------------
// I/O helpers. Same idiom as proposal_signature_controller.php so the shape
// of JSON errors doesn't drift between files.
// -----------------------------------------------------------------------------

function sig_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sig_ok(array $extra = []): void
{
    sig_out(['status' => 'success'] + $extra);
}

function sig_fail(string $message, int $code = 400, string $errCode = 'error'): void
{
    sig_out(['status' => 'error', 'code' => $errCode, 'message' => $message], $code);
}

/** JSON body with $_POST fallback (for form-encoded requests). */
function sig_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $j   = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($j) ? $j : $_POST;
}

/**
 * Resolve $type + $token to a normalised doc via lpc_load_document(). Returns
 * [$type, $docId, $doc]. Fails with 400/404 on bad input — never returns null.
 */
function sig_resolve(): array
{
    $body  = sig_body();
    $type  = strtolower(trim((string) (
        $body['type'] ?? $_GET['type'] ?? ''
    )));
    $token = trim((string) (
        $body['token'] ?? $_GET['token'] ?? ''
    ));
    if ($type === '' || $token === '') {
        sig_fail('Paramètres manquants (type, token).', 400, 'bad_request');
    }
    if (!in_array($type, DocumentSignature::TYPES, true)) {
        sig_fail("Type de document inconnu : {$type}.", 400, 'unknown_type');
    }

    try {
        $db = Database::getInstance()->getConnection();
    } catch (Throwable $e) {
        error_log('signatures_controller: DB unavailable: ' . $e->getMessage());
        sig_fail('Service indisponible.', 503, 'db_down');
    }

    $doc = sig_load_doc($db, $type, $token);
    if (!$doc) {
        sig_fail('Document introuvable.', 404, 'not_found');
    }
    $docId = (int) ($doc['record_id'] ?? 0);
    if ($docId <= 0) {
        sig_fail('Document invalide.', 500, 'no_record_id');
    }
    return [$type, $docId, $doc, $token];
}

/**
 * Load a document in the normalised shape the canonical payload hashes.
 *
 * lpc_load_document() uses the renderer's older type vocabulary
 * (invoice / delivery / po) that pre-dates the DocumentSignature slugs
 * (facture / bl / bon_commande). Mapping lives here so callers only ever
 * deal in the one canonical name from DocumentSignature::TYPES.
 *
 * Split out of sig_resolve() because sign_external has to RE-load the
 * document after applying the counterparty's edits — see the ordering
 * comment in that case block.
 *
 * @return array<string,mixed>|null
 */
function sig_load_doc(PDO $db, string $type, string $token): ?array
{
    static $loadTypeMap = [
        'quote'        => 'quote',
        'cre'          => 'cre',
        'bl'           => 'delivery',
        'facture'      => 'invoice',
        'bon_commande' => 'po',
        'payslip'      => 'payslip',
        'contract'     => 'contract',
    ];
    $loadType = $loadTypeMap[$type] ?? $type;
    return lpc_load_document($db, $loadType, $token) ?: null;
}

/**
 * May the current session sign $type internally?
 *
 * Migration 050 introduced signatures.{type}.internal.sign for every document
 * type; the devis had already shipped under crm.proposals.sign in migration
 * 048. Both are accepted for 'quote' so an install that granted the old one
 * — and has not yet re-run scripts/sync_permissions.php — keeps working.
 * includes/components/signature_sign_button.php applies the SAME rule when
 * deciding whether to render the button; the two must not drift, or an
 * operator sees a button that 403s on submit. Drop the legacy entry once
 * every environment has been synced.
 */
function sig_can_sign_internal(string $type): bool
{
    static $legacyPerm = ['quote' => 'crm.proposals.sign'];
    if (empty($_SESSION['user_id'])) return false;
    if (Rbac::hasPermission(DocumentSignature::permissionFor($type, 'internal'))) return true;
    return isset($legacyPerm[$type]) && Rbac::hasPermission($legacyPerm[$type]);
}

/** Verify base URL — used by the QR/verify links in the response. */
function sig_verify_base(): string
{
    if (defined('APP_URL') && APP_URL !== '') return rtrim(APP_URL, '/');
    $scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/** Public-safe view of a signature row. Never leaks IP / UA / user id. */
function sig_public(array $row): array
{
    return [
        'id'             => (int) $row['id'],
        'party'          => $row['party'],
        'signer_name'    => $row['signer_name']     ?? null,
        'signer_role'    => $row['signer_role']     ?? null,
        'signatory_name' => $row['signatory_name']  ?? null,
        'signatory_role' => $row['signatory_role']  ?? null,
        'signatory_phone'=> $row['signatory_phone'] ?? null,
        'signed_at'      => $row['signed_at'],
        'hash_prefix'    => substr((string) $row['content_hash'], 0, 16),
        'verify_token'   => $row['verify_token'],
        'verify_url'     => sig_verify_base() . '/verify.php?token=' . $row['verify_token'],
        'revoked'        => !empty($row['revoked_at']),
    ];
}

// -----------------------------------------------------------------------------
// Dispatch
// -----------------------------------------------------------------------------

$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? '')));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

switch ($action) {

    // -------------------------------------------------------------------------
    // status — public. Reveals only what the printed QR already reveals to
    // anyone holding the PDF, so a token-only check is enough.
    // -------------------------------------------------------------------------
    case 'status': {
        if ($method !== 'GET') sig_fail('GET requis.', 405, 'method');
        [$type, $docId, $doc] = sig_resolve();

        $int = DocumentSignature::getActiveByParty($type, $docId, $doc, 'internal');
        $ext = DocumentSignature::getActiveByParty($type, $docId, $doc, 'external');

        // "Stale" = the document WAS signed, then its figures changed, so the
        // stored hash no longer matches and getActiveByParty() returns null.
        // The PDF renders that case as unsigned (deliberately — never stamp
        // figures nobody attested to), but the internal UI has to tell
        // "never signed" apart from "signed, then edited" or the operator
        // has no idea why their signature vanished.
        $latest = DocumentSignature::latestAny($type, $docId);
        $stale  = (!$int && !$ext && $latest) ? sig_public($latest) : null;

        sig_ok([
            'internal'        => $int ? sig_public($int) : null,
            'external'        => $ext ? sig_public($ext) : null,
            'stale'           => $stale !== null,
            'stale_signature' => $stale,
            // Whether the CURRENT viewer may add an internal signature. The
            // button is rendered server-side behind the same check, but the
            // modal re-reads it so a permission revoked mid-session can't be
            // acted on from a stale tab.
            'can_sign_internal' => sig_can_sign_internal($type),
        ]);
        break;
    }

    // -------------------------------------------------------------------------
    // sign_internal — authenticated. Signer identity is UserProfile::*,
    // resolved server-side. Body-supplied name/role fields are ignored.
    // -------------------------------------------------------------------------
    case 'sign_internal': {
        if ($method !== 'POST') sig_fail('POST requis.', 405, 'method');
        Csrf::requireValid();
        if (empty($_SESSION['user_id'])) sig_fail('Session requise.', 401, 'auth');

        [$type, $docId, $doc, $token] = sig_resolve();
        if (!sig_can_sign_internal($type)) {
            sig_fail("Vous n'avez pas la permission de signer ce document.", 403, 'forbidden');
        }

        // Sprint 12 (BL-2608-CB78 diagnosis fix) originally made sign_internal
        // run the BL side effects too, flipping deliveries.status to
        // 'completed' the moment an operator tapped "Signer côté LPC". That
        // was a regression: an internal signature is an ATTESTATION, not the
        // customer's receipt confirmation. Closing the BL from the internal
        // button meant that, the instant LPC signed first, the "Faire Signer"
        // share dropdown disappeared (bon_livraison.js hides it when
        // signatures.status === 'completed') AND sign_bl.php refused the
        // customer's link with "déjà signée et clôturée" — so the client could
        // never sign at all.
        //
        // A BL is only ever "completed" when the CUSTOMER signs it externally:
        // they are the party who confirms received quantities, empties
        // returned and cash paid, and lpc_signature_side_effects_bl() books
        // exactly those figures. The internal button therefore records its
        // hash-only attestation row and does NOT touch business state — for a
        // BL it must behave exactly like quote / invoice / po / payslip /
        // contract. The CRE case is the one genuine exception (see the
        // sign_external comment): the operator measures the empties and signs
        // the CRE "closed" before it is ever handed to the customer, whose
        // signature is then the acknowledgment.
        try {
            $db = Database::getInstance()->getConnection();
        } catch (Throwable $e) {
            error_log('signatures_controller: DB unavailable at sign_internal: ' . $e->getMessage());
            sig_fail('Service indisponible.', 503, 'db_down');
        }

        // Decide whether side effects should fire for THIS doc, from THIS
        // party. Keep the rule table tiny and explicit — anything not listed
        // here defaults to false, so quote / invoice / po / payslip /
        // contract / bl are all pure attestations.
        $shouldRunSideEffects = false;
        try {
            if ($type === 'cre') {
                $s = $db->prepare("SELECT status FROM cre_documents WHERE id = ? LIMIT 1");
                $s->execute([$docId]);
                $shouldRunSideEffects = ((string) $s->fetchColumn() === 'en_transit');
            }
        } catch (Throwable $e) {
            // A read failure here is fatal — better to refuse the
            // signature than to fall through and lose the state check.
            error_log('signatures_controller: state check failed at sign_internal: ' . $e->getMessage());
            sig_fail('Service indisponible.', 503, 'db_down');
        }

        // An internal signature carries no counterparty-editable extras (only
        // BL sign_external posts items[]/payment/observations). For the one
        // type that can still reach the side-effects layer from here (CRE),
        // those extras are unused — CRE has no customer-editable fields — but
        // the dispatcher signature expects them, so pass empty values rather
        // than branching the call.
        $extras = [
            'items'             => [],
            'payment_collected' => null,
            'observations'      => null,
        ];

        try {
            $db->beginTransaction();

            if ($shouldRunSideEffects) {
                lpc_signature_side_effects_dispatch($db, $type, $docId, $extras);
            }

            // Re-read after side effects (a no-op re-read if we skipped)
            // so the hash covers the post-state, matching sign_external's
            // ordering exactly. $token was captured from sig_resolve()
            // above (4th element of the return tuple).
            $signedDoc = $shouldRunSideEffects
                ? sig_load_doc($db, $type, $token)
                : $doc;
            if (!$signedDoc) {
                throw new RuntimeException('Document illisible après mise à jour.');
            }

            $row = DocumentSignature::signInternal(
                $type, $docId, $signedDoc, (int) $_SESSION['user_id']
            );
            $db->commit();
        } catch (RuntimeException $e) {
            if ($db->inTransaction()) $db->rollBack();
            sig_fail($e->getMessage(), 400, 'signature_refused');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('signatures_controller: sign_internal failed: ' . $e->getMessage());
            sig_fail("La signature n'a pas pu être enregistrée.", 500, 'server');
        }

        sig_ok([
            'signature'  => sig_public($row),
            'verify_url' => sig_verify_base() . '/verify.php?token=' . $row['verify_token'],
        ]);
        break;
    }

    // -------------------------------------------------------------------------
    // sign_external — token-only. Rate-limited per IP because there is no
    // login gate: 30 attempts per 15 minutes is more than enough for a
    // legitimate customer even accounting for retries after a bad draw.
    // -------------------------------------------------------------------------
    case 'sign_external': {
        if ($method !== 'POST') sig_fail('POST requis.', 405, 'method');
        Csrf::requireValid();

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        RateLimiter::guard('sig_external_ip', $ip, 30, 15);

        [$type, $docId, $doc, $token] = sig_resolve();

        $body = sig_body();
        $identity = [
            'name'  => trim((string) ($body['signatory_name']  ?? '')),
            'role'  => trim((string) ($body['signatory_role']  ?? '')),
            'phone' => trim((string) ($body['signatory_phone'] ?? '')),
        ];
        $image  = (string) ($body['signature_image'] ?? '');
        // Doc-type-specific extras the phone posted alongside the signature
        // (BL sends items[]/payment/observations; CRE sends none). Whitelist
        // them here so the side-effects layer never sees $_POST directly.
        $extras = [
            'items'             => is_array($body['items'] ?? null) ? $body['items'] : [],
            'payment_collected' => $body['payment_collected'] ?? null,
            'observations'      => $body['observations']      ?? null,
        ];

        // ONE transaction covers both the signature row and the downstream
        // state change (delivery status flip, ledger updates, inventory
        // movements). A failure in either half rolls both back.
        try {
            $db = Database::getInstance()->getConnection();
        } catch (Throwable $e) {
            error_log('signatures_controller: DB unavailable at sign_external: ' . $e->getMessage());
            sig_fail('Service indisponible.', 503, 'db_down');
        }

        // CRE ONLY — mirrors the $shouldRunSideEffects check sign_internal
        // already applies (see the Sprint 12 comment on that case above).
        //
        // empties_collection.php now offers a "Signer côté LPC" gate BEFORE
        // a CRE is ever shared with the client (openCreDispatchGate() in
        // operations-empties_collection.js), so the operator's internal
        // signature routinely runs lpc_signature_side_effects_cre() FIRST
        // now — booking the empties ledger and flipping cre_documents.status
        // to 'signed' before the customer ever opens their link.
        // lpc_signature_side_effects_cre() requires status = 'en_transit'
        // and throws "déjà traité" otherwise. Unlike sign_internal, this
        // case had no pre-check for that — so without this guard, EVERY
        // customer signature on a CRE LPC already signed would hit that
        // throw and fail with a 500, even though the link is perfectly
        // valid. Skipping side effects here does not skip the customer's
        // own signature row below, only a state change that already
        // happened. BL keeps its original unconditional behaviour: the
        // customer can still amend figures on their phone before signing,
        // a materially different situation this fix does not need to touch.
        $shouldRunSideEffects = true;
        if ($type === 'cre') {
            try {
                $s = $db->prepare("SELECT status FROM cre_documents WHERE id = ? LIMIT 1");
                $s->execute([$docId]);
                $shouldRunSideEffects = ((string) $s->fetchColumn() === 'en_transit');
            } catch (Throwable $e) {
                error_log('signatures_controller: state check failed at sign_external: ' . $e->getMessage());
                sig_fail('Service indisponible.', 503, 'db_down');
            }
        }

        // ORDER IS LOAD-BEARING: side effects FIRST, then re-load, then sign.
        //
        // On a BL the counterparty can amend the figures on their phone
        // before signing (received quantities, empties returned, cash paid
        // to the driver, reserves). Those amendments land in the database
        // via the side-effects layer. If we hashed the document as it stood
        // when the page was opened, the stored content_hash would describe
        // the PRE-amendment figures, and every later render would recompute
        // a different hash from the post-amendment row — so
        // getActiveByParty() would return null and the signature would show
        // as unsigned forever. Applying the edits, re-reading the document,
        // and only then hashing means the signature covers exactly the
        // figures the customer actually agreed to.
        try {
            $db->beginTransaction();

            if ($shouldRunSideEffects) {
                lpc_signature_side_effects_dispatch($db, $type, $docId, $extras);
            }

            // Re-read after side effects (a no-op re-read if we skipped —
            // $doc, captured by sig_resolve() above, already reflects the
            // document as it stands) so the hash covers the post-state,
            // matching sign_internal's ordering exactly.
            $signedDoc = $shouldRunSideEffects ? sig_load_doc($db, $type, $token) : $doc;
            if (!$signedDoc) {
                throw new RuntimeException('Document illisible après mise à jour.');
            }

            $row = DocumentSignature::signExternal($type, $docId, $signedDoc, $identity, $image);
            $db->commit();
        } catch (RuntimeException $e) {
            if ($db->inTransaction()) $db->rollBack();
            sig_fail($e->getMessage(), 400, 'signature_refused');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('signatures_controller: sign_external failed: ' . $e->getMessage());
            sig_fail("La signature n'a pas pu être enregistrée.", 500, 'server');
        }

        sig_ok([
            'signature'  => sig_public($row),
            'verify_url' => sig_verify_base() . '/verify.php?token=' . $row['verify_token'],
        ]);
        break;
    }

    // -------------------------------------------------------------------------
    // decline — the counterparty says no, without signing. Token-gated like
    // sign_external, and rate-limited for the same reason.
    //
    // Currently quote-only. CRE and BL keep their own reject_cre / reject_bl
    // actions, which predate this controller and are wired into their pages
    // already — rerouting them here would be churn for no gain today. If a
    // third type ever needs declining, unify all three here rather than
    // adding a fourth bespoke endpoint.
    // -------------------------------------------------------------------------
    case 'decline': {
        if ($method !== 'POST') sig_fail('POST requis.', 405, 'method');
        Csrf::requireValid();

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        RateLimiter::guard('sig_decline_ip', $ip, 30, 15);

        [$type, $docId, $doc] = sig_resolve();
        if ($type !== 'quote') {
            sig_fail('Type non pris en charge pour cette action.', 400, 'unsupported_type');
        }

        $body   = sig_body();
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '') sig_fail('Motif requis.', 400, 'bad_request');

        try {
            $db = Database::getInstance()->getConnection();
        } catch (Throwable $e) {
            error_log('signatures_controller: DB unavailable at decline: ' . $e->getMessage());
            sig_fail('Service indisponible.', 503, 'db_down');
        }

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT id, reference, status, client_name FROM proposals WHERE id = ? FOR UPDATE");
            $stmt->execute([$docId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$p) throw new RuntimeException('Devis introuvable.');
            if ($p['status'] === 'accepted') {
                throw new RuntimeException('Ce devis a déjà été accepté et signé.');
            }

            // 'rejected' is a pre-existing value of the proposals.status
            // enum. The reason rides on notes rather than a new column —
            // it is commercial feedback for the rep, not structured data.
            $db->prepare("UPDATE proposals SET status = 'rejected' WHERE id = ?")->execute([$docId]);

            lpc_signature_notify_admin(
                $db,
                'Devis à revoir',
                "Le client ({$p['client_name']}) souhaite discuter du devis {$p['reference']}.\n"
                . "Message : " . mb_substr($reason, 0, 500)
            );

            $db->commit();
        } catch (RuntimeException $e) {
            if ($db->inTransaction()) $db->rollBack();
            sig_fail($e->getMessage(), 400, 'decline_refused');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('signatures_controller: decline failed: ' . $e->getMessage());
            sig_fail("La demande n'a pas pu être enregistrée.", 500, 'server');
        }

        sig_ok(['message' => 'Votre message a été transmis à votre conseiller.']);
        break;
    }

    // -------------------------------------------------------------------------
    // revoke — authenticated, gated by the same permission that grants
    // signing. Revoking is by row id, not by (doc, party), because revoking
    // "the current active signature" is ambiguous if multiple have accrued.
    // -------------------------------------------------------------------------
    case 'revoke': {
        if ($method !== 'POST') sig_fail('POST requis.', 405, 'method');
        Csrf::requireValid();
        if (empty($_SESSION['user_id'])) sig_fail('Session requise.', 401, 'auth');

        $body = sig_body();
        $sigId  = (int) ($body['signature_id'] ?? 0);
        $reason = trim((string) ($body['reason'] ?? ''));

        if ($sigId <= 0) sig_fail('signature_id manquant.', 400, 'bad_request');

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare('SELECT document_type, party FROM document_signatures WHERE id = ? LIMIT 1');
            $stmt->execute([$sigId]);
            $meta = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('signatures_controller: revoke lookup failed: ' . $e->getMessage());
            sig_fail('Service indisponible.', 503, 'db_down');
        }
        if (!$meta) sig_fail('Signature introuvable.', 404, 'not_found');

        Rbac::requirePermission(DocumentSignature::permissionFor(
            $meta['document_type'],
            $meta['party']
        ));

        $ok = DocumentSignature::revoke($sigId, (int) $_SESSION['user_id'], $reason);
        if (!$ok) sig_fail('La signature n\'a pas pu être révoquée (déjà révoquée ?).', 400, 'revoke_failed');
        sig_ok();
        break;
    }

    default:
        sig_fail('Action inconnue.', 400, 'unknown_action');
}
