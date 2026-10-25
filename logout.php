<?php
/**
 * Log out: POST with the CSRF token only -- the Log out button in the top bar,
 * after "Log out?" (includes/header.php). A plain visit (an old link or
 * bookmark, or another site trying to log someone out) changes nothing and
 * goes back to the home page. The automatic sign-outs (deactivated or deleted
 * account, includes/auth.php) don't come through here.
 */
require_once __DIR__ . '/includes/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_logged_in()) {
        $_SESSION['flash_error'] = 'Your session expired before you logged out. Please try again.';
    }
    header('Location: ' . BASE_URL . '/index.php');   // signed in: the dashboard; signed out: the login page
    exit;
}
if (is_logged_in()) {
    try {
        require_once __DIR__ . '/includes/functions.php';   // connects to the database
        log_my_activity($pdo, 'LOGOUT', 'Signed out.');
    } catch (Throwable $e) {
        error_log('Logout could not be logged: ' . $e->getMessage());   // sign out anyway
    }
}
// End the session completely: its data, its cookie, and anything of it the browser kept, so
// Back can't show a signed-in page again (Clear-Site-Data: Chrome, Edge, Firefox; the
// no-store pages and the reload in assets/js/app.js cover the rest)
$_SESSION = [];
session_destroy();
$cookie = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $cookie['path'], 'domain' => $cookie['domain'],
                               'secure' => $cookie['secure'], 'httponly' => $cookie['httponly'], 'samesite' => $cookie['samesite'] ?: 'Lax']);
header('Clear-Site-Data: "cache"');
header('Cache-Control: no-store');
header('Location: ' . BASE_URL . '/index.php');
exit;
