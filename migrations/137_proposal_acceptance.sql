-- =============================================================================
-- 137_proposal_acceptance.sql
-- -----------------------------------------------------------------------------
-- Proposition commerciale: what a signed devis leaves behind.
--
-- WHY (found while filming the training videos, docs/VIDEO_QA_FINDINGS.md §4,
-- 18/11/2026)
--   1. A client's « Bon pour accord » moved the proposal to 'accepted' and
--      nothing else (4.1): the prices it signed never became its tariff, Net 15
--      and the 80 % empties minimum lived only on the PDF, the client stayed a
--      prospect, and no order ever said which devis it came from
--      (sales_orders.proposal_id was never written). The proposal knew its
--      client only by a name snapshot.
--        · proposals.client_id, proposals.created_by;
--        · clients.payment_terms_days (Facturation dates the invoice from it),
--          clients.empties_return_min_pct (Gestion des Vides reads it),
--          clients.terms_proposal_id (the devis the terms come from);
--        · client_price_history.source 'proposal' (a signed price).
--      The code is includes/functions/proposal_terms.php.
--   2. Every client on live has TWO 411 accounts (4.6): one made when the
--      client was created, a second by « Convertir ». The client points at the
--      second; the first carries no entry. The unused duplicate is deactivated
--      (never deleted), only when it carries no journal line and no client,
--      deleted or not, points at it.
--   3. Backfill: earlier proposals get their client_id by name; the two
--      accepted ones (SMART LOGISTICS, BOULANGERIE SAKER) carry their payment
--      terms and empties policy onto their client. Their prices are NOT copied
--      into the tariffs: orders may have moved them since, and the tariff
--      screens are where that history lives.
--
-- Idempotent: columns guarded by information_schema; the backfills only fill
-- what is empty; the enum change re-states the full list.
-- =============================================================================

SET NAMES utf8mb4;

-- 1. The columns ---------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'proposals' AND column_name = 'client_id');
SET @sql := IF(@has = 0,
   'ALTER TABLE proposals ADD COLUMN client_id INT NULL DEFAULT NULL COMMENT ''The client the devis is for (client_name stays the printed snapshot)'' AFTER reference, ADD KEY idx_proposals_client (client_id)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'proposals' AND column_name = 'created_by');
SET @sql := IF(@has = 0,
   'ALTER TABLE proposals ADD COLUMN created_by INT NULL DEFAULT NULL COMMENT ''The commercial who wrote it (users.id)'' AFTER sales_rep_name',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'clients' AND column_name = 'payment_terms_days');
SET @sql := IF(@has = 0,
   'ALTER TABLE clients ADD COLUMN payment_terms_days SMALLINT NULL DEFAULT NULL COMMENT ''Days to pay an invoice, from the signed devis (0 = at delivery; NULL = 30, the default)'' AFTER credit_limit',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'clients' AND column_name = 'empties_return_min_pct');
SET @sql := IF(@has = 0,
   'ALTER TABLE clients ADD COLUMN empties_return_min_pct TINYINT UNSIGNED NULL DEFAULT NULL COMMENT ''Minimum share of delivered bottles the client returns, from the signed devis'' AFTER payment_terms_days',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'clients' AND column_name = 'terms_proposal_id');
SET @sql := IF(@has = 0,
   'ALTER TABLE clients ADD COLUMN terms_proposal_id INT NULL DEFAULT NULL COMMENT ''The signed devis the terms and tariff come from; new orders link to it'' AFTER empties_return_min_pct',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

ALTER TABLE client_price_history
    MODIFY COLUMN source ENUM('order', 'client_file', 'import', 'proposal') NOT NULL DEFAULT 'order';

-- 2. Earlier proposals: their client by name ------------------------------------
UPDATE proposals p
  JOIN (SELECT name, MIN(id) AS id FROM clients WHERE deleted_at IS NULL GROUP BY name) c
    ON c.name = p.client_name
   SET p.client_id = c.id
 WHERE p.client_id IS NULL;

-- 3. The accepted ones carry their terms (latest accepted devis per client) ----
UPDATE clients c
  JOIN (SELECT client_id, MAX(id) AS id FROM proposals
         WHERE status = 'accepted' AND client_id IS NOT NULL GROUP BY client_id) last
    ON last.client_id = c.id
  JOIN proposals p ON p.id = last.id
   SET c.terms_proposal_id      = p.id,
       c.payment_terms_days     = CASE
           WHEN p.payment_terms REGEXP '[0-9]+' THEN CAST(REGEXP_SUBSTR(p.payment_terms, '[0-9]+') AS UNSIGNED)
           WHEN LOWER(p.payment_terms) LIKE '%livraison%' THEN 0
           ELSE NULL END,
       c.empties_return_min_pct = CASE
           WHEN p.empties_policy REGEXP '[0-9]+ ?%'
                THEN CAST(REPLACE(REPLACE(REGEXP_SUBSTR(p.empties_policy, '[0-9]+ ?%'), '%', ''), ' ', '') AS UNSIGNED)
           ELSE NULL END
 WHERE c.terms_proposal_id IS NULL;

-- 4. The duplicate 411 « Convertir » left behind --------------------------------
UPDATE chart_of_accounts dup
  JOIN chart_of_accounts used ON used.name = dup.name AND used.id <> dup.id AND used.code LIKE '411%'
  JOIN clients k ON k.account_id = used.id AND k.deleted_at IS NULL
   SET dup.is_active = 0
 WHERE dup.code LIKE '411%'
   AND dup.is_active = 1
   AND NOT EXISTS (SELECT 1 FROM journal_lines l WHERE l.account_id = dup.id)
   AND NOT EXISTS (SELECT 1 FROM clients k2 WHERE k2.account_id = dup.id);
