-- =============================================================================
-- scripts/tests/mail/support/fixture_schema.sql
-- -----------------------------------------------------------------------------
-- The few bureau tables Courrier reads, with only the columns it uses, for the
-- Mail tests (a throwaway database; CI's MariaDB 10.11 service). The real
-- tables predate migrations/ and have many more columns. migrations/300 is then
-- applied on top, as it is on the server. No client data, ever.
-- =============================================================================

CREATE TABLE roles (
    id   INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    default_landing_permission VARCHAR(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    module      VARCHAR(50)  NULL,
    description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id       INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employees (
    id             INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    first_name     VARCHAR(100) NOT NULL,
    last_name      VARCHAR(100) NOT NULL,
    employee_code  VARCHAR(30)  NULL,
    job_title      VARCHAR(120) NULL,
    personal_phone VARCHAR(40)  NULL,
    avatar_path    VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(190) NOT NULL UNIQUE,
    first_name  VARCHAR(100) NOT NULL DEFAULT '',
    last_name   VARCHAR(100) NOT NULL DEFAULT '',
    status      VARCHAR(20)  NOT NULL DEFAULT 'active',
    role_id     INT NULL,
    employee_id INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    title       VARCHAR(150) NOT NULL,
    message     TEXT NULL,
    type        VARCHAR(20) NOT NULL DEFAULT 'info',
    related_url VARCHAR(255) NULL,
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NULL,
    action     VARCHAR(20) NOT NULL,
    table_name VARCHAR(64) NULL,
    record_id  INT NULL,
    new_value  TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (name) VALUES ('admin'), ('accountant'), ('driver');
INSERT INTO permissions (name, module, description) VALUES ('*', 'system', 'Tout');
INSERT INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name = '*' WHERE r.name = 'admin';
