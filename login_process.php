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

/** Back to the login page with a message; the email is kept so it doesn't have to be retyped. */
function login_fail(string $message): void {
    global $error_key, $fallback_page, $email;
    $_SESSION[$error_key] = $message;
    $_SESSION['login_email'] = mb_substr($email, 0, 190);
    header('Location: ' . BASE_URL . '/' . $fallback_page);
    exit;
}

if ($email === '' || $password === '') {
    login_fail('Please enter both your email and password.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    login_fail('Incorrect email or password. Please try again.');
}

try {
    require_once __DIR__ . '/includes/functions.php';   // connects to the database
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Brute-force protection, checked BEFORE the password: while the email or
    // this IP is locked, even the right password is refused. Locks apply to
    // any email entered, so they don't reveal whether an account exists.
    $locks = array_filter([active_login_lock($pdo, 'account', $email), active_login_lock($pdo, 'ip', $ip)]);
    if ($locks) {
        record_login_attempt($pdo, $email, $user['user_id'] ?? null, false, $ip, true);
        login_fail(login_lock_message(max(array_column($locks, 'seconds_left'))));
    }

    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_login_attempt($pdo, $email, $user['user_id'] ?? null, false, $ip);
        $lock = register_failed_login($pdo, $email, $ip, $attempts_left);
        if ($lock) {
            login_fail(login_lock_message($lock['seconds_left']));
        }
        login_fail('Incorrect email or password. Please try again.'
            . ($attempts_left > 0 && $attempts_left <= WARN_REMAINING_ATTEMPTS
                ? ' ' . $attempts_left . ' attempt' . ($attempts_left === 1 ? '' : 's') . ' left before login is locked for ' . LOCKOUT_MINUTES . ' minutes.'
                : ''));
    }

    // Confirm the account actually belongs to the portal it logged in through
    if ($portal && !$portal['validate']($user)) {
        record_login_attempt($pdo, $email, (int)$user['user_id'], false, $ip);
        login_fail('This account is not registered for this login portal. Please use the correct login page.');
    }

    // Successful login: log it, then screen for suspicious patterns (RBAC /
    // suspicious-login-alerts module, Ch.3 3.1 of the capstone paper) before
    // the session is established.
    record_login_attempt($pdo, $email, (int)$user['user_id'], true, $ip);
    flag_suspicious_login($pdo, (int)$user['user_id'], $user['full_name'], $ip);
    log_activity($pdo, (int)$user['user_id'], 'LOGIN', 'Signed in through the ' . ($portal_key === 'faculty' ? 'Faculty' : 'Admin') . ' login page.', $user['role']);

    // Keep the stored hash on PHP's current default algorithm / cost
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['user_id']]);
    }
} catch (Throwable $e) {
    // Database or other server problem: log the details, show the user a plain message
    error_log('Login failed for ' . $email . ': ' . $e->getMessage());
    login_fail('We couldn\'t sign you in right now. Please try again in a moment.');
}

session_regenerate_id(true);   // new session id on login (prevents session fixation)
unset($_SESSION['login_email'], $_SESSION['csrf_token']);   // new CSRF token for the new session too

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