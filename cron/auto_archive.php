<?php
/**
 * Daily document housekeeping -- the scheduled-job version of the checks
 * the dashboards run on page load (includes/functions.php):
 *   1. auto-archive (run_daily_auto_archive()): every posted document nobody
 *      has opened in ARCHIVE_UNVIEWED_YEARS years moves to its owner's archive
 *   2. Recently Deleted cleanup (run_daily_deleted_cleanup()): documents
 *      deleted more than DELETED_RETENTION_DAYS ago are permanently deleted,
 *      and owners are warned DELETED_PURGE_WARNING_DAYS days before
 * Each step is recorded as done for the day only once it finished; a
 * failed step is tried again on the next run / page load.
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

$failed = false;
$result = run_daily_auto_archive($pdo, true);
if ($result === null) {
    fwrite(STDERR, date('Y-m-d H:i:s') . " Auto-archive failed -- see the PHP error log.\n");
    $failed = true;
} else {
    echo date('Y-m-d H:i:s') . " Auto-archive: {$result['archived']} document(s) not opened in " . archive_period_label()
       . ' (since ' . archive_cutoff_datetime() . ') moved to the archive'
       . ($result['document_ids'] ? ': #' . implode(', #', $result['document_ids']) : '') . ".\n";
}

$result = run_daily_deleted_cleanup($pdo, true);
if ($result === null) {
    fwrite(STDERR, date('Y-m-d H:i:s') . " Recently Deleted cleanup failed (or only partly done) -- see the PHP error log.\n");
    $failed = true;
} else {
    echo date('Y-m-d H:i:s') . " Recently Deleted: {$result['purged']} document(s) deleted more than " . (int)DELETED_RETENTION_DAYS
       . ' days ago permanently deleted' . ($result['document_ids'] ? ': #' . implode(', #', $result['document_ids']) : '')
       . "; {$result['warned']} owner warning(s) sent.\n";
}
exit($failed ? 1 : 0);
