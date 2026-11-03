-- =============================================================================
-- 355_data_wipe.sql
-- -----------------------------------------------------------------------------
-- The data wipe tool (2 Nov 2026): clears the client's test data module by
-- module before real data starts, for one account only (WIPE_ADMIN_EMAIL in
-- the server's .env). includes/classes/DataWipe.php; docs/DATA_WIPE.md.
--
--   · data_wipes      — the tool's own log, never wiped: who, when, what (the
--                       tables picked and the rows each lost), why, the safety
--                       copy (a private file kept 30 days) and the MD notice.
--   · data_wipe_codes — one-time codes emailed before a wipe: hashed, 10
--                       minutes, 5 tries, single use, tied to the tables picked.
--   · data_wipe_lock  — one row; a wipe locks it FOR UPDATE, so two wipes never
--                       run at once.
--
-- No foreign keys (users.id predates the migrations folder, as in 123).
-- Idempotent: CREATE TABLE IF NOT EXISTS and INSERT IGNORE only.
-- =============================================================================

CREATE TABLE IF NOT EXISTS data_wipes (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id          INT          NOT NULL,
    user_name        VARCHAR(160) NOT NULL,
    picked           TEXT         NOT NULL,              -- JSON: the tables ticked
    counts           MEDIUMTEXT   NULL,                  -- JSON: table => rows deleted (and rows unlinked)
    total            INT UNSIGNED NOT NULL DEFAULT 0,
    reason           VARCHAR(1000) NOT NULL,
    balances         MEDIUMTEXT   NULL,                  -- JSON: what was recalculated
    copy_file        VARCHAR(255) NULL,                  -- name inside the private copies folder; NULL once deleted
    copy_size        BIGINT UNSIGNED NULL,
    copy_sha256      CHAR(64)     NULL,
    copy_expires_at  DATETIME     NULL,
    copy_deleted_at  DATETIME     NULL,
    md_user_id       INT          NULL,
    md_notified_at   DATETIME     NULL,
    md_emailed       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_data_wipes_copy (copy_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_wipe_codes (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id      INT          NOT NULL,
    code_hash    VARCHAR(255) NOT NULL,                 -- password_hash() of the 6 digits
    picked_hash  CHAR(64)     NOT NULL,                 -- SHA-256 of the tables picked when it was sent
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at   DATETIME     NOT NULL,
    consumed_at  DATETIME     NULL,
    ip           VARCHAR(45)  NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_data_wipe_codes_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_wipe_lock (
    id  TINYINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO data_wipe_lock (id) VALUES (1);
