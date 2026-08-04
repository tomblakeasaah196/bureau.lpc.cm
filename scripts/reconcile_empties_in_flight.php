<?php
/**
 * scripts/reconcile_empties_in_flight.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — nightly drift catcher for client_empties_ledger
 * .quantity_in_flight.
 *
 * WHY THIS EXISTS
 *   The +/- accounting for quantity_in_flight fires at three moments:
 *     · BL dispatch                    (sales_controller.php save_order)
 *     · BL signature                   (lpc_signature_side_effects_bl)
 *     · migration backfill             (migrations/074_empties_in_flight.sql)
 *
 *   Any hand-written UPDATE that touches deliveries.status without going
 *   through the two forward paths — a data-fix script, an operator rescuing
 *   a stuck delivery in phpMyAdmin, a future feature that adds a fourth
 *   status — will silently drift the counter. This script recomputes the
 *   truth from deliveries + delivery_items + products.linked_empty_id, then
 *   compares against the stored value. In --apply mode it overwrites the
 *   stored figure; in dry-run it prints the diff and does nothing.
 *
 * SAFE TO RUN NIGHTLY
 *   Idempotent. The recompute is a pure aggregation over the current state
 *   of open BLs; running it twice in a row produces the same numbers. See
 *   scripts/cron/ for cron examples (add a `reconcile_empties_in_flight.sh`
 *   wrapper if wiring it up).
 *
 * USAGE
 *   php scripts/reconcile_empties_in_flight.php           # dry-run + report
 *   php scripts/reconcile_empties_in_flight.php --apply   # overwrite drift
 *   php scripts/reconcile_empties_in_flight.php --quiet   # suppress "match"
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$apply = in_array('--apply', $argv ?? [], true);
$quiet = in_array('--quiet', $argv ?? [], true);

$db = Database::getInstance()->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "== Empties in-flight reconciliation ==\n";
echo $apply ? "MODE: APPLY (drift will be overwritten)\n\n" : "MODE: DRY-RUN\n\n";

// -----------------------------------------------------------------------------
// 1. Compute the truth. Per (client_id, product_id=linked_empty_id), sum
//    the accepted-or-shipped quantities on every open BL. Same shape as
//    the migration backfill so re-running either lands on the same number.
// -----------------------------------------------------------------------------
$truthStmt = $db->query("
    SELECT
        d.client_id,
        p.linked_empty_id AS product_id,
        SUM(COALESCE(di.delivered_quantity, di.quantity)) AS in_flight
    FROM deliveries d
    JOIN delivery_items di ON di.delivery_id = d.id
    JOIN products p        ON p.id = di.product_id
    WHERE d.status IN ('dispatched', 'driver_confirmed')
      AND p.linked_empty_id IS NOT NULL
    GROUP BY d.client_id, p.linked_empty_id
");
$truth = [];
foreach ($truthStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $key = $r['client_id'] . ':' . $r['product_id'];
    $truth[$key] = (int) $r['in_flight'];
}

// -----------------------------------------------------------------------------
// 2. Compare against stored. Iterate every ledger row that either currently
//    holds a non-zero in-flight OR should according to truth — union of the
//    two keyspaces so a row that dropped to zero also gets caught.
// -----------------------------------------------------------------------------
$storedStmt = $db->query("
    SELECT id, client_id, product_id, quantity_in_flight
      FROM client_empties_ledger
     WHERE quantity_in_flight <> 0
        OR (client_id, product_id) IN (
            SELECT d.client_id, p.linked_empty_id
              FROM deliveries d
              JOIN delivery_items di ON di.delivery_id = d.id
              JOIN products p        ON p.id = di.product_id
             WHERE d.status IN ('dispatched', 'driver_confirmed')
               AND p.linked_empty_id IS NOT NULL
        )
");
$stored = [];
foreach ($storedStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $key = $r['client_id'] . ':' . $r['product_id'];
    $stored[$key] = ['id' => (int) $r['id'], 'v' => (int) $r['quantity_in_flight']];
}

// -----------------------------------------------------------------------------
// 3. Diff and act.
// -----------------------------------------------------------------------------
$allKeys = array_unique(array_merge(array_keys($truth), array_keys($stored)));
sort($allKeys);

$fixed    = 0;
$matches  = 0;
$missing  = 0; // truth says a row should exist but ledger has none
$drift    = 0;

$db->beginTransaction();
try {
    $upd = $db->prepare(
        "UPDATE client_empties_ledger
            SET quantity_in_flight = ?
          WHERE id = ?"
    );
    // NULL-site trap: MySQL treats NULL as distinct in UNIQUE(client_id,
    // site_id, product_id), so a naked INSERT can silently duplicate an
    // existing NULL-site row. Look up first, and if a NULL-site row
    // already exists for the same (client, product) we overwrite it via
    // the UPDATE path instead of inserting a second row.
    $lookup = $db->prepare(
        "SELECT id FROM client_empties_ledger
          WHERE client_id = ? AND product_id = ? AND site_id IS NULL
          LIMIT 1"
    );
    $ins = $db->prepare(
        "INSERT INTO client_empties_ledger
             (client_id, site_id, product_id,
              total_out, total_in, quantity_owed, quantity_in_flight)
         VALUES (?, NULL, ?, 0, 0, 0, ?)"
    );

    foreach ($allKeys as $key) {
        [$clientId, $productId] = array_map('intval', explode(':', $key));
        $truthVal  = $truth[$key]  ?? 0;
        $storedRow = $stored[$key] ?? null;

        if ($storedRow === null) {
            // Truth says non-zero but no row exists. Should only happen for
            // clients whose ledger row was manually deleted or migrated;
            // create it so the counter is representable.
            if ($truthVal > 0) {
                printf("  · client %d / product %d: MISSING row, truth=%d\n",
                    $clientId, $productId, $truthVal);
                if ($apply) {
                    // Guarded insert: if a NULL-site row snuck in via a
                    // path outside the storedStmt query above, overwrite
                    // it rather than duplicate.
                    $lookup->execute([$clientId, $productId]);
                    $existingId = $lookup->fetchColumn();
                    if ($existingId) {
                        $upd->execute([$truthVal, (int) $existingId]);
                    } else {
                        $ins->execute([$clientId, $productId, $truthVal]);
                    }
                    $fixed++;
                }
                $missing++;
            }
            continue;
        }

        if ($storedRow['v'] === $truthVal) {
            if (!$quiet) {
                printf("  · client %d / product %d: OK (%d)\n",
                    $clientId, $productId, $truthVal);
            }
            $matches++;
            continue;
        }

        $delta = $truthVal - $storedRow['v'];
        printf("  · client %d / product %d: DRIFT stored=%d truth=%d delta=%+d\n",
            $clientId, $productId, $storedRow['v'], $truthVal, $delta);
        if ($apply) {
            $upd->execute([$truthVal, $storedRow['id']]);
            $fixed++;
        }
        $drift++;
    }

    if ($apply) {
        $db->commit();
    } else {
        $db->rollBack();
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}

echo "\n";
echo "Match:    {$matches}\n";
echo "Drift:    {$drift}\n";
echo "Missing:  {$missing}\n";
echo $apply ? "Applied:  {$fixed}\n" : "Would apply: " . ($drift + $missing) . "\n";
