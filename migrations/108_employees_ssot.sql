-- =============================================================================
-- migrations/108_employees_ssot.sql
-- -----------------------------------------------------------------------------
-- Sprint 15 · Employees become the Single Source of Truth
--
-- THE PROBLEM
-- -----------
-- Employee data has lived in three tables written by three different UIs:
--
--   · `users`               — auth surface, but also carries first_name,
--                             last_name, employee_code. Written by Paramètres →
--                             Utilisateurs (settings_controller.save_users) AND
--                             by Master Data → Employés (mdm_controller
--                             module=employees). Settings could create a login
--                             account for a "person" who had no HR record at
--                             all — the very definition of granting access to a
--                             non-employee.
--   · `employee_profiles`   — job_title, phone, avatar, base_salary,
--                             id_card_number, hire_date. Written only by MDM.
--   · `hr_contracts`        — base_salary, housing_allowance,
--                             transport_allowance, cnps_number, bank details,
--                             marital_status, dependents, tax_regime, hire_date.
--                             Written only by Gestion du Personnel.
--
-- Two `base_salary` columns and two `hire_date` columns, no synchronisation,
-- no shared writer. Whichever surface you edit, the others go stale — payroll
-- reads hr_contracts, MDM reads employee_profiles, and neither has ever agreed
-- with the other except by accident.
--
-- THE FIX
-- -------
-- One `employees` table with every field a person carries. Auth stays on
-- `users`, but users gains an `employee_id` FK (added in 109). Every user is
-- an employee; not every employee is a user. Creating an employee and
-- provisioning a login become two separate actions with two separate audit
-- trails, and neither can create the other.
--
-- The salary priority on backfill, agreed with the operator:
--   1. hr_contracts.base_salary   (what payroll actually uses today)
--   2. employee_profiles.base_salary
--   3. 50 000 FCFA fallback       (chosen deliberately — non-zero so the
--                                  NOT NULL constraint holds without giving
--                                  anyone a "0 FCFA" payslip by surprise)
--
-- The 0-value case is treated as "unset" (NULLIF), because hr_contracts rows
-- get auto-created with DEFAULT 0 by the current UI even when the admin never
-- typed a salary. If a genuine 0-salary employee exists (intern), an admin
-- corrects them from Données de Base afterwards.
--
-- WHY employees.id = users.id ON BACKFILL
-- ---------------------------------------
-- Every legacy user gets an employees row whose id equals the users.id it
-- came from. That makes migration 109's users.employee_id backfill a trivial
-- `UPDATE users SET employee_id = id`, with no need for a lookup column.
-- AUTO_INCREMENT continues past MAX(id), so employees created after this
-- migration through the UI get their own fresh ids without collision.
--
-- IDEMPOTENT
-- ----------
-- CREATE TABLE IF NOT EXISTS. Backfill uses INSERT IGNORE — a second run
-- inserts nothing because every id already exists.
--
-- Depends on: 107_user_sessions_login_identifier_width.sql
-- Also depends on presence of: users, roles, employee_profiles, hr_contracts.
-- =============================================================================

