-- =============================================================================
-- scripts/tests/wipe/support/fixture_schema.sql
-- -----------------------------------------------------------------------------
-- A small model of bureau's tables for the numbering and data wipe tests (a
-- throwaway database; CI's MariaDB 10.11 service). The real tables predate
-- migrations/ and have many more columns; only the ones the code under test
-- reads are here. migrations/35x are applied on top, as on the server.
-- No client data, ever.
-- =============================================================================

CREATE TABLE app_preferences (
    id           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pref_key     VARCHAR(80)  NOT NULL UNIQUE,
    pref_value   TEXT         NULL,
    kind         VARCHAR(20)  NOT NULL DEFAULT 'text',
    options_json TEXT         NULL,
    group_key    VARCHAR(40)  NOT NULL DEFAULT 'general',
    label_fr     VARCHAR(160) NULL,
    label_en     VARCHAR(160) NULL,
    help_fr      TEXT         NULL,
    unit         VARCHAR(20)  NULL,
    min_value    DECIMAL(15,4) NULL,
    max_value    DECIMAL(15,4) NULL,
    sort_order   INT          NOT NULL DEFAULT 0,
    is_locked    TINYINT(1)   NOT NULL DEFAULT 0,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_preferences (pref_key, pref_value, kind) VALUES
  ('doc_prefix_invoice', 'FAC', 'text'), ('doc_prefix_quote', 'DEV', 'text'), ('doc_prefix_delivery', 'BL', 'text'),
  ('doc_prefix_order', 'CMD', 'text'), ('doc_prefix_purchase', 'BC', 'text'),
  ('doc_number_format', '{PREFIX}-{YYMM}-{SEQ}', 'select'), ('doc_sequence_padding', '4', 'int'),
  ('doc_sequence_reset', 'yearly', 'select');

-- The numbered documents: only what DocCounter reads (the reference)
CREATE TABLE invoices          (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE) ENGINE=InnoDB;
CREATE TABLE proposals         (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE) ENGINE=InnoDB;
CREATE TABLE sales_orders      (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE) ENGINE=InnoDB;
CREATE TABLE deliveries        (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE) ENGINE=InnoDB;
CREATE TABLE purchase_orders   (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE) ENGINE=InnoDB;
CREATE TABLE payments          (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE) ENGINE=InnoDB;
CREATE TABLE expenses          (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE) ENGINE=InnoDB;
CREATE TABLE cre_documents     (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE) ENGINE=InnoDB;
CREATE TABLE inventory_reports (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL) ENGINE=InnoDB;
CREATE TABLE recycling_sales   (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL) ENGINE=InnoDB;
