<?php
/**
 * Optional two-factor authentication. After the right username and password
 * (login_process.php):
 *   - 2FA on (users.two_factor_enabled, verified email): a 6-digit code goes
 *     to the verified email, and the session is only created once it's
 *     entered (login_verify.php);
 *   - no verified email: "Set up your email" (Set up now / Not now). Set up
 *     now: email -> code -> "Turn on two-factor authentication?". Not now:
 *     straight in, asked again after EMAIL_PROMPT_REMIND_DAYS;
 *   - otherwise: straight in.
 * REQUIRE_2FA_FOR_ADMINS makes it compulsory for Admin accounts. The same
 * codes verify a new email and turn 2FA on (profile.php) and reset a
 * forgotten password (forgot_password.php).
 *
 * Codes (login_otps): random, stored only as a password_hash(), working
 * once, for OTP_TTL_SECONDS and OTP_MAX_ATTEMPTS tries; a new one can be sent
 * after OTP_RESEND_SECONDS, at most OTP_MAX_SENDS_PER_HOUR per account per
 * hour, and sending one cancels the earlier code for the same purpose.
 * Times are compared in PHP (Asia/Manila, as stored).
 */
require_once __DIR__ . '/mailer.php';

// ---------------------------------------------------------------------
// Two-factor email addresses
// ---------------------------------------------------------------------

function email_normalize(string $email): string {
    return mb_strtolower(trim($email));
}

