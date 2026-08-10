-- =============================================================================
-- 091_budget_buckets_md_view.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — MD-first Budget & Performance module.
--
-- WHY
--   The Managing Director does NOT need to see the full OHADA Class-6 tree to
--   run the company. He needs eight big-line targets, three colours per line
--   (green / yellow / red), and one screen he can open in under a minute.
--
--   The existing tables (`budgets`, `budget_lines`, `budget_transfers`,
--   `performance_targets`) stay intact — they are the source of the DETAILED
--   view that Finance still uses. This migration adds a SECOND, higher-level
--   layer built on top of them:
--
--     · budget_buckets           — the 8 buckets the MD ever sees.
--     · budget_bucket_accounts   — which OHADA accounts roll up into each.
--     · budget_bucket_targets    — the annual target the MD types in, split
--                                  across 12 months (auto ÷12 by default,
--                                  optionally overridden per month).
--     · budget_transfer_requests — Finance drafts, MD approves. Replaces the
--                                  MD-facing modal in `budget_transfers`.
--
--   The MD view NEVER writes to `budgets` or `budget_lines`. Actuals come from
--   the same aggregator the Finance page uses (getActualsByAccountAndMonth in
--   api/v1/budget_controller.php) — so a franc counted here is the same franc
--   counted there. That is the whole point of the mapping table.
--
-- WHAT THIS CREATES
--   1. `budget_buckets`         (8 rows seeded).
--   2. `budget_bucket_accounts` (many-to-one OHADA → bucket).
--   3. `budget_bucket_targets`  (per-year annual + m01..m12).
--   4. `budget_transfer_requests` (draft / approve / reject workflow).
--   5. Two new permissions (`accounting.budgets.request_transfer`,
--      `accounting.budgets.approve_transfer`) granted to Finance and Admin
--      respectively.
--
-- STYLE
--   Follows the pattern established by 053_expenses_module.sql — real seed
--   data pinned by account_number subquery so it survives on any install
--   where the OHADA rows carry different IDs. Idempotent on re-run.
--
-- Depends on: 003_ohada_backfill.sql (ohada_accounts + Class 6 rows),
--             053_expenses_module.sql (adds 6132/6222/6231/6252/6281/6411/
--             6461/6781), 001_rbac_seed.sql (permissions table).
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. budget_buckets
--    The 8 MD-facing lines. `is_revenue` flips variance polarity, exactly like
--    `ohada_accounts.type = 'revenue'` does in the Finance view. `is_emergency`
--    reproduces the same flag on ohada_accounts — one bucket carries it, and
--    that bucket is the pool a transfer request draws from.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `budget_buckets` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `bucket_key`    VARCHAR(64)  NOT NULL COMMENT 'stable identifier, used in code',
  `label_fr`      VARCHAR(128) NOT NULL,
  `label_en`      VARCHAR(128) NOT NULL,
  `is_revenue`    TINYINT(1)   NOT NULL DEFAULT 0,
  `is_emergency`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'the pool transfer requests draw from',
  `icon`          VARCHAR(60)      NULL COMMENT 'FontAwesome class',
  `color`         VARCHAR(20)      NULL COMMENT 'Tailwind color token',
  `sort_order`    INT(11)      NOT NULL DEFAULT 100,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bucket_key` (`bucket_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `budget_buckets`
    (bucket_key, label_fr, label_en, is_revenue, is_emergency, icon, color, sort_order)
VALUES
  ('revenue',           'Chiffre d''affaires',    'Revenue',              1, 0, 'fa-arrow-trend-up', 'emerald', 10),
  ('payroll',           'Salaires & charges',     'Payroll',              0, 0, 'fa-user-tie',       'sky',     20),
  ('fuel',              'Carburant & transport',  'Fuel & Transport',     0, 0, 'fa-gas-pump',       'amber',   30),
  ('maintenance',       'Maintenance',            'Maintenance',          0, 0, 'fa-tools',          'orange',  40),
  ('rent_utilities',    'Loyer & services',       'Rent & Utilities',     0, 0, 'fa-building',       'indigo',  50),
  ('marketing',         'Marketing',              'Marketing',            0, 0, 'fa-bullhorn',       'pink',    60),
  ('other_opex',        'Autres charges',         'Other Opex',           0, 0, 'fa-ellipsis-h',     'slate',   70),
  ('emergency',         'Fonds d''imprévus',      'Emergency Fund',       0, 1, 'fa-shield-alt',     'rose',    80)
ON DUPLICATE KEY UPDATE
    label_fr = VALUES(label_fr), label_en = VALUES(label_en),
    is_revenue = VALUES(is_revenue), is_emergency = VALUES(is_emergency),
    icon = VALUES(icon), color = VALUES(color), sort_order = VALUES(sort_order);

