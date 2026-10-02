<?php
require_once __DIR__ . '/../config/config.php';

/** Escape for HTML output. Lives here so pages that don't need the database (the login pages) have it too. */
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * CSRF protection for state-changing forms: one random token per session,
 * sent as a hidden field (csrf_field()) and checked with csrf_valid().
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function csrf_valid(): bool {
    return is_string($_POST['csrf_token'] ?? null) && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

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
            header('Location: ' . BASE_URL . '/approval/dashboard.php');
            break;
        default:
            header('Location: ' . BASE_URL . '/index.php');
    }
    exit;
}