function email_valid(string $email): bool {
    return $email !== '' && mb_strlen($email) <= 150 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Whether another account already has $email as its verified two-factor email (emails can't be shared). */
function email_taken(PDO $pdo, string $email, int $except_user_id = 0): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(email) = ? AND user_id <> ? AND email_verified_at IS NOT NULL");
    $stmt->execute([email_normalize($email), $except_user_id]);
    return (bool)$stmt->fetchColumn();
}

/** The account's verified two-factor email, or null. */
function verified_email(array $user): ?string {
    return !empty($user['email']) && !empty($user['email_verified_at']) ? (string)$user['email'] : null;
}

/**
 * Make $email the account's verified two-factor email. An address another
 * account has only unverified (typed in by the Admin, never confirmed) is
 * taken off that account first, as it evidently isn't theirs.
 */
function set_verified_email(PDO $pdo, int $user_id, string $email): void {
    $email = email_normalize($email);
    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }
    try {
        $pdo->prepare("UPDATE users SET email = NULL WHERE LOWER(email) = ? AND user_id <> ? AND email_verified_at IS NULL")
            ->execute([$email, $user_id]);
        $pdo->prepare("UPDATE users SET email = ?, email_verified_at = ? WHERE user_id = ?")
            ->execute([$email, date('Y-m-d H:i:s'), $user_id]);
        if ($own) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/** Whether this account must use two-factor authentication (REQUIRE_2FA_FOR_ADMINS). */
function two_factor_required(array $user): bool {
    return REQUIRE_2FA_FOR_ADMINS && ($user['role'] ?? '') === 'admin';
}

/**
 * Whether logging in needs an emailed code: a verified email, and 2FA turned
 * on (or required). Before database/supabase/06_optional_two_factor.sql has
 * run (no two_factor_enabled column), a verified email means on, as before.
 */
function two_factor_on(array $user): bool {
    if (verified_email($user) === null) {
        return false;
    }
    return !array_key_exists('two_factor_enabled', $user) || !empty($user['two_factor_enabled']) || two_factor_required($user);
}

/** Whether to show "Set up your email" after the password: no verified email, and no "Not now" in the last EMAIL_PROMPT_REMIND_DAYS. */
function email_prompt_due(array $user): bool {
    if (verified_email($user) !== null) {
        return false;
    }
    if (two_factor_required($user) || empty($user['email_prompt_dismissed_at'])) {
        return true;
    }
    return time() - strtotime($user['email_prompt_dismissed_at']) >= EMAIL_PROMPT_REMIND_DAYS * 86400;
}

/**
 * Whether the database has the 2FA on/off columns (database/supabase/06_optional_two_factor.sql
 * has run). $user: a full users row (SELECT *). Until then 2FA can't be turned on or off.
 */
function two_factor_ready(array $user): bool {
    return array_key_exists('two_factor_enabled', $user);
}

const TWO_FACTOR_NOT_READY = 'Two-factor authentication can\'t be turned on or off yet: the database update for it (06_optional_two_factor.sql) hasn\'t been run. Please contact the administrator.';

/** Turn two-factor authentication on or off for an account (needs two_factor_ready()). */
function set_two_factor(PDO $pdo, int $user_id, bool $on): void {
    $pdo->prepare("UPDATE users SET two_factor_enabled = ? WHERE user_id = ?")->execute([$on ? 1 : 0, $user_id]);
}

// ---------------------------------------------------------------------
// One-time codes
// ---------------------------------------------------------------------

/** What each kind of code is for, in the email. */
function otp_email(string $purpose, string $code, array $user): array {
    $minutes = (int)ceil(OTP_TTL_SECONDS / 60);
    $expires = "The code expires in {$minutes} minutes and works only once.";
    return match ($purpose) {
        'login' => ['Your login code', email_bodies('Your login code', [
            "Enter this code to finish logging in to the " . SYSTEM_NAME . " as {$user['username']}.", $expires,
            "If you didn't just try to log in, someone may know your password: change it right away and tell the administrator.",
        ], $code)],
        'setup_email', 'change_email' => ['Verify your email', email_bodies('Verify your email', [
            "Enter this code to confirm this address for {$user['username']} on the " . SYSTEM_NAME
                . '. Once confirmed, it can receive your login codes and password reset codes.', $expires,
            "If you didn't ask for this, you can ignore this email.",
        ], $code)],
        'enable_2fa' => ['Turn on two-factor authentication', email_bodies('Turn on two-factor authentication', [
            "Enter this code to turn on two-factor authentication for {$user['username']} on the " . SYSTEM_NAME
                . '. After that, each login also needs a code sent to this email.', $expires,
            "If you didn't ask for this, someone may know your password: change it right away and tell the administrator.",
        ], $code)],
        'reset_password' => ['Reset your password', email_bodies('Reset your password', [
            "Enter this code to set a new password for {$user['username']} on the " . SYSTEM_NAME . '.', $expires,
            "If you didn't ask to reset your password, ignore this email -- your password stays the same.",
        ], $code)],
    };
}

/** Emails sent to the account in the last hour (each send of each code counts). */
function otp_sends_last_hour(PDO $pdo, int $user_id): int {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(sent_count), 0) FROM login_otps WHERE user_id = ? AND last_sent_at >= ?");
    $stmt->execute([$user_id, date('Y-m-d H:i:s', time() - 3600)]);
    return (int)$stmt->fetchColumn();
}

const OTP_TOO_MANY_SENDS = 'Too many codes were sent to this account in the last hour. Please try again later.';
const OTP_SEND_FAILED = "We couldn't send the email right now. Please try again in a moment. If it keeps failing, contact the administrator.";

/**
 * Send a new code for $purpose to $email; the account's earlier code for the
 * same purpose stops working.
 * @return array{ok: bool, otp_id?: int, error?: string}
 */
function otp_issue(PDO $pdo, array $user, string $purpose, string $email): array {
    $user_id = (int)$user['user_id'];
    if (otp_sends_last_hour($pdo, $user_id) >= OTP_MAX_SENDS_PER_HOUR) {
        return ['ok' => false, 'error' => OTP_TOO_MANY_SENDS];
    }
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE login_otps SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL")->execute([$now, $user_id, $purpose]);
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare(
        "INSERT INTO login_otps (user_id, purpose, email, code_hash, ip_address, last_sent_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)"
    )->execute([$user_id, $purpose, $email, password_hash($code, PASSWORD_DEFAULT), client_ip(),
                $now, date('Y-m-d H:i:s', time() + OTP_TTL_SECONDS)]);
    $otp_id = (int)$pdo->lastInsertId();
    [$subject, [$text, $html]] = otp_email($purpose, $code, $user);
    if (!send_email($email, $subject, $text, $html)) {
        $pdo->prepare("UPDATE login_otps SET used_at = ? WHERE id = ?")->execute([$now, $otp_id]);
        return ['ok' => false, 'error' => OTP_SEND_FAILED];
    }
    return ['ok' => true, 'otp_id' => $otp_id];
}

/**
 * Send a fresh code in place of code $otp_id (same purpose and address),
 * once OTP_RESEND_SECONDS have passed since the last one.
 * @return array{ok: bool, error?: string, wait?: int}
 */
function otp_resend(PDO $pdo, array $user, int $otp_id): array {
    $stmt = $pdo->prepare("SELECT * FROM login_otps WHERE id = ? AND user_id = ?");
    $stmt->execute([$otp_id, (int)$user['user_id']]);
    $otp = $stmt->fetch();
    if (!$otp || $otp['used_at'] !== null) {
        return ['ok' => false, 'error' => 'This code is no longer valid. Please start again.'];
    }
    $wait = strtotime($otp['last_sent_at']) + OTP_RESEND_SECONDS - time();
    if ($wait > 0) {
        return ['ok' => false, 'error' => "Please wait {$wait} seconds before asking for a new code.", 'wait' => $wait];
    }
    if (otp_sends_last_hour($pdo, (int)$user['user_id']) >= OTP_MAX_SENDS_PER_HOUR) {
        return ['ok' => false, 'error' => OTP_TOO_MANY_SENDS];
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $now = time();
    $pdo->prepare(
        "UPDATE login_otps SET code_hash = ?, attempts = 0, sent_count = sent_count + 1, last_sent_at = ?, expires_at = ?
         WHERE id = ? AND used_at IS NULL"
    )->execute([password_hash($code, PASSWORD_DEFAULT), date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + OTP_TTL_SECONDS), $otp_id]);
    [$subject, [$text, $html]] = otp_email($otp['purpose'], $code, $user);
    if (!send_email($otp['email'], $subject, $text, $html)) {
        return ['ok' => false, 'error' => OTP_SEND_FAILED];
    }
    return ['ok' => true];
}

/**
 * Check a code typed for code $otp_id. Each check uses up one try, counted
 * before comparing (two requests at once can't get extra tries).
 * @return string 'ok' (now used up), 'wrong' ($left tries remain), 'expired',
 *                'too_many' (no tries left) or 'invalid' (no such open code)
 */
function otp_check(PDO $pdo, int $otp_id, int $user_id, string $purpose, string $code, ?int &$left = null): string {
    $stmt = $pdo->prepare("SELECT * FROM login_otps WHERE id = ? AND user_id = ? AND purpose = ?");
    $stmt->execute([$otp_id, $user_id, $purpose]);
    $otp = $stmt->fetch();
    if (!$otp || $otp['used_at'] !== null) {
        return 'invalid';
    }
    if ((int)$otp['attempts'] >= OTP_MAX_ATTEMPTS) {
        return 'too_many';
    }
    if (strtotime($otp['expires_at']) <= time()) {
        return 'expired';
    }
    $try = $pdo->prepare("UPDATE login_otps SET attempts = attempts + 1 WHERE id = ? AND used_at IS NULL AND attempts < ?");
    $try->execute([$otp_id, OTP_MAX_ATTEMPTS]);
    if ($try->rowCount() !== 1) {
        return 'too_many';
    }
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) === 6 && password_verify($code, $otp['code_hash'])) {
        $use = $pdo->prepare("UPDATE login_otps SET used_at = ? WHERE id = ? AND used_at IS NULL");
        $use->execute([date('Y-m-d H:i:s'), $otp_id]);
        return $use->rowCount() === 1 ? 'ok' : 'invalid';
    }
    $left = OTP_MAX_ATTEMPTS - (int)$otp['attempts'] - 1;
    return $left > 0 ? 'wrong' : 'too_many';
}

