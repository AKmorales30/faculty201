-- ============================================================================
-- Rename: "Faculty-201 File Management System" -> "Faculty File Management
-- System", "201-Files" -> "My Files" in text already stored and shown to
-- users (notifications, activity log, reminders, archive reasons). New rows
-- already use the new names (the code changed with this migration).
--
-- Text only: no table, column, status value or file path changes, so every
-- document and file link keeps working. Safe to run twice (the second run
-- finds nothing left to change).
--
-- How to run: Supabase > SQL Editor > paste this file > Run.
-- ============================================================================

BEGIN;

CREATE OR REPLACE FUNCTION rename_201_text(t text) RETURNS text
LANGUAGE sql IMMUTABLE AS $$
    SELECT replace(replace(replace(replace(replace(replace(replace(replace(replace(replace(
           replace(replace(replace(replace(replace(replace(replace(replace(t,
        'Faculty-201 File Management System', 'Faculty File Management System'),
        'your 201-Files',               'My Files'),
        'Your 201-Files',               'My Files'),
        '201-Files',                    'My Files'),
        'official 201-file record',     'official faculty record'),
        '201-file documents',           'faculty documents'),
        'to keep your 201 file complete', 'to keep your files complete'),
        'Your 201 file is',             'Your files are'),
        'Missing from the 201 file',    'Missing from My Files'),
        'check your 201 file',          'check My Files'),
        'to your 201 file',             'to My Files'),
        'from your 201 file',           'from My Files'),
        'to their 201 file',            'to My Files'),
        'their 201 files',              'their files'),
        'their 201 file',               'their files'),
        'own 201 file',                 'own files'),
        '201 file of ',                 'Files of '),
        '201 file',                     'files')
$$;

UPDATE notifications SET message = rename_201_text(message)
 WHERE message ~* '201[- ]?file|faculty-201';

UPDATE activity_logs SET details = rename_201_text(details)
 WHERE details ~* '201[- ]?file|faculty-201';

UPDATE ai_reminders SET message = rename_201_text(message), reason = rename_201_text(reason)
 WHERE message ~* '201[- ]?file|faculty-201' OR reason ~* '201[- ]?file|faculty-201';

UPDATE documents SET archive_reason = rename_201_text(archive_reason)
 WHERE archive_reason ~* '201[- ]?file|faculty-201';

DROP FUNCTION rename_201_text(text);

COMMIT;

-- Check (should return 0 for every table):
-- SELECT 'notifications', COUNT(*) FROM notifications WHERE message ~* '201[- ]?file'
-- UNION ALL SELECT 'activity_logs', COUNT(*) FROM activity_logs WHERE details ~* '201[- ]?file'
-- UNION ALL SELECT 'ai_reminders', COUNT(*) FROM ai_reminders WHERE message ~* '201[- ]?file' OR reason ~* '201[- ]?file'
-- UNION ALL SELECT 'documents', COUNT(*) FROM documents WHERE archive_reason ~* '201[- ]?file';
