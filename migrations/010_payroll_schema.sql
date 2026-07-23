-- =============================================================================
-- 010_payroll_schema.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 4 (parallel) · Cameroon payroll schema.
--
-- WHY (from AUDIT_REPORT §6 and §3.5):
--   hr_contracts / hr_advances / hr_payslips exist but the UI submits a black
--   box: the accountant has no preview of CNPS / IRPP / CFC / CAC / CRTV before
--   generating the paie. Rates were also hard-coded in api/v1/payroll_controller
--   which means updating the CNPS ceiling requires a code deploy.
--
-- WHAT THIS DOES:
--   1. Extends hr_contracts with the missing fiscal fields (marital_status,
--      dependents_count, tax_regime, seniority_years, cnps_number).
--   2. Extends hr_payslips with a JSON breakdown column + a CRTV line item
--      (existing schema already has cnps_employee/irpp/cac/cfc/rav/tdl).
--   3. Creates payroll_rates + payroll_irpp_brackets so rates are data,
--      not code. Seeds the 2026 CM tables in the same file.
--
-- Idempotent. Uses information_schema.columns for pre-existence checks.
-- =============================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. hr_contracts — additive columns.
-- ---------------------------------------------------------------------------
DELIMITER //

DROP PROCEDURE IF EXISTS lpc_add_col_10 //
CREATE PROCEDURE lpc_add_col_10(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
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

CALL lpc_add_col_10('hr_contracts', 'marital_status',
    "marital_status ENUM('single','married','divorced','widowed') NOT NULL DEFAULT 'single' AFTER cnps_number");
CALL lpc_add_col_10('hr_contracts', 'dependents_count',
    "dependents_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER marital_status");
CALL lpc_add_col_10('hr_contracts', 'seniority_years',
    "seniority_years SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER dependents_count");
CALL lpc_add_col_10('hr_contracts', 'tax_regime',
    "tax_regime ENUM('standard','expatriate','exempt') NOT NULL DEFAULT 'standard' AFTER seniority_years");
CALL lpc_add_col_10('hr_contracts', 'hire_date',
    "hire_date DATE NULL AFTER tax_regime");

-- ---------------------------------------------------------------------------
-- 2. hr_payslips — add CRTV + breakdown JSON + gross fields not already present.
--    The base schema already has cnps_employee/irpp/cac/cfc/rav/tdl/net_pay.
--    We add crtv (Redevance Audio-Visuelle codified separately from rav for
--    the 2026 spec), cnps_employer (charge patronale, not deducted from net),
--    taxable_base (post frais-pro / abattement), breakdown JSON and a
--    preview-only snapshot column so re-issuing a payslip is auditable.
-- ---------------------------------------------------------------------------

CALL lpc_add_col_10('hr_payslips', 'crtv',
    "crtv DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER cfc");
CALL lpc_add_col_10('hr_payslips', 'cnps_employer',
    "cnps_employer DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER cnps_employee");
CALL lpc_add_col_10('hr_payslips', 'taxable_base',
    "taxable_base DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER gross_salary");
CALL lpc_add_col_10('hr_payslips', 'breakdown_json',
    "breakdown_json JSON NULL AFTER net_pay");

-- ---------------------------------------------------------------------------
-- 3. Rates table — one row per (year, key). The engine reads this at compute()
--    time so we can bump the CNPS ceiling from 750k → 900k FCFA without a
--    code deploy.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payroll_rates (
    id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    year          SMALLINT UNSIGNED NOT NULL,
    rate_key      VARCHAR(64) NOT NULL,
    rate_value    DECIMAL(12,6) NOT NULL,
    notes         VARCHAR(255) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by    INT NULL,
    UNIQUE KEY uk_year_key (year, rate_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed / upsert 2026 CM rates. Values in Payroll.php default to these if the
-- row is missing so the engine still runs on a partially-migrated instance.
INSERT INTO payroll_rates (year, rate_key, rate_value, notes) VALUES
    (2026, 'cnps_employee_rate',   0.042,   'Part salariale CNPS pension'),
    (2026, 'cnps_employer_rate',   0.162,   'Part patronale CNPS (PVID+PF+RP)'),
    (2026, 'cnps_ceiling',         750000,  'Plafond mensuel cotisation CNPS FCFA'),
    (2026, 'cfc_employee_rate',    0.010,   'Contribution Crédit Foncier (part salariale)'),
    (2026, 'cfc_employer_rate',    0.015,   'Contribution Crédit Foncier (part patronale)'),
    (2026, 'cac_rate',             0.100,   'Centimes Additionnels Communaux — 10% de l''IRPP'),
    (2026, 'frais_pro_rate',       0.300,   'Frais professionnels — 30% du brut - CNPS'),
    (2026, 'abattement_annuel',    500000,  'Abattement forfaitaire annuel — 500 000 FCFA'),
    (2026, 'tdl_threshold',        100000,  'Seuil TDL (Taxe de Développement Local)'),
    (2026, 'tdl_amount',           3000,    'Montant mensuel TDL au-delà du seuil')
ON DUPLICATE KEY UPDATE rate_value = VALUES(rate_value), notes = VALUES(notes);

-- ---------------------------------------------------------------------------
-- 4. IRPP progressive brackets. Bounds in FCFA on the MONTHLY net taxable
--    base (base - CNPS - frais_pro - abattement/12). max_amount NULL means
--    "+ infini" (top bracket).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payroll_irpp_brackets (
    id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    year          SMALLINT UNSIGNED NOT NULL,
    tranche_ord   TINYINT UNSIGNED NOT NULL,
    min_amount    DECIMAL(15,2) NOT NULL,
    max_amount    DECIMAL(15,2) NULL,
    rate          DECIMAL(6,4) NOT NULL,
    UNIQUE KEY uk_year_ord (year, tranche_ord)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO payroll_irpp_brackets (year, tranche_ord, min_amount, max_amount, rate) VALUES
    (2026, 1, 0,       166666,  0.1000),
    (2026, 2, 166666,  250000,  0.1500),
    (2026, 3, 250000,  416666,  0.2500),
    (2026, 4, 416666,  NULL,    0.3500)
ON DUPLICATE KEY UPDATE
    min_amount = VALUES(min_amount),
    max_amount = VALUES(max_amount),
    rate       = VALUES(rate);

-- ---------------------------------------------------------------------------
-- 5. CRTV progressive brackets. CRTV (Redevance Audio-Visuelle) is a fixed
--    monthly amount that steps up by gross_salary band — same table shape
--    so future adjustments are a single UPDATE.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payroll_crtv_brackets (
    id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    year          SMALLINT UNSIGNED NOT NULL,
    tranche_ord   TINYINT UNSIGNED NOT NULL,
    gross_min     DECIMAL(15,2) NOT NULL,
    gross_max     DECIMAL(15,2) NULL,
    fixed_amount  DECIMAL(15,2) NOT NULL,
    UNIQUE KEY uk_year_ord (year, tranche_ord)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO payroll_crtv_brackets (year, tranche_ord, gross_min, gross_max, fixed_amount) VALUES
    (2026,  1,      0,      50000,      0),
    (2026,  2,  50000,     100000,    750),
    (2026,  3, 100000,     200000,   1950),
    (2026,  4, 200000,     300000,   3250),
    (2026,  5, 300000,     400000,   4550),
    (2026,  6, 400000,     500000,   5850),
    (2026,  7, 500000,     600000,   7150),
    (2026,  8, 600000,     700000,   8450),
    (2026,  9, 700000,     800000,   9750),
    (2026, 10, 800000,     900000,  11050),
    (2026, 11, 900000,    1000000,  12350),
    (2026, 12, 1000000,       NULL, 13000)
ON DUPLICATE KEY UPDATE
    gross_min    = VALUES(gross_min),
    gross_max    = VALUES(gross_max),
    fixed_amount = VALUES(fixed_amount);

-- ---------------------------------------------------------------------------
-- 6. Payslip token expiry (per AUDIT §3.3 M-DB-14 — token needs a TTL).
--    Additive; the app writes NOW() + INTERVAL 90 DAY on issue.
-- ---------------------------------------------------------------------------
CALL lpc_add_col_10('hr_payslips', 'token_expires_at',
    "token_expires_at TIMESTAMP NULL DEFAULT NULL AFTER token");

-- Housekeeping.
DROP PROCEDURE IF EXISTS lpc_add_col_10;

COMMIT;

-- =============================================================================
-- Verification (manual):
--   SELECT rate_key, rate_value FROM payroll_rates WHERE year = 2026;
--   SELECT tranche_ord, min_amount, max_amount, rate
--     FROM payroll_irpp_brackets WHERE year = 2026 ORDER BY tranche_ord;
--   DESC hr_contracts;   -- should show marital_status, dependents_count, etc.
--   DESC hr_payslips;    -- should show crtv, cnps_employer, taxable_base, breakdown_json.
-- =============================================================================
