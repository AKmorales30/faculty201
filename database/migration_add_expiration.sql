-- =====================================================================
-- Migration: add expiration tracking to documents
--
-- The capstone paper (p.18) describes an "expiration monitoring"
-- feature ("applied only to documents with identifiable expiration or
-- validity dates"), and the Faculty upload wireframe (Figure 7) has an
-- explicit "Expiration Date (if any)" field. The current schema.sql
-- has no column to store this, so it's added here as a separate,
-- non-destructive migration rather than editing schema.sql directly
-- (run this once against an existing database; new setups can just
-- run schema.sql then this file, in order).
-- =====================================================================

USE faculty201_repository;

ALTER TABLE documents
    ADD COLUMN expiration_date DATE NULL AFTER file_path;

CREATE INDEX idx_documents_expiration ON documents(expiration_date);

-- The faculty member enters this at submission time (Figure 7 wireframe),
-- but the request may not be confirmed/filed until a *different* user
-- (Program Chair / Dean, in a separate login session) acts on it later.
-- It has to be persisted on the request row itself -- not in session --
-- so it survives to the filing step in approval/request_detail.php.
ALTER TABLE submission_requests
    ADD COLUMN expiration_date_hint DATE NULL AFTER document_type_hint;
