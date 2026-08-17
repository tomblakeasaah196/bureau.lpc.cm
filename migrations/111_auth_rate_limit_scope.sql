-- =============================================================================
-- 111_auth_rate_limit_scope.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — split the login rate limiter into per-account + per-IP.
--
-- WHY:
--   api/v1/auth.php and api/v1/session_relogin.php used one rate-limit
--   bucket, keyed by IP address only, for the whole login flow. Everyone in
--   an office sits behind one NAT'd public IP, so once that IP's attempt
--   count crossed `sec_max_login_attempts` (from ANY mix of employees —
--   successes and failures both count), the 429 applied to the IP, which
--   meant the entire office was locked out of login for `sec_lockout_minutes`
--   — not just whoever mistyped or was being guessed against.
--
--   The code change (api/v1/auth.php, api/v1/session_relogin.php) now checks
--   two buckets:
--     · 'auth_acct' — keyed by a hash of the normalised email. This is what
--       `sec_max_login_attempts` / `sec_lockout_minutes` govern below, same
--       as before. A lockout here only affects the one account being
--       guessed against.
--     · 'auth_ip'   — keyed by IP, much more generous, a backstop against a
--       script spraying many different addresses from one connection.
--
-- WHAT THIS DOES:
--   1. Updates `sec_max_login_attempts`'s help text so the Settings page
--      correctly describes it as a per-account limit (value is untouched —
--      the ON DUPLICATE KEY UPDATE rule from migration 034 never touches
--      pref_value on a re-run).
--   2. Inserts `sec_max_login_attempts_ip`, the new per-IP backstop, into
--      Paramètres -> Préférences -> Sécurité, seeded at 50/window — loose
--      enough that a busy office never trips it on ordinary traffic.
--
-- NOTE ON help_fr: the column is VARCHAR(255) (see migration 034). Both
-- strings below are kept well inside that; an over-long value fails the whole
-- migration with SQLSTATE 22001 rather than truncating.
--
-- Depends on: 034 (app_preferences table).
-- Idempotent: safe to run more than once.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

INSERT INTO app_preferences
    (pref_key, pref_value, kind, options_json, group_key, label_fr, label_en, help_fr, unit, min_value, max_value, sort_order, is_locked)
VALUES
(
    'sec_max_login_attempts',
    '10',
    'int',
    NULL,
    'security',
    'Tentatives de connexion max (par compte)',
    'Max login attempts (per account)',
    "Par compte (adresse email tentée), et non par IP. Un dépassement ne verrouille que ce compte, jamais les autres utilisateurs du même réseau. Fenêtre = la durée de verrouillage ci-dessous.",
    'essais',
    3,
    50,
    20,
    0
),
(
    'sec_max_login_attempts_ip',
    '50',
    'int',
    NULL,
    'security',
    'Tentatives de connexion max (par IP)',
    'Max login attempts (per IP)',
    "Garde-fou par adresse IP. Volontairement plus large que la limite par compte : tout un bureau partage une seule IP publique. Ne doit se déclencher que sur une attaque, jamais sur l'usage normal.",
    'essais',
    10,
    500,
    21,
    0
)
ON DUPLICATE KEY UPDATE
    -- Refresh metadata (labels, help, bounds) but NEVER the stored value —
    -- same rule as every other app_preferences seed since migration 034.
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
--    WHERE pref_key IN ('sec_max_login_attempts', 'sec_max_login_attempts_ip');
--
--   -- expect two rows in group 'security', values untouched if they already
--   -- existed (10 for the per-account one on a fresh install).
--
-- Then open Paramètres -> Préférences -> Sécurité: "Tentatives de connexion
-- max (par compte)" and "Tentatives de connexion max (par IP)" should both
-- be editable there — no code deploy needed to retune either number again.
-- =============================================================================
