<?php
/**
 * Daily expiration alerts -- the scheduled-job version of the check the
 * dashboards run on page load (run_daily_expiration_check() in
 * includes/functions.php). Safe to run any number of times: each alert
 * goes out once per document and expiration date (expiration_alerts_sent).
 * Command line only.
 *
 * Linux / macOS (cron), every day at 6:00 AM -- `crontab -e`, then add:
 *   0 6 * * * /usr/bin/php /path/to/faculty201/cron/expiration_check.php >> /path/to/faculty201/cron/expiration_check.log 2>&1
 * (with the Docker image: docker exec <container> php /var/www/html/cron/expiration_check.php)
 * Set the same DB_HOST / DB_NAME / DB_USER / DB_PASS environment variables
 * the website uses if they aren't the XAMPP defaults (see config/db.php).
 *
 * Windows (XAMPP + Task Scheduler):
 *   1. Task Scheduler > Create Basic Task... > name "Faculty 201 expiration alerts"
 *   2. Trigger: Daily, 6:00 AM
 *   3. Action: Start a program
 *        Program/script:  C:\xampp\php\php.exe
 *        Add arguments:   C:\faculty201\cron\expiration_check.php
 *        Start in:        C:\faculty201\cron
 *   4. Finish; in the task's Properties tick "Run whether user is logged on or not".
 *   Or from a command prompt:
 *     schtasks /Create /TN "Faculty201 Expiration Alerts" /SC DAILY /ST 06:00 /TR "C:\xampp\php\php.exe C:\faculty201\cron\expiration_check.php"
 *   (MySQL must be running in XAMPP at that time.)
 *
 * Test by hand:  php cron/expiration_check.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

require_once __DIR__ . '/../includes/functions.php';   // config (Asia/Manila), database, migrations

$result = run_daily_expiration_check($pdo, true);
if ($result === null) {
    fwrite(STDERR, date('Y-m-d H:i:s') . " Expiration check failed -- see the PHP error log.\n");
    exit(1);
}
echo date('Y-m-d H:i:s') . " Expiration check: {$result['documents']} expired / expiring document(s), {$result['alerts']} new alert(s) sent.\n";
