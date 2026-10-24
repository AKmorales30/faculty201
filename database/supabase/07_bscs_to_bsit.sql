-- =====================================================================
-- BSCS -> BSIT (PostgreSQL / Supabase)
--
-- The College of Computing Studies has one program, BSIT (PROGRAMS in
-- config/config.php). This moves every account still set to BSCS to BSIT.
-- Nothing is deleted. Safe to run more than once (the second run changes
-- nothing). Not applied automatically: run it yourself in Supabase,
-- SQL Editor > New query, paste this file, Run.
--
-- 1. (optional) See who is affected first -- run this SELECT on its own:
--      SELECT user_id, full_name, username, role, program, college, is_active, deleted_at
--      FROM users WHERE program = 'BSCS' ORDER BY role, full_name;
-- =====================================================================

BEGIN;

-- 2. Move them to BSIT (and into CCS if no college was set)
UPDATE users
   SET program = 'BSIT',
       college = COALESCE(NULLIF(college, ''), 'CCS')
 WHERE program = 'BSCS';

COMMIT;

-- 3. Check: should return 0
SELECT COUNT(*) AS still_bscs FROM users WHERE program = 'BSCS';
