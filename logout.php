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
session_unset();
session_destroy();
header('Location: ' . BASE_URL . '/index.php');
exit;
