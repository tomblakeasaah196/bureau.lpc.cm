-- =============================================================================
-- 310_mail_read_image_trust.sql
-- -----------------------------------------------------------------------------
-- Courrier (Mail), PR 2 (reading): the senders whose remote pictures a person
-- always loads, the "Toujours pour cet expéditeur" choice in the reader
-- (docs/MAIL.md, section 2). Remote pictures are held back by default because
-- loading them tells the sender the mail was opened, and when.
--
-- Per person, never shared: one row per (person, sender address in lower
-- case). user_id is users.id without a foreign key (users predates the
-- migrations folder; see 123). Removing the row is enough to stop trusting.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS.
-- =============================================================================

CREATE TABLE IF NOT EXISTS mail_image_trust (
    user_id     INT NOT NULL,
    sender      VARCHAR(190) NOT NULL,                -- the sender address, lower case
    created_at  DATETIME NULL,
    PRIMARY KEY (user_id, sender)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- VERIFY
-- -----------------------------------------------------------------------------
--   SHOW CREATE TABLE mail_image_trust;
