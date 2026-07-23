-- =============================================================================
-- 020_cameroon_tax_module.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Cameroon tax compliance foundation.
--
-- WHAT THIS DOES
--   1. Seeds the OHADA/SYSCOHADA tax sub-accounts that were missing
--      (44xx family) so journal entries can post to the correct account.
--   2. Adds company_tax_settings — one row per legal entity, drives
--      régime (réel/simplifié), CIT rate, CNPS group (A/B/C), whether
--      the company itself is a withholding agent.
--   3. Adds withholding-agent metadata on clients: is_withholding_agent,
--      withholding_air_rate, withholding_vat_agent, notes.
--   4. Extends invoices with AIR / withholding split fields so an
--      invoice paid by a withholding client (Prometal etc.) records
--      the AIR portion separately from the net cash portion.
--   5. Extends payments with air_withheld_amount + certificate reference
--      so the AIR credited against monthly declaration is auditable.
--   6. Creates tax_declarations (header) and tax_declaration_lines
--      (component breakdown) to store the exact amounts the user files
--      each period — TVA, AIR, IRPP/CAC/DIPE, TSR, patente.
--   7. Creates withholding_certificates — attestations de retenue à la
--      source received from clients; the AIR credit only counts once
--      an attestation is uploaded (audit-trail requirement).
--
-- Idempotent. Uses IF NOT EXISTS / conditional ALTERs everywhere.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. Missing OHADA / SYSCOHADA revised (2018) sub-accounts for CM tax admin.
--    Numbers per Acte Uniforme OHADA + CM CGI cross-reference.
-- -----------------------------------------------------------------------------
INSERT INTO ohada_accounts (account_number, name, type) VALUES
  ('4421', 'IRPP retenu sur salaires',                       'liability'),
  ('4423', 'IRPP autres — acompte trimestriel',              'liability'),
  ('4424', 'AIR retenu à la source par les clients (à récupérer)', 'asset'),
  ('4425', 'CAC — Centimes Additionnels Communaux',          'liability'),
  ('4426', 'Retenues CFC salariés',                          'liability'),
  ('4427', 'Retenues TDL / CRTV / autres',                   'liability'),
  ('4428', 'Retenues à la source sur factures fournisseurs (à reverser)', 'liability'),
  ('4431', 'TVA facturée sur ventes (collectée)',            'liability'),
  ('4432', 'TVA collectée à décaisser (période courante)',   'liability'),
  ('4433', 'TVA à régulariser',                              'liability'),
  ('4451', 'TVA récupérable sur achats de biens',            'asset'),
  ('4452', 'TVA récupérable sur immobilisations',            'asset'),
  ('4453', 'TVA récupérable sur services',                   'asset'),
  ('4455', 'Crédit de TVA reportable',                       'asset'),
  ('4457', 'Patente & contribution des licences',            'expense'),
  ('4471', 'Acompte IS / minimum de perception (2.2 / 5.5%)','asset'),
  ('4472', 'Impôt sur les sociétés à payer (IS)',            'liability'),
  ('4481', 'TSR — Taxe Spéciale sur le Revenu',              'liability'),
  ('4485', 'FNE — Fonds National de l''Emploi (patronale)',  'liability'),
  ('431',  'Sécurité sociale (CNPS)',                        'liability'),
  ('447',  'Etat — impôts, taxes et versements assimilés',   'liability'),
  ('664',  'Charges sociales patronales',                    'expense'),
  ('6411', 'IRPP à la charge de l''entreprise',              'expense'),
  ('6412', 'Charges fiscales sur salaires (CFC part patronale, FNE)', 'expense'),
  ('419',  'Clients — avances et acomptes reçus',            'liability'),
  ('421',  'Personnel — avances et acomptes',                'asset'),
  ('422',  'Personnel — rémunérations dues',                 'liability')
ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type);


