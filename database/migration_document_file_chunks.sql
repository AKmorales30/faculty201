-- =====================================================================
-- Store uploaded files in pieces, so a file is never bigger than one
-- MySQL packet (max_allowed_packet).
--
-- Why: uploaded files are kept in the database (document_files), one row
-- per file. MySQL / MariaDB refuses any query or row bigger than
-- max_allowed_packet -- 1 MB on XAMPP -- so every file over about 900 KB
-- failed to save ("The document could not be saved just now"), and could
-- not have been read back either.
--
-- After this, new files are written to document_file_chunks in pieces of
-- half the packet size (document_files keeps the type and size, with an
-- empty data column). Files saved before keep working as they are.
--
-- Not applied automatically. Apply it once:
--   mysql -u root faculty201_repository < database/migration_document_file_chunks.sql
-- Until then, files that fit in one packet still save; bigger ones are
-- refused with a clear "file too large" message.
-- =====================================================================

USE faculty201_repository;

CREATE TABLE IF NOT EXISTS document_file_chunks (
    document_id INT NOT NULL,
    seq         INT NOT NULL,              -- 0, 1, 2, ... in file order
    data        MEDIUMBLOB NOT NULL,       -- at most half of max_allowed_packet
    PRIMARY KEY (document_id, seq),
    CONSTRAINT fk_document_file_chunks_document
        FOREIGN KEY (document_id) REFERENCES documents (document_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
