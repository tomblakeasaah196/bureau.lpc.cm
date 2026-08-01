-- =============================================================================
-- 053_expenses_module.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — split "Frais Généraux (OPEX)" out of Achats into a proper
-- Gestion des Dépenses module, wired into the OHADA general ledger and the
-- Budgets & Performance module.
--
-- BACKGROUND
-- ----------
-- Until this migration, OPEX lived in TWO unrelated places:
--
--   1. `overheads` — a tab on modules/inventory/procurement.php (Achats &
--      Dépenses). Simple table: title, category (free string), amount, date,
--      payment_status. NOT posted to journal_entries. NOT tied to an OHADA
--      account. NOT visible in Budgets (getActualsByAccountAndMonth ignored it).
--      In other words: a spend register that never touched the books.
--
--   2. "Sortie de Caisse (Dépense)" on modules/accounting/cashflow.php. This
--      one DID post a real JE via JournalPoster::postExpense — but was a
--      free-form 3-field entry (compte à débiter, montant, description) with
--      no category, no history, no receipt, no supplier. Data landed in
--      `treasury_transactions` with transaction_type = 'out_expense' and
--      that was the whole trail.
--
-- Same operation, two forms, two schemas, two mental models. Budgets showed
-- neither. Categorised OPEX had no ledger; ledgered OPEX had no category.
--
-- WHAT THIS MIGRATION DOES
--
--   1. Creates `expense_categories` — one row per category, mapping to a
--      chart_of_accounts row (Class 6, OHADA). Ten defaults seeded, each
--      pinned to an existing OHADA account so budgets can compute Engagé.
--
--   2. Creates `expenses` — the durable record. Every expense carries a
--      category (→ OHADA account), an optional treasury account, an
--      optional supplier, an optional journal_entry_id, and (through
--      migration 038's source_type/source_id) is round-trip-linkable back
--      from the ledger.
--
--   3. Creates `expense_recurring_templates` — for rent/salaries/subs.
--      Draft generation happens in application code; the table is the
--      config side of "every 1st, create a draft".
--
--   4. Creates `expense_attachments` — receipt/invoice uploads, one-to-many.
--
--   5. Backfills `expenses` from `overheads`, mapping the seven legacy
--      category strings onto the new category rows by title match, and
--      falling through to "Autre" for anything unrecognised. Old rows keep
--      their date and reference; the closed-period lock triggers on
--      `overheads` (migration 005) are mirrored onto `expenses` so a
--      migrated row cannot be edited into a closed year either.
--
--   6. Adds RBAC permissions accounting.expenses.{view,create,edit,
--      delete,categories.manage} and grants them to admin + accountant.
--      The legacy `inventory.procurement.overhead` permission is kept —
--      the procurement UI drops the OPEX tab in this same release but
--      historical role grants and audit rows still reference it.
--
-- IDEMPOTENT: every step is guarded on information_schema or uses
-- INSERT … ON DUPLICATE KEY UPDATE / INSERT … NOT EXISTS. Safe to re-run.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. Seed the OHADA accounts the default categories need.
--    Migration 003 seeded 601 and 605; 615 was seeded there too (maintenance).
--    Everything else below is added on demand here rather than assumed present,
--    because a fresh install without them would leave the default categories
--    pointing at NULL chart_of_accounts rows and JournalPoster would throw.
--
--    Naming follows SYSCOHADA révisé:
--      6132 Locations immobilières               — rent
--      6222 Entretien et réparations            — repairs on non-vehicles
--      6231 Publicité                            — marketing
--      6252 Fournitures de bureau                — office supplies
--      6281 Téléphone / internet                — utilities catch-all
--      6411 Salaires                             — payroll (informational only —
--             actual payroll postings still come from HR module)
--      6461 Cotisations sociales                — social charges
--      6781 Charges exceptionnelles diverses    — "Autre"
-- -----------------------------------------------------------------------------
INSERT INTO ohada_accounts (account_number, name, type) VALUES
  ('6132', 'Locations immobilières',                'expense'),
  ('6222', 'Entretien et réparations',              'expense'),
  ('6231', 'Publicité',                             'expense'),
  ('6252', 'Fournitures de bureau',                 'expense'),
  ('6281', 'Frais de télécommunications',           'expense'),
  ('6411', 'Salaires — appointements',              'expense'),
  ('6461', 'Cotisations sociales',                  'expense'),
  ('6781', 'Charges exceptionnelles diverses',      'expense')
ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type);

-- Every ohada_accounts row above needs a chart_of_accounts front-row, because
-- JournalPoster::coaByOhada() joins the two. Without this the default
-- categories would point at usable OHADA accounts that have no COA row and
-- postExpense would throw at the first save.
INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active)
SELECT o.id, o.account_number, o.name, o.type, 1
  FROM ohada_accounts o
 WHERE o.account_number IN ('6132','6222','6231','6252','6281','6411','6461','6781','601','605','615')
   AND NOT EXISTS (
     SELECT 1 FROM chart_of_accounts c WHERE c.ohada_account_id = o.id
   );

