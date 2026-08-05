-- =============================================================================
-- 089_tft_categorization.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Tableau de Flux de Trésorerie (SYSCOHADA TFT) support.
--
-- WHY
--   SYSCOHADA revised requires four mandatory financial statements. We ship the
--   Bilan and Compte de Résultat; the TFT and Notes Annexes have been the
--   holdout. Producing a TFT means classifying every cash movement into one of
--   three activities:
--
--     • Activité Opérationnelle (Operating)  — CAFG + variation BFR
--     • Investissement          (Investing)  — acquisitions/cessions immo,
--                                              titres, subventions reçues
--     • Financement             (Financing)  — apports capitaux, emprunts,
--                                              dividendes payés, remboursements
--
--   Rather than making the accountant re-tag every treasury movement, we tag
--   the CHART OF ACCOUNTS itself — the counter-account of a bank/caisse debit
--   or credit tells us which activity produced the flow.
--
-- WHAT THIS DOES
--   1. Adds `tft_category` on chart_of_accounts (enum, default 'operating').
--   2. Adds `tft_category` on ohada_accounts    (same, template for new COAs).
--   3. Backfills sensible defaults by class prefix:
--        Class 1 (except 12,13,14) → financing (capital, emprunts)
--        Class 2 (immobilisations) → investing
--        Class 5 (trésorerie)      → cash          — excluded from the sum
--        Classes 3,4,6,7,8         → operating     — default
--        Special exclusions        → 12,13,14,15   → operating (résultat)
--
--   4. Adds `tft_lines` (canonical SYSCOHADA TFT rubric list) so exports stay
--      in stable order regardless of how the aggregator walks accounts.
--
-- Idempotent.
-- =============================================================================

START TRANSACTION;

DELIMITER //
DROP PROCEDURE IF EXISTS lpc_add_col_089 //
CREATE PROCEDURE lpc_add_col_089(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists FROM information_schema.columns
      WHERE table_schema=DATABASE() AND table_name=p_table AND column_name=p_col;
    IF v_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_ddl);
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END//
DELIMITER ;

CALL lpc_add_col_089('chart_of_accounts', 'tft_category',
    "tft_category ENUM('operating','investing','financing','cash','excluded')
        NOT NULL DEFAULT 'operating'
        COMMENT 'SYSCOHADA TFT bucket — see migration 089'");

CALL lpc_add_col_089('ohada_accounts', 'tft_category',
    "tft_category ENUM('operating','investing','financing','cash','excluded')
        NOT NULL DEFAULT 'operating'");

-- ---------------------------------------------------------------------------
-- Backfill: derive from account code prefix.
-- ---------------------------------------------------------------------------

-- Cash (class 5)
UPDATE chart_of_accounts SET tft_category = 'cash'
 WHERE SUBSTRING(code,1,1) = '5';
UPDATE ohada_accounts SET tft_category = 'cash'
 WHERE SUBSTRING(account_number,1,1) = '5';

-- Investing (class 2)
UPDATE chart_of_accounts SET tft_category = 'investing'
 WHERE SUBSTRING(code,1,1) = '2';
UPDATE ohada_accounts SET tft_category = 'investing'
 WHERE SUBSTRING(account_number,1,1) = '2';

-- Financing (class 1, excluding 12/13/14/15 which are results / provisions)
UPDATE chart_of_accounts SET tft_category = 'financing'
 WHERE SUBSTRING(code,1,1) = '1'
   AND SUBSTRING(code,1,2) NOT IN ('12','13','14','15');
UPDATE ohada_accounts SET tft_category = 'financing'
 WHERE SUBSTRING(account_number,1,1) = '1'
   AND SUBSTRING(account_number,1,2) NOT IN ('12','13','14','15');

-- Operating: everything else already defaults to 'operating'; excluded stays
-- reserved for accountant overrides.

-- ---------------------------------------------------------------------------
-- Canonical TFT rubric list (SYSCOHADA révisé).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tft_lines (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    code         VARCHAR(6) NOT NULL UNIQUE,   -- ZA, ZB, ...
    activity     ENUM('operating','investing','financing','total') NOT NULL,
    name         VARCHAR(200) NOT NULL,
    formula      VARCHAR(60) NOT NULL COMMENT 'internal aggregation key',
    sign         ENUM('+','-','=') NOT NULL DEFAULT '+',
    display_order INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO tft_lines (code, activity, name, formula, sign, display_order) VALUES
  ('ZA','operating','Trésorerie nette au 1er janvier',                           'treso_open',    '=',  10),
  ('FA','operating','Capacité d\'Autofinancement Globale (CAFG)',                'cafg',          '+',  20),
  ('FB','operating','(-) Variation de l\'actif circulant HAO',                   'var_hao',       '-',  30),
  ('FC','operating','(-) Variation des stocks',                                  'var_stocks',    '-',  40),
  ('FD','operating','(-) Variation des créances',                                'var_creances',  '-',  50),
  ('FE','operating','(+) Variation du passif circulant',                         'var_dettes',    '+',  60),
  ('ZB','operating','Flux de trésorerie provenant des activités opérationnelles','flux_ops',      '=',  70),
  ('FF','investing','(-) Décaissements liés aux acquisitions d\'immobilisations','acq_immo',      '-', 100),
  ('FG','investing','(+) Encaissements liés aux cessions d\'immobilisations',    'ces_immo',      '+', 110),
  ('ZC','investing','Flux de trésorerie provenant des activités d\'investissement','flux_inv',    '=', 120),
  ('FH','financing','(+) Augmentations de capital par apports nouveaux',         'capital_in',    '+', 200),
  ('FI','financing','(-) Réductions de capital / rachats d\'actions',            'capital_out',   '-', 210),
  ('FJ','financing','(-) Dividendes versés',                                     'dividends',     '-', 220),
  ('FK','financing','(+) Emprunts nouveaux',                                     'loans_in',      '+', 230),
  ('FL','financing','(-) Remboursements d\'emprunts',                            'loans_out',     '-', 240),
  ('ZD','financing','Flux de trésorerie provenant des activités de financement', 'flux_fin',      '=', 250),
  ('ZE','total',    'VARIATION DE LA TRÉSORERIE NETTE DE LA PÉRIODE (ZB+ZC+ZD)', 'var_treso',     '=', 900),
  ('ZF','total',    'Trésorerie nette au 31 décembre',                           'treso_close',   '=', 910),
  ('ZG','total',    'Contrôle : Trésorerie N - Trésorerie N-1',                  'treso_check',   '=', 920)
ON DUPLICATE KEY UPDATE
    activity = VALUES(activity), name = VALUES(name), formula = VALUES(formula),
    sign = VALUES(sign), display_order = VALUES(display_order);

DROP PROCEDURE IF EXISTS lpc_add_col_089;

COMMIT;

-- =============================================================================
-- Verification:
--   SELECT tft_category, COUNT(*) FROM chart_of_accounts GROUP BY tft_category;
--   SELECT * FROM tft_lines ORDER BY display_order;
-- =============================================================================
