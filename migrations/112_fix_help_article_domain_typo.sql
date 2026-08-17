-- =============================================================================
-- 112_fix_help_article_domain_typo.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — fix a stray example domain in the English "Signing in"
-- help article.
--
-- WHY THIS EXISTS AS ITS OWN MIGRATION:
--   Migration 106 seeded the Help Centre article bodies. Its English "Signing
--   in" body has an example address using the wrong placeholder domain
--   (`lapetitecour.cm` instead of the real `lpc.cm`) — the French version of
--   the same article already had it right.
--
--   The natural fix would be editing 106's body_md and re-running it, but 106
--   already applied here (schema_migrations has its checksum) and
--   scripts/migrate.php refuses to re-apply a migration whose file content
--   changed — by design, so nobody silently rewrites history. So: leave 106
--   untouched and correct the live row with a small, targeted UPDATE instead,
--   per the runner's own guidance ("Fix the migration (create a new one)").
--
-- WHAT THIS DOES:
--   Replaces the substring in help_article_bodies.body_md wherever it still
--   contains the wrong domain. REPLACE() is a no-op on a row that no longer
--   contains the string, so this is naturally idempotent — safe to re-run,
--   and safe even if 106 is ever re-seeded with the corrected text.
--
-- Depends on: 106_help_login_by_email_articles.sql (creates the rows this
-- touches; a no-op UPDATE if that migration hasn't run yet).
-- Idempotent: safe to re-run.
-- =============================================================================

START TRANSACTION;

UPDATE help_article_bodies
   SET body_md = REPLACE(body_md, 'lapetitecour.cm', 'lpc.cm')
 WHERE body_md LIKE '%lapetitecour.cm%';

COMMIT;

-- =============================================================================
-- Verification (run manually after this file):
--
--   SELECT article_id, lang FROM help_article_bodies WHERE body_md LIKE '%lapetitecour.cm%';
--   -- expect 0 rows
-- =============================================================================
