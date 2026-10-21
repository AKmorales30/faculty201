<?php
require_once __DIR__ . '/includes/auth.php';

// Maps each portal's form value (the role chosen on the landing page) to:
// the landing page with that role's login form open, to bounce back to on
// failure, the session key used for that form's flash error, and a
// validator that checks the authenticated user actually belongs there.
// Faculty (full-time + part-time) share one portal; Admin, Program
// Chair, and Dean share the other. Employment type and the specific
// role (admin / program_chair / dean) still fully control what each
// account can see and do once inside — only the LOGIN SCREEN is shared.
$PORTALS = [
    'faculty' => [
        'page'       => 'index.php?role=faculty',
        'error_key'  => 'login_error_faculty',
        'validate'   => function (array $user): bool {
            return $user['role'] === 'faculty';
        },
    ],
    'admin' => [
        'page'       => 'index.php?role=admin',
        'error_key'  => 'login_error_admin',
        'validate'   => function (array $user): bool {
            return in_array($user['role'], ['admin', 'program_chair', 'dean'], true);
        },
    ],
];

$portal_key = is_string($_POST['portal'] ?? null) ? $_POST['portal'] : '';
$portal = $PORTALS[$portal_key] ?? null;

// Fallback target if we don't recognize the portal (e.g. tampered form):
// the role choice, with the error shown there
$fallback_page = $portal['page'] ?? 'index.php';
$error_key = $portal['error_key'] ?? 'login_error';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$username = mb_substr(mb_strtolower(trim((string)($_POST['username'] ?? ''))), 0, 150);   // the lockout key; fits login_attempts.username
$password = (string)($_POST['password'] ?? '');
// The connecting address only: X-Forwarded-For can be set by anyone, so it
// isn't trusted (a known proxy in front of the app would have to be configured).
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

/**
 * Back to the login page with a message; the username is kept so it doesn't
 * have to be retyped. $bad_fields marks the username / password boxes red --
 * only when what was typed is wrong, not for a lock or a server problem.
 */
function login_fail(string $message, bool $bad_fields = false): never {
    global $error_key, $fallback_page, $username;
    $_SESSION[$error_key] = $message;
    $_SESSION['login_username'] = mb_substr($username, 0, 150);
    $_SESSION['login_bad_fields'] = $bad_fields;
    header('Location: ' . BASE_URL . '/' . $fallback_page);
    exit;
}

/**
 * Run a side task of logging in (lockout check, attempt log, suspicious-login
 * screening, hash upgrade). If it fails -- e.g. a table missing because a
 * migration didn't run -- the error is logged and $fallback returned, so it
 * can't stop a user with the right password from signing in.
 */
function login_side_task(string $what, callable $task, $fallback = null) {
    try {
        return $task();
    } catch (Throwable $e) {
        error_log("Login: {$what} failed (sign-in continues): " . $e->getMessage());
        return $fallback;
    }
}

const LOGIN_BAD_CREDENTIALS = 'Invalid username or password.';
// password_verify() against this when the username doesn't exist, so a wrong
// username takes as long to answer as a wrong password (doesn't reveal which)
const LOGIN_TIMING_HASH = '$2y$10$Nu0j1id/XmI1uwQKvlaY4uAHJPzBSJwN..QlAafSBlKWbxexd3d0W';

// The role comes from the browser, so it is never trusted on its own: an
// unknown one is refused here, and a known one is checked against the
// account's real role after the password (validate, below).
if (!$portal) {
    login_fail('Please choose your role, then log in.');
}
if (!csrf_valid()) {
    login_fail('Your session expired. Please log in again.');
}

if ($username === '' || $password === '') {
    login_fail('Please enter both your username and password.', true);
}
// Logging in with the email, as before usernames: say what changed
if (str_contains($username, '@')) {
    login_fail('Please log in with your username, not your email. Ask the administrator if you don\'t know your username.', true);
}

// Only what logging in can't do without -- the database, the account and its
// password -- stops it with the generic message; side tasks fail safe.
try {
    require_once __DIR__ . '/includes/functions.php';   // connects to the database
    // Accounts from before usernames get one (first.last) -- logins need it
    login_side_task('assigning usernames', fn() => ensure_usernames($pdo));
    // Deactivated accounts are found too, so the right password gets the "deactivated" message
    // below instead of "Invalid username or password" (a wrong password still gets the generic one)
    $user = false;
    if (username_valid($username)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
    }
} catch (Throwable $e) {
    // Database or other server problem: log the details, show the user a plain message
    error_log('Login failed for ' . $username . ': ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    login_fail('We couldn\'t sign you in right now. Please try again in a moment.');
}
$user_id = $user ? (int)$user['user_id'] : null;

