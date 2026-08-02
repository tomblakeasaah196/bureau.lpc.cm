-- =============================================================================
-- 060_recycling_price_override.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — negotiated (overridden) unit prices on recycling sales,
-- with a full who/what/why audit trail.
--
-- WHY
-- ---
-- modules/operations/empties_collection.php → "Vente Recyclage" prices every
-- line at `products.base_price`, read server-side in cre_controller.php
-- (action=sell_to_recycler). The client never got to choose a price, which is
-- correct as a default and wrong as an absolute: the recycler haggles on the
-- spot. Bottle shape, cork condition, batch quality, volume of the lot and
-- plain market movement all move the number, and the driver either records a
-- figure that does not match the cash in his hand, or gives up and books the
-- catalogue price — quietly breaking the cash reconciliation in
-- `Revenus Recyclage`.
--
-- The fix is not "let the client post any price it likes". It is: allow a
-- deliberate, reasoned deviation from the catalogue price, and make that
-- deviation impossible to hide.
--
-- WHAT THIS DOES
--
--   1. Adds four columns to `recycling_sale_items` so each line carries its
--      own truth:
--        · base_price          the catalogue price at the moment of sale
--                              (snapshot — products.base_price moves later)
--        · is_price_overridden 0/1 flag, cheap to filter and index on
--        · override_reason     the operator's stated justification
--        · override_by         who typed it (users.id)
--      `unit_price` keeps its existing meaning: the price actually applied.
--      So `unit_price <> base_price` and `is_price_overridden = 1` say the
--      same thing two ways, on purpose — the flag survives rounding and the
--      comparison survives a corrupted flag.
--
--   2. Creates `recycling_price_overrides` — an append-only log, one row per
--      overridden line. This is deliberately NOT just the columns above:
--        · the sale-item columns answer "what did this line cost and why",
--          and are what the driver's receipt and the revenue table read;
--        · the log answers "show me every price deviation this quarter, by
--          whom, and by how much" without scanning a join of sale items, and
--          keeps its row even if a sale line is later corrected.
--      It records the delta in absolute and percentage terms so a supervisor
--      can sort by "worst deviation" without arithmetic in the query.
--
--   3. Adds the `operations.recycling.override_price` permission and grants
--      it to every role that can already sell to a recycler — the driver on
--      the field IS the person negotiating, so gating him behind a second
--      approval would just push the deviation back into the shadows. The
--      audit trail is the control here, not the gate. The permission exists
--      anyway so it CAN be revoked per-role later without a code change.
--
-- WHAT THIS DELIBERATELY DOES NOT DO
--   · It does not touch `products.base_price`. A negotiated price is a fact
--     about one transaction, not a new catalogue price. Changing the standard
--     price stays an explicit, separate, admin-level act.
--   · It adds no trigger. `recycling_sale_items` is written once per sale
--     inside the existing transaction in cre_controller.php, so the app can
--     write the log row in that same transaction (see 007_audit_columns.sql
--     §2 for why trigger coverage stops at the OHADA financial tables).
--
-- Idempotent: safe to re-run.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. Columns on recycling_sale_items
-- ---------------------------------------------------------------------------

DELIMITER //

DROP PROCEDURE IF EXISTS lpc_add_col_060 //
CREATE PROCEDURE lpc_add_col_060(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_def TEXT)
BEGIN
    DECLARE v_exists INT;
    SELECT COUNT(*) INTO v_exists FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_col;
    IF v_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END//

DELIMITER ;

-- Catalogue price snapshot. NULL on pre-migration rows: for those, unit_price
-- WAS the catalogue price by construction, so backfill below sets them equal.
CALL lpc_add_col_060('recycling_sale_items', 'base_price',
    'DECIMAL(12,2) NULL DEFAULT NULL COMMENT "products.base_price at time of sale"');

CALL lpc_add_col_060('recycling_sale_items', 'is_price_overridden',
    'TINYINT(1) NOT NULL DEFAULT 0 COMMENT "1 = unit_price was negotiated, not catalogue"');

CALL lpc_add_col_060('recycling_sale_items', 'override_reason',
    'VARCHAR(500) NULL DEFAULT NULL COMMENT "operator justification, required when overriding"');