-- -----------------------------------------------------------------------------
-- 2. company_tax_settings
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS company_tax_settings (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    legal_name               VARCHAR(200) NOT NULL,
    niu                      VARCHAR(50)  NOT NULL COMMENT 'Numéro d''Identifiant Unique',
    tax_office               VARCHAR(100) DEFAULT NULL COMMENT 'e.g. CIME Littoral II',
    tax_regime               ENUM('reel','simplifie','forfait','non_professionnel') NOT NULL DEFAULT 'reel',
    cit_rate                 DECIMAL(5,4) NOT NULL DEFAULT 0.3300 COMMENT '0.33 or 0.275',
    air_rate                 DECIMAL(5,4) NOT NULL DEFAULT 0.0220 COMMENT '0.022 régime réel, 0.055 régime simplifié',
    tva_rate                 DECIMAL(5,4) NOT NULL DEFAULT 0.1925,
    tva_liable               TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Only true if turnover >= 50M and régime réel',
    cnps_group               ENUM('A','B','C') NOT NULL DEFAULT 'A' COMMENT 'AT rate: A=1.75%, B=2.5%, C=5%',
    is_withholding_agent     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Do WE withhold AIR from OUR suppliers?',
    filing_day               TINYINT      NOT NULL DEFAULT 15 COMMENT 'DGI monthly deadline (15th)',
    fiscal_year_start_month  TINYINT      NOT NULL DEFAULT 1,
    created_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_niu (niu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed a default row if none exists (régime réel, AIR 2.2%, CNPS group A).
INSERT INTO company_tax_settings (legal_name, niu, tax_regime, cit_rate, air_rate, tva_rate, tva_liable, cnps_group, is_withholding_agent)
SELECT 'Bureau LPC SARL', 'P000000000000', 'reel', 0.3300, 0.0220, 0.1925, 1, 'A', 0
 WHERE NOT EXISTS (SELECT 1 FROM company_tax_settings);


-- -----------------------------------------------------------------------------
-- 3. clients — withholding agent metadata
-- -----------------------------------------------------------------------------
-- add is_withholding_agent
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='is_withholding_agent');
SET @sql := IF(@has=0,
   'ALTER TABLE clients ADD COLUMN is_withholding_agent TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 if client withholds AIR at source (Prometal, SONARA, PMUC, listed entities per Arrêté DGI n°00001)''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- add withholding_air_rate
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='withholding_air_rate');
SET @sql := IF(@has=0,
   'ALTER TABLE clients ADD COLUMN withholding_air_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0000 COMMENT ''0.022 (real regime supplier), 0.055 (simplified), 0.0 if not applicable''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- add withholding_vat_agent
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='withholding_vat_agent');
SET @sql := IF(@has=0,
   'ALTER TABLE clients ADD COLUMN withholding_vat_agent TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 if client also withholds TVA at source (public entities, oil sector)''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- add tax_regime (own — because the AIR rate WE apply as their supplier
-- depends on THEIR régime if they withhold from us; but also affects how
-- we treat their supplier invoices to us if they're a supplier)
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='client_tax_regime');
SET @sql := IF(@has=0,
   "ALTER TABLE clients ADD COLUMN client_tax_regime ENUM('reel','simplifie','forfait','non_professionnel','unknown') NOT NULL DEFAULT 'unknown'",
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 4. invoices — AIR split (net_payable + air_withheld)
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='air_rate');
SET @sql := IF(@has=0,
   'ALTER TABLE invoices
      ADD COLUMN air_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0000 COMMENT ''AIR rate the client is expected to withhold at source (0 if none)''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='air_amount');
SET @sql := IF(@has=0,
   'ALTER TABLE invoices ADD COLUMN air_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT ''AIR expected to be withheld = subtotal * air_rate''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='net_payable');
SET @sql := IF(@has=0,
   'ALTER TABLE invoices ADD COLUMN net_payable DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT ''total_amount - air_amount; what the client actually transfers''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- backfill net_payable = total_amount for existing rows
UPDATE invoices SET net_payable = total_amount WHERE net_payable = 0;


-- -----------------------------------------------------------------------------
-- 5. payments — AIR withheld split + certificate ref
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='payments' AND column_name='air_withheld_amount');
SET @sql := IF(@has=0,
   'ALTER TABLE payments ADD COLUMN air_withheld_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT ''Portion of the invoice value the client kept as AIR''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='payments' AND column_name='withholding_certificate_id');
SET @sql := IF(@has=0,
   'ALTER TABLE payments ADD COLUMN withholding_certificate_id INT DEFAULT NULL COMMENT ''FK to withholding_certificates when attestation uploaded''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- 6. tax_declarations + tax_declaration_lines
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tax_declarations (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    kind                  ENUM('tva','air','irpp_dipe','tsr','is_annuel','patente') NOT NULL,
    period_year           SMALLINT NOT NULL,
    period_month          TINYINT  NOT NULL COMMENT '1-12; for annual filings this is 12',
    status                ENUM('draft','ready','filed','paid') NOT NULL DEFAULT 'draft',
    computed_at           TIMESTAMP NULL,
    filed_at              TIMESTAMP NULL,
    paid_at               TIMESTAMP NULL,
    due_date              DATE NULL,
    total_due             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_credit          DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'e.g. AIR already withheld',
    net_to_pay            DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'total_due - total_credit; 0 if credit >= due',
    reference             VARCHAR(40)  NULL COMMENT 'DGI receipt reference once filed',
    computed_by           INT NULL,
    filed_by              INT NULL,
    notes                 TEXT NULL,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_period (kind, period_year, period_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tax_declaration_lines (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    declaration_id    INT NOT NULL,
    line_code         VARCHAR(30) NOT NULL COMMENT 'e.g. TVA_COLLECTEE, TVA_DEDUCTIBLE, AIR_BRUT, AIR_RETENU_SOURCE',
    label             VARCHAR(200) NOT NULL,
    amount            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    is_credit         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 if this line reduces net_to_pay',
    source_ref        VARCHAR(120) NULL COMMENT 'e.g. SQL fingerprint for audit',
    CONSTRAINT fk_tdl_decl FOREIGN KEY (declaration_id) REFERENCES tax_declarations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -----------------------------------------------------------------------------
-- 7. withholding_certificates (attestations de retenue à la source)
--    Uploaded by the accountant after receiving them from the withholding
--    client. Only certified retenues are counted as AIR credit — this is
--    the DGI audit requirement (art. L.94 LPF).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS withholding_certificates (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    client_id         INT NOT NULL,
    certificate_ref   VARCHAR(80) NOT NULL COMMENT 'The client-issued attestation reference',
    issue_date        DATE NOT NULL,
    period_year       SMALLINT NOT NULL,
    period_month      TINYINT  NOT NULL,
    total_amount      DECIMAL(15,2) NOT NULL COMMENT 'AIR withheld across all invoices this period',
    file_path         VARCHAR(255) NULL COMMENT 'uploads/withholding/… PDF',
    uploaded_by       INT NOT NULL,
    uploaded_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cert (client_id, certificate_ref),
    KEY idx_period (period_year, period_month),
    CONSTRAINT fk_wc_client FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add FK on payments.withholding_certificate_id now that the parent table exists.
SET @has_fk := (
  SELECT COUNT(*) FROM information_schema.key_column_usage
   WHERE table_schema=DATABASE()
     AND table_name='payments' AND column_name='withholding_certificate_id'
     AND referenced_table_name='withholding_certificates'
);
SET @sql := IF(@has_fk=0,
   'ALTER TABLE payments ADD CONSTRAINT fk_pay_wc FOREIGN KEY (withholding_certificate_id) REFERENCES withholding_certificates(id) ON DELETE SET NULL',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

COMMIT;

-- =============================================================================
-- VERIFICATION:
--   SELECT account_number, name FROM ohada_accounts WHERE account_number LIKE '44%' ORDER BY account_number;
--   DESC company_tax_settings;
--   SELECT column_name FROM information_schema.columns WHERE table_name='invoices' AND column_name IN ('air_rate','air_amount','net_payable');
--   SELECT column_name FROM information_schema.columns WHERE table_name='clients' AND column_name IN ('is_withholding_agent','withholding_air_rate');
-- =============================================================================
