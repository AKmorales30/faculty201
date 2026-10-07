<?php
/**
 * Daily auto-archive -- the scheduled-job version of the check the
 * dashboards run on page load (run_daily_auto_archive() in
 * includes/functions.php). Moves every active document more than
 * ARCHIVE_AFTER_YEARS old (config/config.php) to its owner's archive.
 * Safe to run any number of times. Command line only.
 *
 * Linux / macOS (cron), every day at 5:55 AM (before the expiration alerts,
 * so archived documents get none) -- `crontab -e`, then add:
 *   55 5 * * * /usr/bin/php /path/to/faculty201/cron/auto_archive.php >> /path/to/faculty201/cron/auto_archive.log 2>&1
 * (with the Docker image: docker exec <container> php /var/www/html/cron/auto_archive.php)
 * Set the same DB_HOST / DB_NAME / DB_USER / DB_PASS environment variables
 * the website uses if they aren't the XAMPP defaults (see config/db.php).
 *
 * Windows (XAMPP + Task Scheduler):
 *   schtasks /Create /TN "Faculty201 Auto-Archive" /SC DAILY /ST 05:55 /TR "C:\xampp\php\php.exe C:\faculty201\cron\auto_archive.php"
 *   (or Task Scheduler > Create Basic Task..., as described in cron/expiration_check.php)
 *   MySQL must be running in XAMPP at that time.
 *
 * Test by hand:  php cron/auto_archive.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

require_once __DIR__ . '/../includes/functions.php';   // config (Asia/Manila), database, migrations

$result = run_daily_auto_archive($pdo, true);
if ($result === null) {
    fwrite(STDERR, date('Y-m-d H:i:s') . " Auto-archive failed -- see the PHP error log.\n");
    exit(1);
}
echo date('Y-m-d H:i:s') . " Auto-archive: {$result['archived']} document(s) older than " . ARCHIVE_AFTER_YEARS
   . ' years (dated before ' . archive_cutoff_date() . ') moved to the archive'
   . ($result['document_ids'] ? ': #' . implode(', #', $result['document_ids']) : '') . ".\n";
