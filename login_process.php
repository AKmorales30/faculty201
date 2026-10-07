<?php
require_once __DIR__ . '/includes/auth.php';

// Maps each portal's form value to: the login page to bounce back to on
// failure, the session key used for that page's flash error, and a
// validator that checks the authenticated user actually belongs there.
// Faculty (full-time + part-time) share one portal; Admin, Program
// Chair, and Dean share the other. Employment type and the specific
// role (admin / program_chair / dean) still fully control what each
// account can see and do once inside — only the LOGIN SCREEN is shared.
$PORTALS = [
    'faculty' => [
        'page'       => 'login_faculty.php',
        'error_key'  => 'login_error_faculty',
        'validate'   => function (array $user): bool {
            return $user['role'] === 'faculty';
        },
    ],
    'admin' => [
        'page'       => 'login_admin.php',
        'error_key'  => 'login_error_admin',
        'validate'   => function (array $user): bool {
            return in_array($user['role'], ['admin', 'program_chair', 'dean'], true);
        },
    ],
];

$portal_key = $_POST['portal'] ?? '';
$portal = $PORTALS[$portal_key] ?? null;

// Fallback target if we don't recognize the portal (e.g. tampered form)
$fallback_page = $portal['page'] ?? 'index.php';
$error_key = $portal['error_key'] ?? 'login_error';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$email = mb_substr(mb_strtolower(trim($_POST['email'] ?? '')), 0, 150);   // the lockout key; fits login_attempts.email
$password = $_POST['password'] ?? '';
// The connecting address only: X-Forwarded-For can be set by anyone, so it
// isn't trusted (a known proxy in front of the app would have to be configured).
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

/**
 * Back to the login page with a message; the email is kept so it doesn't
 * have to be retyped. $bad_fields marks the email / password boxes red --
 * only when what was typed is wrong, not for a lock or a server problem.
 */
function login_fail(string $message, bool $bad_fields = false): void {
    global $error_key, $fallback_page, $email;
    $_SESSION[$error_key] = $message;
    $_SESSION['login_email'] = mb_substr($email, 0, 190);
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

const LOGIN_BAD_CREDENTIALS = 'Invalid email or password.';

if ($email === '' || $password === '') {
    login_fail('Please enter both your email and password.', true);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    login_fail(LOGIN_BAD_CREDENTIALS, true);
}

// Only what logging in can't do without -- the database, the account and its
// password -- stops it with the generic message; side tasks fail safe.
try {
    require_once __DIR__ . '/includes/functions.php';   // connects to the database
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
} catch (Throwable $e) {
    // Database or other server problem: log the details, show the user a plain message
    error_log('Login failed for ' . $email . ': ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    login_fail('We couldn\'t sign you in right now. Please try again in a moment.');
}
$user_id = $user ? (int)$user['user_id'] : null;

// Brute-force protection, checked BEFORE the password: while the email or
// this IP is locked, even the right password is refused. Locks apply to
// any email entered, so they don't reveal whether an account exists.
$locks = array_filter([
    login_side_task('account lock check', fn() => active_login_lock($pdo, 'account', $email)),
    login_side_task('IP lock check', fn() => active_login_lock($pdo, 'ip', $ip)),
]);
if ($locks) {
    login_side_task('recording the attempt', fn() => record_login_attempt($pdo, $email, $user_id, false, $ip, true));
    login_fail(login_lock_message(max(array_column($locks, 'seconds_left'))));
}

if (!$user || !password_verify($password, $user['password_hash'])) {
    login_side_task('recording the attempt', fn() => record_login_attempt($pdo, $email, $user_id, false, $ip));
    $attempts_left = null;
    $lock = login_side_task('lockout check', function () use ($pdo, $email, $ip, &$attempts_left) {
        return register_failed_login($pdo, $email, $ip, $attempts_left);
    });
    if ($lock) {
        login_fail(login_lock_message($lock['seconds_left']));
    }
    login_fail(LOGIN_BAD_CREDENTIALS
        . ($attempts_left !== null && $attempts_left > 0 && $attempts_left <= WARN_REMAINING_ATTEMPTS
            ? ' ' . $attempts_left . ' attempt' . ($attempts_left === 1 ? '' : 's') . ' left before login is locked for ' . LOCKOUT_MINUTES . ' minutes.'
            : ''), true);
}

// Confirm the account actually belongs to the portal it logged in through
if ($portal && !$portal['validate']($user)) {
    login_side_task('recording the attempt', fn() => record_login_attempt($pdo, $email, $user_id, false, $ip));
    login_fail('This account is not registered for this login portal. Please use the correct login page.');
}

// Successful login: log it, then screen for suspicious patterns (RBAC /
// suspicious-login-alerts module, Ch.3 3.1 of the capstone paper) before
// the session is established.
login_side_task('recording the login', fn() => record_login_attempt($pdo, $email, $user_id, true, $ip));
login_side_task('suspicious-login screening', fn() => flag_suspicious_login($pdo, $user_id, $user['full_name'], $ip));
log_activity($pdo, $user_id, 'LOGIN', 'Signed in through the ' . ($portal_key === 'faculty' ? 'Faculty' : 'Admin') . ' login page.', $user['role']);

// Keep the stored hash on PHP's current default algorithm / cost
if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    login_side_task('upgrading the password hash', fn() => $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), $user_id]));
}

session_regenerate_id(true);   // new session id on login (prevents session fixation)
unset($_SESSION['login_email'], $_SESSION['login_bad_fields'], $_SESSION['csrf_token']);   // new CSRF token for the new session too

// Store only what's needed in session — never the password hash
$_SESSION['user'] = [
    'user_id'   => (int)$user['user_id'],
    'role'      => $user['role'],
    'full_name' => $user['full_name'],
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