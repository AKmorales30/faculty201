<?php
/**
 * Daily reminders -- the scheduled-job version of the check the dashboards
 * run on page load (run_daily_reminder_check() in includes/ai_reminders.php).
 * Checks every active account (also the way to send everyone their
 * reminders right away -- or use "Run reminder check now" on the Admin
 * dashboard). Works out who needs a reminder (no seminar certificate in
 * REMINDER_NO_SEMINAR_MONTHS months, missing required documents, expired /
 * expiring documents, incomplete PDS; the follow-up summary for the
 * Admin, Program Chairs and Deans), has Gemini word the messages
 * (fixed templates when AI is off or unreachable) and sends them. Safe to
 * run any number of times: a reminder is repeated only after
 * REMINDER_REPEAT_DAYS or when its reason changes. Command line only.
 *
 * Linux / macOS (cron), every day at 6:05 AM (after the expiration alerts)
 * -- `crontab -e`, then add:
 *   5 6 * * * /usr/bin/php /path/to/faculty201/cron/reminder_check.php >> /path/to/faculty201/cron/reminder_check.log 2>&1
 * (with the Docker image: docker exec <container> php /var/www/html/cron/reminder_check.php)
 *
 * Windows (XAMPP + Task Scheduler):
 *   schtasks /Create /TN "Faculty201 Reminders" /SC DAILY /ST 06:05 /TR "C:\xampp\php\php.exe C:\faculty201\cron\reminder_check.php"
 *   (or Task Scheduler > Create Basic Task..., as described in cron/expiration_check.php)
 *   MySQL must be running in XAMPP at that time; AI wording needs internet.
 *
 * Test by hand:  php cron/reminder_check.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

require_once __DIR__ . '/../includes/functions.php';   // config (Asia/Manila), database, migrations, AI

$result = run_daily_reminder_check($pdo, true, 'scheduled task');
if ($result === null) {
    fwrite(STDERR, date('Y-m-d H:i:s') . " Reminder check failed -- see the PHP error log.\n");
    exit(1);
}
echo date('Y-m-d H:i:s') . " Reminder check: {$result['checked']} accounts checked, {$result['sent']} reminder(s) sent"
   . " ({$result['ai_written']} worded by AI" . (ai_enabled() ? '' : ' -- AI is off, templates used') . "), {$result['updated']} follow-up count(s) updated, {$result['resolved']} resolved"
   . ($result['failed'] ? ', errors for account #' . implode(', #', $result['failed']) . ' (see the PHP error log)' : '') . ".\n";
exit($result['failed'] ? 1 : 0);
