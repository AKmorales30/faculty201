<?php
require_once __DIR__ . '/../config/config.php';

function current_user() {
    return $_SESSION['user'] ?? null;
}

function is_logged_in() {
    return isset($_SESSION['user']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

/**
 * Restrict a page to one or more roles. Redirects home (with an error
 * flash) if the logged-in user's role is not allowed.
 */
function require_role($allowed_roles) {
    require_login();
    $roles = is_array($allowed_roles) ? $allowed_roles : [$allowed_roles];
    if (!in_array(current_user()['role'], $roles, true)) {
        $_SESSION['flash_error'] = 'You do not have access to that page.';
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

function redirect_to_dashboard() {
    $role = current_user()['role'];
    switch ($role) {
        case 'admin':
            header('Location: ' . BASE_URL . '/admin/dashboard.php');
            break;
        case 'faculty':
            header('Location: ' . BASE_URL . '/faculty/dashboard.php');
            break;
        case 'program_chair':
        case 'dean':
            header('Location: ' . BASE_URL . '/approval/pending_requests.php');
            break;
        default:
            header('Location: ' . BASE_URL . '/index.php');
    }
    exit;
}