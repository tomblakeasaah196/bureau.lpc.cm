-- =============================================================================
-- 086_tax_declarations_approval_flow.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 9 · Tax module hardening for external audit.
--
-- WHY
--   The tax_declarations table stored `status` (draft/ready/filed/paid) and
--   `reference`. That is not sufficient for a DGI or Big-4 audit trail. For
--   each period we must be able to prove, years after the fact:
--     · WHO clicked "Approuver" and WHEN
--     · what the exact snapshot looked like at that moment (PDF)
--     · which journal entry recognises the DGI / CNPS payable
--     · which treasury payment discharged the liability
--     · the DGI/CNPS receipt (quittance) the tax office issued back
--
-- WHAT THIS DOES
--   1. Adds approval-lock columns on tax_declarations (locked_at, locked_by,
--      snapshot_pdf_path, approval_hash).
--   2. Adds payable-JE + quittance columns (payable_je_id, payment_je_id,
--      paid_amount, paid_reference, quittance_path).
--   3. Adds a source_ref column on tax_declaration_lines (already documented
--      in migration 020 but never actually created — this is the audit
--      fingerprint per line).
--   4. Adds commune / patente / employer-tax preferences so Paramètres can
--      round-trip everything through Prefs::* the same way as TVA/AIR/CIT.
--
-- Idempotent. Uses information_schema column-existence checks.
-- =============================================================================

START TRANSACTION;

DELIMITER //

