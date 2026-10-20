-- =====================================================================
-- Demo accounts for a FRESH Supabase database (the same ones as
-- database/seed.sql). Run after 01_schema.sql. Skip this file when you
-- copy your real data over from MySQL -- use 03_after_data_import.sql.
--
-- Password for every account below: Password123
-- (checked with PHP's password_verify()). Change them after the first login.
-- Safe to run again: accounts already there are left as they are.
-- =====================================================================

BEGIN;

INSERT INTO users (user_id, role, full_name, email, password_hash, employment_type, employment_status, date_engaged) VALUES
(1, 'admin',         'Selverino J. Galela',      'admin@ccs.udm.edu.ph',         '$2b$10$ucYlHMTarCTqWABtKlMzIO4s72/pLe11WRMyUJeT04GP1SyAxeqMa', NULL,        NULL,     NULL),
(2, 'program_chair', 'Engr. Rosario Villanueva', 'programchair@ccs.udm.edu.ph',  '$2b$10$ucYlHMTarCTqWABtKlMzIO4s72/pLe11WRMyUJeT04GP1SyAxeqMa', NULL,        NULL,     NULL),
(3, 'dean',          'Dr. Fernando Del Rosario', 'dean@ccs.udm.edu.ph',          '$2b$10$ucYlHMTarCTqWABtKlMzIO4s72/pLe11WRMyUJeT04GP1SyAxeqMa', 'full_time', 'active', '2015-06-01'),
(4, 'faculty',       'Adrian Kyle Morales',      'amorales@ccs.udm.edu.ph',      '$2b$10$ucYlHMTarCTqWABtKlMzIO4s72/pLe11WRMyUJeT04GP1SyAxeqMa', 'full_time', 'active', '2019-08-15'),
(5, 'faculty',       'Fionna Yvonne Austria',    'faustria@ccs.udm.edu.ph',      '$2b$10$ucYlHMTarCTqWABtKlMzIO4s72/pLe11WRMyUJeT04GP1SyAxeqMa', 'part_time', 'paused', '2021-01-10'),
(6, 'faculty',       'John Michael Santos',      'jsantos@ccs.udm.edu.ph',       '$2b$10$ucYlHMTarCTqWABtKlMzIO4s72/pLe11WRMyUJeT04GP1SyAxeqMa', 'part_time', 'active', '2022-06-20')
ON CONFLICT DO NOTHING;

-- migration_programs_colleges.sql put every non-admin account in CCS
UPDATE users SET college = 'CCS' WHERE user_id BETWEEN 2 AND 6 AND college IS NULL;

INSERT INTO employment_history (faculty_id, event_type, event_date, remarks)
SELECT v.faculty_id, v.event_type, v.event_date::date, v.remarks
  FROM (VALUES
        (4, 'engaged', '2019-08-15', 'Initial engagement as full-time faculty'),
        (5, 'engaged', '2021-01-10', 'Initial engagement as part-time faculty'),
        (5, 'paused',  '2023-06-01', 'Took a leave of absence'),
        (6, 'engaged', '2022-06-20', 'Initial engagement as part-time faculty')
       ) AS v (faculty_id, event_type, event_date, remarks)
 WHERE NOT EXISTS (SELECT 1 FROM employment_history h WHERE h.faculty_id = v.faculty_id AND h.event_type = v.event_type
                                                          AND h.event_date = v.event_date::date);

-- The ids above were given by hand: move every id counter past them, so
-- the next account created in Manage Faculty gets id 7 (not 1 -> "duplicate key")
DO $$
DECLARE
    r record;
BEGIN
    FOR r IN SELECT table_name, column_name FROM information_schema.columns
              WHERE table_schema = 'public' AND is_identity = 'YES'
    LOOP
        EXECUTE format('SELECT setval(pg_get_serial_sequence(%L, %L), COALESCE((SELECT MAX(%I) FROM public.%I), 0) + 1, false)',
                       'public.' || r.table_name, r.column_name, r.column_name, r.table_name);
    END LOOP;
END
$$;

COMMIT;
