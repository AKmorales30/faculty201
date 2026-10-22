<?php
/**
 * The second step of logging in (includes/two_factor.php), after
 * login_process.php accepted the username and password. POST + CSRF only;
 * every answer goes back to the landing page, which shows the step:
 *   set_email     no verified email yet: send a code to this address
 *   other_email   (while verifying a new address) use a different one
 *   verify        the 6-digit code -- right: the email is verified if it was
 *                 new, and the login is finished (complete_login())
 *   resend        a new code, after OTP_RESEND_SECONDS
 *   cancel        back to the username / password form
 * Too many wrong codes ends the pending login: it counts as a failed login
 * (toward the lockout) and raises a Security Alert.
 */
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$pending = login_pending();
$portal = $pending['portal'] ?? (in_array($_POST['portal'] ?? '', ['faculty', 'admin'], true) ? $_POST['portal'] : 'faculty');
$page = BASE_URL . '/index.php?role=' . $portal;

/** Back to the login form (pending login ended) with a message. */
function verify_restart(string $page, string $portal, string $message): never {
    unset($_SESSION['pending_login']);
    $_SESSION['login_error_' . $portal] = $message;
    header('Location: ' . $page);
    exit;
}

/** Back to the step with a message ($ok: a notice instead of an error). */
function verify_back(string $page, string $message = '', bool $ok = false): never {
    if ($message !== '') { $_SESSION[$ok ? 'step_notice' : 'step_error'] = $message; }
    header('Location: ' . $page);
    exit;
}

if (!csrf_valid()) {
    verify_back($page, 'Your session expired. Please try again.');
}
if (!$pending) {
    verify_restart($page, $portal, 'Your login timed out. Please log in again.');
}
$action = (string)($_POST['action'] ?? '');
if ($action === 'cancel') {
    unset($_SESSION['pending_login']);
    header('Location: ' . $page);
    exit;
}

try {
    require_once __DIR__ . '/includes/functions.php';
    $user = login_account($pdo, (int)$pending['user_id']);
    if (!$user) {
        verify_restart($page, $portal, 'This account can no longer log in. Please contact the administrator.');
    }
    $role = $user['role'];

    // ----- no verified email yet: the address to verify -------------------
    if ($action === 'set_email' && $pending['step'] === 'email') {
        $email = email_normalize((string)($_POST['email'] ?? ''));
        if (!email_valid($email)) {
            verify_back($page, 'Please enter a valid email address.');
        }
        if (email_taken($pdo, $email, (int)$user['user_id'])) {
            verify_back($page, 'That email is already used by another account. Please use a different one.');
        }
        $sent = otp_issue($pdo, $user, 'setup_email', $email);
        if (!$sent['ok']) {
            verify_back($page, $sent['error']);
        }
        $_SESSION['pending_login'] = ['step' => 'code', 'purpose' => 'setup_email', 'otp_id' => $sent['otp_id'],
                                      'email' => $email, 'sent_at' => time()] + $pending;
        log_activity($pdo, (int)$user['user_id'], 'EMAIL_CODE_SENT', 'First login: verification code sent to ' . mask_email($email) . '.', $role);
        verify_back($page);
    }

    if ($action === 'other_email' && $pending['purpose'] === 'setup_email') {
        otp_cancel($pdo, $pending['otp_id']);
        $_SESSION['pending_login'] = ['step' => 'email', 'otp_id' => null, 'email' => null] + $pending;
        verify_back($page);
    }

    if ($pending['step'] !== 'code' || !$pending['otp_id']) {
        verify_back($page);
    }

    // ----- a new code -------------------------------------------------------
    if ($action === 'resend') {
        $result = otp_resend($pdo, $user, (int)$pending['otp_id']);
        if (!$result['ok']) {
            verify_back($page, $result['error']);
        }
        $_SESSION['pending_login']['sent_at'] = time();
        log_activity($pdo, (int)$user['user_id'], $pending['purpose'] === 'login' ? 'LOGIN_CODE_SENT' : 'EMAIL_CODE_SENT',
            'New code sent to ' . mask_email($pending['email']) . ' (resend).', $role);
        verify_back($page, 'We sent a new code to ' . mask_email($pending['email']) . '.', true);
    }

    // ----- the code ---------------------------------------------------------
    if ($action === 'verify') {
        $left = null;
        $result = otp_check($pdo, (int)$pending['otp_id'], (int)$user['user_id'], $pending['purpose'], (string)($_POST['code'] ?? ''), $left);
        if ($result === 'ok') {
            if ($pending['purpose'] === 'setup_email') {
                if (email_taken($pdo, $pending['email'], (int)$user['user_id'])) {   // confirmed by another account meanwhile
                    $_SESSION['pending_login'] = ['step' => 'email', 'otp_id' => null, 'email' => null] + $pending;
                    verify_back($page, 'That email is already used by another account. Please use a different one.');
                }
                set_verified_email($pdo, (int)$user['user_id'], $pending['email']);
                log_activity($pdo, (int)$user['user_id'], 'EMAIL_VERIFIED', 'Two-factor email set up and verified: ' . mask_email($pending['email']) . '.', $role);
                $user = login_account($pdo, (int)$user['user_id']);
            }
            complete_login($pdo, $user, $portal);
        }
        $message = otp_retry_message($result, $left);
        if ($message !== null) {
            verify_back($page, $message);
        }
        // Out of tries: start over, and it counts as a failed login
        login_side_task('recording the attempt', fn() => record_login_attempt($pdo, (string)$user['username'], (int)$user['user_id'], false,
                                                                             $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        $what = $pending['purpose'] === 'login' ? 'login code' : 'email verification code';
        log_activity($pdo, (int)$user['user_id'], 'LOGIN_CODE_FAILED', "Too many wrong {$what}s for " . mask_email($pending['email'])
            . ' -- the login was stopped.', $role);
        if ($pending['purpose'] === 'login') {
            raise_security_alert($pdo, $user, OTP_MAX_ATTEMPTS . ' wrong login codes after the right password -- someone may know the password.');
        }
        verify_restart($page, $portal, 'Too many wrong codes. Please log in again.');
    }
} catch (Throwable $e) {
    error_log('Login verification failed: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    verify_back($page, 'Something went wrong. Please try again in a moment.');
}

verify_back($page);
