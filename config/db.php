<?php
/**
 * Database connection (PDO): PostgreSQL on Supabase, or MySQL / MariaDB
 *
 * Settings, first found wins:
 *   1. a connection string: the DATABASE_URL environment variable, or
 *      'database_url' in config/database.php
 *   2. environment variables DB_DRIVER, DB_HOST, DB_PORT, DB_NAME, DB_USER,
 *      DB_PASS (set these in Render's dashboard when deploying)
 *   3. the separate fields in config/database.php (template:
 *      config/database.example.php -- where to paste the Supabase details)
 *   4. XAMPP-friendly MySQL defaults
 * So this exact same file works unchanged on your local XAMPP setup AND on
 * Render. DB_CONFIG_FILE points at a settings file kept somewhere else.
 *
 * Supabase (DB_DRIVER pgsql): connect through the Session pooler, with
 * SSL (sslmode=require); the schema is database/supabase/01_schema.sql.
 *
 * MySQL SSL: managed cloud MySQL/MariaDB providers (like MariaDB SkySQL)
 * require an SSL connection. If a CA certificate file is present at
 * config/skysql-ca.pem, it's used automatically -- but only for a
 * database on another host. The file is in the repository, so it also
 * exists on a local XAMPP copy, whose MySQL has no SSL: connecting to
 * localhost runs without SSL as before (it used to fail with "MySQL server
 * has gone away"). DB_SSL=0 / DB_SSL=1 forces it off / on.
 */

/** Settings from config/database.php, without the template's placeholders; [] when nothing was pasted in. */
function db_file_settings(): array {
    $file = getenv('DB_CONFIG_FILE') ?: __DIR__ . '/database.php';
    $loaded = is_file($file) ? (include $file) : [];
    $settings = array_filter(is_array($loaded) ? $loaded : [], fn($v) => is_string($v) && trim($v) !== ''
        && !str_contains($v, 'PASTE-') && !str_contains($v, '[YOUR-PASSWORD]'));
    // Only the template's defaults left (driver, port, ...): not set up yet
    return isset($settings['database_url']) || isset($settings['host']) ? array_map('trim', $settings) : [];
}

/**
 * The parts of a connection string, e.g. Supabase's
 * postgresql://postgres.ref:password@aws-0-region.pooler.supabase.com:5432/postgres
 * The password runs to the last "@", so one with an unencoded "@" still works.
 */
function db_parse_url(string $url): array {
    if (!preg_match('#^([a-z0-9+]+)://([^:/@]+)(?::(.*))?@([^@/:?]+)(?::(\d+))?(?:/([^?]*))?(?:\?(.*))?$#i', trim($url), $m)) {
        error_log('Database connection failed: the database connection string (DATABASE_URL / database_url) could not be read');
        throw new RuntimeException('Database connection failed');
    }
    parse_str($m[7] ?? '', $query);
    $parts = [
        'driver'   => in_array(strtolower($m[1]), ['postgres', 'postgresql', 'pgsql'], true) ? 'pgsql' : 'mysql',
        'user'     => rawurldecode($m[2]),
        'password' => rawurldecode($m[3] ?? ''),
        'host'     => $m[4],
        'port'     => $m[5] ?? '',
        'name'     => rawurldecode($m[6] ?? ''),
        'sslmode'  => (string)($query['sslmode'] ?? ''),
    ];
    return array_filter($parts, fn($v) => $v !== '');
}

$dbFile = db_file_settings();
$dbUrl = getenv('DATABASE_URL') ?: ($dbFile['database_url'] ?? '');
$dbFromUrl = $dbUrl !== '' ? db_parse_url($dbUrl) : [];
$dbSetting = function (string $key, string $env, string $default) use ($dbFromUrl, $dbFile): string {
    if (isset($dbFromUrl[$key])) { return $dbFromUrl[$key]; }
    $value = getenv($env);
    return $value !== false && $value !== '' ? $value : ($dbFile[$key] ?? $default);
};

define('DB_DRIVER', $dbSetting('driver', 'DB_DRIVER', 'mysql') === 'pgsql' ? 'pgsql' : 'mysql');
$dbIsPgsql = DB_DRIVER === 'pgsql';
define('DB_HOST', $dbSetting('host', 'DB_HOST', 'localhost'));
define('DB_NAME', $dbSetting('name', 'DB_NAME', $dbIsPgsql ? 'postgres' : 'faculty201_repository'));
define('DB_USER', $dbSetting('user', 'DB_USER', $dbIsPgsql ? 'postgres' : 'root'));
define('DB_PASS', $dbSetting('password', 'DB_PASS', ''));
define('DB_PORT', $dbSetting('port', 'DB_PORT', $dbIsPgsql ? '5432' : '3306'));

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$dbIsLocal = in_array(strtolower(DB_HOST), ['localhost', '127.0.0.1', '::1'], true);
if ($dbIsPgsql) {
    $sslmode = $dbSetting('sslmode', 'DB_SSLMODE', $dbIsLocal ? 'prefer' : 'require');
    $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";sslmode=" . $sslmode;
} else {
    $sslCaPath = __DIR__ . '/skysql-ca.pem';
    $useSsl = getenv('DB_SSL') !== false ? getenv('DB_SSL') === '1' : !$dbIsLocal;
    if ($useSsl && file_exists($sslCaPath)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCaPath;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
}

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // The message can include the host and user name: log it, don't show it
    error_log('Database connection failed: ' . $e->getMessage());
    if (defined('APP_DEBUG') && APP_DEBUG) { die('Database connection failed: ' . htmlspecialchars($e->getMessage())); }
    throw new RuntimeException('Database connection failed');   // shown as a plain "Something went wrong" page
}

// NOW() / CURDATE() and the timestamp columns in Philippine time, the same
// as PHP (config.php), whatever time zone the database server uses (a
// hosted one is often UTC).
// MySQL: a fixed offset, since the Philippines has no daylight saving time
// and named zones need MySQL's time zone tables loaded. PostgreSQL has the
// named zones built in (and reads a bare '+08:00' as 8 hours WEST of UTC).
$pdo->exec($dbIsPgsql ? "SET TIME ZONE 'Asia/Manila'" : "SET time_zone = '+08:00'");
