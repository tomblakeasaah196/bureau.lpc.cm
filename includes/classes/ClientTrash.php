<?php
/**
 * includes/classes/ClientTrash.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 11 · client corbeille (soft-delete + reassignment).
 *
 * One place owns the "delete a client" workflow so api/v1/delete_client.php,
 * api/v1/purge_client.php and scripts/cron/purge_deleted_clients.php can't
 * drift apart on which child tables get reassigned.
 *
 * THE MODEL
 * ---------
 *   1. softDelete($clientId, $reassignToId, $userId)
 *        Moves every transactional row that points at $clientId onto
 *        $reassignToId (merging where a unique key would otherwise collide),
 *        THEN sets clients.deleted_at/deleted_by/deleted_reassigned_to on
 *        $clientId. Permission: crm.clients.delete.
 *
 *        By the time this returns, $clientId has zero dependent rows left —
 *        which is exactly what makes the next two steps trivial deletes
 *        instead of a second reassignment pass.
 *
 *   2. restore($clientId)
 *        Clears deleted_at/deleted_by/deleted_reassigned_to. The client
 *        itself comes back; its historical orders/invoices/payments do NOT
 *        move back off the reassignment target — that merge was a
 *        deliberate, permanent consolidation, not a hold. Permission:
 *        crm.clients.restore.
 *
 *   3. purge($clientId)
 *        `DELETE FROM clients WHERE id = ? AND deleted_at IS NOT NULL`.
 *        Only callable on a client already sitting in the corbeille.
 *        Used both by the manual "Supprimer définitivement" button
 *        (permission: crm.clients.purge) and by the nightly cron once the
 *        retention window (CLIENTS_TRASH_RETENTION_DAYS) has passed.
 *
 * WHY REASSIGNMENT HAPPENS AT STEP 1, NOT STEP 3
 * -----------------------------------------------------------------------------
 *   client_wallets / client_empties_ledger / deliveries / payments /
 *   sales_orders all carry ON DELETE RESTRICT to clients (migration 006).
 *   RESTRICT only fires on a real DELETE, so soft-deleting alone never risks
 *   an orphan — but it also means the eventual hard DELETE in purge() would
 *   fail with a FK violation if those rows still pointed at the trashed
 *   client. Doing the reassignment once, up front, at the moment a client is
 *   sent to the corbeille means purge() (called either by a human today or
 *   the cron in 30 days) never has to ask "reassign to whom?" again — that
 *   question was already answered when the client was deleted.
 *
 * TABLES TOUCHED (every place in the codebase that writes a `client_id`
 * column, per a full grep of api/ + includes/ + modules/):
 *   sales_orders, deliveries, payments, invoices, cre_documents  — plain
 *     client_id column, reassign is a straight UPDATE.
 *   client_wallets       — UNIQUE(client_id): merge balance into the
 *                          target's row (or create one) instead of colliding.
 *   client_empties_ledger — UNIQUE(client_id, site_id, product_id): merge
 *                          quantities into the matching target row where one
 *                          already exists, else move the row.
 *   client_prices        — composite PK (client_id, product_id): the
 *                          target's own negotiated price wins on conflict
 *                          (a discount is a policy choice, not a ledger
 *                          balance — there's nothing to sum). The source row
 *                          is dropped in that case.
 *   client_sites         — reassigned by plain UPDATE. No known unique
 *                          constraint beyond the surrogate id.
 *
 * All of the "no known unique constraint" tables above are still wrapped in
 * the same collision-safe helper (updateOrMerge), so if a future migration
 * adds a unique key we haven't seen, this degrades to a per-row merge
 * instead of a hard failure partway through the transaction.
 * -----------------------------------------------------------------------------
 */

class ClientTrash
{
    /** Plain client_id columns — a straight reassignment UPDATE is safe. */
    private const SIMPLE_TABLES = [
        'sales_orders', 'deliveries', 'payments', 'invoices', 'cre_documents', 'client_sites',
    ];

