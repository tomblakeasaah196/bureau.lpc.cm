-- =============================================================================
-- 300_mail_foundation.sql
-- -----------------------------------------------------------------------------
-- Courrier (Mail), PR 1: every table the five Mail PRs use (docs/MAIL.md,
-- section 8), so PRs 2 to 5 build in parallel on one fixed schema. A PR that
-- needs more adds its own migration in its reserved number range (docs/MAIL.md,
-- section 5): 300-309 PR 1, 310-319 PR 2, 320-329 PR 3, 330-339 PR 4,
-- 340-349 PR 5. scripts/tests/migration_lint.test.php knows this block.
--
-- Mail stays on the mail server (mail.lpc.cm). These tables hold a copy of the
-- last 12 months (headers and text, for fast lists and search), the people who
-- share a mailbox, and the team's own work on conversations. Removing a
-- mailbox deletes its copy here (foreign keys cascade), never the mail on the
-- server. Attachments are never copied: they are read from the server.
--
-- Dates are DATETIME in the app's own time (Africa/Douala, like the rest of the
-- database); the code always writes them from PHP. Person columns are INT
-- users.id without foreign keys (users predates the migrations folder; see 123).
--
-- Reserved words avoided on purpose: refs (not "references"), to_list, cc_list,
-- bcc_list, reply_to_list (not "to"), setting_key (not "key").
--
-- Idempotent: CREATE TABLE IF NOT EXISTS, INSERT ... ON DUPLICATE KEY / IGNORE.
-- =============================================================================

