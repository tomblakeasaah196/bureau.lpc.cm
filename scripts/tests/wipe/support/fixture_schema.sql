-- =============================================================================
-- scripts/tests/wipe/support/fixture_schema.sql
-- -----------------------------------------------------------------------------
-- A small model of bureau's tables for the numbering and data wipe tests (a
-- throwaway database; CI's MariaDB 10.11 service). The real tables predate
-- migrations/ and have many more columns; only the ones the code under test
-- reads are here, with bureau's real foreign keys and delete rules, so the
-- wipe follows them as it would on the server. migrations/35x are applied on
-- top. No client data, ever.
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

-- ---- Staff (never wiped) -------------------------------------------------------
CREATE TABLE roles (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL) ENGINE=InnoDB;
CREATE TABLE employees (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, employee_code VARCHAR(20) NOT NULL,
    first_name VARCHAR(50) NOT NULL, last_name VARCHAR(50) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE users (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, role_id INT NOT NULL, employee_code VARCHAR(20) NULL,
    first_name VARCHAR(50) NOT NULL, last_name VARCHAR(50) NOT NULL, email VARCHAR(190) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB;

-- ---- Chart of accounts (never wiped, except a client's or supplier's own sub-account)
CREATE TABLE ohada_accounts (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, account_number VARCHAR(20) NOT NULL) ENGINE=InnoDB;
CREATE TABLE chart_of_accounts (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, ohada_account_id INT NOT NULL, code VARCHAR(20) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL,
    CONSTRAINT fk_coa_ohada FOREIGN KEY (ohada_account_id) REFERENCES ohada_accounts (id)
) ENGINE=InnoDB;
CREATE TABLE treasury_accounts (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, balance DECIMAL(15,2) NOT NULL DEFAULT 0,
    coa_account_id INT NULL, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE financial_years (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, year INT NOT NULL, state VARCHAR(24) NOT NULL DEFAULT 'open') ENGINE=InnoDB;

-- ---- Master data ----------------------------------------------------------------
CREATE TABLE clients (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, account_id INT NULL, deleted_reassigned_to INT NULL,
    CONSTRAINT fk_clients_account FOREIGN KEY (account_id) REFERENCES chart_of_accounts (id),
    CONSTRAINT fk_clients_reassigned FOREIGN KEY (deleted_reassigned_to) REFERENCES clients (id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE suppliers (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, account_id INT NULL,
    CONSTRAINT fk_suppliers_account FOREIGN KEY (account_id) REFERENCES chart_of_accounts (id)
) ENGINE=InnoDB;
CREATE TABLE products (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL, name VARCHAR(100) NOT NULL, linked_empty_id INT NULL,
    cump DECIMAL(15,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_products_empty FOREIGN KEY (linked_empty_id) REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE vehicles (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, plate_number VARCHAR(20) NOT NULL) ENGINE=InnoDB;

-- ---- Accounting ------------------------------------------------------------------
CREATE TABLE journal_entries (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE, date DATE NOT NULL,
    status ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft', source_type VARCHAR(32) NULL, source_id INT NULL,
    reversed_by_entry_id INT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE journal_lines (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, journal_entry_id INT NOT NULL, account_id INT NOT NULL,
    debit DECIMAL(15,2) NOT NULL DEFAULT 0, credit DECIMAL(15,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_jl_entry FOREIGN KEY (journal_entry_id) REFERENCES journal_entries (id) ON DELETE CASCADE,
    CONSTRAINT fk_jl_account FOREIGN KEY (account_id) REFERENCES chart_of_accounts (id)
) ENGINE=InnoDB;

-- ---- Sales -----------------------------------------------------------------------
CREATE TABLE proposals (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE, client_name VARCHAR(150) NOT NULL DEFAULT '') ENGINE=InnoDB;
CREATE TABLE proposal_items (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, proposal_id INT NOT NULL, product_id INT NULL,
    CONSTRAINT fk_pi_proposal FOREIGN KEY (proposal_id) REFERENCES proposals (id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE sales_orders (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE, client_id INT NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0, payment_status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
    CONSTRAINT fk_so_client FOREIGN KEY (client_id) REFERENCES clients (id)
) ENGINE=InnoDB;
CREATE TABLE sales_order_items (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, sales_order_id INT NOT NULL, product_id INT NOT NULL, quantity INT NOT NULL,
    CONSTRAINT fk_soi_order FOREIGN KEY (sales_order_id) REFERENCES sales_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_soi_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB;
CREATE TABLE deliveries (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE, sales_order_id INT NOT NULL DEFAULT 0, client_id INT NOT NULL DEFAULT 0,
    vehicle_id INT NULL, status VARCHAR(20) NOT NULL DEFAULT 'completed', journal_entry_id INT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, signed_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_del_order FOREIGN KEY (sales_order_id) REFERENCES sales_orders (id),
    CONSTRAINT fk_del_client FOREIGN KEY (client_id) REFERENCES clients (id),
    CONSTRAINT fk_del_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id),
    CONSTRAINT fk_del_je FOREIGN KEY (journal_entry_id) REFERENCES journal_entries (id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE delivery_items (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, delivery_id INT NOT NULL, product_id INT NOT NULL, quantity INT NOT NULL,
    delivered_quantity INT NULL, returned_empty_qty INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_di_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE CASCADE,
    CONSTRAINT fk_di_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB;
CREATE TABLE invoices (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE, client_id INT NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0, status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inv_client FOREIGN KEY (client_id) REFERENCES clients (id)
) ENGINE=InnoDB;
CREATE TABLE invoice_items (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, invoice_id INT NOT NULL, product_id INT NOT NULL,
    CONSTRAINT fk_ii_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    CONSTRAINT fk_ii_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB;
CREATE TABLE invoice_deliveries (
    invoice_id INT NOT NULL, delivery_id INT NOT NULL, PRIMARY KEY (invoice_id, delivery_id),
    CONSTRAINT fk_idl_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    CONSTRAINT fk_idl_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE payments (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE, invoice_id INT NULL, client_id INT NOT NULL DEFAULT 0,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0, air_withheld_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    payment_method ENUM('cash','momo','bank','wallet') NOT NULL DEFAULT 'cash',
    status ENUM('pending','validated','rejected') NOT NULL DEFAULT 'validated',
    CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id),
    CONSTRAINT fk_pay_client FOREIGN KEY (client_id) REFERENCES clients (id)
) ENGINE=InnoDB;
CREATE TABLE client_wallets (
    client_id INT NOT NULL PRIMARY KEY, balance DECIMAL(15,2) NOT NULL DEFAULT 0,
    last_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallet_client FOREIGN KEY (client_id) REFERENCES clients (id)
) ENGINE=InnoDB;
CREATE TABLE client_empties_ledger (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, site_id INT NULL, product_id INT NOT NULL,
    total_out INT NOT NULL DEFAULT 0, total_in INT NOT NULL DEFAULT 0, quantity_owed INT NOT NULL DEFAULT 0, quantity_in_flight INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_cel_client FOREIGN KEY (client_id) REFERENCES clients (id),
    CONSTRAINT fk_cel_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB;

-- ---- Purchasing, stock, treasury, expenses -----------------------------------------
CREATE TABLE purchase_orders (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE, supplier_id INT NULL,
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
) ENGINE=InnoDB;
CREATE TABLE inventory_movements (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, movement_type VARCHAR(20) NOT NULL, quantity INT NOT NULL,
    reference_id INT NULL, date DATETIME DEFAULT CURRENT_TIMESTAMP, logged_by INT NOT NULL DEFAULT 1,
    CONSTRAINT fk_im_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB;
CREATE TABLE treasury_transactions (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, account_id INT NOT NULL, transaction_type VARCHAR(30) NOT NULL,
    amount DECIMAL(15,2) NOT NULL, reference VARCHAR(100) NULL, linked_transfer_id INT NULL, deleted_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_tt_account FOREIGN KEY (account_id) REFERENCES treasury_accounts (id),
    CONSTRAINT fk_tt_link FOREIGN KEY (linked_transfer_id) REFERENCES treasury_transactions (id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE expenses (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE, journal_entry_id INT NULL,
    CONSTRAINT fk_exp_je FOREIGN KEY (journal_entry_id) REFERENCES journal_entries (id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE expense_attachments (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, expense_id INT NOT NULL, stored_path VARCHAR(500) NOT NULL,
    CONSTRAINT fk_ea_expense FOREIGN KEY (expense_id) REFERENCES expenses (id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE overheads (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL) ENGINE=InnoDB;
-- A protected table that can't do without an overhead: wiping overheads must be refused
CREATE TABLE company_annexes (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, overhead_id INT NOT NULL,
    CONSTRAINT fk_annex_overhead FOREIGN KEY (overhead_id) REFERENCES overheads (id)
) ENGINE=InnoDB;

-- ---- Numbered documents only DocCounter reads ----------------------------------------
CREATE TABLE cre_documents     (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE) ENGINE=InnoDB;
CREATE TABLE inventory_reports (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL) ENGINE=InnoDB;
CREATE TABLE recycling_sales   (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL) ENGINE=InnoDB;

-- ---- What follows the records ---------------------------------------------------------
CREATE TABLE notifications (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(100) NOT NULL, message TEXT NOT NULL,
    type VARCHAR(10) NOT NULL DEFAULT 'info', related_url VARCHAR(255) NULL
) ENGINE=InnoDB;
CREATE TABLE audit_logs (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, action VARCHAR(50) NOT NULL, table_name VARCHAR(50) NOT NULL, record_id INT NOT NULL
) ENGINE=InnoDB;
CREATE TABLE pdf_documents (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) NOT NULL, record_id INT NOT NULL, path VARCHAR(255) NOT NULL
) ENGINE=InnoDB;
