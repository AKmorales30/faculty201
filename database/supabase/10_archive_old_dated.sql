-- =====================================================================
-- OPTIONAL: move existing documents dated 3+ years ago to Archived Docs
-- (PostgreSQL / Supabase)
--
-- New uploads already do this (archive_at_upload(), includes/functions.php).
-- This is for documents uploaded before that rule. Not run by the app.
--
-- A document's own date: the seminar / training end date, else its start
-- date, else the date issued (never the upload date). Skipped: the PDS,
-- documents with no date, and documents the Admin restored (archive_exempt).
-- "3 years ago" is counted in Philippine time.
--
-- HOW TO USE (Supabase > SQL Editor > New query):
--   1. Paste this whole file and Run. It changes NOTHING: it shows how many
--      documents would move, then the list.
--   2. If that's right, remove the "--" in front of every line of STEP 2
--      (select those lines, Ctrl / Cmd + /) and Run again. Running STEP 2
--      twice is harmless: moved documents are no longer in 201-Files.
-- =====================================================================

-- STEP 1 -- preview: how many would move
SELECT COUNT(*) AS would_move_to_archived_docs
FROM documents d
WHERE d.status = 'active' AND d.document_type <> 'PDS' AND d.archive_exempt = 0
  AND COALESCE(d.date_end, d.date_start, d.date_issued) <= ((now() AT TIME ZONE 'Asia/Manila')::date - INTERVAL '3 years');

-- ... and which ones
SELECT d.document_id, u.full_name AS faculty, d.document_type, d.document_subtype, d.title,
       COALESCE(d.date_end, d.date_start, d.date_issued) AS document_date, d.filed_at AS uploaded
FROM documents d JOIN users u ON u.user_id = d.faculty_id
WHERE d.status = 'active' AND d.document_type <> 'PDS' AND d.archive_exempt = 0
  AND COALESCE(d.date_end, d.date_start, d.date_issued) <= ((now() AT TIME ZONE 'Asia/Manila')::date - INTERVAL '3 years')
ORDER BY u.full_name, document_date;

-- STEP 2 -- move them (remove the "--" in front of each line below, then Run)
-- BEGIN;
-- WITH moved AS (
--     UPDATE documents d
--        SET status = 'archived',
--            archived_at = (now() AT TIME ZONE 'Asia/Manila'),
--            archived_by = NULL,
--            archive_reason = 'Auto: document dated 3+ years ago (existing document)'
--      WHERE d.status = 'active' AND d.document_type <> 'PDS' AND d.archive_exempt = 0
--        AND COALESCE(d.date_end, d.date_start, d.date_issued) <= ((now() AT TIME ZONE 'Asia/Manila')::date - INTERVAL '3 years')
--     RETURNING d.document_id
-- )
-- INSERT INTO activity_logs (user_id, user_role, action, details)
-- SELECT NULL, 'system', 'DOCUMENT_AUTO_ARCHIVE',
--        'Moved ' || COUNT(*) || ' existing document(s) dated 3+ years ago to Archived Docs (database/supabase/10_archive_old_dated.sql): '
--        || COALESCE(string_agg('#' || document_id, ', ' ORDER BY document_id), 'none') || '.'
-- FROM moved;
-- COMMIT;
