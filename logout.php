<?php
require_once __DIR__ . '/includes/auth.php';
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