-- A connected mailbox: a person's own (personal) or one the team shares.
CREATE TABLE IF NOT EXISTS mail_accounts (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind              ENUM('personal','shared') NOT NULL,
    email             VARCHAR(190) NOT NULL,
    display_name      VARCHAR(120) NOT NULL,          -- the name in the From line
    owner_user_id     INT NULL,                       -- personal: its person; shared: who added it
    username          VARCHAR(190) NOT NULL,          -- IMAP/SMTP login (the full address on cPanel)
    secret            TEXT NOT NULL,                  -- the password, Crypto::encrypt (APP_ENCRYPTION_KEY)
    imap_host         VARCHAR(190) NOT NULL,
    imap_port         SMALLINT UNSIGNED NOT NULL,
    imap_encryption   ENUM('ssl','starttls','none') NOT NULL DEFAULT 'ssl',
    smtp_host         VARCHAR(190) NOT NULL,
    smtp_port         SMALLINT UNSIGNED NOT NULL,
    smtp_encryption   ENUM('ssl','starttls','none') NOT NULL DEFAULT 'ssl',
    status            ENUM('active','needs_reconnect','paused') NOT NULL DEFAULT 'active',
    last_error        VARCHAR(500) NULL,
    capabilities      JSON NULL,
    folders_synced_at DATETIME NULL,
    last_synced_at    DATETIME NULL,
    created_at        DATETIME NULL,
    updated_at        DATETIME NULL,
    UNIQUE KEY uq_mail_accounts_email (email),
    KEY idx_mail_accounts_owner (kind, owner_user_id),
    KEY idx_mail_accounts_sync (status, last_synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- People on a shared mailbox: Lecture (read), Lecture et envoi (send), Gestionnaire (manage).
CREATE TABLE IF NOT EXISTS mail_account_members (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id  INT UNSIGNED NOT NULL,
    user_id     INT NOT NULL,
    level       ENUM('read','send','manage') NOT NULL,
    added_by    INT NULL,
    created_at  DATETIME NULL,
    updated_at  DATETIME NULL,
    UNIQUE KEY uq_mail_members (account_id, user_id),
    KEY idx_mail_members_user (user_id),
    CONSTRAINT fk_mail_members_account FOREIGN KEY (account_id) REFERENCES mail_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Folders on the server. path = the server's own name (modified UTF-7, use it in IMAP); name = readable.
CREATE TABLE IF NOT EXISTS mail_folders (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id        INT UNSIGNED NOT NULL,
    path              VARCHAR(255) NOT NULL,
    name              VARCHAR(255) NOT NULL,
    delimiter         VARCHAR(4) NULL,
    role              ENUM('inbox','sent','drafts','archive','spam','trash','other') NOT NULL DEFAULT 'other',
    attributes        JSON NULL,
    selectable        TINYINT(1) NOT NULL DEFAULT 1,
    uidvalidity       BIGINT UNSIGNED NULL,
    uidnext           BIGINT UNSIGNED NULL,
    highestmodseq     BIGINT UNSIGNED NULL,
    unread_count      INT UNSIGNED NOT NULL DEFAULT 0,   -- in bureau's copy (last 12 months)
    total_count       INT UNSIGNED NOT NULL DEFAULT 0,   -- in bureau's copy
    server_unseen     INT UNSIGNED NULL,                 -- on the server, all dates
    server_total      INT UNSIGNED NULL,
    initial_synced_at DATETIME NULL,                     -- first full copy finished
    synced_at         DATETIME NULL,
    created_at        DATETIME NULL,
    updated_at        DATETIME NULL,
    UNIQUE KEY uq_mail_folders_path (account_id, path),
    KEY idx_mail_folders_role (account_id, role),
    CONSTRAINT fk_mail_folders_account FOREIGN KEY (account_id) REFERENCES mail_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversations. Teamwork columns (PR 4) and the AI summary (PR 5) live here too.
CREATE TABLE IF NOT EXISTS mail_threads (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id       INT UNSIGNED NOT NULL,
    subject          VARCHAR(500) NULL,
    subject_key      VARCHAR(191) NULL,              -- lower case, without Re:/Fwd:/TR: (Sync\Threader)
    snippet          VARCHAR(300) NULL,
    participants     JSON NULL,                      -- [{name, email}], first sender first
    message_count    INT UNSIGNED NOT NULL DEFAULT 0,
    unread_count     INT UNSIGNED NOT NULL DEFAULT 0,
    has_attachments  TINYINT(1) NOT NULL DEFAULT 0,
    first_message_at DATETIME NULL,
    last_message_at  DATETIME NULL,
    last_inbound_at  DATETIME NULL,                  -- last message not sent from this mailbox
    -- Teamwork on shared mailboxes (PR 4)
    status           ENUM('open','done') NOT NULL DEFAULT 'open',
    assigned_to      INT NULL,
    assigned_by      INT NULL,
    assigned_at      DATETIME NULL,
    done_by          INT NULL,
    done_at          DATETIME NULL,
    -- Praxis AI summary (PR 5), kept until a newer message arrives
    ai_summary       JSON NULL,
    ai_summary_at    DATETIME NULL,
    created_at       DATETIME NULL,
    updated_at       DATETIME NULL,
    KEY idx_mail_threads_last (account_id, last_message_at),
    KEY idx_mail_threads_subject (account_id, subject_key),
    KEY idx_mail_threads_assigned (assigned_to),
    CONSTRAINT fk_mail_threads_account FOREIGN KEY (account_id) REFERENCES mail_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One message in one folder (the same message in two folders is two rows sharing message_id_hash).
CREATE TABLE IF NOT EXISTS mail_messages (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id       INT UNSIGNED NOT NULL,
    folder_id        INT UNSIGNED NOT NULL,
    thread_id        INT UNSIGNED NULL,
    uid              BIGINT UNSIGNED NOT NULL,
    message_id       VARCHAR(255) NULL,              -- without angle brackets
    message_id_hash  CHAR(40) NULL,                  -- sha1(lower(message_id)): Messages::hashId()
    in_reply_to      VARCHAR(255) NULL,
    refs             TEXT NULL,                      -- References, space separated, without brackets
    subject          VARCHAR(500) NULL,
    from_name        VARCHAR(255) NULL,
    from_email       VARCHAR(255) NULL,
    to_list          JSON NULL,                      -- [{name, email}]
    cc_list          JSON NULL,
    bcc_list         JSON NULL,
    reply_to_list    JSON NULL,
    to_text          TEXT NULL,                      -- names and addresses of To and Cc, for search
    sent_at          DATETIME NULL,                  -- the Date header
    received_at      DATETIME NULL,                  -- arrival on the server (INTERNALDATE); sort by this
    size             INT UNSIGNED NOT NULL DEFAULT 0,
    is_seen          TINYINT(1) NOT NULL DEFAULT 0,
    is_flagged       TINYINT(1) NOT NULL DEFAULT 0,
    is_answered      TINYINT(1) NOT NULL DEFAULT 0,
    is_draft         TINYINT(1) NOT NULL DEFAULT 0,
    has_attachments  TINYINT(1) NOT NULL DEFAULT 0,  -- not counting inline pictures
    is_outbound      TINYINT(1) NOT NULL DEFAULT 0,  -- sent from this mailbox
    snippet          VARCHAR(300) NULL,
    body_text        MEDIUMTEXT NULL,
    body_html        MEDIUMTEXT NULL,                -- as sent, NOT sanitised: never print it without PR 2's sanitiser
    -- pending: not read yet; cached: in body_text/body_html; remote: too large, read when opened; error
    body_state       ENUM('pending','cached','remote','error') NOT NULL DEFAULT 'pending',
    parts            JSON NULL,                      -- where the text and HTML parts are on the server
    list_unsubscribe VARCHAR(500) NULL,
    seen_by          INT NULL,                       -- first person to open it in bureau
    seen_at          DATETIME NULL,
    sent_by          INT NULL,                       -- the person who sent it from bureau (PR 3)
    created_at       DATETIME NULL,
    updated_at       DATETIME NULL,
    UNIQUE KEY uq_mail_messages_uid (folder_id, uid),
    KEY idx_mail_messages_thread (thread_id, received_at),
    KEY idx_mail_messages_hash (account_id, message_id_hash),
    KEY idx_mail_messages_reply (account_id, in_reply_to(191)),
    KEY idx_mail_messages_folder (folder_id, received_at),
    FULLTEXT KEY ft_mail_messages_search (subject, from_name, from_email, to_text, body_text),
    CONSTRAINT fk_mail_messages_account FOREIGN KEY (account_id) REFERENCES mail_accounts (id) ON DELETE CASCADE,
    CONSTRAINT fk_mail_messages_folder  FOREIGN KEY (folder_id)  REFERENCES mail_folders (id)  ON DELETE CASCADE,
    CONSTRAINT fk_mail_messages_thread  FOREIGN KEY (thread_id)  REFERENCES mail_threads (id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attachment details only; the file stays on the server and is read when opened.
CREATE TABLE IF NOT EXISTS mail_attachments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    message_id  INT UNSIGNED NOT NULL,
    section     VARCHAR(32)  NOT NULL,               -- IMAP body part, e.g. "2" or "1.2"
    filename    VARCHAR(255) NOT NULL,
    mime        VARCHAR(127) NOT NULL,
    encoding    VARCHAR(32)  NULL,
    size        INT UNSIGNED NOT NULL DEFAULT 0,     -- decoded size, estimated
    content_id  VARCHAR(255) NULL,
    is_inline   TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_mail_attachments_message (message_id),
    CONSTRAINT fk_mail_attachments_message FOREIGN KEY (message_id) REFERENCES mail_messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Drafts, queued, scheduled and sent mail written in bureau (PR 3 owns the payload keys).
CREATE TABLE IF NOT EXISTS mail_outbox (
    id                  CHAR(36) NOT NULL PRIMARY KEY,        -- uuid made in PHP
    account_id          INT UNSIGNED NOT NULL,
    user_id             INT NOT NULL,                         -- who wrote it
    kind                ENUM('new','reply','reply_all','forward') NOT NULL DEFAULT 'new',
    status              ENUM('draft','queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'draft',
    thread_id           INT UNSIGNED NULL,
    reply_to_message_id INT UNSIGNED NULL,                    -- mail_messages.id answered or forwarded
    payload             JSON NOT NULL,
    message_id          VARCHAR(255) NULL,
    message_id_hash     CHAR(40) NULL,
    send_at             DATETIME NULL,
    sent_at             DATETIME NULL,
    attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error          VARCHAR(500) NULL,
    idempotency_key     VARCHAR(64) NULL,
    created_at          DATETIME NULL,
    updated_at          DATETIME NULL,
    UNIQUE KEY uq_mail_outbox_idem (idempotency_key),
    KEY idx_mail_outbox_user (user_id),
    KEY idx_mail_outbox_hash (message_id_hash),
    KEY idx_mail_outbox_due (status, send_at),
    CONSTRAINT fk_mail_outbox_account FOREIGN KEY (account_id) REFERENCES mail_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Teamwork on shared mailboxes (PR 4): internal notes, what happened, who is viewing or replying.
CREATE TABLE IF NOT EXISTS mail_thread_notes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    thread_id   INT UNSIGNED NOT NULL,
    user_id     INT NOT NULL,
    body        TEXT NOT NULL,
    created_at  DATETIME NULL,
    updated_at  DATETIME NULL,
    deleted_at  DATETIME NULL,
    KEY idx_mail_notes_thread (thread_id, created_at),
    CONSTRAINT fk_mail_notes_thread FOREIGN KEY (thread_id) REFERENCES mail_threads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_thread_activity (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    thread_id   INT UNSIGNED NOT NULL,
    user_id     INT NULL,
    type        VARCHAR(40) NOT NULL,                -- assigned, unassigned, done, reopened, note, sent, merged, ...
    data        JSON NULL,
    created_at  DATETIME NULL,
    KEY idx_mail_activity_thread (thread_id, created_at),
    CONSTRAINT fk_mail_activity_thread FOREIGN KEY (thread_id) REFERENCES mail_threads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_presence (
    thread_id   INT UNSIGNED NOT NULL,
    user_id     INT NOT NULL,
    state       ENUM('viewing','replying') NOT NULL,
    expires_at  DATETIME NOT NULL,                   -- short (about 60 s), renewed while on screen
    PRIMARY KEY (thread_id, user_id),
    KEY idx_mail_presence_expiry (expires_at),
    CONSTRAINT fk_mail_presence_thread FOREIGN KEY (thread_id) REFERENCES mail_threads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mail's own settings, key -> JSON. Keys: signature.* (PR 3), team.* (PR 4), ai.* (PR 5).
CREATE TABLE IF NOT EXISTS mail_settings (
    setting_key   VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value JSON NULL,
    updated_by    INT NULL,
    updated_at    DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Permission: add shared mailboxes and manage every shared one (owner decision:
-- every staff login uses Mail; admins add shared mailboxes). Admin holds its
-- permissions as explicit rows, so it gets this one here; another role can be
-- given it later on Rôles & Permissions. LPC\Mail\People::isAdmin reads it from
-- the database (not the session cache), so it works without signing in again.
-- -----------------------------------------------------------------------------
INSERT INTO permissions (name, module, description) VALUES
    ('mail.shared.manage', 'mail', 'Ajouter des boîtes mail partagées et gérer toutes les boîtes partagées')
ON DUPLICATE KEY UPDATE
    module      = VALUES(module),
    description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name = 'mail.shared.manage'
 WHERE r.name = 'admin';

-- -----------------------------------------------------------------------------
-- VERIFY
-- -----------------------------------------------------------------------------
--   SHOW TABLES LIKE 'mail\_%';                          -- 11 tables
--   SHOW INDEX FROM mail_messages WHERE Index_type = 'FULLTEXT';
--   SELECT name FROM permissions WHERE name = 'mail.shared.manage';