SET NAMES utf8mb4;

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. employees — the SSOT for a person the company employs.
-- -----------------------------------------------------------------------------
-- Column groups match the four categories the operator locked in as scope:
--   · Identity & HR basics
--   · Payroll & compensation
--   · Documents (paths — the file bytes live under /uploads/employees/…,
--     and multi-file attachments go in the sibling employee_documents table)
--   · Org chart & work pattern (reporting-line self-FK, working days/hours)
--
-- utf8mb4_unicode_ci matches the rest of the schema. All VARCHAR widths are
-- deliberately sized to fit inside InnoDB's 767-byte index prefix under
-- utf8mb4 wherever they might carry an index (190 × 4 = 760).
CREATE TABLE IF NOT EXISTS employees (
    id                        INT NOT NULL AUTO_INCREMENT PRIMARY KEY,

    -- Identity ---------------------------------------------------------------
    employee_code             VARCHAR(20)  NOT NULL,
    first_name                VARCHAR(50)  NOT NULL,
    last_name                 VARCHAR(50)  NOT NULL,
    gender                    ENUM('male','female','other','unspecified') NOT NULL DEFAULT 'unspecified',
    date_of_birth             DATE NULL,
    national_id_number        VARCHAR(50) NULL,
    cnps_number               VARCHAR(50) NULL,
    marital_status            ENUM('single','married','divorced','widowed') NOT NULL DEFAULT 'single',
    dependents_count          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    personal_phone            VARCHAR(30) NULL,
    home_address              VARCHAR(255) NULL,
    emergency_contact_name    VARCHAR(100) NULL,
    emergency_contact_phone   VARCHAR(30) NULL,

    -- Employment status & dates ----------------------------------------------
    hire_date                 DATE NULL,
    termination_date          DATE NULL,
    -- Payroll list filter. Distinct from users.status (which locks LOGIN).
    -- Someone on garden leave: employees.is_active=1, users.status='inactive'.
    is_active                 TINYINT(1) NOT NULL DEFAULT 1,

    -- Payroll & compensation -------------------------------------------------
    base_salary               DECIMAL(15,2) NOT NULL,
    housing_allowance         DECIMAL(15,2) NOT NULL DEFAULT 0,
    transport_allowance       DECIMAL(15,2) NOT NULL DEFAULT 0,
    other_allowances          DECIMAL(15,2) NOT NULL DEFAULT 0,
    tax_regime                ENUM('standard','expatriate','exempt') NOT NULL DEFAULT 'standard',
    seniority_years           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    bank_name                 VARCHAR(100) NULL,
    bank_account_number       VARCHAR(100) NULL,
    mobile_money_number       VARCHAR(30) NULL,

    -- Documents (single-slot fields — multi-doc goes in employee_documents) --
    avatar_path               VARCHAR(255) NULL,
    id_card_scan_path         VARCHAR(255) NULL,
    contract_pdf_path         VARCHAR(255) NULL,

    -- Org chart & work pattern -----------------------------------------------
    job_title                 VARCHAR(100) NULL,
    department                VARCHAR(100) NULL,
    manager_id                INT NULL,               -- self-FK, added below
    -- working_days as a SET of weekday tokens: 'mon,tue,wed,thu,fri'. SET
    -- rather than 7 booleans because the payroll grid, the timesheet reader,
    -- and any future scheduler all want it as one bag; less noise than
    -- {mon:1, tue:1, ...}.
    working_days              SET('mon','tue','wed','thu','fri','sat','sun') NOT NULL DEFAULT 'mon,tue,wed,thu,fri',
    working_hours_start       TIME NULL,
    working_hours_end         TIME NULL,

    -- Audit trail ------------------------------------------------------------
    created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by                INT NULL,
    updated_at                TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by                INT NULL,

    UNIQUE KEY uq_employees_employee_code (employee_code),
    KEY idx_employees_is_active (is_active),
    KEY idx_employees_manager   (manager_id),
    KEY idx_employees_name      (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Self-FK for manager. Added as a separate ALTER because MariaDB refuses to
-- reference a table that "does not yet exist" from inside its own CREATE, and
-- because we want ON DELETE SET NULL — a departed manager should not cascade-
-- delete their reports.
--
-- Guarded so a re-run is a no-op.
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE employees
        ADD CONSTRAINT fk_employees_manager
        FOREIGN KEY (manager_id) REFERENCES employees(id) ON DELETE SET NULL',
    'SELECT "fk_employees_manager exists" AS info'
  )
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE()
    AND table_name = 'employees'
    AND constraint_name = 'fk_employees_manager'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- -----------------------------------------------------------------------------
-- 2. employee_documents — arbitrary attachments per employee.
-- -----------------------------------------------------------------------------
-- Kept out of `employees` so the main record stays narrow and so the number
-- of documents per person is unbounded. `label` is free text ("contrat 2026",
-- "CV FR", "diplôme").
CREATE TABLE IF NOT EXISTS employee_documents (
    id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT NOT NULL,
    label         VARCHAR(150) NOT NULL,
    file_path     VARCHAR(255) NOT NULL,
    mime_type     VARCHAR(100) NULL,
    file_bytes    INT UNSIGNED NULL,
    uploaded_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    uploaded_by   INT NULL,
    KEY idx_ed_employee (employee_id),
    CONSTRAINT fk_ed_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- 3. BACKFILL — every current `users` row becomes an `employees` row.
-- -----------------------------------------------------------------------------
-- Explicit id = u.id so migration 109's users.employee_id backfill collapses
-- to a one-liner. AUTO_INCREMENT continues past MAX(id) for new rows.
--
-- INSERT IGNORE, not ON DUPLICATE KEY UPDATE: this migration owns the SHAPE
-- of the row, but its VALUES are the pre-migration state, and re-running the
-- migration should not overwrite whatever an admin has since edited through
-- the new UI. If you need to re-import from the legacy tables, you do it
-- explicitly, not by re-running a schema migration.
--
-- Salary source, in order:
--   1. hr_contracts.base_salary, treating 0 as unset
--      (the old UI auto-created contract rows with base_salary DEFAULT 0 even
--      when the admin never typed a salary — a raw COALESCE would pick that 0
--      over a real employee_profiles.base_salary further down the list).
--   2. employee_profiles.base_salary, same treatment.
--   3. 50 000 FCFA fallback.
--
-- COALESCE + NULLIF gives us this ranking in one expression. Zero-value
-- allowances stay 0 because those defaults are meaningful (no housing
-- allowance is a real state; no salary is not).
INSERT IGNORE INTO employees (
    id,
    employee_code, first_name, last_name,
    gender, date_of_birth,
    national_id_number, cnps_number,
    marital_status, dependents_count,
    personal_phone, home_address,
    emergency_contact_name, emergency_contact_phone,
    hire_date, is_active,
    base_salary, housing_allowance, transport_allowance, other_allowances,
    tax_regime, seniority_years,
    bank_name, bank_account_number,
    avatar_path, id_card_scan_path, contract_pdf_path,
    job_title, department,
    working_days,
    created_at
)
SELECT
    u.id,
    u.employee_code,
    u.first_name,
    u.last_name,
    'unspecified',                                         -- gender never captured before
    NULL,                                                  -- date_of_birth
    ep.id_card_number,
    c.cnps_number,
    COALESCE(c.marital_status, 'single'),
    COALESCE(c.dependents_count, 0),
    ep.phone,                                              -- personal_phone comes from employee_profiles
    NULL,                                                  -- home_address
    NULL, NULL,                                            -- emergency contact
    COALESCE(c.hire_date, ep.hire_date),                   -- prefer contract's, fall back to profile's
    CASE WHEN u.status = 'active' THEN 1 ELSE 0 END,       -- users.status is the only legacy signal for "active in the company"
    -- Salary priority: hr_contracts (real) → employee_profiles (real) → 50 000.
    -- NULLIF(x, 0) turns the auto-created 0.00 into NULL so the next arg wins.
    COALESCE(NULLIF(c.base_salary, 0), NULLIF(ep.base_salary, 0), 50000),
    COALESCE(c.housing_allowance, 0),
    COALESCE(c.transport_allowance, 0),
    0,                                                     -- other_allowances (new concept)
    COALESCE(c.tax_regime, 'standard'),
    COALESCE(c.seniority_years, 0),
    c.bank_name,
    c.bank_account,
    ep.avatar,
    NULL,                                                  -- id_card_scan_path (bytes never captured)
    NULL,                                                  -- contract_pdf_path
    ep.job_title,
    NULL,                                                  -- department (new concept)
    'mon,tue,wed,thu,fri',                                 -- default working days
    COALESCE(u.created_at, CURRENT_TIMESTAMP)
FROM users u
LEFT JOIN employee_profiles ep ON ep.user_id = u.id
LEFT JOIN hr_contracts      c  ON c.user_id  = u.id;

COMMIT;

-- =============================================================================
-- VERIFICATION QUERIES  (run manually after import — they do NOT alter data)
-- -----------------------------------------------------------------------------
-- 1. One employees row per users row.
--
--    SELECT
--      (SELECT COUNT(*) FROM users)     AS users_count,
--      (SELECT COUNT(*) FROM employees) AS employees_count;
--    -- expect: equal.
--
-- 2. Which salary source each employee got — the "who fell through to 50 000"
--    report the operator asked for. Read this AFTER 108, BEFORE letting anyone
--    edit through the new UI; anyone in the FALLBACK bucket needs their real
--    salary typed in.
--
--    SELECT
--      e.id, e.employee_code, e.first_name, e.last_name, e.base_salary,
--      CASE
--        WHEN c.base_salary IS NOT NULL AND c.base_salary <> 0 THEN 'hr_contracts'
--        WHEN ep.base_salary IS NOT NULL AND ep.base_salary <> 0 THEN 'employee_profiles'
--        ELSE 'FALLBACK_50000'
--      END AS salary_source
--    FROM employees e
--    LEFT JOIN employee_profiles ep ON ep.user_id = e.id
--    LEFT JOIN hr_contracts      c  ON c.user_id  = e.id
--    ORDER BY salary_source, e.employee_code;
--
-- 3. Self-FK is present and NULL-permissive.
--
--    SELECT constraint_name, delete_rule
--      FROM information_schema.referential_constraints
--     WHERE constraint_schema = DATABASE()
--       AND constraint_name   = 'fk_employees_manager';
--    -- expect: SET NULL.
--
-- 4. The three "Bloqué" users landed as is_active = 0 employees.
--
--    SELECT e.employee_code, e.first_name, e.last_name, e.is_active, u.status
--      FROM employees e JOIN users u ON u.id = e.id
--     WHERE u.status <> 'active';
--
-- 5. Employees are ready for 109 (users.employee_id will pair 1:1 on u.id).
--
--    SELECT COUNT(*) FROM users u
--     WHERE NOT EXISTS (SELECT 1 FROM employees e WHERE e.id = u.id);
--    -- expect: 0.
-- =============================================================================
