-- =====================================================================
-- Migration: keep the 201-file documents themselves in the database
--
-- Render's free plan wipes the server's disk on every deploy / restart,
-- which deleted every uploaded file under uploads/ while the documents
-- rows survived ("The file is no longer available on the server").
-- Each filed document's bytes are now stored here, one row per document;
-- uploads/ is only a local copy.
--
-- Applied automatically by run_pending_migrations() in
-- includes/functions.php (recorded in schema_migrations).
-- =====================================================================

CREATE TABLE document_files (
    document_id   INT PRIMARY KEY,
    mime_type     VARCHAR(100) NOT NULL,
    file_size     INT NOT NULL,
    data          LONGBLOB NOT NULL,
    stored_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(document_id) ON DELETE CASCADE
) ENGINE=InnoDB;
