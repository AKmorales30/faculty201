<?php
/**
 * Database connection (PDO / MySQL)
 *
 * Reads from environment variables first (set these in Render's
 * dashboard when deploying), and falls back to XAMPP-friendly
 * defaults if they're not set -- so this exact same file works
 * unchanged on your local XAMPP setup AND on Render.
 *
 * SSL: managed cloud MySQL/MariaDB providers (like MariaDB SkySQL)
 * require an SSL connection. If a CA certificate file is present at
 * config/skysql-ca.pem, it's used automatically -- but only for a
 * database on another host. The file is in the repository, so it also
 * exists on a local XAMPP copy, whose MySQL has no SSL: connecting to
 * localhost runs without SSL as before (it used to fail with "MySQL server
 * has gone away"). DB_SSL=0 / DB_SSL=1 forces it off / on.
 */

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'faculty201_repository');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_PORT', getenv('DB_PORT') ?: '3306');

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$sslCaPath = __DIR__ . '/skysql-ca.pem';
$dbIsLocal = in_array(strtolower(DB_HOST), ['localhost', '127.0.0.1', '::1'], true);
$useSsl = getenv('DB_SSL') !== false ? getenv('DB_SSL') === '1' : !$dbIsLocal;
if ($useSsl && file_exists($sslCaPath)) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCaPath;
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        $options
    );
} catch (PDOException $e) {
    // The message can include the host and user name: log it, don't show it
    error_log('Database connection failed: ' . $e->getMessage());
    if (defined('APP_DEBUG') && APP_DEBUG) { die('Database connection failed: ' . htmlspecialchars($e->getMessage())); }
    throw new RuntimeException('Database connection failed');   // shown as a plain "Something went wrong" page
}

// MySQL's NOW() / CURDATE() and TIMESTAMP columns in Philippine time, the
// same as PHP (config.php), whatever time zone the database server uses
// (a hosted one is often UTC).
// A fixed offset, since the Philippines has no daylight saving time and
// named zones need MySQL's time zone tables loaded.
$pdo->exec("SET time_zone = '+08:00'");
