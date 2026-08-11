-- =============================================================================
-- migrations/101_help_chat_log_schema.sql
-- -----------------------------------------------------------------------------
-- Sprint 7H · Help Centre AI Assistant — question log.
--
-- WHAT THIS CREATES
--   help_chat_log   one row per question asked of the floating assistant
--                   (api/v1/help_chat_controller.php?action=ask).
--
-- WHY THIS EXISTS: the assistant can only answer from articles that already
-- exist. Every question it could NOT ground in a published, RBAC-visible
-- article is, by construction, a gap in the help centre — this table is what
-- turns that into something reviewable instead of invisible. had_good_match
-- is the field to filter on for that: `WHERE had_good_match = 0 ORDER BY
-- created_at DESC` is the to-do list of articles worth writing next.
--
-- matched_article_slugs and answer_text both reflect what the VISITOR
-- actually received — i.e. already filtered through lpc_help_visible() before
-- the DeepSeek call happened. Nothing in this table can leak a restricted
-- article's existence beyond what the visitor's own RBAC already allowed
-- them to see in the same request.
--
-- No FK on user_id (mirrors audit_logs) — a log row should survive regardless
-- of what happens to the account later.
--
-- Idempotent: safe to re-run.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

CREATE TABLE IF NOT EXISTS help_chat_log (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id                INT UNSIGNED DEFAULT NULL,
    lang                   CHAR(2)      NOT NULL DEFAULT 'fr',
    -- The page the widget was opened from (window.location.pathname at ask
    -- time) — context for reviewing a gap: "asked from /modules/hr/payroll.php"
    -- says more than the question text alone sometimes does.
    page_path              VARCHAR(190) NOT NULL DEFAULT '',
    question               TEXT         NOT NULL,
    -- Comma-separated slugs of the articles actually cited in the answer —
    -- empty when had_good_match = 0.
    matched_article_slugs  VARCHAR(600) NOT NULL DEFAULT '',
    had_good_match         TINYINT(1)   NOT NULL DEFAULT 0,
    -- Did this question consume one of the user's daily quota slots
    -- (ai_role_limits, migration 104)? Only a SUCCESSFUL DeepSeek answer is
    -- billable — a "nothing found" (no retrieval match, no API call) or a
    -- provider failure never costs the user part of their daily allowance.
    billable                TINYINT(1)   NOT NULL DEFAULT 0,
    answer_text            MEDIUMTEXT   NULL,
    -- Set when the DeepSeek call itself failed (provider error, timeout) —
    -- distinct from had_good_match=0, which means "retrieval found nothing",
    -- not "the provider errored". Empty string = no error.
    error_message          VARCHAR(300) NOT NULL DEFAULT '',
    created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_help_chat_log_user  (user_id, created_at),
    KEY idx_help_chat_log_gaps  (had_good_match, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- =============================================================================
-- Verification:
--
--   SHOW TABLES LIKE 'help\_chat\_log';
--
-- Content-gap report (the reason this table exists):
--   SELECT question, page_path, COUNT(*) AS asked, MAX(created_at) AS last_asked
--     FROM help_chat_log
--    WHERE had_good_match = 0
--    GROUP BY question, page_path
--    ORDER BY asked DESC, last_asked DESC
--    LIMIT 50;
--
-- No admin UI reads this table yet in PR2 — it is deliberately just a log for
-- now. A "Content gaps" tab on the help article editor is the natural
-- follow-up once there's enough traffic for the report above to be useful.
-- =============================================================================
