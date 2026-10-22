<?php
/**
 * Forgot password, on the landing page (POST + CSRF only):
 *   request       the username: a code goes to its verified email
 *   verify        the 6-digit code
 *   set_password  the new password (after a right code)
 *   resend        a new code, after OTP_RESEND_SECONDS
 *   cancel        back to the login form
 * Whether the username exists, or has a verified email, is never shown:
 * every username gets the same answer and the same code screen ("if this
 * account has a verified email, we've sent a code ... no email? contact the
 * administrator"); for one that doesn't, no code is sent and none works.
 * At most PASSWORD_RESET_MAX_PER_IP_HOUR requests per IP address an hour
 * send anything.
 */
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$reset = password_reset_pending();
$portal = $reset['portal'] ?? (in_array($_POST['portal'] ?? '', ['faculty', 'admin'], true) ? $_POST['portal'] : 'faculty');
$page = BASE_URL . '/index.php?role=' . $portal;

/** Back to the reset step with a message ($ok: a notice instead of an error). */
function reset_back(string $page, string $message = '', bool $ok = false): never {
    if ($message !== '') { $_SESSION[$ok ? 'step_notice' : 'step_error'] = $message; }
    header('Location: ' . $page);
    exit;
}

/** End the reset and go back to the login form with a message ($ok: a success notice). */
function reset_end(string $page, string $portal, string $message, bool $ok = false): never {
    unset($_SESSION['password_reset']);
    $_SESSION[($ok ? 'login_notice_' : 'login_error_') . $portal] = $message;
    header('Location: ' . $page);
    exit;
}

if (!csrf_valid()) {
    reset_back($page, 'Your session expired. Please try again.');
}
$action = (string)($_POST['action'] ?? '');
if ($action === 'cancel') {
    unset($_SESSION['password_reset']);
    header('Location: ' . $page);
    exit;
}

try {
    require_once __DIR__ . '/includes/functions.php';
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    // ----- the username -------------------------------------------------------
    if ($action === 'request') {
        $username = mb_substr(username_normalize((string)($_POST['username'] ?? '')), 0, 150);
        if ($username === '') {
            $_SESSION['step_view'] = 'reset_start';
            reset_back($page, 'Please enter your username.');
        }
        unset($_SESSION['pending_login']);
        $user = null;
        if (username_valid($username)) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 AND deleted_at IS NULL");
            $stmt->execute([$username]);
            $user = $stmt->fetch() ?: null;
        }
        $email = $user ? verified_email($user) : null;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE action = 'PASSWORD_RESET_REQUEST' AND ip_address = ? AND created_at >= ?");
        $stmt->execute([$ip, date('Y-m-d H:i:s', time() - 3600)]);
        $throttled = (int)$stmt->fetchColumn() >= PASSWORD_RESET_MAX_PER_IP_HOUR;

        $sent = ['ok' => false];
        if ($email !== null && !$throttled) {
            $sent = otp_issue($pdo, $user, 'reset_password', $email);
            if (!$sent['ok']) { error_log("Password reset code for {$username} not sent: {$sent['error']}"); }
        }
        log_activity($pdo, $user ? (int)$user['user_id'] : null, 'PASSWORD_RESET_REQUEST', "Forgot password for username \"{$username}\": "
            . match (true) {
                $sent['ok']     => 'code sent to ' . mask_email($email) . '.',
                $throttled      => 'nothing sent (too many requests from this IP address).',
                $user === null  => 'nothing sent (no such active account).',
                $email === null => 'nothing sent (no verified email -- the Admin has to reset the password).',
                default         => 'the code could not be sent.',
            }, $user['role'] ?? null);
        $_SESSION['password_reset'] = [
            'portal'   => $portal,
            'user_id'  => $sent['ok'] ? (int)$user['user_id'] : null,   // null: looks the same, but no code works
            'otp_id'   => $sent['otp_id'] ?? null,
            'step'     => 'code',
            'tries'    => 0,
            'sent_at'  => time(),
            'started'  => time(),
        ];
        $_SESSION['step_slide'] = true;
        reset_back($page);
    }

    if (!$reset) {
        reset_end($page, $portal, 'Your password reset timed out. Please start again.');
    }
    $user = $reset['user_id'] ? login_account($pdo, (int)$reset['user_id']) : null;

    // ----- a new code -----------------------------------------------------------
    if ($action === 'resend' && $reset['step'] === 'code') {
        $wait = $reset['sent_at'] + OTP_RESEND_SECONDS - time();
        if ($wait > 0) {
            reset_back($page, "Please wait {$wait} seconds before asking for a new code.");
        }
        if ($user && $reset['otp_id']) {
            $result = otp_resend($pdo, $user, (int)$reset['otp_id']);
            if (!$result['ok']) { error_log("Password reset code for {$user['username']} not resent: {$result['error']}"); }
        }
        $_SESSION['password_reset']['sent_at'] = time();
        reset_back($page, 'If this account has a verified email, we sent a new code to it.', true);
    }

    // ----- the code ---------------------------------------------------------------
    if ($action === 'verify' && $reset['step'] === 'code') {
        $left = null;
        if ($user && $reset['otp_id']) {
            $result = otp_check($pdo, (int)$reset['otp_id'], (int)$user['user_id'], 'reset_password', (string)($_POST['code'] ?? ''), $left);
        } else {   // no account behind it: every code is wrong, with the same tries
            $tries = ++$_SESSION['password_reset']['tries'];
            $left = OTP_MAX_ATTEMPTS - $tries;
            $result = $left > 0 ? 'wrong' : 'too_many';
        }
        if ($result === 'ok') {
            $_SESSION['password_reset']['step'] = 'password';
            reset_back($page);
        }
        $message = otp_retry_message($result, $left);
        if ($message !== null) {
            reset_back($page, $message);
        }
        if ($user) {
            log_activity($pdo, (int)$user['user_id'], 'LOGIN_CODE_FAILED', 'Too many wrong password reset codes -- the reset was stopped.', $user['role']);
        }
        reset_end($page, $portal, 'Too many wrong codes. Please start again.');
    }

    // ----- the new password -------------------------------------------------------
    if ($action === 'set_password' && $reset['step'] === 'password' && $user) {
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if ($rules = password_rule_errors($new)) {
            reset_back($page, password_rule_message($rules));
        }
        if (!hash_equals($new, $confirm)) {
            reset_back($page, 'The two passwords don\'t match.');
        }
        if (password_verify($new, $user['password_hash'])) {
            reset_back($page, 'Please choose a password different from your current one.');
        }
        $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = ? WHERE user_id = ?")
            ->execute([password_hash($new, PASSWORD_DEFAULT), date('Y-m-d H:i:s'), (int)$user['user_id']]);
        log_activity($pdo, (int)$user['user_id'], 'PASSWORD_RESET_SELF', 'Reset the password with a code sent to ' . mask_email((string)$user['email']) . '.', $user['role']);
        send_account_notice((string)$user['email'], 'Your password was changed', [
            "The password of {$user['username']} on the " . SYSTEM_NAME . ' was just reset with a code sent to this email.',
            "If this wasn't you, contact the administrator right away.",
        ]);
        reset_end($page, $portal, 'Your password has been reset. Log in with your new password.', true);
    }
} catch (Throwable $e) {
    error_log('Password reset failed: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    reset_back($page, 'Something went wrong. Please try again in a moment.');
}

reset_back($page);