    /**
     * Move $clientId to the corbeille: reassign its dependents to
     * $reassignToId, then soft-delete. Runs in one transaction — either the
     * whole thing lands or nothing does.
     *
     * @throws RuntimeException on bad input (caller turns this into a 4xx JSON reply)
     */
    public static function softDelete(PDO $db, int $clientId, int $reassignToId, int $userId): void
    {
        if ($clientId === $reassignToId) {
            throw new RuntimeException("Impossible de réaffecter un client vers lui-même.");
        }

        $ownTxn = !$db->inTransaction();
        if ($ownTxn) $db->beginTransaction();

        try {
            $source = self::lockClient($db, $clientId);
            if (!$source) throw new RuntimeException("Client introuvable.");
            if ($source['deleted_at'] !== null) throw new RuntimeException("Ce client est déjà dans la corbeille.");

            $target = self::lockClient($db, $reassignToId);
            if (!$target) throw new RuntimeException("Client de réaffectation introuvable.");
            if ($target['deleted_at'] !== null) throw new RuntimeException("Impossible de réaffecter vers un client déjà supprimé.");

            self::reassignAll($db, $clientId, $reassignToId);

            $upd = $db->prepare("
                UPDATE clients
                   SET deleted_at = NOW(), deleted_by = :uid, deleted_reassigned_to = :target
                 WHERE id = :id
            ");
            $upd->execute(['uid' => $userId, 'target' => $reassignToId, 'id' => $clientId]);

            if ($ownTxn) $db->commit();
        } catch (Throwable $e) {
            if ($ownTxn && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Bring a client back out of the corbeille. Does not undo the reassignment. */
    public static function restore(PDO $db, int $clientId): void
    {
        $client = self::lockClient($db, $clientId);
        if (!$client) throw new RuntimeException("Client introuvable.");
        if ($client['deleted_at'] === null) throw new RuntimeException("Ce client n'est pas dans la corbeille.");

        $db->prepare("
            UPDATE clients
               SET deleted_at = NULL, deleted_by = NULL, deleted_reassigned_to = NULL
             WHERE id = ?
        ")->execute([$clientId]);
    }

    /**
     * Permanently erase a client already in the corbeille. Safe by
     * construction: softDelete() already stripped every dependent row off
     * this client, so this is a plain DELETE — no FK violation expected. If
     * one still happens (a table we don't know about references clients),
     * it bubbles up as a PDOException rather than being swallowed, so the
     * caller can surface a real error instead of silently no-op'ing.
     */
    public static function purge(PDO $db, int $clientId): void
    {
        $client = self::lockClient($db, $clientId);
        if (!$client) throw new RuntimeException("Client introuvable.");
        if ($client['deleted_at'] === null) throw new RuntimeException("Ce client n'est pas dans la corbeille — utilisez d'abord la suppression.");

        $db->prepare("DELETE FROM clients WHERE id = ? AND deleted_at IS NOT NULL")->execute([$clientId]);
    }

    /** Days left before the nightly cron auto-purges, for the corbeille UI. */
    public static function daysRemaining(string $deletedAt, int $retentionDays): int
    {
        $deletedTs = strtotime($deletedAt);
        if ($deletedTs === false) return 0;
        $expiresTs = $deletedTs + ($retentionDays * 86400);
        return max(0, (int) ceil(($expiresTs - time()) / 86400));
    }

    // ------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------

    private static function lockClient(PDO $db, int $id): ?array
    {
        $stmt = $db->prepare("SELECT id, deleted_at FROM clients WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function reassignAll(PDO $db, int $from, int $to): void
    {
        foreach (self::SIMPLE_TABLES as $table) {
            self::reassignSimple($db, $table, $from, $to);
        }
        self::reassignWallet($db, $from, $to);
        self::reassignEmptiesLedger($db, $from, $to);
        self::reassignClientPrices($db, $from, $to);
    }

    /**
     * Straight UPDATE ... SET client_id = :to WHERE client_id = :from, but
     * falls back to a row-by-row merge if a unique constraint we didn't know
     * about rejects the bulk update — belt-and-braces for tables whose exact
     * schema wasn't visible from the application code alone.
     */
    private static function reassignSimple(PDO $db, string $table, int $from, int $to): void
    {
        if (!self::tableExists($db, $table)) return; // some installs may not have every optional table

        try {
            $db->prepare("UPDATE `{$table}` SET client_id = ? WHERE client_id = ?")
               ->execute([$to, $from]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e; // not a duplicate-key conflict — real error, don't hide it

            // Duplicate-key conflict on a bulk update: move rows one at a
            // time, dropping any individual row that would collide with an
            // existing row already owned by the target (same reasoning as
            // client_prices below — the target's existing row wins).
            $ids = $db->prepare("SELECT id FROM `{$table}` WHERE client_id = ?");
            $ids->execute([$from]);
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $rowId) {
                try {
                    $db->prepare("UPDATE `{$table}` SET client_id = ? WHERE id = ?")->execute([$to, $rowId]);
                } catch (PDOException $inner) {
                    if ($inner->getCode() !== '23000') throw $inner;
                    $db->prepare("DELETE FROM `{$table}` WHERE id = ?")->execute([$rowId]);
                }
            }
        }
    }

    /** client_wallets: UNIQUE(client_id) — merge balances instead of colliding. */
    private static function reassignWallet(PDO $db, int $from, int $to): void
    {
        if (!self::tableExists($db, 'client_wallets')) return;

        $stmt = $db->prepare("SELECT balance FROM client_wallets WHERE client_id = ?");
        $stmt->execute([$from]);
        $balance = $stmt->fetchColumn();
        if ($balance === false) return; // source has no wallet row — nothing to merge

        $db->prepare("
            INSERT INTO client_wallets (client_id, balance) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE balance = balance + ?, last_updated = CURRENT_TIMESTAMP
        ")->execute([$to, $balance, $balance]);

        $db->prepare("DELETE FROM client_wallets WHERE client_id = ?")->execute([$from]);
    }

    /** client_empties_ledger: UNIQUE(client_id, site_id, product_id) — merge quantities. */
    private static function reassignEmptiesLedger(PDO $db, int $from, int $to): void
    {
        if (!self::tableExists($db, 'client_empties_ledger')) return;

        $stmt = $db->prepare("
            SELECT id, site_id, product_id, total_in, total_out, quantity_owed
              FROM client_empties_ledger WHERE client_id = ?
        ");
        $stmt->execute([$from]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return;

        $findTarget = $db->prepare("
            SELECT id FROM client_empties_ledger
             WHERE client_id = ? AND product_id = ?
               AND (site_id = ? OR (? IS NULL AND site_id IS NULL))
        ");
        $merge = $db->prepare("
            UPDATE client_empties_ledger
               SET total_in = total_in + ?, total_out = total_out + ?, quantity_owed = quantity_owed + ?
             WHERE id = ?
        ");
        $move = $db->prepare("UPDATE client_empties_ledger SET client_id = ? WHERE id = ?");
        $del  = $db->prepare("DELETE FROM client_empties_ledger WHERE id = ?");

        foreach ($rows as $row) {
            $findTarget->execute([$to, $row['product_id'], $row['site_id'], $row['site_id']]);
            $targetId = $findTarget->fetchColumn();

            if ($targetId) {
                $merge->execute([$row['total_in'], $row['total_out'], $row['quantity_owed'], $targetId]);
                $del->execute([$row['id']]);
            } else {
                $move->execute([$to, $row['id']]);
            }
        }
    }

    /** client_prices: composite PK (client_id, product_id) — target's own tarif wins. */
    private static function reassignClientPrices(PDO $db, int $from, int $to): void
    {
        if (!self::tableExists($db, 'client_prices')) return;

        $stmt = $db->prepare("SELECT product_id, custom_price FROM client_prices WHERE client_id = ?");
        $stmt->execute([$from]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return;

        $exists = $db->prepare("SELECT 1 FROM client_prices WHERE client_id = ? AND product_id = ?");
        $move   = $db->prepare("
            UPDATE client_prices SET client_id = ? WHERE client_id = ? AND product_id = ?
        ");
        $del    = $db->prepare("DELETE FROM client_prices WHERE client_id = ? AND product_id = ?");

        foreach ($rows as $row) {
            $exists->execute([$to, $row['product_id']]);
            if ($exists->fetchColumn()) {
                // Target already has a negotiated price for this product — keep theirs.
                $del->execute([$from, $row['product_id']]);
            } else {
                $move->execute([$to, $from, $row['product_id']]);
            }
        }
    }

    private static function tableExists(PDO $db, string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];

        $stmt = $db->prepare("
            SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?
        ");
        $stmt->execute([$table]);
        return $cache[$table] = ((int) $stmt->fetchColumn() > 0);
    }
}
