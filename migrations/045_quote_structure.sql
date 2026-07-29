-- =============================================================================
-- 045_quote_structure.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 9 · make the commercial offer a proper contract
-- document.
--
-- WHY:
--   The devis is not a fiscal document — art. 150 CGI binds the facture, not
--   the offer. But once a client signs "Bon pour accord" it IS a contract under
--   OHADA, and contract formation needs: identified parties, a precise object,
--   a price AND ITS TAX TREATMENT, and a validity.
--
--   DEV-2607-841B had none of the tax treatment. It printed
--       "MONTANT TOTAL MENSUEL ESTIMÉ (FCFA) : 100 000"
--   with nothing saying whether that is HT or TTC. A client who assumes TTC and
--   is invoiced HT + 19,25 % has a legitimate dispute — and since LPC's water is
--   exonerated, the silence also throws away a selling point.
--
--   It also expressed validity as "30 Jours à compter de l'émission", which
--   requires the reader to know the issue date and do arithmetic, and carried
--   no revision number — so where an offer was re-sent after negotiation,
--   neither party could prove which version was accepted.
--
-- WHAT THIS ADDS:
--   1. proposals  — the fiscal ladder, a computed expiry, a revision counter,
--                   and the prospect's NIU/RCCM.
--   2. products   — consigne (deposit) and replacement values. Articles 1 and 2
--                   of the terms promise "des frais de remplacement au tarif en
--                   vigueur" and never state one; this is where that tarif lives.
--   3. company_annexes — the ANOR certificate, RCCM extract and any other PDF
--                   appended to an offer. The document claims "certifiée ANOR"
--                   and shows client logos; annexes turn claims into evidence.
--   4. proposal_template_settings — copy for the new sections, so the wording
--                   stays editable in the Proposal Studio like everything else.
--
-- IDEMPOTENT. Additive only; no existing column changes type.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. proposals — fiscal ladder
--    Mirrors the invoice columns exactly (migration 040), so converting a quote
--    to an invoice is a copy and the two documents can never quote different
--    tax treatments for the same deal.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='proposals' AND column_name='subtotal');
SET @sql := IF(@has=0,
   'ALTER TABLE proposals ADD COLUMN subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT ''Total HT''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='proposals' AND column_name='tva_rate');
SET @sql := IF(@has=0,
   'ALTER TABLE proposals ADD COLUMN tva_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='proposals' AND column_name='tva_amount');
SET @sql := IF(@has=0,
   'ALTER TABLE proposals ADD COLUMN tva_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='proposals' AND column_name='tva_exemption_reason');
SET @sql := IF(@has=0,
   'ALTER TABLE proposals ADD COLUMN tva_exemption_reason VARCHAR(160) NULL COMMENT ''Printed when tva_rate = 0 instead of a bare 0 %''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='proposals' AND column_name='excise_rate');
SET @sql := IF(@has=0,
   'ALTER TABLE proposals ADD COLUMN excise_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0000',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='proposals' AND column_name='excise_amount');
SET @sql := IF(@has=0,
   'ALTER TABLE proposals ADD COLUMN excise_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill: everything issued so far was HT with no tax stated. total_amount
-- was the only figure, so it becomes the subtotal — which is what it in fact
-- was. No amount changes; the document simply starts saying so.
UPDATE proposals SET subtotal = total_amount WHERE subtotal = 0 AND total_amount > 0;

-- -----------------------------------------------------------------------------
-- 2. proposals — validity as a date, and a revision counter
--    validity_days already exists and stays (the studio edits it); valid_until
--    is derived at issue so the printed date cannot drift if the row is
--    re-rendered months later with a different validity_days setting.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='proposals' AND column_name='valid_until');
SET @sql := IF(@has=0,
   'ALTER TABLE proposals ADD COLUMN valid_until DATE NULL COMMENT ''Frozen at issue = date + validity_days. Printed as "Offre valable jusqu''''au ..."''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE proposals
   SET valid_until = DATE_ADD(`date`, INTERVAL COALESCE(validity_days, 30) DAY)
 WHERE valid_until IS NULL;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='proposals' AND column_name='revision');
SET @sql := IF(@has=0,
   'ALTER TABLE proposals ADD COLUMN revision SMALLINT NOT NULL DEFAULT 1 COMMENT ''Bumped on re-issue after negotiation; printed as "rév. 2"''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 3. proposals — the prospect's fiscal identity
--    Collected during the sale rather than chased after the first invoice is
--    rejected. Optional at quote stage: a prospect is not always ready to hand
--    over a NIU on first contact.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='proposals' AND column_name='client_niu');
SET @sql := IF(@has=0,
   'ALTER TABLE proposals ADD COLUMN client_niu VARCHAR(50) NULL COMMENT ''Numéro d''''Identifiant Unique du prospect''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='proposals' AND column_name='client_rccm');
SET @sql := IF(@has=0,
   'ALTER TABLE proposals ADD COLUMN client_rccm VARCHAR(80) NULL',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 4. products — consigne and replacement tariff
--    A 20L bonbonne is lent, not sold. Article 2 of the terms threatens
--    replacement fees "au tarif en vigueur" without ever publishing one, which
--    is the single most likely source of a later dispute.
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='products' AND column_name='deposit_value');
SET @sql := IF(@has=0,
   'ALTER TABLE products ADD COLUMN deposit_value DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT ''Consigne — refundable deposit per returnable container. 0 = not consigned.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='products' AND column_name='replacement_value');
SET @sql := IF(@has=0,
   'ALTER TABLE products ADD COLUMN replacement_value DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT ''Charged on loss or major damage. Printed in the offer''''s consigne schedule.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 5. company_annexes — evidence appended to an offer
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS company_annexes (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    label_fr      VARCHAR(120) NOT NULL,
    label_en      VARCHAR(120) NULL,
    file_path     VARCHAR(255) NOT NULL COMMENT 'Web-relative, under /uploads/annexes/',
    kind          ENUM('certificate','registry','reference','other') NOT NULL DEFAULT 'other',
    show_on_quote TINYINT(1) NOT NULL DEFAULT 1,
    sort_order    SMALLINT NOT NULL DEFAULT 0,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_annex_active (is_active, show_on_quote, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- 6. Copy for the new sections, editable in the Proposal Studio like the rest.
--    lpc_proposal_defaults() carries the same strings, so the document renders
--    correctly even if this INSERT never runs.
-- -----------------------------------------------------------------------------
INSERT INTO proposal_template_settings
    (setting_key, value_fr, value_en, kind, group_key, label_fr, help_fr, sort_order)
VALUES
-- ---- Pricing / fiscal ---------------------------------------------------------
('tbl_subtotal', 'Total Hors Taxe (HT)', 'Subtotal (excl. tax)', 'text', 'offer', 'Libellé — total HT', NULL, 60),
('tbl_tva', 'TVA', 'VAT', 'text', 'offer', 'Libellé — TVA', NULL, 61),
('tbl_ttc', 'Montant Total Mensuel Estimé (TTC)', 'Estimated Monthly Total (incl. tax)', 'text', 'offer', 'Libellé — total TTC', NULL, 62),
('tbl_exempt_default', 'Exonéré de TVA — régime applicable au produit facturé (art. 128 CGI).', 'VAT exempt — regime applicable to the goods supplied (s. 128 CGI).', 'textarea', 'offer', 'Mention d''exonération par défaut', 'Imprimée sous la ligne TVA lorsque le taux est nul.', 63),
('tbl_words', 'Arrêtée la présente offre à la somme de :', 'This offer is closed at the sum of:', 'text', 'offer', 'Libellé — montant en lettres', NULL, 64),

-- ---- Consigne schedule --------------------------------------------------------
('sec_consigne_title', 'Barème des Consignes et Remplacements', 'Deposit & Replacement Schedule', 'text', 'terms', 'Titre — barème des consignes', NULL, 70),
('sec_consigne_intro', 'Les contenants réutilisables sont mis à disposition sous consigne. La consigne est intégralement restituée au retour du contenant en bon état. Le tarif de remplacement s''applique uniquement en cas de perte ou de détérioration majeure, conformément à l''Article 2.', 'Returnable containers are provided against a refundable deposit. The deposit is returned in full when the container is returned in good condition. The replacement tariff applies only in the event of loss or major damage, per Article 2.', 'textarea', 'terms', 'Introduction — barème', NULL, 71),
('tbl_consigne_item', 'Contenant', 'Container', 'text', 'terms', 'Colonne — contenant', NULL, 72),
('tbl_consigne_deposit', 'Consigne (FCFA)', 'Deposit (FCFA)', 'text', 'terms', 'Colonne — consigne', NULL, 73),
('tbl_consigne_replace', 'Remplacement (FCFA)', 'Replacement (FCFA)', 'text', 'terms', 'Colonne — remplacement', NULL, 74),

-- ---- Delivery & transport liability -------------------------------------------
('sec_delivery_title', 'Livraison et Transfert des Risques', 'Delivery & Transfer of Risk', 'text', 'terms', 'Titre — livraison', NULL, 80),
('sec_delivery_zone_label', 'Zone de livraison', 'Delivery area', 'text', 'terms', 'Libellé — zone', NULL, 81),
('sec_delivery_zone', 'Douala et périphérie. Livraison hors zone sur devis complémentaire.', 'Douala and surrounding areas. Deliveries outside this area are quoted separately.', 'textarea', 'terms', 'Zone de livraison', 'Adaptez à votre couverture réelle.', 82),
('sec_delivery_cost_label', 'Frais de transport', 'Transport cost', 'text', 'terms', 'Libellé — frais', NULL, 83),
('sec_delivery_cost', 'Inclus dans les prix indiqués pour la zone de livraison ci-dessus.', 'Included in the quoted prices within the delivery area above.', 'textarea', 'terms', 'Frais de transport', NULL, 84),
('sec_delivery_risk_label', 'Transfert des risques', 'Transfer of risk', 'text', 'terms', 'Libellé — risques', NULL, 85),
('sec_delivery_risk', 'Les marchandises voyagent sous la responsabilité de LA PETITE COUR jusqu''à leur réception sur site. Le transfert des risques s''opère à la signature du bon de livraison par le client ou son représentant. Toute réserve doit être portée sur le bon de livraison au moment de la réception.', 'Goods travel at LA PETITE COUR''s risk until receipt on site. Risk transfers upon signature of the delivery note by the client or their representative. Any reservation must be recorded on the delivery note at the time of receipt.', 'textarea', 'terms', 'Transfert des risques', NULL, 86),

-- ---- Cover summary + validity -------------------------------------------------
('cover_summary_title', 'En bref', 'At a glance', 'text', 'cover', 'Titre — encadré de synthèse', NULL, 90),
('cover_summary_total', 'Montant mensuel estimé', 'Estimated monthly amount', 'text', 'cover', 'Libellé — montant', NULL, 91),
('meta_valid_until', 'Offre valable jusqu''au', 'Offer valid until', 'text', 'cover', 'Libellé — date de validité', 'Remplace « 30 jours à compter de l''émission ».', 92),
('meta_revision', 'Révision', 'Revision', 'text', 'cover', 'Libellé — révision', NULL, 93),

-- ---- Annexes ------------------------------------------------------------------
('sec_annex_title', 'Annexes', 'Appendices', 'text', 'terms', 'Titre — annexes', NULL, 95),
('sec_annex_intro', 'Les documents suivants sont joints à la présente offre :', 'The following documents are attached to this offer:', 'textarea', 'terms', 'Introduction — annexes', NULL, 96)

ON DUPLICATE KEY UPDATE
    -- Never clobber wording an admin has already edited.
    label_fr   = VALUES(label_fr),
    help_fr    = VALUES(help_fr),
    group_key  = VALUES(group_key),
    sort_order = VALUES(sort_order);


-- -----------------------------------------------------------------------------
-- VERIFY
-- -----------------------------------------------------------------------------
--   SELECT reference, `date`, valid_until, revision, subtotal, tva_rate,
--          tva_amount, total_amount, client_niu
--     FROM proposals ORDER BY id DESC LIMIT 10;
--
--   -- Consigned products still missing a tariff. A product is consigned when
--   -- it has a linked empty (linked_empty_id) — that is how the empties ledger
--   -- already identifies them. Article 2 threatens a replacement fee for each
--   -- of these and the offer cannot state one until they are filled in:
--   SELECT p.id, p.code, p.name, p.format, p.deposit_value, p.replacement_value
--     FROM products p
--    WHERE p.linked_empty_id IS NOT NULL
--      AND p.replacement_value = 0
--    ORDER BY p.name;
--
--   SELECT setting_key, value_fr FROM proposal_template_settings
--    WHERE group_key IN ('terms','cover','offer') ORDER BY sort_order;
