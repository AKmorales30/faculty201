<?php
require_once __DIR__ . '/../config/config.php';

/** Escape for HTML output. Lives here so pages that don't need the database (the login pages) have it too. */
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Cache-busting ?v= for a file under the app root (its modification time): a
 * changed CSS / JS file gets a new URL, so browsers never run an old cached
 * copy against a new page. Lives here so the landing page has it too.
 */
function admin_asset(string $path): string {
    return BASE_URL . '/' . $path . '?v=' . (int)@filemtime(ROOT_PATH . '/' . $path);
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
        header('Location: ' . BASE_URL . '/index.php');   // the login form is on the landing page
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
// ---------------------------------------------------------------------
// Account status on every request. Active / Deactivated is users.is_active
// (1 / 0), set only in Manage Faculty -- employment pause is a separate
// field and doesn't affect logging in. login_process.php refuses a
// deactivated account; the check below ends the session of one that is
// deactivated (or removed) while signed in, on every page and AJAX request.
// ---------------------------------------------------------------------

const ACCOUNT_DEACTIVATED_MESSAGE = 'Your account has been deactivated. Please contact the administrator.';
const ACCOUNT_DELETED_MESSAGE = 'This account has been deleted. Please contact the administrator.';

/** The landing page's login form a role uses ('faculty', or 'admin' for Admin / Program Chair / Dean). */
function login_portal_for_role(?string $role): string {
    return $role === 'faculty' ? 'faculty' : 'admin';
}

/**
 * Sign out a session whose account is no longer active: log it, clear the
 * session (new id, old data destroyed), keep only the message for the
 * landing page, then send a page request to that role's login form and an
 * AJAX request a 401 (assets/js/app.js reloads the page, which lands there).
 */
function end_deactivated_session(PDO $pdo, bool $deleted = false): void {
    $u = $_SESSION['user'];
    $message = $deleted ? ACCOUNT_DELETED_MESSAGE : ACCOUNT_DEACTIVATED_MESSAGE;
    try {
        $pdo->prepare("INSERT INTO activity_logs (user_id, user_role, action, details, ip_address, user_agent) VALUES (?, ?, 'SESSION_ENDED', ?, ?, ?)")
            ->execute([(int)$u['user_id'], $u['role'] ?? null, 'Signed out automatically: the account was ' . ($deleted ? 'deleted.' : 'deactivated.'),
                       $_SERVER['REMOTE_ADDR'] ?? null, isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null]);
    } catch (Throwable $e) {
        error_log('Deactivated session: could not log it: ' . $e->getMessage());
    }
    $portal = login_portal_for_role($u['role'] ?? null);
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['login_error_' . $portal] = $message;

    $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
         || str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/api/');
    if ($ajax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => $message]);
        exit;
    }
    header('Location: ' . BASE_URL . '/index.php?role=' . $portal);
    exit;
}

// Runs at the top level of every page / endpoint that includes this file (all of them), so the
// connection lands in the global $pdo that includes/functions.php uses too.
// The same read refreshes the signed-in user's name, username and email in the session, so the top bar,
// avatar initials and greetings show the name in the database right away -- also after the Admin renames the account.
if (!empty($_SESSION['user']['user_id']) && PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../config/db.php';
    try {
        $account_check = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");   // * : the name columns may not be migrated yet
        $account_check->execute([(int)$_SESSION['user']['user_id']]);
        $account_row = $account_check->fetch();
    } catch (Throwable $e) {
        $account_row = null;   // database problem: the page itself reports it
        error_log('Account status check failed: ' . $e->getMessage());
    }
    if ($account_row === false || ($account_row && ((int)$account_row['is_active'] !== 1 || !empty($account_row['deleted_at'])))) {
        end_deactivated_session($pdo, !empty($account_row['deleted_at']));   // no such account, deactivated, or deleted
    }
    if ($account_row) {
        $_SESSION['user']['full_name'] = $account_row['full_name'];
        $_SESSION['user']['first_name'] = $account_row['first_name'] ?? null;
        $_SESSION['user']['username'] = $account_row['username'] ?? null;   // also after the Admin changes it
        $_SESSION['user']['email'] = $account_row['email'] ?? null;
    }
    unset($account_check, $account_row);
}
