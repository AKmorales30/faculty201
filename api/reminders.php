<?php
/**
 * Dismiss one of your own reminders (the Dismiss button on the dashboard
 * Reminders card, includes/reminders_card.php). POST + CSRF token; then
 * back to the dashboard. A dismissed reminder only comes back if it still
 * applies after REMINDER_REPEAT_DAYS, or its reason changes.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'dismiss') {
    redirect_to_dashboard();
}
if (!csrf_valid()) {
    $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    redirect_to_dashboard();
}
dismiss_reminder($pdo, (int)current_user()['user_id'], (int)($_POST['id'] ?? 0));
redirect_to_dashboard();
