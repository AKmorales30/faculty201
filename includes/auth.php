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

/**
 * Password rules, used wherever a password is set (Change Password, and
 * the Admin's temporary passwords). Returns what's missing; empty = OK.
 */
function password_rule_errors(string $password): array {
    $errors = [];
    if (strlen($password) < 8)                { $errors[] = 'at least 8 characters'; }
    if (!preg_match('/[A-Z]/', $password))    { $errors[] = 'an uppercase letter'; }
    if (!preg_match('/[a-z]/', $password))    { $errors[] = 'a lowercase letter'; }
    if (!preg_match('/[0-9]/', $password))    { $errors[] = 'a number'; }
    return $errors;
}

/** "Password needs at least 8 characters and a number." for password_rule_errors() output. */
function password_rule_message(array $errors): string {
    $last = array_pop($errors);
    return 'The password needs ' . ($errors ? implode(', ', $errors) . ' and ' : '') . $last . '.';
}

/**
 * Random temporary password that meets password_rule_errors(): 12
 * characters, no look-alikes (0/O, 1/l/I) so it can be read out or copied
 * by hand.
 */
function generate_temporary_password(int $length = 12): string {
    $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789'];
    $all = implode('', $sets);
    $chars = array_map(fn($set) => $set[random_int(0, strlen($set) - 1)], $sets);   // one of each kind
    while (count($chars) < $length) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    for ($i = count($chars) - 1; $i > 0; $i--) {   // shuffle with a secure random source
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
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
    // A temporary password (set by the Admin) must be changed before anything else
    if (!empty($_SESSION['user']['must_change_password']) && basename($_SERVER['PHP_SELF']) !== 'change_password.php') {
        header('Location: ' . BASE_URL . '/change_password.php');
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