-- -----------------------------------------------------------------------------
-- 2. expense_categories
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(120) NOT NULL,
  `slug`             VARCHAR(120) NOT NULL COMMENT 'stable identifier; used to match legacy overheads.category strings',
  `coa_account_id`   INT(11)          NULL COMMENT 'chart_of_accounts.id — the OHADA Class 6 account this category posts to',
  `icon`             VARCHAR(60)      NULL COMMENT 'FontAwesome class, e.g. fa-truck',
  `color`            VARCHAR(20)      NULL COMMENT 'Tailwind color token, e.g. amber, rose',
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `is_system`        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'seeded defaults; UI blocks delete but allows rename/reassign',
  `sort_order`       INT(11)      NOT NULL DEFAULT 100,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_expense_cat_slug` (`slug`),
  KEY `idx_expense_cat_active` (`is_active`),
  CONSTRAINT `fk_expense_cat_coa` FOREIGN KEY (`coa_account_id`)
    REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Ten defaults. Each is pinned to a chart_of_accounts row via subquery so the
-- seed survives on any install where the codes differ (the LPCxxxxx vanity
-- codes discussed in migration 038 are exactly this hazard).
INSERT INTO `expense_categories` (name, slug, coa_account_id, icon, color, is_active, is_system, sort_order)
SELECT * FROM (
  SELECT 'Loyer & Immobilier'   AS n, 'loyer'       AS s, (SELECT id FROM chart_of_accounts WHERE code='6132' LIMIT 1) AS c, 'fa-building'      AS i, 'indigo' AS co, 1 AS a, 1 AS sy, 10 AS so UNION ALL
  SELECT 'Salaires & Primes',        'salaires',     (SELECT id FROM chart_of_accounts WHERE code='6411' LIMIT 1),      'fa-user-tie',       'sky',    1, 1, 20 UNION ALL
  SELECT 'Charges sociales',         'charges_soc',  (SELECT id FROM chart_of_accounts WHERE code='6461' LIMIT 1),      'fa-users',          'sky',    1, 1, 25 UNION ALL
  SELECT 'Carburant & Transport',    'logistique',   (SELECT id FROM chart_of_accounts WHERE code='605'  LIMIT 1),      'fa-gas-pump',       'amber',  1, 1, 30 UNION ALL
  SELECT 'Entretien & Maintenance',  'maintenance',  (SELECT id FROM chart_of_accounts WHERE code='615'  LIMIT 1),      'fa-tools',          'orange', 1, 1, 40 UNION ALL
  SELECT 'Fournitures de bureau',    'bureau',       (SELECT id FROM chart_of_accounts WHERE code='6252' LIMIT 1),      'fa-briefcase',      'slate',  1, 1, 50 UNION ALL
  SELECT 'Marketing & Publicité',    'marketing',    (SELECT id FROM chart_of_accounts WHERE code='6231' LIMIT 1),      'fa-bullhorn',       'pink',   1, 1, 60 UNION ALL
  SELECT 'Télécom & Internet',       'telecom',      (SELECT id FROM chart_of_accounts WHERE code='6281' LIMIT 1),      'fa-wifi',           'teal',   1, 1, 70 UNION ALL
  SELECT 'Réparations diverses',     'reparations',  (SELECT id FROM chart_of_accounts WHERE code='6222' LIMIT 1),      'fa-wrench',         'orange', 1, 1, 80 UNION ALL
  SELECT 'Autre / Divers',           'autre',        (SELECT id FROM chart_of_accounts WHERE code='6781' LIMIT 1),      'fa-ellipsis-h',     'gray',   1, 1, 999
) AS defaults
ON DUPLICATE KEY UPDATE
  -- Never overwrite a name the user has customised, but do keep the OHADA
  -- mapping fresh so a re-run after a corrected code lands where it should.
  coa_account_id = COALESCE(expense_categories.coa_account_id, VALUES(coa_account_id)),
  icon           = COALESCE(NULLIF(expense_categories.icon,''), VALUES(icon)),
  color          = COALESCE(NULLIF(expense_categories.color,''), VALUES(color)),
  is_system      = 1;

-- -----------------------------------------------------------------------------
-- 3. expenses — the durable record.
--
-- payment_status/payment_date + treasury_account_id together carry the "unpaid
-- → paid" transition: an expense may exist as 'unpaid' before treasury moves.
-- When it flips to 'paid', treasury_account_id must be set and JournalPoster
-- ::postExpense is called by the controller, storing the resulting
-- journal_entry_id here.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `expenses` (
  `id`                    INT(11)        NOT NULL AUTO_INCREMENT,
  `reference`             VARCHAR(50)    NOT NULL,
  `title`                 VARCHAR(200)   NOT NULL,
  `description`           TEXT               NULL,
  `category_id`           INT(11)        NOT NULL,
  `supplier_id`           INT(11)            NULL,
  `amount`                DECIMAL(15,2)  NOT NULL,
  `expense_date`          DATE           NOT NULL COMMENT 'date the charge was incurred (drives budget month + closed-period check)',
  `payment_status`        ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  `payment_date`          DATE               NULL COMMENT 'set when marked paid',
  `treasury_account_id`   INT(11)            NULL COMMENT 'account debited when paid',
  `journal_entry_id`      INT(11)            NULL COMMENT 'set by JournalPoster::postExpense on paid',
  `recurring_template_id` INT(11)            NULL COMMENT 'origin template if auto-generated',
  `created_by`            INT(11)        NOT NULL,
  `created_at`            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_expense_ref` (`reference`),
  KEY `idx_expense_date` (`expense_date`),
  KEY `idx_expense_category` (`category_id`),
  KEY `idx_expense_status` (`payment_status`),
  KEY `idx_expense_recurring` (`recurring_template_id`),
  CONSTRAINT `fk_expense_category`  FOREIGN KEY (`category_id`)         REFERENCES `expense_categories` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_expense_treasury`  FOREIGN KEY (`treasury_account_id`) REFERENCES `treasury_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_expense_supplier`  FOREIGN KEY (`supplier_id`)         REFERENCES `suppliers` (`id`)         ON DELETE SET NULL,
  CONSTRAINT `fk_expense_je`        FOREIGN KEY (`journal_entry_id`)    REFERENCES `journal_entries` (`id`)   ON DELETE SET NULL,
  CONSTRAINT `fk_expense_user`      FOREIGN KEY (`created_by`)          REFERENCES `users` (`id`)             ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- 4. expense_recurring_templates — "every month on the 1st, create a draft"
--
-- Draft-generation is handled by scripts/generate_recurring_expenses.php run
-- by cron; this table is only its config. Storing last_generated_date lets a
-- late run catch up without double-inserting.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `expense_recurring_templates` (
  `id`                    INT(11)        NOT NULL AUTO_INCREMENT,
  `name`                  VARCHAR(150)   NOT NULL,
  `category_id`           INT(11)        NOT NULL,
  `supplier_id`           INT(11)            NULL,
  `treasury_account_id`   INT(11)            NULL COMMENT 'default account for the generated draft',
  `amount`                DECIMAL(15,2)  NOT NULL,
  `day_of_month`          TINYINT        NOT NULL DEFAULT 1 COMMENT '1..28; higher values would drift over Feb',
  `is_active`             TINYINT(1)     NOT NULL DEFAULT 1,
  `last_generated_date`   DATE               NULL,
  `notes`                 TEXT               NULL,
  `created_by`            INT(11)        NOT NULL,
  `created_at`            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recur_active` (`is_active`),
  CONSTRAINT `fk_recur_category` FOREIGN KEY (`category_id`)         REFERENCES `expense_categories` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_recur_treasury` FOREIGN KEY (`treasury_account_id`) REFERENCES `treasury_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_recur_supplier` FOREIGN KEY (`supplier_id`)         REFERENCES `suppliers` (`id`)         ON DELETE SET NULL,
  CONSTRAINT `fk_recur_user`     FOREIGN KEY (`created_by`)          REFERENCES `users` (`id`)             ON DELETE RESTRICT,
  CONSTRAINT `chk_recur_dom`     CHECK (day_of_month BETWEEN 1 AND 28)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Now that the templates table exists, backfill the FK on expenses.
-- Guarded because information_schema-friendly ALTER re-runs are noisy in MySQL.
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE table_schema = DATABASE()
     AND table_name   = 'expenses'
     AND constraint_name = 'fk_expense_recurring_template'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE `expenses`
     ADD CONSTRAINT `fk_expense_recurring_template`
     FOREIGN KEY (`recurring_template_id`)
     REFERENCES `expense_recurring_templates` (`id`) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 5. expense_attachments — receipts / invoices / photos, one-to-many
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `expense_attachments` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `expense_id`     INT(11)      NOT NULL,
  `filename`       VARCHAR(255) NOT NULL COMMENT 'display name shown in UI',
  `stored_path`    VARCHAR(500) NOT NULL COMMENT 'relative path under /uploads/expenses',
  `mime_type`      VARCHAR(100) NOT NULL,
  `size_bytes`     BIGINT       NOT NULL,
  `uploaded_by`    INT(11)      NOT NULL,
  `uploaded_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_att_expense` (`expense_id`),
  CONSTRAINT `fk_att_expense` FOREIGN KEY (`expense_id`)  REFERENCES `expenses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_att_user`    FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- 6. Backfill from overheads.
--
-- The legacy category column is a free-typed VARCHAR that the old modal seeded
-- from a fixed <select>: 'Logistique', 'Maintenance', 'Loyer', 'Salaires',
-- 'Bureau', 'Marketing', 'Autre'. Any other value falls through to 'autre'.
--
-- Only run if the source table exists AND the target is empty for this row's
-- reference — so a re-run does not double-migrate.
-- -----------------------------------------------------------------------------
SET @has_overheads := (
  SELECT COUNT(*) FROM information_schema.tables
   WHERE table_schema = DATABASE() AND table_name = 'overheads'
);

SET @cat_map := 'INSERT INTO expenses
  (reference, title, description, category_id, amount, expense_date,
   payment_status, created_by, created_at)
  SELECT o.reference,
         o.title,
         CONCAT(''[Migré depuis Frais Généraux] '', COALESCE(o.category, '''')),
         COALESCE(
           (SELECT ec.id FROM expense_categories ec WHERE ec.slug =
             CASE LOWER(o.category)
               WHEN ''logistique''  THEN ''logistique''
               WHEN ''maintenance'' THEN ''maintenance''
               WHEN ''loyer''       THEN ''loyer''
               WHEN ''salaires''    THEN ''salaires''
               WHEN ''bureau''      THEN ''bureau''
               WHEN ''marketing''   THEN ''marketing''
               ELSE ''autre''
             END LIMIT 1),
           (SELECT id FROM expense_categories WHERE slug = ''autre'' LIMIT 1)
         ),
         o.amount,
         o.date,
         o.payment_status,
         o.created_by,
         o.created_at
    FROM overheads o
   WHERE NOT EXISTS (SELECT 1 FROM expenses e WHERE e.reference = o.reference)';

SET @sql := IF(@has_overheads > 0, @cat_map, 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 7. Closed-period lock — mirror the triggers migration 005 put on overheads
--    onto expenses. Without this, backdating an expense into a closed year
--    would silently succeed even though the same act on the legacy table
--    would (and still does) fail.
-- -----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS bi_expenses_closed_period;
DELIMITER //
CREATE TRIGGER bi_expenses_closed_period
BEFORE INSERT ON `expenses`
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM financial_years
         WHERE status = 'closed'
           AND YEAR(NEW.expense_date) = fiscal_year
    ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'expenses: cannot insert into a closed financial year.';
    END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS bu_expenses_closed_period;
DELIMITER //
CREATE TRIGGER bu_expenses_closed_period
BEFORE UPDATE ON `expenses`
FOR EACH ROW
BEGIN
    IF NEW.expense_date <> OLD.expense_date THEN
        IF EXISTS (
            SELECT 1 FROM financial_years
             WHERE status = 'closed'
               AND (YEAR(NEW.expense_date) = fiscal_year OR YEAR(OLD.expense_date) = fiscal_year)
        ) THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'expenses: cannot re-date into a closed financial year.';
        END IF;
    END IF;
END//
DELIMITER ;

-- -----------------------------------------------------------------------------
-- 8. RBAC — new permissions + grants.
-- -----------------------------------------------------------------------------
INSERT INTO `permissions` (`name`, `module`, `description`) VALUES
  ('accounting.expenses.view',              'accounting', 'Voir les dépenses'),
  ('accounting.expenses.create',            'accounting', 'Enregistrer une dépense'),
  ('accounting.expenses.edit',              'accounting', 'Modifier une dépense'),
  ('accounting.expenses.delete',            'accounting', 'Supprimer une dépense'),
  ('accounting.expenses.mark_paid',         'accounting', 'Marquer une dépense payée (poste au grand-livre)'),
  ('accounting.expenses.categories.manage', 'accounting', 'Gérer les catégories & mapping OHADA'),
  ('accounting.expenses.recurring.manage',  'accounting', 'Gérer les dépenses récurrentes')
ON DUPLICATE KEY UPDATE
  module = VALUES(module),
  description = VALUES(description);

-- Admin gets every permission by default (see 001_rbac_seed.sql:222) — no
-- explicit grant needed. Accountant + operations do not, so wire them
-- explicitly. `operations` gets view+create only, matching how the legacy
-- overhead permission was granted to that role.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p
    ON p.name IN (
      'accounting.expenses.view',
      'accounting.expenses.create',
      'accounting.expenses.edit',
      'accounting.expenses.delete',
      'accounting.expenses.mark_paid',
      'accounting.expenses.categories.manage',
      'accounting.expenses.recurring.manage'
    )
 WHERE r.name = 'accountant'
   AND NOT EXISTS (
     SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id
   );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p
    ON p.name IN (
      'accounting.expenses.view',
      'accounting.expenses.create'
    )
 WHERE r.name = 'operations'
   AND NOT EXISTS (
     SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id
   );

COMMIT;
