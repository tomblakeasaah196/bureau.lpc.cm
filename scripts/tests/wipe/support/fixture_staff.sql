-- =============================================================================
-- scripts/tests/wipe/support/fixture_staff.sql
-- -----------------------------------------------------------------------------
-- On top of fixture_schema.sql, for the "one by one" tests (data_wipe_records):
-- columns naming a staff account, with a foreign key or (as most of bureau's)
-- without one; the employee record and HR tables; Courrier's tables with their
-- real delete rules. No client data, ever.
-- =============================================================================

-- ---- Staff ids on documents: declared (FK) and undeclared ------------------------------
ALTER TABLE users ADD COLUMN updated_by INT NULL;
ALTER TABLE employees ADD COLUMN created_by INT NULL, ADD COLUMN avatar_path VARCHAR(255) NULL, ADD COLUMN manager_id INT NULL,
    ADD CONSTRAINT fk_employees_manager FOREIGN KEY (manager_id) REFERENCES employees (id) ON DELETE SET NULL;
ALTER TABLE invoices ADD COLUMN created_by INT NOT NULL DEFAULT 1, ADD COLUMN updated_by INT NULL,
    ADD CONSTRAINT fk_inv_created_by FOREIGN KEY (created_by) REFERENCES users (id);
ALTER TABLE payments ADD COLUMN logged_by INT NOT NULL DEFAULT 1, ADD COLUMN validated_by INT NULL, ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    ADD CONSTRAINT fk_pay_logged_by FOREIGN KEY (logged_by) REFERENCES users (id),
    ADD CONSTRAINT fk_pay_validated_by FOREIGN KEY (validated_by) REFERENCES users (id);
ALTER TABLE deliveries ADD COLUMN driver_id INT NULL, ADD COLUMN created_by INT NOT NULL DEFAULT 1,
    ADD CONSTRAINT fk_del_driver FOREIGN KEY (driver_id) REFERENCES users (id);
ALTER TABLE sales_orders ADD COLUMN created_by INT NOT NULL DEFAULT 1;
ALTER TABLE journal_entries ADD COLUMN created_by INT NOT NULL DEFAULT 1, ADD COLUMN approved_by INT NULL;
ALTER TABLE expenses ADD COLUMN created_by INT NOT NULL DEFAULT 1, ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    ADD CONSTRAINT fk_exp_created_by FOREIGN KEY (created_by) REFERENCES users (id);
ALTER TABLE treasury_accounts ADD COLUMN created_by INT NOT NULL DEFAULT 1,
    ADD CONSTRAINT fk_ta_created_by FOREIGN KEY (created_by) REFERENCES users (id);
ALTER TABLE vehicles ADD COLUMN default_driver_id INT NULL,
    ADD CONSTRAINT fk_vehicles_driver FOREIGN KEY (default_driver_id) REFERENCES users (id);
ALTER TABLE audit_logs ADD CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id);

-- ---- The person's own ------------------------------------------------------------------
CREATE TABLE employee_documents (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, file_path VARCHAR(255) NOT NULL, uploaded_by INT NULL,
    CONSTRAINT fk_ed_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE hr_contracts (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, base_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_hrc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE hr_payslips (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, month INT NOT NULL, year INT NOT NULL, net DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_by INT NOT NULL,
    CONSTRAINT fk_hrp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_hrp_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB;
CREATE TABLE user_prefs (
    user_id INT NOT NULL, pref_key VARCHAR(60) NOT NULL, pref_value VARCHAR(255) NULL, PRIMARY KEY (user_id, pref_key)
) ENGINE=InnoDB;
CREATE TABLE user_sessions (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NULL,
    CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE help_chat_log (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, question TEXT NOT NULL) ENGINE=InnoDB;

-- ---- Courrier (mail_*), with migration 300's delete rules --------------------------------
CREATE TABLE mail_accounts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, kind ENUM('personal','shared') NOT NULL, email VARCHAR(190) NOT NULL UNIQUE,
    display_name VARCHAR(120) NOT NULL, owner_user_id INT NULL, secret TEXT NOT NULL
) ENGINE=InnoDB;
CREATE TABLE mail_account_members (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, account_id INT UNSIGNED NOT NULL, user_id INT NOT NULL, added_by INT NULL,
    UNIQUE KEY uq_mail_members (account_id, user_id),
    CONSTRAINT fk_mam_account FOREIGN KEY (account_id) REFERENCES mail_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE mail_threads (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, account_id INT UNSIGNED NOT NULL, subject VARCHAR(255) NOT NULL, assigned_to INT NULL,
    CONSTRAINT fk_mt_account FOREIGN KEY (account_id) REFERENCES mail_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE mail_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, account_id INT UNSIGNED NOT NULL, thread_id INT UNSIGNED NULL, subject VARCHAR(255) NOT NULL,
    body_text MEDIUMTEXT NULL, sent_by INT NULL, seen_by INT NULL,
    CONSTRAINT fk_mm_account FOREIGN KEY (account_id) REFERENCES mail_accounts (id) ON DELETE CASCADE,
    CONSTRAINT fk_mm_thread FOREIGN KEY (thread_id) REFERENCES mail_threads (id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE mail_thread_notes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, thread_id INT UNSIGNED NOT NULL, user_id INT NOT NULL, body TEXT NOT NULL,
    CONSTRAINT fk_mtn_thread FOREIGN KEY (thread_id) REFERENCES mail_threads (id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE mail_signatures (user_id INT NOT NULL PRIMARY KEY, phone VARCHAR(40) NULL) ENGINE=InnoDB;
