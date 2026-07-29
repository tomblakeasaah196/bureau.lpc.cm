-- =============================================================================
-- 042_pricing_fallback.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 9 · make the price ladder explicit and configurable.
--
-- WHY:
--   There are three tiers of price in this system and only two of them were
--   ever written down:
--
--     1. client_prices.custom_price   the negotiated tarif for one client and
--                                     one product. Editable in Master Data Hub
--                                     > Prix & Tarifs.
--     2. products.base_price          the catalogue price. Editable in the
--                                     Produits tab, which is where a sales
--                                     manager looking for "the default price"
--                                     would never think to go.
--     3. …nothing.                    If base_price is 0 — and the audit
--                                     already records rows where cump is 0.00
--                                     and money precision drifted (AUDIT_REPORT
--                                     H-DB-12, M-DB-12) — the line is quoted at
--                                     ZERO. No warning, no floor. A devis for
--                                     free water is a document the customer is
--                                     entitled to hold us to.
--
--   Tier 3 is what this migration adds: a last-resort floor, plus a switch for
--   what should happen when a price cannot be resolved at all.
--
--   These are app_preferences rows rather than constants because the whole
--   point is that a manager can change them without a deployment — which is
--   also why Prefs::setMany only UPDATEs rows that already exist. A preference
--   with no row here is silently unsettable, so the rows must be seeded.
--
-- SAFE TO RE-RUN. INSERT … NOT EXISTS on pref_key.
--
-- ROLLBACK:
--   DELETE FROM app_preferences WHERE pref_key IN
--     ('price_fallback_amount', 'price_fallback_mode');
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- price_fallback_amount
-- -----------------------------------------------------------------------------
-- The floor, in FCFA. Used only when tiers 1 and 2 both resolve to nothing
-- usable (no client tarif, and base_price is 0 or NULL).
--
-- Seeded at 0, deliberately. A non-zero default would invent a price for every
-- mispriced product in the catalogue and hide the data problem behind a plausible
-- number; 0 keeps the existing behaviour until someone makes a decision, and the
-- UI flags every product that would fall through to it.
-- -----------------------------------------------------------------------------
INSERT INTO app_preferences
    (pref_key, pref_value, kind, group_key, label_fr, label_en, help_fr, unit,
     min_value, max_value, sort_order, is_locked, is_active)
SELECT 'price_fallback_amount', '0', 'decimal', 'commercial',
       'Prix de repli (défaut)',
       'Fallback price (default)',
       'Utilisé uniquement si le client n''a pas de tarif négocié ET que le prix de base du produit est nul.',
       'FCFA', 0, 100000000, 25, 0, 1
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM app_preferences WHERE pref_key = 'price_fallback_amount');

-- -----------------------------------------------------------------------------
-- price_fallback_mode
-- -----------------------------------------------------------------------------
-- What the line editors do when a price resolves to 0 and no floor is set.
--
--   'zero'  — keep today's behaviour: fill 0 and say nothing.
--   'warn'  — fill 0 and flag the line. THE DEFAULT: it changes no number, so
--             it cannot break an existing workflow, but it stops a zero-priced
--             line leaving the building unnoticed.
--   'block' — refuse to save the document until a price is entered. Correct for
--             an organisation that has decided a zero line is always an error,
--             but it is a policy choice, so it is opt-in.
-- -----------------------------------------------------------------------------
INSERT INTO app_preferences
    (pref_key, pref_value, kind, options_json, group_key, label_fr, label_en, help_fr,
     sort_order, is_locked, is_active)
SELECT 'price_fallback_mode', 'warn', 'select',
       '[{"value":"zero","label":"Autoriser (silencieux)"},{"value":"warn","label":"Avertir"},{"value":"block","label":"Bloquer l''enregistrement"}]',
       'commercial',
       'Ligne à prix nul',
       'Zero-priced line',
       'Comportement lorsqu''aucun prix ne peut être résolu pour une ligne.',
       26, 0, 1
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM app_preferences WHERE pref_key = 'price_fallback_mode');

COMMIT;

-- =============================================================================
-- VERIFICATION
-- -----------------------------------------------------------------------------
--   SELECT pref_key, pref_value, kind, group_key, is_locked
--     FROM app_preferences
--    WHERE pref_key LIKE 'price_fallback%';
--   -- expect 2 rows, is_locked = 0 on both (Prefs::setMany skips locked rows)
--
--   -- Products that would fall through to the floor today:
--   SELECT id, code, name, base_price
--     FROM products
--    WHERE is_active = 1 AND (base_price IS NULL OR base_price = 0);
-- =============================================================================
