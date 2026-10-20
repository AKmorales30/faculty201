<?php
/**
 * Database settings -- TEMPLATE. Copy this file to config/database.php and
 * paste your Supabase connection details there. config/database.php is in
 * .gitignore (the password never goes into Git) and config/.htaccess blocks
 * web access to this folder.
 *
 * Where to find them: Supabase dashboard > your project > Connect (top bar)
 * > "Session pooler". Use the SESSION pooler, not "Direct connection" (that
 * one is IPv6-only, which Render and most home networks can't reach) and
 * not "Transaction pooler" (port 6543 -- it breaks PHP's prepared queries).
 *
 * Either paste the whole connection string into 'database_url' (replace
 * [YOUR-PASSWORD] in it with the database password), or fill in the
 * separate fields and leave 'database_url' empty. A password with special
 * characters (@ : / # ? %) is easier in the separate 'password' field.
 *
 * Nothing pasted (placeholders left as they are) = the local XAMPP MySQL
 * database, as before.
 *
 * On Render, set environment variables instead (they override this file):
 * DATABASE_URL, or DB_DRIVER=pgsql + DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS.
 */
return [
    // The whole "Session pooler" connection string, e.g.
    // postgresql://postgres.abcdefghijklmnop:MyPassword@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres
    'database_url' => 'PASTE-YOUR-SUPABASE-SESSION-POOLER-CONNECTION-STRING-HERE',

    // -- or the same, field by field (used when 'database_url' is empty)
    'driver'   => 'pgsql',                                       // 'pgsql' = Supabase, 'mysql' = XAMPP / MariaDB
    'host'     => 'PASTE-HOST-HERE',                             // aws-0-<region>.pooler.supabase.com
    'port'     => '5432',
    'name'     => 'postgres',
    'user'     => 'PASTE-USER-HERE',                             // postgres.<project-ref>
    'password' => 'PASTE-DATABASE-PASSWORD-HERE',                // Project Settings > Database > Database password

    // Supabase requires an encrypted connection; 'require' is right for it
    'sslmode'  => 'require',
];
