<?php
/**
 * api/v1/update_proposal.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — edit an UNSIGNED devis in place, from the detail modal on
 * the "Devis" tab of modules/crm/clients.php.
 *
 * ── The contract ──────────────────────────────────────────────────────────
 * The reference and the token are KEPT. A devis that has been shared keeps
 * working on the link the client already holds, and that link now shows the
 * corrected figures. This is the deliberate choice over rotating the token:
 * the overwhelmingly common edit is a correction made minutes after sending
 * ("wrong quantity", "wrong price"), and invalidating the link forces a
 * re-send for a mistake the client has usually not opened yet.
 *
 * The cost of that choice is real and worth stating: a client who ALREADY
 * read the devis sees it change under them with no notice. The mitigation is
 * that once anyone signs, this endpoint refuses outright — the moment the
 * document carries an attestation, silent mutation stops being acceptable and
 * the only path forward is a new devis.
 *
 * ── Why signed devis are rejected here and not just hidden in the UI ──────
 * proposal_detail.php returns editable=false and the modal renders read-only,
 * but that is presentation. This check is the one that matters: a POST
 * crafted by hand, a stale tab left open before someone else signed, or a
 * future caller all land here. The rule is re-derived from
 * document_signatures on every request, inside the same transaction that does
 * the write, so a signature landing mid-edit cannot be raced past.
 *
 * Matches fetch_proposals.php's rule exactly — ANY non-revoked signature
 * locks, including a stale one whose hash no longer matches. See
 * proposal_detail.php for why stale still counts.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

Rbac::requirePermission('crm.proposals.create');
Csrf::requireValid();

function up_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function up_fail(string $msg, int $code = 400): void
{
    up_out(['status' => 'error', 'message' => $msg], $code);
}

$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) { $data = $_POST; }

$id = (int) ($data['id'] ?? 0);
if ($id <= 0) {
    up_fail('Devis introuvable.', 404);
}
if (empty($data['items']) || !is_array($data['items'])) {
    up_fail('Un devis doit comporter au moins une ligne.');
}

try {
    $db = Database::getInstance()->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    error_log('update_proposal: DB unavailable: ' . $e->getMessage());
    up_fail('Service indisponible.', 503);
}

try {
    $db->beginTransaction();

    // ---- 1. the devis must exist, and must not be signed -------------------
    //
    // FOR UPDATE holds the row for the duration of the transaction. Without
    // it, "check unsigned" and "write" are two statements with a gap between
    // them, and a signature committed inside that gap would be silently
    // invalidated by an edit that had already passed its check.
    $stmt = $db->prepare('SELECT id, reference, token FROM proposals WHERE id = ? FOR UPDATE');
    $stmt->execute([$id]);
    $proposal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$proposal) {
        $db->rollBack();
        up_fail('Devis introuvable.', 404);
    }

    // migration 048 may not have run; no table means nothing can be signed.
    $hasSigTable = (int) $db->query("
        SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'document_signatures'
    ")->fetchColumn() > 0;

    if ($hasSigTable) {
        $sig = $db->prepare("
            SELECT COUNT(*) FROM document_signatures
             WHERE document_type = 'quote' AND document_id = ? AND revoked_at IS NULL
        ");
        $sig->execute([$id]);
        if ((int) $sig->fetchColumn() > 0) {
            $db->rollBack();
            up_out([
                'status'  => 'error',
                'locked'  => true,
                'message' => 'Ce devis est signé et ne peut plus être modifié. '
                           . 'Créez un nouveau devis à partir de celui-ci.',
            ], 409);
        }
    }

    // ---- 2. metadata -------------------------------------------------------
    //
    // The client snapshot is NOT touched. Re-resolving it would let a client
    // renamed since the devis was written silently rewrite a document already
    // in someone's inbox — the exact thing the snapshot design prevents. An
    // edit changes terms and lines, never who the devis was addressed to; for
    // that, the answer is a new devis.
    $validity = (int) ($data['validity_days'] ?? 30);
    if ($validity < 0) { $validity = 0; }
    $buffer = (int) ($data['buffer_stock_weeks'] ?? 2);
    if ($buffer < 0) { $buffer = 0; }

    $lang = ($data['language'] ?? 'fr') === 'en' ? 'en' : 'fr';

    // ---- 3. recompute the total from the lines -----------------------------
    //
    // Recomputed server-side, never taken from the payload — the client is
    // free to send whatever total it likes and this figure is what the PDF,
    // the KPIs and the signature hash all read.
    $clean = [];
    $total = 0.0;
    foreach ($data['items'] as $item) {
        $qty   = (int) ($item['quantity'] ?? 0);
        $price = (float) ($item['unit_price'] ?? 0);
        if ($qty <= 0) { continue; }

        $pid = isset($item['product_id']) && $item['product_id'] !== '' && $item['product_id'] !== null
             ? (int) $item['product_id'] : null;

        // A line the editor rendered read-only (pre-056, no product_id) comes
        // back carrying its snapshot text instead. Preserved verbatim rather
        // than dropped: the alternative is that opening an old devis and
        // saving it silently deletes lines.
        $clean[] = [
            'product_id'  => $pid,
            'description' => (string) ($item['product_description'] ?? ''),
            'format'      => $item['product_format'] ?? null,
            'pack_note'   => $item['pack_note'] ?? null,
            'quantity'    => $qty,
            'unit_price'  => $price,
        ];
        $total += $qty * $price;
    }

    if (!$clean) {
        $db->rollBack();
        up_fail('Un devis doit comporter au moins une ligne valide.');
    }

    // ---- 4. resolve product snapshots for picker-backed lines --------------
    //
    // Re-snapshotted from the catalogue at save time, exactly the way
    // create_proposal.php does it, so a line added during an edit is
    // indistinguishable from one added at creation. Lines with no product_id
    // keep the text they arrived with.
    $itemCols = $db->query("
        SELECT column_name FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'proposal_items'
    ")->fetchAll(PDO::FETCH_COLUMN);

    $hasPackNote  = in_array('pack_note',  $itemCols, true);
    $hasProductId = in_array('product_id', $itemCols, true);

    $hasPackCols = (int) $db->query("
        SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'products'
           AND column_name IN ('units_per_pack', 'unit_of_measure')
    ")->fetchColumn() === 2;

    $prodCols = 'name, format' . ($hasPackCols ? ', units_per_pack, unit_of_measure' : '');
    $prodStmt = $db->prepare("SELECT {$prodCols} FROM products WHERE id = ?");

    foreach ($clean as &$line) {
        if ($line['product_id'] === null) { continue; }

        $prodStmt->execute([$line['product_id']]);
        $product = $prodStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            // Product deleted from the catalogue since. Keep whatever text the
            // line already had rather than writing "Produit ID: 42" onto a
            // devis the client may already have read.
            continue;
        }

        $line['description'] = $product['name'];
        $line['format']      = $product['format'];
        $line['pack_note']   = null;

        if ($hasPackCols) {
            $per = (int) ($product['units_per_pack'] ?? 1);
            if ($per > 1) {
                $uom = (!empty($product['unit_of_measure']) && $product['unit_of_measure'] !== 'unite')
                     ? $product['unit_of_measure'] : 'unité';
                $line['pack_note'] = "pack de {$per} {$uom}s";
            }
        }
    }
    unset($line);

    // ---- 5. write ----------------------------------------------------------
    $hasUpdatedAt = (int) $db->query("
        SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'proposals'
           AND column_name = 'updated_at'
    ")->fetchColumn() > 0;

    $sql = "
        UPDATE proposals SET
            validity_days      = :validity_days,
            delivery_frequency = :delivery_frequency,
            buffer_stock_weeks = :buffer_stock_weeks,
            payment_terms      = :payment_terms,
            empties_policy     = :empties_policy,
            language           = :language,
            total_amount       = :total_amount
            " . ($hasUpdatedAt ? ', updated_at = NOW()' : '') . "
        WHERE id = :id
    ";
    $db->prepare($sql)->execute([
        'validity_days'      => $validity,
        'delivery_frequency' => (string) ($data['delivery_frequency'] ?? 'Hebdomadaire'),
        'buffer_stock_weeks' => $buffer,
        'payment_terms'      => (string) ($data['payment_terms'] ?? 'Net 30 Jours'),
        'empties_policy'     => (string) ($data['empties_policy'] ?? ''),
        'language'           => $lang,
        'total_amount'       => $total,
        'id'                 => $id,
    ]);

    // Lines are REPLACED, not diffed. proposal_items rows are pure snapshot
    // data with no foreign keys pointing at them (migration 006 links
    // sales_orders to proposals, never to individual lines), so a delete-then-
    // insert is safe and avoids an update/insert/delete reconciliation that
    // would have to match rows by position.
    $db->prepare('DELETE FROM proposal_items WHERE proposal_id = ?')->execute([$id]);

    $cols = ['proposal_id', 'product_description', 'product_format', 'quantity', 'unit_price'];
    if ($hasPackNote)  { $cols[] = 'pack_note'; }
    if ($hasProductId) { $cols[] = 'product_id'; }

    $placeholders = implode(', ', array_map(static fn($c) => ':' . $c, $cols));
    $insert = $db->prepare(
        'INSERT INTO proposal_items (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')'
    );

    foreach ($clean as $line) {
        $bind = [
            'proposal_id'         => $id,
            'product_description' => $line['description'],
            'product_format'      => $line['format'],
            'quantity'            => $line['quantity'],
            'unit_price'          => $line['unit_price'],
        ];
        if ($hasPackNote)  { $bind['pack_note']  = $line['pack_note']; }
        if ($hasProductId) { $bind['product_id'] = $line['product_id']; }
        // total_price is STORED GENERATED — never inserted. Same as
        // create_proposal.php.
        $insert->execute($bind);
    }

    $db->commit();

    up_out([
        'status'    => 'success',
        'message'   => 'Devis mis à jour.',
        'reference' => $proposal['reference'],
        'token'     => $proposal['token'],
        'total'     => $total,
    ]);

} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    error_log('update_proposal: ' . $e->getMessage());
    up_fail('Erreur serveur. Veuillez réessayer.', 500);
}