// Brute-force protection, checked BEFORE the password: while the username or
// this IP is locked, even the right password is refused. Locks apply to
// any username entered, so they don't reveal whether an account exists.
$locks = array_filter([
    login_side_task('account lock check', fn() => active_login_lock($pdo, 'account', $username)),
    login_side_task('IP lock check', fn() => active_login_lock($pdo, 'ip', $ip)),
]);
if ($locks) {
    login_side_task('recording the attempt', fn() => record_login_attempt($pdo, $username, $user_id, false, $ip, true));
    login_fail(login_lock_message(max(array_column($locks, 'seconds_left'))));
}

$password_ok = password_verify($password, $user ? $user['password_hash'] : LOGIN_TIMING_HASH);
if (!$user || !$password_ok) {
    login_side_task('recording the attempt', fn() => record_login_attempt($pdo, $username, $user_id, false, $ip));
    $attempts_left = null;
    $lock = login_side_task('lockout check', function () use ($pdo, $username, $ip, &$attempts_left) {
        return register_failed_login($pdo, $username, $ip, $attempts_left);
    });
    if ($lock) {
        login_fail(login_lock_message($lock['seconds_left']));
    }
    login_fail(LOGIN_BAD_CREDENTIALS
        . ($attempts_left !== null && $attempts_left > 0 && $attempts_left <= WARN_REMAINING_ATTEMPTS
            ? ' ' . $attempts_left . ' attempt' . ($attempts_left === 1 ? '' : 's') . ' left before login is locked for ' . LOCKOUT_MINUTES . ' minutes.'
            : ''), true);
}

// Deleted from Manage Faculty (restorable for DELETED_RETENTION_DAYS) or deactivated (users.is_active = 0):
// right password, but no session. Logged as a blocked attempt -- blocked attempts don't count toward the
// brute-force lockout. (After the permanent deletion the username no longer exists: a normal failed login.)
if (!empty($user['deleted_at'])) {
    login_side_task('recording the attempt', fn() => record_login_attempt($pdo, $username, $user_id, false, $ip, true));
    log_activity($pdo, $user_id, 'LOGIN_BLOCKED', 'Login refused: the account is deleted.', $user['role']);
    login_fail(ACCOUNT_DELETED_MESSAGE);
}
if ((int)$user['is_active'] !== 1) {
    login_side_task('recording the attempt', fn() => record_login_attempt($pdo, $username, $user_id, false, $ip, true));
    log_activity($pdo, $user_id, 'LOGIN_BLOCKED', 'Login refused: the account is deactivated.', $user['role']);
    login_fail(ACCOUNT_DEACTIVATED_MESSAGE);
}

// Confirm the account actually belongs to the portal it logged in through
if (!$portal['validate']($user)) {
    login_side_task('recording the attempt', fn() => record_login_attempt($pdo, $username, $user_id, false, $ip));
    login_fail('This account is not registered for this role. Use "Change role" to choose the correct one.');
}

// Keep the stored hash on PHP's current default algorithm / cost
if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    login_side_task('upgrading the password hash', fn() => $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), $user_id]));
}

// Successful login: log it, then screen for suspicious patterns (RBAC /
// suspicious-login-alerts module, Ch.3 3.1 of the capstone paper) before
// the session is established.
login_side_task('recording the login', fn() => record_login_attempt($pdo, $username, $user_id, true, $ip));
login_side_task('suspicious-login screening', fn() => flag_suspicious_login($pdo, $user_id, $user['full_name'], $ip));
log_activity($pdo, $user_id, 'LOGIN', 'Signed in through the ' . ($portal_key === 'faculty' ? 'Faculty' : 'Admin') . ' login page.', $user['role']);

session_regenerate_id(true);   // new session id on login (prevents session fixation)
unset($_SESSION['login_username'], $_SESSION['login_bad_fields'], $_SESSION['csrf_token']);   // new CSRF token for the new session too

// Store only what's needed in session — never the password hash
$_SESSION['user'] = [
    'user_id'   => (int)$user['user_id'],
    'role'      => $user['role'],
    'full_name' => $user['full_name'],
    'first_name' => $user['first_name'] ?? null,   // greetings: person_first_name()
    'username'  => $user['username'],
    'email'     => $user['email'],
    'profile_picture' => $user['profile_picture'] ?? null,   // header picture; updated when it's changed on My Profile
    // Temporary password from the Admin: require_login() allows only Change Password until it's replaced
    'must_change_password' => !empty($user['must_change_password']),
];

if ($_SESSION['user']['must_change_password']) {
    header('Location: ' . BASE_URL . '/change_password.php');
    exit;
}

redirect_to_dashboard();