DROP PROCEDURE IF EXISTS lpc_add_col_86 //
CREATE PROCEDURE lpc_add_col_86(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
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

-- -----------------------------------------------------------------------------
-- 1. tax_declarations — approval lock + audit trail.
--    locked_at   = timestamp of the click on "Approuver"; once set the
--                  snapshot is frozen. Re-running compute() no longer
--                  overwrites the persisted lines: it produces a warning
--                  "les chiffres ont changé depuis l'approbation".
--    approval_hash = SHA-256 of the JSON snapshot at lock time, so any
--                  later drift is provable (audit standard).
-- -----------------------------------------------------------------------------
CALL lpc_add_col_86('tax_declarations', 'locked_at',
    "locked_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Approval timestamp — snapshot frozen once set.'");
CALL lpc_add_col_86('tax_declarations', 'locked_by',
    "locked_by INT NULL DEFAULT NULL COMMENT 'user_id who approved the declaration.'");
CALL lpc_add_col_86('tax_declarations', 'snapshot_pdf_path',
    "snapshot_pdf_path VARCHAR(255) NULL DEFAULT NULL COMMENT 'uploads/tax_declarations/… PDF frozen at approval.'");
CALL lpc_add_col_86('tax_declarations', 'approval_hash',
    "approval_hash CHAR(64) NULL DEFAULT NULL COMMENT 'SHA-256 of lines JSON at lock time.'");

-- -----------------------------------------------------------------------------
-- 2. Payable + payment plumbing.
--    payable_je_id  : (optional) JE that recognises the liability at
--                     approval — usually not needed because the liability
--                     was already booked by payroll / invoicing.
--    payment_je_id  : JE posted when the accountant records the payment.
--                     Debits liability, credits treasury account.
--    paid_amount    : cash actually paid (may differ from net_to_pay in
--                     the rare case of a partial payment).
--    paid_reference : bank reference / cheque no. / DGI virement ref.
--    quittance_path : PDF/scan of the DGI/CNPS receipt.
-- -----------------------------------------------------------------------------
CALL lpc_add_col_86('tax_declarations', 'payable_je_id',
    "payable_je_id INT NULL DEFAULT NULL COMMENT 'journal_entries.id — payable recognition JE (optional).'");
CALL lpc_add_col_86('tax_declarations', 'payment_je_id',
    "payment_je_id INT NULL DEFAULT NULL COMMENT 'journal_entries.id — treasury outflow JE.'");
CALL lpc_add_col_86('tax_declarations', 'paid_amount',
    "paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00");
CALL lpc_add_col_86('tax_declarations', 'paid_reference',
    "paid_reference VARCHAR(80) NULL DEFAULT NULL");
CALL lpc_add_col_86('tax_declarations', 'quittance_path',
    "quittance_path VARCHAR(255) NULL DEFAULT NULL COMMENT 'uploads/tax_declarations/quittances/… receipt file.'");
CALL lpc_add_col_86('tax_declarations', 'treasury_account_id',
    "treasury_account_id INT NULL DEFAULT NULL COMMENT 'treasury_accounts.id used to pay.'");

-- -----------------------------------------------------------------------------
-- 3. tax_declaration_lines — audit fingerprint per line.
-- -----------------------------------------------------------------------------
CALL lpc_add_col_86('tax_declaration_lines', 'source_ref',
    "source_ref VARCHAR(160) NULL DEFAULT NULL COMMENT 'SQL fingerprint / source rows for audit.'");
CALL lpc_add_col_86('tax_declaration_lines', 'destination',
    "destination ENUM('dgi','cnps','commune','other') NOT NULL DEFAULT 'dgi' COMMENT 'Which authority receives the amount.'");
CALL lpc_add_col_86('tax_declaration_lines', 'informational',
    "informational TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 if not a movement — just context (turnover, rate, etc.)'");

DROP PROCEDURE IF EXISTS lpc_add_col_86;

-- -----------------------------------------------------------------------------
-- 4. Preference seeds — employer-tax + commune + FNE + AT rate group.
--    These live in `app_preferences` (same table Prefs::* reads/writes), so
--    Paramètres tab can round-trip them without a new table.
--    tax_fne_rate       : Fonds National de l'Emploi — 1% patronale on
--                          gross salary (CGI art. 4).
--    tax_at_rate_a/b/c  : Accident du travail — 1.75 / 2.5 / 5 % patronale.
--    tax_commune_monthly: Taxe communale forfait mensuelle (0 if not due).
--
-- We keep any value the user has already set (COALESCE(pref_value, VALUES()))
-- because a re-run of this migration must not overwrite production config.
-- -----------------------------------------------------------------------------
INSERT INTO app_preferences
    (pref_key, pref_value, kind, group_key, label_fr, label_en, help_fr, unit, sort_order, is_active)
VALUES
    ('tax_fne_rate',        '1.00', 'decimal', 'tax', 'Taux FNE (patronal)',
        'FNE rate (employer)',      'Fonds National de l''Emploi, 1 % de la masse salariale.',
        '%', 210, 1),
    ('tax_at_rate_a',       '1.75', 'decimal', 'tax', 'CNPS Accident du travail — groupe A',
        'CNPS AT — group A',        'Groupe A (risque faible) — 1,75 % de la masse plafonnée.',
        '%', 220, 1),
    ('tax_at_rate_b',       '2.50', 'decimal', 'tax', 'CNPS Accident du travail — groupe B',
        'CNPS AT — group B',        'Groupe B (risque moyen) — 2,5 % de la masse plafonnée.',
        '%', 221, 1),
    ('tax_at_rate_c',       '5.00', 'decimal', 'tax', 'CNPS Accident du travail — groupe C',
        'CNPS AT — group C',        'Groupe C (risque élevé) — 5 % de la masse plafonnée.',
        '%', 222, 1),
    ('tax_commune_monthly', '0',    'int',     'tax', 'Taxe communale — forfait mensuel',
        'Commune tax — monthly',    'Montant fixe FCFA versé chaque mois à la commune (0 si sans objet).',
        'FCFA', 230, 1),
    ('tax_patente_annual',  '0',    'int',     'tax', 'Patente annuelle (override)',
        'Annual patente (override)', 'Laisser 0 pour laisser le moteur calculer la patente à partir du CA N-1.',
        'FCFA', 240, 1)
ON DUPLICATE KEY UPDATE
    kind       = VALUES(kind),
    group_key  = VALUES(group_key),
    label_fr   = VALUES(label_fr),
    label_en   = VALUES(label_en),
    help_fr    = VALUES(help_fr),
    unit       = VALUES(unit),
    sort_order = VALUES(sort_order),
    is_active  = VALUES(is_active);
    -- NB: pref_value is NOT overwritten on duplicate — the user's setting wins.

COMMIT;

-- =============================================================================
-- Verification:
--   DESC tax_declarations;
--     -- must show: locked_at, locked_by, snapshot_pdf_path, approval_hash,
--     --           payable_je_id, payment_je_id, paid_amount, paid_reference,
--     --           quittance_path, treasury_account_id
--   DESC tax_declaration_lines;
--     -- must show: source_ref, destination, informational
--   SELECT `key`,`value` FROM preferences WHERE `key` LIKE 'tax_%' ORDER BY `key`;
-- =============================================================================
