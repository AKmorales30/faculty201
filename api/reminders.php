<?php
/**
 * Dismiss one of your own reminders (the Dismiss button on the dashboard
 * Reminders card, includes/reminders_card.php), or -- Admin only -- run the
 * reminder check for every account now ("Run reminder check now" on the
 * dashboard's follow-up card). POST + CSRF token; then back to the
 * dashboard. A dismissed reminder only comes back if it still applies
 * after REMINDER_REPEAT_DAYS, or its reason changes.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($action, ['dismiss', 'run_check'], true)) {
    redirect_to_dashboard();
}
if (!csrf_valid()) {
    $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    redirect_to_dashboard();
}
if ($action === 'run_check') {
    $me = current_user();
    if ($me['role'] !== 'admin') {
        $_SESSION['flash_error'] = 'Only the Admin can run the reminder check.';
        redirect_to_dashboard();
    }
    $result = run_daily_reminder_check($pdo, true, 'run now by the Admin', $me);
    if ($result === null) {
        $_SESSION['flash_error'] = 'The reminder check failed -- see the Activity Log (Reminder Check) and the PHP error log.';
    } else {
        $_SESSION['flash_success'] = "Reminder check finished: {$result['checked']} account(s) checked, {$result['sent']} reminder(s) sent, "
            . "{$result['resolved']} resolved" . ($result['failed'] ? ', ' . count($result['failed']) . ' account(s) had errors (see the Activity Log)' : '') . '.';
    }
    redirect_to_dashboard();
}
dismiss_reminder($pdo, (int)current_user()['user_id'], (int)($_POST['id'] ?? 0));
redirect_to_dashboard();
