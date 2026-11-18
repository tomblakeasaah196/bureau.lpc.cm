-- =============================================================================
-- 156 · Go-live readiness: the TVA switch, the DG's own role, payroll without
--       the IT partner, two preferences that work (the owner's decisions 18,
--       19, 21, 24; 18/11/2026)
-- -----------------------------------------------------------------------------
-- 1. app_preferences  « LPC assujetti à la TVA » (tax_tva_liable): OFF until
--                     the owner confirms with the tax adviser (decision 19). On:
--                     water invoices propose 19,25 % (4431), new purchase orders
--                     hold the TVA as recoverable so reception books Dr 601 HT +
--                     Dr 4452 / Cr 401 TTC with the stock at HT, and the TVA
--                     declaration deducts what receptions booked to 4452. Off:
--                     unchanged (0 % on water, TTC as cost). Only orders saved
--                     while it is on are split; earlier ones never move.
--                     « Délai de paiement par défaut » and « Imposer la 2FA aux
--                     administrateurs » are shown again (migration 141 hid them):
--                     both now work (decision 24). The 2FA stays off.
-- 2. roles / users    « Directeur Général » (directeur_general) gets every
--                     permission « Administrateur » holds and its home page;
--                     Timothée Massomba moves to it (decision 21). « Administrateur »
--                     stays for JBS Praxis Support. He signs in again to load it.
-- 3. employees        in_payroll: 1 for everyone, 0 for JBS Praxis Support (a
--                     yearly retainer, never a salary; decision 18). Gestion du
--                     Personnel lists and calculates only in_payroll = 1.
--
-- Idempotent: guarded ALTER, INSERT IGNORE, UPDATEs that set a value.
-- =============================================================================

SET NAMES utf8mb4;

-- 1. Preferences --------------------------------------------------------------
UPDATE app_preferences
   SET pref_value = '0',
       label_fr   = 'LPC assujetti à la TVA',
       help_fr    = "Coché : l'eau est facturée avec la TVA (19,25 %) et les achats séparent la TVA récupérable (601 hors taxe, 4452 la TVA). Décoché : l'eau sans TVA, les achats au prix payé. À cocher seulement après confirmation du conseiller fiscal."
 WHERE pref_key = 'tax_tva_liable';

UPDATE app_preferences
   SET is_active = 1,
       help_fr   = "Échéance d'une facture quand le client n'a pas ses propres conditions (devis signé), et conditions proposées sur un nouveau devis."
 WHERE pref_key = 'default_payment_terms_days';

UPDATE app_preferences
   SET is_active = 1,
       help_fr   = "Coché : un Administrateur ou le Directeur Général reçoit un code par email après son mot de passe (sauf sur un appareil mémorisé). Sans envoi d'emails configuré, aucun code n'est demandé."
 WHERE pref_key = 'sec_require_2fa_admin';

-- 2. The DG's own role --------------------------------------------------------
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT dg.id, rp.permission_id
  FROM roles dg
  JOIN roles a ON a.name = 'admin'
  JOIN role_permissions rp ON rp.role_id = a.id
 WHERE dg.name = 'directeur_general';

UPDATE roles dg
  JOIN roles a ON a.name = 'admin'
   SET dg.default_landing_permission = COALESCE(dg.default_landing_permission, a.default_landing_permission),
       dg.description = 'Directeur Général'
 WHERE dg.name = 'directeur_general';

UPDATE users u
  JOIN roles a  ON a.id = u.role_id AND a.name = 'admin'
  JOIN roles dg ON dg.name = 'directeur_general'
  LEFT JOIN employees e ON e.id = u.employee_id
   SET u.role_id = dg.id
 WHERE LOWER(u.email) = 'timothee.massomba@lpc.cm'
    OR (UPPER(e.last_name) = 'MASSOMBA' AND e.first_name LIKE 'Timoth%');

-- 3. Payroll without the IT partner -------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'in_payroll');
SET @sql := IF(@has = 0,
   'ALTER TABLE employees ADD COLUMN in_payroll TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''0: never in the payroll (JBS Praxis Support, a yearly retainer; decision 18)''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE employees SET in_payroll = 0
 WHERE first_name LIKE 'JBS Praxis%' OR CONCAT(first_name, ' ', last_name) LIKE 'JBS Praxis Support%';

-- 4. Help: the TVA switch -------------------------------------------------------
INSERT IGNORE INTO help_articles (category_id, slug, required_permission, kind, page_path, sort_order, is_published, created_at, updated_at)
SELECT a.category_id, 'facturation-lpc-assujetti-tva', 'accounting.invoices.view', 'article',
       '/modules/accounting/invoices.php', 75, 1, NOW(), NOW()
  FROM help_articles a WHERE a.slug = 'demarrer-facturation';

INSERT IGNORE INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md, updated_at)
SELECT a.id, 'fr', "LPC assujetti à la TVA : ce que change l'interrupteur",
       "Paramètres → Préférences → « LPC assujetti à la TVA » : les factures, les achats et la déclaration.",
       "TVA, assujetti, 19,25 %, 4431, 4452, achats, déclaration",
       "**LPC assujetti à la TVA** (Paramètres → Préférences, Fiscalité) dit si LPC facture la TVA et la récupère sur ses achats. Il reste **décoché** tant que le conseiller fiscal ne l'a pas confirmé.

## Décoché (aujourd'hui)

- Les factures d'eau sont à **0 %** (eau exonérée).
- Un achat va au coût **tel qu'il est payé** : 601 et le stock au prix de la commande.

## Coché

- Une nouvelle facture propose **19,25 %** : la TVA va en 4431 « TVA facturée ».
- Une **nouvelle commande fournisseur** garde la TVA comme récupérable : à la réception, débit 601 le **hors taxe**, débit **4452 TVA récupérable**, crédit du fournisseur le **TTC** ; le stock entre au hors taxe.
- La **déclaration de TVA** (Déclarations Fiscales) déduit la TVA que les réceptions du mois ont passée en 4452, et retire celle des avoirs.

Les commandes déjà enregistrées ne changent pas : seules celles saisies interrupteur coché séparent la TVA. La ristourne garde sa règle (19,25 % de TVA, 2 % de précompte) dans les deux cas.", NOW()
  FROM help_articles a WHERE a.slug = 'facturation-lpc-assujetti-tva';

INSERT IGNORE INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'accounting.invoices', a.id, 75 FROM help_articles a WHERE a.slug = 'facturation-lpc-assujetti-tva';
