-- =============================================================================
-- 157_tax_payments_statements_closing.sql
-- -----------------------------------------------------------------------------
-- Comptabilité 3 (Déclarations Fiscales, États Financiers, Clôture), the training
-- video's QA on copy A, 18/11/2026 (docs/VIDEO_QA_FINDINGS.md §13):
--
--   tax_declarations.kind      gains 'cnps' and 'commune': both are computed and
--                              shown every month, but « Approuver & verrouiller »
--                              failed on them (the row could not be written).
--   tax_declarations.carry_forward
--                              the TVA credit a month carries to the next
--                              (TaxEngine reads it as « Crédit de TVA reporté »).
--   treasury_transactions.transaction_type
--                              gains 'out_tax_payment': a declaration paid from a
--                              treasury account (13.1).
--   permissions                accounting.tax.view / prepare / approve / pay
--                              (13.4: the page was gated on accounting.invoices.view,
--                              which the sales role holds, and anyone could
--                              approve and pay). The Comptable prepares and pays,
--                              the Contrôleur Financier approves and pays, the DG
--                              and the Administrateur do everything.
--   ohada_accounts 6418        « Autres impôts et taxes directs » for the commune
--                              tax and the patente when they are paid (641 is
--                              taken here by the taxes on salaries).
--   financial_mappings         the États Financiers' missing prefixes: 21 to 27
--                              (immobilisations), 29 / 39 / 49 (dépréciations),
--                              12 to 15 (capitaux propres), 17 to 19 (dettes
--                              financières), 55 (Mobile Money), 81 to 88 (HAO, in a
--                              new line « Résultat HAO »).
--
-- Re-runnable: guarded ALTERs, INSERT IGNORE, keyed updates.
-- =============================================================================

SET NAMES utf8mb4;

-- 1. Declaration kinds and the TVA carry-forward -------------------------------
ALTER TABLE tax_declarations
    MODIFY kind ENUM('tva','air','irpp_dipe','cnps','tsr','commune','is_annuel','patente') NOT NULL;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'tax_declarations' AND column_name = 'carry_forward');
SET @s := IF(@c = 0,
    "ALTER TABLE tax_declarations ADD COLUMN carry_forward DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'TVA credit carried to the next month' AFTER net_to_pay",
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. A tax payment is its own treasury movement ---------------------------------
ALTER TABLE treasury_transactions
    MODIFY transaction_type ENUM('in_tournee','in_other','in_client_payment','in_recycling_sale','out_expense',
        'out_client_refund','out_supplier_payment','out_payroll','out_advance','transfer_in','transfer_out','in_loan',
        'in_loan_repayment','out_loan','out_loan_repayment','out_asset_purchase','out_tax_payment') NOT NULL;

-- 3. Who prepares, approves and pays the declarations ---------------------------
INSERT IGNORE INTO permissions (name, module, description) VALUES
    ('accounting.tax.view',    'accounting', 'Voir les déclarations fiscales et sociales'),
    ('accounting.tax.prepare', 'accounting', 'Préparer les déclarations, téléverser les attestations AIR'),
    ('accounting.tax.approve', 'accounting', 'Approuver et verrouiller une déclaration'),
    ('accounting.tax.pay',     'accounting', 'Enregistrer le paiement d''une déclaration');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
  JOIN permissions p ON p.name IN ('accounting.tax.view', 'accounting.tax.prepare', 'accounting.tax.pay')
 WHERE r.name IN ('admin', 'directeur_general', 'controleur_financier', 'accountant');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
  JOIN permissions p ON p.name = 'accounting.tax.approve'
 WHERE r.name IN ('admin', 'directeur_general', 'controleur_financier');

-- 4. The account a commune tax or a patente is paid to ---------------------------
INSERT INTO ohada_accounts (account_number, name, type)
SELECT '6418', 'Autres impôts et taxes directs (taxe communale, patente)', 'expense'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ohada_accounts WHERE account_number = '6418');

INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active)
SELECT o.id, '6418', o.name, 'expense', 1
  FROM ohada_accounts o
 WHERE o.account_number = '6418'
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE code = '6418');

-- 5. The États Financiers' missing prefixes --------------------------------------
INSERT INTO financial_report_lines (code, name, report_type, sig_category, display_order)
SELECT 'RES_HAO', 'Résultat Hors Activités Ordinaires (HAO)', 'resultat', 'RES_NET', 85
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM financial_report_lines WHERE code = 'RES_HAO');

INSERT IGNORE INTO financial_mappings (report_line_id, account_prefix, operator)
SELECT l.id, m.prefix, m.op
  FROM (SELECT 'IMM_CORP' AS line, '21' AS prefix, '+' AS op UNION ALL
        SELECT 'IMM_CORP', '22', '+' UNION ALL SELECT 'IMM_CORP', '23', '+' UNION ALL
        SELECT 'IMM_CORP', '25', '+' UNION ALL SELECT 'IMM_CORP', '26', '+' UNION ALL
        SELECT 'IMM_CORP', '27', '+' UNION ALL SELECT 'IMM_CORP', '29', '-' UNION ALL
        SELECT 'STOCKS',   '39', '-' UNION ALL SELECT 'CREANCES', '49', '-' UNION ALL
        SELECT 'CAP_PROP', '12', '+' UNION ALL SELECT 'CAP_PROP', '13', '+' UNION ALL
        SELECT 'CAP_PROP', '14', '+' UNION ALL SELECT 'CAP_PROP', '15', '+' UNION ALL
        SELECT 'DET_FIN',  '17', '+' UNION ALL SELECT 'DET_FIN',  '18', '+' UNION ALL
        SELECT 'DET_FIN',  '19', '+' UNION ALL SELECT 'TRES_ACT', '55', '+' UNION ALL
        SELECT 'RES_HAO',  '81', '-' UNION ALL SELECT 'RES_HAO',  '82', '-' UNION ALL
        SELECT 'RES_HAO',  '83', '-' UNION ALL SELECT 'RES_HAO',  '84', '-' UNION ALL
        SELECT 'RES_HAO',  '85', '-' UNION ALL SELECT 'RES_HAO',  '86', '-' UNION ALL
        SELECT 'RES_HAO',  '87', '-' UNION ALL SELECT 'RES_HAO',  '88', '-') m
  JOIN financial_report_lines l ON l.code = m.line;
