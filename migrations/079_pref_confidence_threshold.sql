-- =============================================================================
-- 079_pref_confidence_threshold.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — tunable auto-post threshold for the journal draft queue.
--
-- WHY:
--   Migration 077 introduced a rules-based confidence score (0..100) on
--   journal_entries and a batch-approve endpoint that only accepts entries
--   at or above a threshold. That threshold was a PHP constant
--   (JournalConfidence::THRESHOLD_AUTO_POST = 90) — changing it required a
--   code deploy, and the right value is a business decision, not an
--   engineering one. A cautious accountant may want 95 for the first month;
--   a seasoned one may drop it to 85 after seeing the reason breakdowns.
--
--   This migration promotes the number to an `app_preferences` row so it can
--   be tuned inline from modules/accounting/journal_entry.php (the small gear
--   icon on the batch action bar) by anyone with `admin.prefs.edit`, and
--   audited like every other preference change.
--
-- WHAT THIS DOES:
--   1. Inserts one `app_preferences` row keyed `accounting_confidence_threshold`
--      · kind      = 'int'
--      · min_value = 50     (below this the whole batch-approve concept
--                            stops meaning anything — anything scored under
--                            50 % is by definition unusual)
--      · max_value = 100    (100 = only perfect matches auto-post)
--      · group_key = 'operations' (shows up next to notify_low_stock_threshold
--                                  and ops_allow_negative_stock in Settings →
--                                  Préférences)
--
--   On re-run the ON DUPLICATE KEY UPDATE refreshes the metadata (label,
--   help text, bounds, sort order) but NEVER the stored value — same rule
--   as migration 034 for every other pref row.
--
-- Depends on: 034 (app_preferences table), 077 (the scorer).
-- Idempotent: safe to run more than once.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

INSERT INTO app_preferences
    (pref_key, pref_value, kind, options_json, group_key, label_fr, label_en, help_fr, unit, min_value, max_value, sort_order, is_locked)
VALUES
(
    'accounting_confidence_threshold',
    '90',
    'int',
    NULL,
    'operations',
    "Seuil d'auto-revue (confiance)",
    'Auto-review confidence threshold',
    "Score de confiance minimum (0-100) pour qu'une écriture en brouillard puisse être postée via le bouton « Poster la sélection ». Une écriture en dessous du seuil reste postable individuellement, mais jamais en lot.",
    '%',
    50,
    100,
    50,
    0
)
ON DUPLICATE KEY UPDATE
    -- Refresh metadata but keep the value the admin has set.
    kind         = VALUES(kind),
    options_json = VALUES(options_json),
    group_key    = VALUES(group_key),
    label_fr     = VALUES(label_fr),
    label_en     = VALUES(label_en),
    help_fr      = VALUES(help_fr),
    unit         = VALUES(unit),
    min_value    = VALUES(min_value),
    max_value    = VALUES(max_value),
    sort_order   = VALUES(sort_order);

COMMIT;

-- =============================================================================
-- Verification (run manually after this file):
--
--   SELECT pref_key, pref_value, kind, min_value, max_value, group_key
--     FROM app_preferences
--    WHERE pref_key = 'accounting_confidence_threshold';
--
--   -- expect one row: 90 / int / 50 / 100 / operations
--
-- Then open modules/accounting/journal_entry.php. The gear icon on the batch
-- action bar (only visible to holders of admin.prefs.edit) opens a modal
-- letting you change the number without leaving the page.
-- =============================================================================
