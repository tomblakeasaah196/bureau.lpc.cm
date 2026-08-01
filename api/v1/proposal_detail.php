<?php
/**
 * api/v1/proposal_detail.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — one devis, by internal id, for the detail modal on the
 * "Devis" tab of modules/crm/clients.php.
 *
 * Distinct from get_proposal.php on purpose. That endpoint is TOKEN-gated and
 * public-facing: the token is the credential, it feeds quote.php and the
 * client's PDF, and it deliberately exposes only what a client may see. This
 * one is ID-gated behind crm.proposals.view and answers the two questions the
 * internal répertoire needs and the public endpoint must never answer:
 *
 *   "can I edit this?"  → editable=false the moment any live signature exists.
 *   "who signed it?"    → the full signature block, including the STALE case.
 *
 * ── Stale signatures ──────────────────────────────────────────────────────
 * fetch_proposals.php marks a devis "Signé" from a plain SQL join: any
 * non-revoked document_signatures row. That is the right rule for LOCKING and
 * it is cheap enough to page and count on. Here, with a single devis loaded,
 * we can afford DocumentSignature::getActive(), which additionally re-hashes
 * the current figures and returns null if they no longer match what was
 * signed. Comparing it against latestAny() separates:
 *
 *   active  — signed, and the figures still match. The normal signed devis.
 *   stale   — signed, then the document changed underneath the signature.
 *
 * A stale devis stays LOCKED here even though its signature no longer
 * validates. Unlocking it would let an edit quietly erase the evidence that
 * something was signed and then altered — the one case the signature exists to
 * catch. The modal surfaces the discrepancy and the way out is a new devis, or
 * an explicit revoke through proposal_signature_controller.php by someone
 * holding crm.proposals.sign.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/document_pdf.php';
require_once __DIR__ . '/../../includes/classes/DocumentSignature.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('crm.proposals.view');

function pd_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function pd_fail(string $msg, int $code = 400): void
{
    pd_out(['status' => 'error', 'message' => $msg], $code);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    pd_fail('Devis introuvable.', 404);
}

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    error_log('proposal_detail: DB unavailable: ' . $e->getMessage());
    pd_fail('Service indisponible.', 503);
}

try {
    // ---- header ----------------------------------------------------------
    // Reads the snapshot columns, exactly as get_proposal.php does — no join
    // back to clients. A devis shows the client as it was WHEN IT WAS SENT;
    // if the company has since been renamed, the historical document must not
    // silently change. client_id is looked up separately, below, and only for
    // the "duplicate" flow.
    $stmt = $db->prepare("
        SELECT id, reference, `date`, validity_days,
               DATE_ADD(`date`, INTERVAL validity_days DAY) AS expires_on,
               delivery_frequency, buffer_stock_weeks, payment_terms,
               empties_policy, total_amount, token, language, status,
               client_name, client_contact, client_title, client_email, client_phone,
               sales_rep_name, created_at
          FROM proposals
         WHERE id = :id
         LIMIT 1
    ");
    $stmt->execute(['id' => $id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
        pd_fail('Devis introuvable.', 404);
    }

    // updated_at only exists after migration 056; absence must not 500 an
    // install that has the code but not the schema yet.
    $hasUpdatedAt = (int) $db->query("
        SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'proposals'
           AND column_name = 'updated_at'
    ")->fetchColumn() > 0;
    if ($hasUpdatedAt) {
        $u = $db->prepare('SELECT updated_at FROM proposals WHERE id = ?');
        $u->execute([$id]);
        $p['updated_at'] = $u->fetchColumn() ?: null;
    } else {
        $p['updated_at'] = null;
    }

    // ---- lines -----------------------------------------------------------
    // product_id (migration 056) and pack_note (047) are both guarded: this
    // endpoint keeps serving devis written before either column existed
    // rather than 500ing on a missing column, the same way get_proposal.php
    // guards pack_note.
    $cols = $db->query("
        SELECT column_name FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'proposal_items'
    ")->fetchAll(PDO::FETCH_COLUMN);

    $hasPackNote  = in_array('pack_note', $cols, true);
    $hasProductId = in_array('product_id', $cols, true);

    $select = 'id, product_description, product_format, quantity, unit_price, total_price'
            . ($hasPackNote  ? ', pack_note'  : '')
            . ($hasProductId ? ', product_id' : '');

    $itemStmt = $db->prepare("
        SELECT {$select} FROM proposal_items WHERE proposal_id = ? ORDER BY id ASC
    ");
    $itemStmt->execute([$id]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    // A line with no product_id cannot be re-priced through the picker — the
    // editor renders it read-only. Flagged here rather than inferred in JS so
    // the rule lives next to the column that causes it.
    foreach ($items as &$it) {
        $it['product_id'] = $hasProductId ? ($it['product_id'] ?? null) : null;
        $it['locked']     = empty($it['product_id']);
    }
    unset($it);

    // ---- signature -------------------------------------------------------
    $signature = null;
    $stale     = false;
    $signed    = false;

    // lpc_load_document() rebuilds the devis in the exact shape the PDF
    // renderer hashes, which is what makes getActive()'s comparison
    // meaningful. Token-based because that is the signature subsystem's only
    // lookup key.
    if (!empty($p['token'])) {
        $doc = lpc_load_document($db, 'quote', (string) $p['token']);
        if ($doc) {
            $active = DocumentSignature::getActive('quote', (int) $p['id'], $doc);
            $latest = DocumentSignature::latestAny('quote', (int) $p['id']);

            $signed = (bool) $latest;                       // matches the list's rule
            $stale  = $latest && !$active;                  // signed, then changed
            $row    = $active ?: $latest;

            if ($row) {
                $verifyBase = defined('APP_URL') ? APP_URL : 'https://bureau.lpc.cm';
                $signature = [
                    'signer_name'  => $row['signer_name'],
                    'signer_role'  => $row['signer_role'],
                    'signed_at'    => $row['signed_at'],
                    'hash_prefix'  => substr((string) $row['content_hash'], 0, 16),
                    'verify_url'   => rtrim($verifyBase, '/') . '/verify/' . $row['verify_token'],
                    'stale'        => $stale,
                ];
            }
        }
    }

    // ---- duplicate target -------------------------------------------------
    // "Créer un nouveau devis à partir de celui-ci" needs a LIVE client id;
    // the devis only stores the name snapshot. Matched on name, restricted to
    // clients not in the corbeille. Null is a legitimate answer (client
    // renamed or deleted) and the UI falls back to asking the user to pick.
    $cid = $db->prepare("
        SELECT id FROM clients
         WHERE name = :name AND deleted_at IS NULL
         ORDER BY id ASC LIMIT 1
    ");
    $cid->execute(['name' => $p['client_name']]);
    $p['client_id'] = ($v = $cid->fetchColumn()) ? (int) $v : null;

    $expired = !$signed
        && !empty($p['expires_on'])
        && strtotime((string) $p['expires_on']) < strtotime(date('Y-m-d'));

    pd_out([
        'status'          => 'success',
        'data'            => $p,
        'items'           => $items,
        'signed'          => $signed,
        'stale'           => $stale,
        'signature'       => $signature,
        // The single flag the UI keys off. Server-side, update_proposal.php
        // re-derives it independently — this value is convenience, never
        // authorisation.
        'editable'        => !$signed && Rbac::hasPermission('crm.proposals.create'),
        'derived_status'  => $signed ? 'signed' : ($expired ? 'expired' : 'sent'),
        'quote_url'       => (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://bureau.lpc.cm')
                             . '/quote.php?token=' . $p['token'],
    ]);

} catch (Throwable $e) {
    error_log('proposal_detail: ' . $e->getMessage());
    pd_fail('Erreur serveur. Veuillez réessayer.', 500);
}