CALL lpc_add_col_060('recycling_sale_items', 'override_by',
    'INT NULL DEFAULT NULL COMMENT "users.id of whoever set the negotiated price"');

DROP PROCEDURE IF EXISTS lpc_add_col_060;

-- Backfill: every existing line was priced at catalogue by definition.
UPDATE recycling_sale_items
   SET base_price = unit_price
 WHERE base_price IS NULL;

-- ---------------------------------------------------------------------------
-- 2. The audit log
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS recycling_price_overrides (
    id                INT AUTO_INCREMENT PRIMARY KEY,

    sale_id           INT NOT NULL       COMMENT 'recycling_sales.id',
    sale_item_id      INT NULL           COMMENT 'recycling_sale_items.id (NULL if line later purged)',
    product_id        INT NOT NULL       COMMENT 'products.id — the empty being sold',

    base_price        DECIMAL(12,2) NOT NULL COMMENT 'catalogue price that was replaced',
    override_price    DECIMAL(12,2) NOT NULL COMMENT 'price actually applied',
    delta_amount      DECIMAL(12,2) NOT NULL COMMENT 'override_price - base_price (signed)',
    delta_pct         DECIMAL(8,2)  NULL     COMMENT 'percentage deviation, NULL when base_price = 0',
    quantity          INT NOT NULL           COMMENT 'units sold at the overridden price',
    total_impact      DECIMAL(14,2) NOT NULL COMMENT 'delta_amount * quantity — cash effect of the deviation',

    reason            VARCHAR(500) NOT NULL  COMMENT 'mandatory justification typed by the operator',

    created_by        INT NOT NULL           COMMENT 'users.id — who negotiated',
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address        VARCHAR(45) NULL       COMMENT 'IPv4/IPv6 of the device that submitted',
    user_agent        VARCHAR(255) NULL,

    INDEX idx_rpo_sale       (sale_id),
    INDEX idx_rpo_product    (product_id),
    INDEX idx_rpo_user       (created_by),
    INDEX idx_rpo_created    (created_at),
    INDEX idx_rpo_impact     (total_impact),

    -- Declared inline rather than through 006's lpc_add_fk helper: that
    -- procedure is DROPped at the end of 006, so it does not exist by the time
    -- this migration runs. CREATE TABLE IF NOT EXISTS makes the whole block a
    -- no-op on re-run, which is exactly the idempotency the helper provided.
    --
    -- sale_id CASCADEs (an override is meaningless without its sale) while
    -- product_id and created_by RESTRICT — you must not be able to delete a
    -- user or a product out from under a price they are on record as having
    -- negotiated. That is the whole point of the log.
    CONSTRAINT fk_rpo_sale    FOREIGN KEY (sale_id)    REFERENCES recycling_sales (id) ON DELETE CASCADE,
    CONSTRAINT fk_rpo_product FOREIGN KEY (product_id) REFERENCES products (id)        ON DELETE RESTRICT,
    CONSTRAINT fk_rpo_user    FOREIGN KEY (created_by) REFERENCES users (id)           ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only log of negotiated price deviations on recycling sales (migration 060)';

-- ---------------------------------------------------------------------------
-- 3. Permission
-- ---------------------------------------------------------------------------
-- Note the lesson of 059: admin's grant in 001_rbac_seed.sql is a one-time
-- snapshot, not a wildcard. Every new permission must be granted explicitly.

-- Column is `module`, not `category` — see 001_rbac_seed.sql:98.
INSERT INTO permissions (name, module, description) VALUES
    ('operations.recycling.override_price', 'operations',
     'Négocier / modifier le prix de vente des vides')
ON DUPLICATE KEY UPDATE
    module      = VALUES(module),
    description = VALUES(description);

-- Grant to admin + every role that can already sell to a recycler.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  CROSS JOIN permissions p
 WHERE p.name = 'operations.recycling.override_price'
   AND (
        r.name = 'admin'
     OR r.id IN (
            SELECT rp.role_id FROM role_permissions rp
              JOIN permissions ps ON ps.id = rp.permission_id
             WHERE ps.name = 'operations.recycling.sell'
        )
   )
   AND NOT EXISTS (
        SELECT 1 FROM role_permissions rp2
         WHERE rp2.role_id = r.id AND rp2.permission_id = p.id
   );
