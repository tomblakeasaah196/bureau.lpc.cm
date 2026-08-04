<?php
/**
 * scripts/correct_jbs_praxis_empties_ledger.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — one-shot corrective SQL for JBS Praxis (client_id = 105).
 *
 * CONTEXT
 *   BL-2608-CB78 (CMD-2608-03837, JBS PRAXIS LLC) was signed on 2026-08-04.
 *   The phone form on public/documents/sign_bl.php used to pre-fill the
 *   "vides rendus" field from delivery_items.returned_empty_qty, which the
 *   driver had populated (mistakenly) with the same figure as the received
 *   quantity. Elisha Godwin signed the BL as-is. Consequence: the empties
 *   ledger booked total_out = 1000 / total_in = 1000 for product 903
 *   (10L avec Bouchon) and total_out = 666 / total_in = 666 for product 901
 *   (20L avec Bouchon) in the same signature event. quantity_owed landed on
 *   zero, JBS Praxis vanished from the DUS list, and 1666 empties were, in
 *   the ERP's view, no longer owed by anyone.
 *
 *   Physically, those 1666 empties are still with the client. The rows must
 *   be corrected to reflect that.
 *
 *   The forward fix lives in public/documents/sign_bl.php (default the
 *   input to 0, unmissable amber banner) and public/documents/bon_livraison
 *   .php + assets/js/modules/documents-bon_livraison.js + api/v1/get_bl.php
 *   (the printed PDF now carries a "Vides Rendus" column so the paper the
 *   customer signs matches the payload byte-for-byte).
 *
 *   This script is the retroactive complement. It runs against exactly two
 *   rows, is idempotent (safe to re-run — it is a no-op after the first
 *   apply), and is reversible: the reverse UPDATE is printed at the end.
 *
 * USAGE
 *   php scripts/correct_jbs_praxis_empties_ledger.php           # dry-run
 *   php scripts/correct_jbs_praxis_empties_ledger.php --apply   # commit
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$apply = in_array('--apply', $argv ?? [], true);

$db = Database::getInstance()->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Row identifiers as they were observed at diagnosis time. Pinned by
// (client_id, product_id) so that a re-numbered ledger PK does not break
// the correction — the natural key is what matters.
$targets = [
    ['client_id' => 105, 'product_id' => 903, 'expected_total_in' => 1000, 'expected_total_out' => 1000],
    ['client_id' => 105, 'product_id' => 901, 'expected_total_in' => 666,  'expected_total_out' => 666],
];

echo "== JBS Praxis (client 105) empties-ledger correction ==\n";
echo $apply ? "MODE: APPLY (rows will be updated)\n\n" : "MODE: DRY-RUN (no changes)\n\n";

$db->beginTransaction();
try {
    $sel = $db->prepare(
        "SELECT id, client_id, site_id, product_id, total_out, total_in, quantity_owed, last_updated
           FROM client_empties_ledger
          WHERE client_id = ? AND product_id = ?
          FOR UPDATE"
    );
    // Zero out total_in and re-derive quantity_owed as total_out - total_in.
    // We deliberately do NOT touch total_out — the client did receive the
    // full bottles, that half of the ledger is correct.
    $upd = $db->prepare(
        "UPDATE client_empties_ledger
            SET total_in = 0,
                quantity_owed = GREATEST(0, total_out - 0),
                last_updated = NOW()
          WHERE id = ?"
    );

    $changed = 0;
    $skipped = 0;

    foreach ($targets as $t) {
        $sel->execute([$t['client_id'], $t['product_id']]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo "  · client {$t['client_id']} / product {$t['product_id']}: no ledger row found — skipped.\n";
            $skipped++;
            continue;
        }

        printf(
            "  · row #%d (client %d / product %d): current out=%d in=%d owed=%d\n",
            $row['id'], $row['client_id'], $row['product_id'],
            $row['total_out'], $row['total_in'], $row['quantity_owed']
        );

        // Idempotency guard: already corrected on a previous run.
        if ((int) $row['total_in'] === 0 && (int) $row['quantity_owed'] === (int) $row['total_out']) {
            echo "     already at target state — skipped.\n";
            $skipped++;
            continue;
        }

        // Safety guard: only apply when the observed values match what we
        // diagnosed. If the numbers have since drifted (a later CRE
        // returned some empties, a manual adjustment happened, etc.) the
        // operator should re-diagnose rather than let this script clobber
        // legitimate movement.
        if ((int) $row['total_out'] !== (int) $t['expected_total_out']
         || (int) $row['total_in']  !== (int) $t['expected_total_in']) {
            echo "     values have drifted from the diagnosis snapshot — skipped for safety.\n";
            echo "     (expected out={$t['expected_total_out']} in={$t['expected_total_in']})\n";
            $skipped++;
            continue;
        }

        $newOwed = (int) $row['total_out']; // total_in becomes 0
        printf("     -> new state: out=%d in=0 owed=%d\n",
            $row['total_out'], $newOwed);

        // Reverse SQL, printed even in apply mode so the operator has a
        // copy-paste rollback next to the run log.
        printf(
            "     REVERSE: UPDATE client_empties_ledger SET total_in = %d, quantity_owed = %d WHERE id = %d;\n",
            $row['total_in'], $row['quantity_owed'], $row['id']
        );

        if ($apply) {
            $upd->execute([$row['id']]);
            $changed++;
        }
    }

    if ($apply) {
        $db->commit();
        echo "\nCommitted. Rows changed: {$changed}. Rows skipped: {$skipped}.\n";
    } else {
        $db->rollBack();
        echo "\nDry-run complete. Re-run with --apply to commit. Rows that would change: "
           . (count($targets) - $skipped) . ".\n";
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}
