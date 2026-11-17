-- =============================================================================
-- scripts/tests/help_videos/support/fixture_schema.sql
-- -----------------------------------------------------------------------------
-- The tables migrations/032 (help centre) writes to, for the training-video
-- tests (a throwaway database). bureau's real RBAC tables predate migrations/
-- and have more columns; only what 032 needs is here. Permissions are checked
-- from the session in the tests (hv_as()), as Rbac does after login.
-- =============================================================================

CREATE TABLE roles (
    id   INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (id, name) VALUES (1, 'admin'), (2, 'accountant'), (3, 'operations'), (5, 'sales');

CREATE TABLE permissions (
    id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    module      VARCHAR(50)  NOT NULL DEFAULT '',
    description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id       INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