-- -----------------------------------------------------------------------------
-- 2. budget_bucket_accounts
--    Many OHADA accounts to one bucket. Same account MUST NOT appear in two
--    buckets — the UNIQUE on ohada_account_id enforces that (a franc rolls up
--    exactly once).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `budget_bucket_accounts` (
  `bucket_id`         INT(11) NOT NULL,
  `ohada_account_id`  INT(11) NOT NULL,
  PRIMARY KEY (`ohada_account_id`),
  KEY `idx_bba_bucket` (`bucket_id`),
  CONSTRAINT `fk_bba_bucket` FOREIGN KEY (`bucket_id`)
      REFERENCES `budget_buckets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bba_ohada`  FOREIGN KEY (`ohada_account_id`)
      REFERENCES `ohada_accounts` (`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed the mapping. Pinned by account_number so IDs may vary per install.
-- Anything not listed here is unmapped, and its actuals are counted under
-- 'other_opex' by the controller's fallback rule.
INSERT INTO `budget_bucket_accounts` (bucket_id, ohada_account_id)
SELECT b.id, o.id
  FROM (
      -- REVENUE — all 7xxx
      SELECT 'revenue'        AS bk, '701'  AS acc UNION ALL
      SELECT 'revenue',                '702'         UNION ALL
      SELECT 'revenue',                '703'         UNION ALL
      SELECT 'revenue',                '704'         UNION ALL
      SELECT 'revenue',                '705'         UNION ALL
      SELECT 'revenue',                '706'         UNION ALL
      SELECT 'revenue',                '707'         UNION ALL
      SELECT 'revenue',                '708'         UNION ALL
      -- PAYROLL — 64xx
      SELECT 'payroll',                '6411'        UNION ALL
      SELECT 'payroll',                '6412'        UNION ALL
      SELECT 'payroll',                '6413'        UNION ALL
      SELECT 'payroll',                '6414'        UNION ALL
      SELECT 'payroll',                '6421'        UNION ALL
      SELECT 'payroll',                '6461'        UNION ALL
      SELECT 'payroll',                '6471'        UNION ALL
      SELECT 'payroll',                '648'         UNION ALL
      -- FUEL & TRANSPORT — 605 and 61 transport
      SELECT 'fuel',                   '605'         UNION ALL
      SELECT 'fuel',                   '611'         UNION ALL
      SELECT 'fuel',                   '612'         UNION ALL
      SELECT 'fuel',                   '624'         UNION ALL
      -- MAINTENANCE — 615, 6222, 616
      SELECT 'maintenance',            '615'         UNION ALL
      SELECT 'maintenance',            '616'         UNION ALL
      SELECT 'maintenance',            '6222'        UNION ALL
      -- RENT & UTILITIES — 6132/6133, telecom, energie
      SELECT 'rent_utilities',         '6132'        UNION ALL
      SELECT 'rent_utilities',         '6133'        UNION ALL
      SELECT 'rent_utilities',         '6141'        UNION ALL
      SELECT 'rent_utilities',         '6281'        UNION ALL
      SELECT 'rent_utilities',         '6284'        UNION ALL
      SELECT 'rent_utilities',         '6051'        UNION ALL
      -- MARKETING — 6231/6232/6233
      SELECT 'marketing',              '6231'        UNION ALL
      SELECT 'marketing',              '6232'        UNION ALL
      SELECT 'marketing',              '6233'        UNION ALL
      -- OTHER OPEX — catch-all buckets
      SELECT 'other_opex',             '6252'        UNION ALL
      SELECT 'other_opex',             '627'         UNION ALL
      SELECT 'other_opex',             '628'         UNION ALL
      SELECT 'other_opex',             '635'         UNION ALL
      SELECT 'other_opex',             '638'         UNION ALL
      SELECT 'other_opex',             '646'         UNION ALL
      SELECT 'other_opex',             '658'         UNION ALL
      SELECT 'other_opex',             '667'         UNION ALL
      SELECT 'other_opex',             '681'         UNION ALL
      SELECT 'other_opex',             '6781'
  ) AS seed
  JOIN `budget_buckets`  b ON b.bucket_key   = seed.bk
  JOIN `ohada_accounts`  o ON o.account_number = seed.acc
ON DUPLICATE KEY UPDATE bucket_id = VALUES(bucket_id);

-- The Emergency bucket picks up any ohada_accounts row already flagged
-- is_emergency = 1 (that flag is the historical way of marking the Imprévus
-- account). Idempotent — re-runs just re-assert the same mapping.
INSERT INTO `budget_bucket_accounts` (bucket_id, ohada_account_id)
SELECT b.id, o.id
  FROM `budget_buckets`  b
  JOIN `ohada_accounts`  o ON o.is_emergency = 1
 WHERE b.bucket_key = 'emergency'
ON DUPLICATE KEY UPDATE bucket_id = VALUES(bucket_id);

-- -----------------------------------------------------------------------------
-- 3. budget_bucket_targets
--    One row per (fiscal_year, bucket). annual_amount is what the MD types;
--    m01..m12 are what actually gets used month-by-month. When the MD saves
--    without touching any month cell, the controller writes annual/12 into
--    each — an "auto-split" default that the MD can override later per month.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `budget_bucket_targets` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `fiscal_year`   YEAR(4)      NOT NULL,
  `bucket_id`     INT(11)      NOT NULL,
  `annual_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `m01` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `m02` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `m03` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `m04` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `m05` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `m06` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `m07` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `m08` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `m09` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `m10` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `m11` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `m12` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `updated_by`    INT(11)          NULL,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_year_bucket` (`fiscal_year`, `bucket_id`),
  CONSTRAINT `fk_bbt_bucket` FOREIGN KEY (`bucket_id`)
      REFERENCES `budget_buckets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- 4. budget_transfer_requests
--    The Finance role drafts a request ("move 500K FCFA from Emergency to
--    Fuel because tanker breakdown"); the MD sees a card on his Overview and
--    clicks Approve or Reject. On approve, the row also mints a corresponding
--    `budget_transfers` entry so the legacy Finance view stays consistent.
--
--    Not modelled as a state machine table on purpose — three states is fine
--    for an ENUM. `decided_by` is NULL until the MD acts.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `budget_transfer_requests` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `fiscal_year`    YEAR(4)      NOT NULL,
  `from_bucket_id` INT(11)      NOT NULL COMMENT 'usually Emergency',
  `to_bucket_id`   INT(11)      NOT NULL,
  `amount`         DECIMAL(15,2) NOT NULL,
  `reason`         TEXT         NOT NULL,
  `status`         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `requested_by`   INT(11)      NOT NULL,
  `requested_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_by`     INT(11)          NULL,
  `decided_at`     TIMESTAMP        NULL,
  `decision_note`  TEXT             NULL,
  PRIMARY KEY (`id`),
  KEY `idx_btr_status` (`status`, `fiscal_year`),
  KEY `idx_btr_from`   (`from_bucket_id`),
  KEY `idx_btr_to`     (`to_bucket_id`),
  CONSTRAINT `fk_btr_from` FOREIGN KEY (`from_bucket_id`)
      REFERENCES `budget_buckets` (`id`),
  CONSTRAINT `fk_btr_to`   FOREIGN KEY (`to_bucket_id`)
      REFERENCES `budget_buckets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- 5. Permissions
--    Two new keys. Both are seeded here so RBAC picks them up without a
--    follow-up migration. Granted per the answers from the MD scoping call:
--    Finance drafts, MD approves.
-- -----------------------------------------------------------------------------
INSERT INTO permissions (`key`, `module`, `description`) VALUES
  ('accounting.budgets.request_transfer', 'accounting',
   'Créer une demande de transfert budgétaire (Finance)'),
  ('accounting.budgets.approve_transfer', 'accounting',
   'Approuver ou refuser une demande de transfert (MD / Admin)')
ON DUPLICATE KEY UPDATE
    module      = VALUES(module),
    description = VALUES(description);

-- Grant the request permission to any role that already carries
-- accounting.budgets.transfer (i.e. Finance / Accountant).
INSERT INTO role_permissions (role, permission_key)
SELECT DISTINCT rp.role, 'accounting.budgets.request_transfer'
  FROM role_permissions rp
 WHERE rp.permission_key = 'accounting.budgets.transfer'
ON DUPLICATE KEY UPDATE role = role;

-- Grant the approve permission to admin only (the MD role).
INSERT INTO role_permissions (role, permission_key) VALUES
  ('admin', 'accounting.budgets.approve_transfer')
ON DUPLICATE KEY UPDATE role = role;

COMMIT;

-- =============================================================================
-- Verification (manual):
--   SELECT bk.bucket_key, COUNT(bba.ohada_account_id) AS n_accounts
--     FROM budget_buckets bk
--     LEFT JOIN budget_bucket_accounts bba ON bba.bucket_id = bk.id
--    GROUP BY bk.bucket_key
--    ORDER BY bk.sort_order;
--     -- expect all 8 buckets, revenue > 0, emergency >= 1.
--
--   SELECT o.account_number, o.name, bk.bucket_key
--     FROM ohada_accounts o
--     LEFT JOIN budget_bucket_accounts bba ON bba.ohada_account_id = o.id
--     LEFT JOIN budget_buckets bk ON bk.id = bba.bucket_id
--    WHERE o.type = 'expense' AND bk.bucket_key IS NULL;
--     -- rows here are the Class-6 accounts that fall through to other_opex.
-- =============================================================================