/** Stop code $otp_id from working (cancelled, or another address chosen). */
function otp_cancel(PDO $pdo, ?int $otp_id): void {
    if ($otp_id) {
        $pdo->prepare("UPDATE login_otps SET used_at = ? WHERE id = ? AND used_at IS NULL")->execute([date('Y-m-d H:i:s'), $otp_id]);
    }
}

/** The message for a failed otp_check() that still lets the person try again (null when they must start over). */
function otp_retry_message(string $result, ?int $left): ?string {
    return match ($result) {
        'wrong'   => "That code isn't right. " . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.',
        'expired' => 'This code has expired. Please ask for a new code.',
        default   => null,
    };
}

// ---------------------------------------------------------------------
// Finishing a login
// ---------------------------------------------------------------------

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

/** Where a portal's login form is, for redirects. */
function login_page_url(string $portal): string {
    return BASE_URL . '/index.php?role=' . (in_array($portal, ['faculty', 'admin'], true) ? $portal : 'faculty');
}

/**
 * The login is accepted (the password, and the code when 2FA is on): record
 * it, screen it, start the session and go to the dashboard (or Change
 * Password, for a temporary password). $how says how, for the activity log.
 */
function complete_login(PDO $pdo, array $user, string $portal, string $how = ''): never {
    $ip = client_ip();
    $user_id = (int)$user['user_id'];
    login_side_task('recording the login', fn() => record_login_attempt($pdo, (string)$user['username'], $user_id, true, $ip));
    login_side_task('suspicious-login screening', fn() => flag_suspicious_login($pdo, $user_id, $user['full_name'], $ip));
    log_activity($pdo, $user_id, 'LOGIN', 'Signed in through the ' . ($portal === 'faculty' ? 'Faculty' : 'Admin') . ' login page'
        . ($how !== '' ? ' ' . $how : '') . '.', $user['role']);
    // Login Activity (includes/login_activity.php): the successful login, then this browser
    // becomes / stays a trusted device (new-device alerts only -- it never skips the code)
    login_history_record($pdo, $user_id, 'success', trusted_device_current($pdo, $user_id) === null);
    trusted_device_remember($pdo, $user_id, device_label());

    session_regenerate_id(true);   // new session id on login (prevents session fixation)
    unset($_SESSION['pending_login'], $_SESSION['password_reset'], $_SESSION['login_username'], $_SESSION['login_bad_fields'],
          $_SESSION['csrf_token'], $_SESSION['step_error'], $_SESSION['step_notice'], $_SESSION['step_slide'],
          $_SESSION['hide_security_banner']);   // new CSRF token for the new session too

    // Store only what's needed in session -- never the password hash
    $_SESSION['user'] = [
        'user_id'   => $user_id,
        'role'      => $user['role'],
        'full_name' => $user['full_name'],
        'first_name' => $user['first_name'] ?? null,   // greetings: person_first_name()
        'username'  => $user['username'],
        'email'     => verified_email($user),           // the verified email, or null (dashboard banner)
        'profile_picture' => $user['profile_picture'] ?? null,   // header picture; updated when it's changed on My Profile
        // Temporary password from the Admin: require_login() allows only Change Password until it's replaced
        'must_change_password' => !empty($user['must_change_password']),
    ];

    if ($_SESSION['user']['must_change_password']) {
        header('Location: ' . BASE_URL . '/change_password.php');
        exit;
    }
    redirect_to_dashboard();
}

/** The account a pending login / reset belongs to, if it may still log in (not deactivated or deleted). */
function login_account(PDO $pdo, int $user_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND is_active = 1 AND deleted_at IS NULL");
    $stmt->execute([$user_id]);
    return $stmt->fetch() ?: null;
}

/** A Security Alert (admin/security_alerts.php) for an account, and a notification to every Admin. */
function raise_security_alert(PDO $pdo, array $user, string $reason): void {
    try {
        $pdo->prepare("INSERT INTO security_alerts (user_id, reason, ip_address) VALUES (?, ?, ?)")
            ->execute([(int)$user['user_id'], $reason, client_ip()]);
        notify_role($pdo, 'admin', "Security alert for {$user['full_name']}: {$reason}");
    } catch (Throwable $e) {
        error_log('Security alert failed: ' . $e->getMessage());
    }
}

/** Tell an account by email that something about its login changed (password, email, two-factor). Never throws. */
function send_account_notice(string $to, string $heading, array $paragraphs): void {
    [$text, $html] = email_bodies($heading, $paragraphs);
    send_email($to, $heading, $text, $html);
}
