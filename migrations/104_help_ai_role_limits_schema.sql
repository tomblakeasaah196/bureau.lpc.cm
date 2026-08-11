-- =============================================================================
-- migrations/104_help_ai_role_limits_schema.sql
-- -----------------------------------------------------------------------------
-- Sprint 7H · Help Centre AI Assistant — per-role daily usage limits.
--
-- WHAT THIS CREATES
--   ai_role_limits   one row per role, capping how many BILLABLE questions
--                    (help_chat_log.billable = 1 — a successful DeepSeek
--                    answer, not a "nothing found") that role's members may
--                    ask the assistant per calendar day.
--
-- daily_limit = NULL means unlimited. Every existing role is seeded at 50,
-- except admin, seeded unlimited — the defaults asked for. Both are editable
-- afterwards from the same gear icon that holds the DeepSeek/embedding
-- settings (migration 098) — a business-policy number, not a secret, so it
-- lives in a plain column, no Crypto involved.
--
-- role_id IS THE PRIMARY KEY, not a surrogate id — a role has at most one
-- limit, so there is nothing a surrogate key would let you express that this
-- doesn't already. ON DELETE CASCADE: deleting a role deletes its limit row;
-- nothing else references it.
--
-- NEW ROLES CREATED AFTER THIS MIGRATION get no row here until an admin sets
-- one. includes/classes/AiRoleLimits.php::dailyLimitFor() returns 50 in that
-- case (the same safe default every existing role got) rather than treating
-- "no row" as unlimited — a newly created role should never silently inherit
-- unlimited usage just because nobody has visited the settings screen yet.
--
-- Idempotent: the seed uses ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)
-- — a deliberate no-op on re-run (see migration 032's role_permissions grant
-- for the same idiom) so re-running this migration can never stomp an admin's
-- already-customised limit back to the default.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- role_id is plain signed INT(11), NOT INT UNSIGNED, to exactly match
-- roles.id — this app's pre-migration legacy tables (roles, users, ...)
-- predate the INT UNSIGNED convention later migrations use for new tables.
-- MySQL requires an FK's column and its referenced column to match type AND
-- signedness exactly; mismatching this is exactly what errno 150 ("Foreign
-- key constraint is incorrectly formed") means. Migration 029's header has
-- the same lesson learned the same way, against audit_logs.user_id.
CREATE TABLE IF NOT EXISTS ai_role_limits (
    role_id      INT(11) NOT NULL,
    -- NULL = unlimited.
    daily_limit  INT UNSIGNED NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by   INT(11) DEFAULT NULL,
    PRIMARY KEY (role_id),
    CONSTRAINT fk_ai_role_limits_role FOREIGN KEY (role_id)
        REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_role_limits (role_id, daily_limit)
SELECT id, CASE WHEN name = 'admin' THEN NULL ELSE 50 END
  FROM roles
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

COMMIT;

-- =============================================================================
-- Verification:
--   SELECT r.name, l.daily_limit FROM roles r
--     JOIN ai_role_limits l ON l.role_id = r.id
--    ORDER BY r.id;
--   -- expect: admin -> NULL, every other existing role -> 50
-- =============================================================================
