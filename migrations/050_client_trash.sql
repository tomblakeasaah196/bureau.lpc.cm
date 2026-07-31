-- =============================================================================
-- migrations/050_client_trash.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 11 · client corbeille (soft-delete + auto-purge).
--
-- WHAT THIS DOES
-- --------------
--   1. Registers three new permissions, split deliberately fine-grained so an
--      admin can grant "send to corbeille" without also granting "empty the
--      corbeille forever":
--
--        crm.clients.delete   move a client to the corbeille (reassigns its
--                              transactional rows to another client first —
--                              see includes/classes/ClientTrash.php)
--        crm.clients.restore  bring a client back out of the corbeille
--        crm.clients.purge    permanently erase a client sitting in the
--                              corbeille, before the retention window expires
--
--   2. Grants all three to 'admin' ONLY. Per migration 031/049's hard-won
--      lesson: 001_rbac_seed.sql enumerates every permission row for admin
--      individually — there is no live '*' row in role_permissions — so a
--      permission created after that seed reaches nobody, not even admin,
--      unless this migration writes the grant explicitly.
--
--      No other role gets these by default. That is intentional: the user
--      asked for this to be a separately assignable permission an admin
--      hands out to whoever they trust with it, not a blanket grant that
--      rides along with crm.clients.edit.
--
--   3. `clients.deleted_at` already exists (migration 007, universal
--      soft-delete column). This migration adds `deleted_by` (who sent it to
--      the corbeille — for the audit trail and for the "restore" UI to show
--      "supprimé par X le Y") and `deleted_reassigned_to` (which client its
--      transactional rows were merged into, so the corbeille UI can show
--      "ses commandes ont été réaffectées à <client>" instead of nothing).
--
-- WHY NO SCHEMA CHANGE TO THE CHILD TABLES
-- -----------------------------------------------------------------------------
--   client_wallets / client_empties_ledger / deliveries / payments /
--   sales_orders all have ON DELETE RESTRICT to clients (migration 006), so a
--   soft-delete (UPDATE clients SET deleted_at = NOW()) never touches them —
--   RESTRICT only fires on an actual DELETE. The reassignment step (app-side,
--   in ClientTrash.php) runs BEFORE that UPDATE, at the moment a client is
--   sent to the corbeille, precisely so that by the time the retention window
--   expires (or an admin purges early), `DELETE FROM clients WHERE id = ?` has
--   nothing left pointing at it and succeeds cleanly.
--
-- Idempotent: safe to re-run.
-- =============================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. Permissions.
-- ---------------------------------------------------------------------------
INSERT INTO permissions (name, module, description) VALUES
    ('crm.clients.delete',  'crm', 'Déplacer un client vers la corbeille (réassigne ses données à un autre client)'),
    ('crm.clients.restore', 'crm', 'Restaurer un client depuis la corbeille'),
    ('crm.clients.purge',   'crm', "Supprimer définitivement un client de la corbeille (avant l'expiration automatique)")
ON DUPLICATE KEY UPDATE
    module      = VALUES(module),
    description = VALUES(description);

-- ---------------------------------------------------------------------------
-- 2. Grant to admin only (see header note — never rely on a live '*' row).
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name IN ('crm.clients.delete', 'crm.clients.restore', 'crm.clients.purge')
 WHERE r.name = 'admin';

-- ---------------------------------------------------------------------------
-- 3. clients: deleted_by + deleted_reassigned_to.
-- ---------------------------------------------------------------------------
SET @sql = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE clients ADD COLUMN deleted_by INT NULL AFTER deleted_at',
    'SELECT "deleted_by exists" AS info')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'clients' AND column_name = 'deleted_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE clients ADD COLUMN deleted_reassigned_to INT NULL AFTER deleted_by',
    'SELECT "deleted_reassigned_to exists" AS info')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'clients' AND column_name = 'deleted_reassigned_to'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index so the corbeille listing (WHERE deleted_at IS NOT NULL) and the
-- nightly purge cron (WHERE deleted_at < ...) both stay index-backed instead
-- of scanning the whole clients table.
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'clients' AND index_name = 'idx_clients_deleted_at'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE clients ADD KEY idx_clients_deleted_at (deleted_at)',
  'SELECT "idx_clients_deleted_at exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- FKs for the two new audit columns. SET NULL on delete: if the user who
-- deleted a client (or the client it was merged into) is later removed, the
-- corbeille row still exists — it just loses the "by whom" / "into whom"
-- label rather than being blocked or cascading.
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.key_column_usage
   WHERE table_schema = DATABASE() AND table_name = 'clients' AND column_name = 'deleted_by'
     AND referenced_table_name = 'users'
);
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE clients ADD CONSTRAINT fk_clients_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT "fk_clients_deleted_by exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.key_column_usage
   WHERE table_schema = DATABASE() AND table_name = 'clients' AND column_name = 'deleted_reassigned_to'
     AND referenced_table_name = 'clients'
);
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE clients ADD CONSTRAINT fk_clients_reassigned_to FOREIGN KEY (deleted_reassigned_to) REFERENCES clients(id) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT "fk_clients_reassigned_to exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

-- =============================================================================
-- Verification:
--
--   SELECT r.name FROM roles r
--     JOIN role_permissions rp ON rp.role_id = r.id
--     JOIN permissions p       ON p.id = rp.permission_id
--    WHERE p.name IN ('crm.clients.delete','crm.clients.restore','crm.clients.purge')
--    ORDER BY r.name;
--   -- expect: admin, admin, admin (one row per permission) — nobody else yet.
--
--   DESCRIBE clients;
--   -- deleted_at, deleted_by, deleted_reassigned_to all present.
--
-- IMPORTANT — same caveat as every prior permission migration: this grant
-- does not reach an already-open session. Rbac::loadFromDb() caches the
-- permission set into $_SESSION at login. The admin must sign out/in (or an
-- admin re-saves their own role from Administration -> Rôles & Permissions)
-- before the corbeille buttons appear.
--
-- To hand this out to another role: Administration -> Rôles & Permissions ->
-- pick the role -> tick "Déplacer un client vers la corbeille" / "Restaurer…"
-- / "Supprimer définitivement…" under the CRM section -> Enregistrer. No
-- further migration needed — that UI writes directly to role_permissions.
-- =============================================================================
