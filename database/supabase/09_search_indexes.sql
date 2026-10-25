-- =====================================================================
-- Faster search-as-you-type (PostgreSQL / Supabase)
--
-- The live search (api/search.php) and the table searches use ILIKE
-- '%words%', which a normal index can't help with. Trigram (pg_trgm) GIN
-- indexes can, on the columns searched most: document title, organizer and
-- extracted text (OCR), and account names / usernames.
--
-- Optional -- search works without it, just slower on many documents.
-- Run in Supabase: SQL Editor > New query, paste this file, Run. Safe to
-- run again. Building the OCR-text index can take a moment on a large table.
-- =====================================================================

-- Supabase keeps extensions in their own schema
CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA extensions;

-- The operator class lives where pg_trgm is installed (extensions on Supabase; public if it was added there before)
DO $$
DECLARE s text;
BEGIN
    SELECT n.nspname INTO s FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE e.extname = 'pg_trgm';
    EXECUTE format('CREATE INDEX IF NOT EXISTS idx_documents_title_trgm ON documents USING gin (title %I.gin_trgm_ops)', s);
    EXECUTE format('CREATE INDEX IF NOT EXISTS idx_documents_organizer_trgm ON documents USING gin (conducted_by %I.gin_trgm_ops)', s);
    EXECUTE format('CREATE INDEX IF NOT EXISTS idx_documents_ocr_trgm ON documents USING gin (ocr_extracted_text %I.gin_trgm_ops)', s);
    EXECUTE format('CREATE INDEX IF NOT EXISTS idx_users_full_name_trgm ON users USING gin (full_name %I.gin_trgm_ops)', s);
    EXECUTE format('CREATE INDEX IF NOT EXISTS idx_users_username_trgm ON users USING gin (username %I.gin_trgm_ops)', s);
END $$;

-- Check (optional): the indexes now exist
SELECT indexname FROM pg_indexes WHERE indexname LIKE '%\_trgm' ORDER BY indexname;
