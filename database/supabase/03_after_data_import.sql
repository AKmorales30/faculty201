-- =====================================================================
-- Run this ONCE after copying the existing data from the MySQL / MariaDB
-- database into Supabase (e.g. with pgloader, data only, into the tables
-- 01_schema.sql made).
--
-- Copied rows keep their old ids, but PostgreSQL's id counters still start
-- at 1 -- the next insert would fail with "duplicate key value violates
-- unique constraint ..._pkey". This moves every counter past the highest
-- id in its table. Safe to run any number of times.
-- =====================================================================

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

-- Imported tables get the same API lockout as 01_schema.sql, in case the
-- import tool recreated any of them
DO $$
DECLARE
    t text;
BEGIN
    FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' LOOP
        EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('REVOKE ALL ON TABLE public.%I FROM anon, authenticated', t);
    END LOOP;
END
$$;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM anon, authenticated;
