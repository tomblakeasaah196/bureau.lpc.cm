-- =============================================================================
-- 012_pdf_documents.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 4 (parallel) · server-side PDF renderer metadata.
--
-- WHY (from AUDIT_REPORT §7 MEDIUM — html2canvas + jsPDF OOM on driver phones):
--   Every public document (facture, bon_commande, bon_livraison, quote,
--   print_cre, print_audit) is currently rendered as bitmap via html2canvas
--   → jsPDF client-side. That produces 1–5 MB image-only PDFs and blows up
--   on 3 GB Android phones for multi-page quotes. The Sprint-4 rewrite
--   pushes rendering to the server (dompdf) and caches the output on disk.
--
-- WHAT THIS DOES:
--   Adds pdf_documents — the cache table. One row per (type, record_id, token).
--   sha256 is compared against a freshly-computed content hash before serving
--   the cached file; if the source record was UPDATE'd we re-render.
--
-- Idempotent.
-- =============================================================================

START TRANSACTION;

CREATE TABLE IF NOT EXISTS pdf_documents (
    id             INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type           ENUM('invoice','delivery','po','quote','cre','audit','payslip') NOT NULL,
    record_id      INT NOT NULL COMMENT 'Points at the source row (invoices.id / deliveries.id / …)',
    token          VARCHAR(64) NOT NULL COMMENT 'Same token as the source record — used in the public URL',
    path           VARCHAR(255) NOT NULL COMMENT 'Relative to /uploads/documents/',
    size_bytes     INT UNSIGNED NOT NULL DEFAULT 0,
    sha256         CHAR(64) NOT NULL COMMENT 'SHA-256 of the source-record content that was rendered',
    generated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    generated_by   INT NULL COMMENT 'users.id — NULL for public token renders',
    source_updated_at TIMESTAMP NULL COMMENT 'MAX(updated_at) of the source rows at render time',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_token (token),
    KEY idx_type_record (type, record_id),
    KEY idx_generated_at (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Optional FK to the user who generated it (SET NULL so we don't lose the
-- cache row when a user is deleted).
DELIMITER //
DROP PROCEDURE IF EXISTS lpc_try_fk_12 //
CREATE PROCEDURE lpc_try_fk_12()
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists FROM information_schema.key_column_usage
     WHERE table_schema=DATABASE() AND table_name='pdf_documents' AND column_name='generated_by'
       AND referenced_table_name='users';
    IF v_exists = 0 THEN
        BEGIN
            DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
            ALTER TABLE pdf_documents
                ADD CONSTRAINT fk_pdf_docs_generated_by
                FOREIGN KEY (generated_by) REFERENCES users(id)
                ON DELETE SET NULL ON UPDATE CASCADE;
        END;
    END IF;
END//
DELIMITER ;

CALL lpc_try_fk_12();
DROP PROCEDURE IF EXISTS lpc_try_fk_12;

COMMIT;

-- =============================================================================
-- Verification:
--   DESC pdf_documents;
--   SELECT type, COUNT(*) FROM pdf_documents GROUP BY type;
-- =============================================================================
