<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
ensure_usernames($pdo);   // accounts from before usernames get one (first.last), shown below

$page_title = 'Manage Faculty';
$me = current_user();
$action = $_POST['action'] ?? '';

// Account status: only Active (can log in) and Deactivated (cannot) -- users.is_active.
// Employment pause, a temporary password and a login lockout are not account
// statuses: they show as small notes under the status.
// The list's filters (role, status) are kept across every action: forms post to the same URL.
$role_filter = in_array($_GET['role'] ?? '', ['admin', 'faculty', 'program_chair', 'dean'], true) ? $_GET['role'] : '';
$status_filter = in_array($_GET['status'] ?? '', ['active', 'deactivated'], true) ? $_GET['status'] : '';
$view = ($_GET['view'] ?? '') === 'deleted' ? 'deleted' : 'accounts';   // Deleted accounts: restorable for DELETED_RETENTION_DAYS
$list_query = http_build_query(array_filter(['view' => $view === 'deleted' ? 'deleted' : null, 'role' => $role_filter, 'status' => $status_filter]));
$list_url = BASE_URL . '/admin/manage_faculty.php' . ($list_query !== '' ? '?' . $list_query : '');

// Every action is a POST with the CSRF token (create / reset / unlock also say so in their own messages)
if (in_array($action, ['edit_account', 'toggle_active', 'pause', 'resume', 'delete_account', 'restore_account', 'reset_2fa_email'], true) && !csrf_valid()) {
    $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    header('Location: ' . $list_url);
    exit;
}

/**
 * Program / college / employment type an account of this role should have.
 * Faculty and Program Chairs belong to a program (the college follows from
 * it); Deans to a college. Upload notifications are scoped by these.
 * Returns [employment_type, program, college] or an error message.
 */
function account_assignment(string $role, array $in) {
    if ($role === 'admin') { return [null, null, null]; }
    $emp = in_array($in['employment_type'] ?? '', ['full_time', 'part_time'], true) ? $in['employment_type'] : null;
    if ($role === 'faculty' && !$emp) { return 'Please select an employment type for a faculty account.'; }
    if ($role === 'dean') {
        $college = $in['college'] ?? '';
        if (!isset(COLLEGES[$college])) { return 'Please select the Dean\'s college.'; }
        return [$emp, null, $college];
    }
    $program = only_program() ?? ($in['program'] ?? '');   // one program: always that one (also replaces an old BSCS)
    if (!isset(PROGRAMS[$program])) { return 'Please select the ' . ($role === 'faculty' ? 'faculty member\'s' : 'Program Chair\'s') . ' program.'; }
    return [$emp, $program, PROGRAMS[$program]['college']];
}

/** "account #12 Juan Dela Cruz (faculty, username juan.delacruz)" -- which account an activity-log entry is about. */
function account_log_name(array $u): string {
    return "account #{$u['user_id']} {$u['full_name']} (" . str_replace('_', ' ', $u['role']) . ', username ' . ($u['username'] ?? '') . ')';
}

/** The account's 2FA status: [label, badge class] -- On, Off, or No email (no verified email yet). */
function account_2fa_status(array $u): array {
    if (verified_email($u) === null) { return ['No email', 'bg-light text-muted border']; }
    return two_factor_on($u) ? ['On', 'bg-success'] : ['Off', 'bg-secondary'];
}

/** The email and 2FA status in the account list: verified, typed in but not verified, or none yet. */
function account_email_line(array $u): string {
    [$label, $class] = account_2fa_status($u);
    $badge = ' <span class="badge ' . $class . '" title="Two-factor authentication">2FA: ' . $label . '</span>';
    if (!empty($u['email_verified_at'])) {
        return '<i class="fa-solid fa-envelope-circle-check text-success" aria-hidden="true"></i><span class="visually-hidden">Verified email:</span> ' . h($u['email']) . $badge;
    }
    if (!empty($u['email'])) {
        return '<i class="fa-regular fa-envelope" aria-hidden="true"></i> ' . h($u['email']) . ' <span class="badge bg-light text-muted border">not verified</span>' . $badge;
    }
    return '<i class="fa-regular fa-envelope" aria-hidden="true"></i> No email set up' . $badge;
}

/** users row for an activity-log entry, or null. */
function account_for_log(PDO $pdo, int $user_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");   // * : two_factor_enabled may not be migrated yet
    $stmt->execute([$user_id]);
    return $stmt->fetch() ?: null;
}

/** Admin accounts not deleted -- the last one can't be deleted. */
function remaining_admin_count(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND deleted_at IS NULL")->fetchColumn();
}

// ---------------------------------------------------------------------
// Edit an account (the Edit account pop-up): name (title, first, middle,
// last, suffix), username, employment type, program / college, academic rank,
// date hired and employee ID. users.full_name is rebuilt from the name
// parts (person_full_name()); every page joins users by user_id, so the new
// name shows everywhere at once -- the account holder's own top bar too
// (includes/auth.php refreshes it on every request). Logged with old ->
// new values; the account holder is notified. The email and two-factor
// authentication aren't edited here: the account holder sets them (Reset
// email / 2FA clears them, below).
// ---------------------------------------------------------------------
if ($action === 'edit_account') {
    $target = user_row($pdo, (int)($_POST['user_id'] ?? 0));
    if (!$target || !empty($target['deleted_at'])) {
        $_SESSION['flash_error'] = 'That account could not be found.';
        header('Location: ' . $list_url);
        exit;
    }
    $name = person_name_input($_POST);
    $username = username_normalize((string)($_POST['username'] ?? ''));
    $in = $_POST;
    if ($target['role'] === 'admin') { $in['employment_type'] = ''; }
    $assignment = account_assignment($target['role'], $in);
    $rank = trim((string)($_POST['academic_rank'] ?? ''));
    $hired = trim((string)($_POST['date_engaged'] ?? ''));
    $hired_dt = DateTime::createFromFormat('!Y-m-d', $hired);
    $employee_id = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['employee_id'] ?? ''))), 0, 30);

    $error = match (true) {
        is_string($name)                                         => $name,
        !username_valid($username)                               => USERNAME_RULE,
        username_taken($pdo, $username, (int)$target['user_id']) => 'Another account already uses that username.',
        is_string($assignment)                                   => $assignment,
        $rank !== '' && !in_array($rank, academic_rank_options($pdo, $target['academic_rank']), true) => 'Please choose an academic rank from the list.',
        $hired !== '' && (!$hired_dt || $hired_dt->format('Y-m-d') !== $hired || $hired > date('Y-m-d', strtotime('+1 year'))) => 'Please enter a valid date hired.',
        $employee_id !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]*$/', $employee_id) => 'The employee ID may only contain letters, digits, spaces and hyphens.',
        default                                                  => null,
    };
    if ($error !== null) {
        $_SESSION['flash_error'] = $error;
        header('Location: ' . $list_url);
        exit;
    }
    [$emp, $program, $college] = $assignment;
    $new = $name + ['full_name' => person_full_name($name), 'username' => $username, 'employment_type' => $emp, 'program' => $program, 'college' => $college,
                    'academic_rank' => $rank !== '' ? $rank : null, 'date_engaged' => $hired !== '' ? $hired : null,
                    'employee_id' => $employee_id !== '' ? $employee_id : null];
    // What changed, old -> new, for the activity log and the notification
    $old_parts = person_name_parts($target);
    $labels = ['full_name' => 'Name', 'username' => 'Username', 'employment_type' => 'Employment type', 'program' => 'Program', 'college' => 'College',
               'academic_rank' => 'Academic rank', 'date_engaged' => 'Date hired', 'employee_id' => 'Employee ID'];
    $show = fn(string $col, $v) => $v === null || $v === '' ? '(none)' : match ($col) {
        'employment_type' => employment_type_label($v), 'program' => PROGRAMS[$v]['label'] ?? $v, 'college' => COLLEGES[$v] ?? $v, default => (string)$v,
    };
    $changes = [];
    foreach ($labels as $col => $label) {
        if ((string)$target[$col] !== (string)$new[$col]) {
            $changes[$label] = [$show($col, $target[$col]), $show($col, $new[$col])];
        }
    }
    if ($old_parts['middle_name'] !== $name['middle_name'] && !isset($changes['Name'])) {   // e.g. "S." -> "Santos": same initial
        $changes['Middle name'] = [$old_parts['middle_name'] ?: '(none)', $name['middle_name'] ?: '(none)'];
    }
    $parts_saved = trim((string)($target['first_name'] ?? '')) !== '';
    if (!$changes && $parts_saved) {
        $_SESSION['flash_success'] = 'Nothing was changed.';
        header('Location: ' . $list_url);
        exit;
    }
    $pdo->prepare(
        "UPDATE users SET name_title = ?, first_name = ?, middle_name = ?, last_name = ?, name_suffix = ?, full_name = ?, username = ?,
                          employment_type = ?, program = ?, college = ?, academic_rank = ?, date_engaged = ?, employee_id = ?
         WHERE user_id = ?"
    )->execute([
        $name['name_title'] ?: null, $name['first_name'], $name['middle_name'] ?: null, $name['last_name'], $name['name_suffix'] ?: null,
        $new['full_name'], $username, $emp, $program, $college, $new['academic_rank'], $new['date_engaged'], $new['employee_id'], $target['user_id'],
    ]);
    if (isset($changes['Username'])) {
        log_my_activity($pdo, 'USERNAME_CHANGE', 'Changed the username of ' . account_log_name($target) . " from \"{$target['username']}\" to \"{$username}\".");
        if ((int)$target['user_id'] === (int)$me['user_id']) { $_SESSION['user']['username'] = $username; }
    }
    if ($changes) {
        log_my_activity($pdo, 'ACCOUNT_UPDATE', 'Updated ' . account_log_name($target) . ' -- '
            . describe_filters(array_map(fn($c) => $c[0] . ' -> ' . $c[1], $changes)) . '.');
        notify($pdo, (int)$target['user_id'], 'The Admin updated your account details: '
            . implode('; ', array_map(fn($k, $c) => "{$k}: {$c[1]}", array_keys($changes), $changes)) . '.');
    }
    $_SESSION['flash_success'] = 'Updated ' . $new['full_name'] . '.';
    header('Location: ' . $list_url);
    exit;
}

// ---------------------------------------------------------------------
// Create account -- "Accounts are created by the Admin" (every login page)
// ---------------------------------------------------------------------
if ($action === 'create') {
    $name      = person_name_input($_POST);
    $full_name = is_array($name) ? person_full_name($name) : '';
    $username  = username_normalize((string)($_POST['username'] ?? ''));
    $email     = email_normalize((string)($_POST['email'] ?? ''));   // optional: the account holder verifies their own at first login
    $role      = $_POST['role'] ?? '';
    $emp_type  = $_POST['employment_type'] ?? null;
    $password  = $_POST['password'] ?? '';

    $valid_roles = ['admin', 'faculty', 'program_chair', 'dean'];
    $assignment = in_array($role, $valid_roles, true) ? account_assignment($role, $_POST) : null;
    if (!csrf_valid()) {
        $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    } elseif (is_string($name)) {
        $_SESSION['flash_error'] = $name;
    } elseif ($username === '' || !in_array($role, $valid_roles, true) || $password === '') {
        $_SESSION['flash_error'] = 'Please fill in all required fields.';
    } elseif (!username_valid($username)) {
        $_SESSION['flash_error'] = USERNAME_RULE;
    } elseif ($email !== '' && !email_valid($email)) {
        $_SESSION['flash_error'] = 'Please enter a valid email address, or leave it blank.';
    } elseif ($rules = password_rule_errors($password)) {
        $_SESSION['flash_error'] = 'Temporary password: ' . lcfirst(password_rule_message($rules));
    } elseif (is_string($assignment)) {
        $_SESSION['flash_error'] = $assignment;
    } else {
        [$emp_type, $program, $college] = $assignment;
        $dupe = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(email) = ?");
        $dupe->execute([$email]);
        if (username_taken($pdo, $username)) {
            $_SESSION['flash_error'] = 'An account with that username already exists.';
        } elseif ($email !== '' && $dupe->fetchColumn()) {
            $_SESSION['flash_error'] = 'Another account already uses that email address.';
        } else {
            // A temporary password: the user must replace it at their first login
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                "INSERT INTO users (role, full_name, name_title, first_name, middle_name, last_name, name_suffix, username, email, password_hash, must_change_password,
                                    employment_type, program, college, employment_status, date_engaged)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $role, $full_name, $name['name_title'] ?: null, $name['first_name'], $name['middle_name'] ?: null, $name['last_name'], $name['name_suffix'] ?: null,
                $username, $email !== '' ? $email : null, $hash,
                $emp_type, $program, $college,
                $role === 'faculty' ? 'active' : null,
                $role === 'faculty' ? date('Y-m-d') : null,
            ]);
            $new_id = (int)$pdo->lastInsertId();
            log_my_activity($pdo, 'ACCOUNT_CREATE', 'Created ' . account_log_name(['user_id' => $new_id, 'full_name' => $full_name, 'role' => $role, 'username' => $username])
                . ($role === 'admin' ? '' : ' -- ' . describe_filters([
                    'Employment type' => employment_type_label($emp_type), 'Program' => PROGRAMS[$program]['label'] ?? '', 'College' => COLLEGES[$college] ?? '',
                ])) . '.');
            if ($role === 'faculty') {
                $pdo->prepare("INSERT INTO employment_history (faculty_id, event_type, event_date, remarks) VALUES (?, 'engaged', CURRENT_DATE, 'Account created by Admin')")
                    ->execute([$new_id]);
            }
            $_SESSION['flash_success'] = "Account created for {$full_name}.";
            // Shown once on the next page load, then forgotten (never logged or stored in plain text)
            $_SESSION['temp_password_notice'] = ['name' => $full_name, 'username' => $username, 'password' => $password, 'what' => 'created'];
        }
    }
    header('Location: ' . $list_url);
    exit;
}

// ---------------------------------------------------------------------
// Reset password: the Admin sets a temporary password (typed or
// generated). The old password is never shown -- only its hash is stored.
// The user must change the temporary one at their next login.
// ---------------------------------------------------------------------
if ($action === 'reset_password') {
    $target = account_for_log($pdo, (int)($_POST['user_id'] ?? 0));
    $password = (string)($_POST['temp_password'] ?? '');
    if ($password === '') { $password = generate_temporary_password(); }

    if (!csrf_valid()) {
        $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    } elseif (!$target) {
        $_SESSION['flash_error'] = 'That account could not be found.';
    } elseif ((int)$target['user_id'] === (int)$me['user_id']) {
        $_SESSION['flash_error'] = 'Use Change Password to change your own password.';
    } elseif ($rules = password_rule_errors($password)) {
        $_SESSION['flash_error'] = 'Temporary password: ' . lcfirst(password_rule_message($rules));
    } else {
        $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE user_id = ?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $target['user_id']]);
        log_my_activity($pdo, 'PASSWORD_RESET', 'Reset the password of ' . account_log_name($target)
            . ' to a temporary password; they must change it at their next login.');
        notify($pdo, (int)$target['user_id'], 'The Admin reset your password on ' . date('F j, Y, g:i A')
            . '. Log in with the temporary password the Admin gave you, then choose a new one. If you did not ask for this, contact the Admin.');
        $_SESSION['flash_success'] = "Password reset for {$target['full_name']}.";
        $_SESSION['temp_password_notice'] = ['name' => $target['full_name'], 'username' => $target['username'], 'password' => $password, 'what' => 'reset'];
    }
    header('Location: ' . $list_url);
    exit;
}

// ---------------------------------------------------------------------
// Unlock: end a brute-force login lock on the account before it expires
// ---------------------------------------------------------------------
if ($action === 'unlock_login') {
    $target = account_for_log($pdo, (int)($_POST['user_id'] ?? 0));
    if (!csrf_valid()) {
        $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    } elseif ($target && clear_login_lock($pdo, 'account', (string)$target['username'], (int)$me['user_id'])) {
        $_SESSION['flash_success'] = "Unlocked login for {$target['full_name']}.";
    } else {
        $_SESSION['flash_error'] = 'That account is not locked.';
    }
    header('Location: ' . $list_url);
    exit;
}

// ---------------------------------------------------------------------
// Reset email / 2FA: for someone who lost access to their email. The
// verified email is removed (codes waiting for it stop working), two-factor
// authentication is turned off and the "Not now" date cleared, so at the next
// login they're asked to set up an email again (optional). Logged; the
// account holder is notified in the system and at the old address.
// ---------------------------------------------------------------------
if ($action === 'reset_2fa_email') {
    $target = account_for_log($pdo, (int)($_POST['user_id'] ?? 0));
    if (!$target || !empty($target['deleted_at'])) {
        $_SESSION['flash_error'] = 'That account could not be found.';
    } elseif ((int)$target['user_id'] === (int)$me['user_id']) {
        $_SESSION['flash_error'] = 'Change your own email and two-factor authentication in Profile Preference.';
    } elseif (empty($target['email_verified_at'])) {
        $_SESSION['flash_error'] = "{$target['full_name']} has no verified email -- nothing to reset.";
    } else {
        $old = (string)$target['email'];
        $was = two_factor_on($target) ? 'on' : 'off';
        // two_factor_enabled / email_prompt_dismissed_at only once database/supabase/06_optional_two_factor.sql has run
        $pdo->prepare("UPDATE users SET email = NULL, email_verified_at = NULL" . (two_factor_ready($target) ? ", two_factor_enabled = 0, email_prompt_dismissed_at = NULL" : '')
            . " WHERE user_id = ?")->execute([$target['user_id']]);
        $pdo->prepare("UPDATE login_otps SET used_at = ? WHERE user_id = ? AND used_at IS NULL")->execute([date('Y-m-d H:i:s'), $target['user_id']]);
        log_my_activity($pdo, 'EMAIL_2FA_RESET', 'Reset the email and two-factor authentication of ' . account_log_name($target) . ' (email was ' . mask_email($old)
            . ", 2FA was {$was}); 2FA is off and they'll be asked to set up an email at their next login.");
        notify($pdo, (int)$target['user_id'], 'The Admin reset your email and two-factor authentication on ' . date('F j, Y, g:i A')
            . '. Two-factor authentication is off; at your next login you can set up a new email.');
        send_account_notice($old, 'Your email was removed', [
            "The administrator removed this address from {$target['username']} on the " . SYSTEM_NAME . ' and turned off two-factor authentication.',
            'Login codes and password reset codes are no longer sent here. A new email can be set up at the next login.',
            "If you didn't ask for this, contact the administrator.",
        ]);
        $_SESSION['flash_success'] = "Email and two-factor authentication reset for {$target['full_name']}. They can set up a new email at their next login.";
    }
    header('Location: ' . $list_url);
    exit;
}

// ---------------------------------------------------------------------
// Toggle active / inactive
// ---------------------------------------------------------------------
if ($action === 'toggle_active') {
    // The form says which status it sets (active = 1 / 0), so a resent or out-of-date form can't flip it the other way.
    // Active / Deactivated is users.is_active everywhere: the login and every page check it (includes/auth.php).
    $uid = (int)($_POST['user_id'] ?? 0);
    $activate = ($_POST['active'] ?? '') === '1';
    $stmt = $pdo->prepare("SELECT user_id, role, is_active FROM users WHERE user_id = ? AND deleted_at IS NULL");
    $stmt->execute([$uid]);
    $target = $stmt->fetch();
    if (!$target) {
        $_SESSION['flash_error'] = 'That account could not be found.';
    } elseif (!$activate && $uid === (int)$me['user_id']) {
        $_SESSION['flash_error'] = 'You cannot deactivate your own account.';
    } elseif (!$activate && $target['role'] === 'admin'
              && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND deleted_at IS NULL")->fetchColumn() <= 1) {
        $_SESSION['flash_error'] = 'You cannot deactivate the last active Admin account.';
    } elseif ((int)$target['is_active'] === ($activate ? 1 : 0)) {
        $_SESSION['flash_success'] = 'The account is already ' . ($activate ? 'active' : 'deactivated') . '.';
    } else {
        $pdo->prepare("UPDATE users SET is_active = ? WHERE user_id = ?")->execute([$activate ? 1 : 0, $uid]);
        $target = account_for_log($pdo, $uid);
        log_my_activity($pdo, $activate ? 'ACCOUNT_ACTIVATE' : 'ACCOUNT_DEACTIVATE',
            ($activate ? 'Activated ' : 'Deactivated ') . account_log_name($target) . '.');
        $_SESSION['flash_success'] = $activate
            ? 'Account activated. ' . $target['full_name'] . ' can log in again.'
            : 'Account deactivated. ' . $target['full_name'] . ' can no longer log in and has been signed out.';
    }
    header('Location: ' . $list_url);
    exit;
}

// ---------------------------------------------------------------------
// Delete an account (soft delete, like documents): deactivated at once --
// it can't log in and its open sessions end (includes/auth.php) -- and
// listed under Deleted accounts, where it can be restored for
// DELETED_RETENTION_DAYS days. Then the daily cleanup deletes it for good
// (purge_deleted_account(): the login is removed; its 201-file documents
// move to Archived Docs under the former faculty member's name). Needs a
// reason and the typed confirmation (DELETE or the person's name). The
// Admin can't delete their own account or the last Admin account.
// ---------------------------------------------------------------------
if ($action === 'delete_account') {
    $target = account_for_log($pdo, (int)($_POST['user_id'] ?? 0));
    $reason = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['reason'] ?? ''))), 0, 300);
    $typed = trim(preg_replace('/\s+/u', ' ', (string)($_POST['confirm_text'] ?? '')));
    $error = match (true) {
        !$target || !empty($target['deleted_at'])                          => 'That account could not be found.',
        (int)$target['user_id'] === (int)$me['user_id']                    => 'You cannot delete your own account.',
        $target['role'] === 'admin' && remaining_admin_count($pdo) <= 1    => 'You cannot delete the last Admin account.',
        mb_strlen($reason) < 5 || mb_strlen($reason) > 255                 => 'Please give the reason for deleting the account (5 to 255 characters).',
        $typed !== 'DELETE' && mb_strtolower($typed) !== mb_strtolower($target['full_name']) => 'To confirm, type DELETE or the account holder\'s full name exactly.',
        default                                                            => null,
    };
    if ($error === null) {
        $pdo->prepare("UPDATE users SET active_before_delete = is_active, is_active = 0, deleted_at = NOW(), deleted_by = ?, delete_reason = ?
                       WHERE user_id = ? AND deleted_at IS NULL")
            ->execute([(int)$me['user_id'], $reason, (int)$target['user_id']]);
        $until = deleted_purge_at(date('Y-m-d H:i:s'))->format('F j, Y');
        log_my_activity($pdo, 'ACCOUNT_DELETE', 'Deleted ' . account_log_name($target) . ' -- Reason: ' . $reason
            . ". Signed out and can't log in; restorable until {$until}, then deleted permanently (their 201-file documents move to Archived Docs).");
        $_SESSION['flash_success'] = "{$target['full_name']}'s account was deleted and signed out. You can restore it under Deleted accounts until {$until}.";
    } else {
        $_SESSION['flash_error'] = $error;
    }
    header('Location: ' . $list_url);
    exit;
}

// Restore a deleted account within DELETED_RETENTION_DAYS: back to how it was (active or deactivated)
if ($action === 'restore_account') {
    $stmt = $pdo->prepare("SELECT user_id, full_name, role, username, email, deleted_at, active_before_delete FROM users
                           WHERE user_id = ? AND deleted_at IS NOT NULL AND purged_at IS NULL");
    $stmt->execute([(int)($_POST['user_id'] ?? 0)]);
    $target = $stmt->fetch();
    if (!$target || deleted_purge_at($target['deleted_at']) <= new DateTimeImmutable()) {
        $_SESSION['flash_error'] = 'That account can no longer be restored.';
    } else {
        $active = $target['active_before_delete'] === null ? 1 : (int)$target['active_before_delete'];
        $pdo->prepare("UPDATE users SET is_active = ?, deleted_at = NULL, deleted_by = NULL, delete_reason = NULL, active_before_delete = NULL WHERE user_id = ?")
            ->execute([$active, (int)$target['user_id']]);
        log_my_activity($pdo, 'ACCOUNT_RESTORE', 'Restored ' . account_log_name($target) . ' (deleted on '
            . date('F j, Y', strtotime($target['deleted_at'])) . ') -- ' . ($active ? 'active again, can log in.' : 'restored as deactivated, as it was.'));
        notify($pdo, (int)$target['user_id'], 'Your account was restored by the Admin on ' . date('F j, Y') . '.');
        $_SESSION['flash_success'] = "Restored {$target['full_name']}'s account" . ($active ? '. They can log in again.' : ' (still deactivated, as it was before).');
    }
    header('Location: ' . BASE_URL . '/admin/manage_faculty.php?view=deleted');
    exit;
}

// ---------------------------------------------------------------------
// Pause / resume employment (faculty only) -- keeps continuity within
// one existing record instead of creating a new row (Ch.1 requirement)
// ---------------------------------------------------------------------
if ($action === 'pause' || $action === 'resume') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $new_status = $action === 'pause' ? 'paused' : 'active';
    $pdo->prepare("UPDATE users SET employment_status = ? WHERE user_id = ? AND role = 'faculty'")->execute([$new_status, $uid]);
    $pdo->prepare("INSERT INTO employment_history (faculty_id, event_type, event_date, remarks) VALUES (?, ?, CURRENT_DATE, ?)")
        ->execute([$uid, $action === 'pause' ? 'paused' : 'resumed', $_POST['remarks'] ?? null]);
    $target = account_for_log($pdo, $uid);
    if ($target) {
        log_my_activity($pdo, 'ACCOUNT_UPDATE', ($action === 'pause' ? 'Paused' : 'Resumed') . ' employment of ' . account_log_name($target)
            . (trim($_POST['remarks'] ?? '') !== '' ? ' -- Remarks: ' . trim($_POST['remarks']) : '') . '.');
    }
    $_SESSION['flash_success'] = $action === 'pause' ? 'Faculty employment paused.' : 'Faculty employment resumed.';
    header('Location: ' . $list_url);
    exit;
}

$where = ['deleted_at IS NULL'];   // deleted accounts are listed under Deleted accounts
$params = [];
if ($role_filter !== '') {
    $where[] = "role = ?";
    $params[] = $role_filter;
}
// Counts for the status filter, within the role filter
$stmt = $pdo->prepare("SELECT is_active, COUNT(*) FROM users WHERE " . implode(' AND ', $where) . " GROUP BY is_active");
$stmt->execute($params);
$status_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
if ($status_filter !== '') {
    $where[] = "is_active = ?";
    $params[] = $status_filter === 'active' ? 1 : 0;
}
$stmt = $pdo->prepare("SELECT * FROM users WHERE " . implode(' AND ', $where) . " ORDER BY role, full_name");
$stmt->execute($params);
$users = $stmt->fetchAll();

// Deleted accounts that can still be restored, the ones deleted permanently soonest first
$deleted = $pdo->query(
    "SELECT u.*, d.full_name AS deleted_by_name FROM users u LEFT JOIN users d ON d.user_id = u.deleted_by
     WHERE u.deleted_at IS NOT NULL AND u.purged_at IS NULL ORDER BY u.deleted_at"
)->fetchAll();
$admins_left = remaining_admin_count($pdo);

/** This page with the role / status filter changed, the other kept. */
$filter_url = fn(array $change): string => '?' . http_build_query(array_filter(array_merge(['role' => $role_filter, 'status' => $status_filter], $change)));

// Accounts locked out by failed logins right now: username => seconds left
$locked = [];
foreach (active_login_locks($pdo) as $lock) {
    if ($lock['lock_type'] === 'account') {
        $locked[$lock['lock_key']] = max($locked[$lock['lock_key']] ?? 0, (int)$lock['seconds_left']);
    }
}

include __DIR__ . '/../includes/header.php';
?>

<?= admin_intro('Accounts are created by the Admin. <strong>Deactivate</strong> someone to remove them for a while: they can\'t log in, and everything stays. <strong>Delete</strong> only accounts created by mistake or of people who have left: a deleted account can be restored for ' . (int)DELETED_RETENTION_DAYS . ' days, then it is removed for good and its 201 files move to Archived Docs. Pausing a faculty member\'s employment only stops the FTA / IPCR reminders.',
    '<a href="' . BASE_URL . '/admin/academic_ranks.php" class="btn btn-outline-brand"><i class="fa-solid fa-list-ol" aria-hidden="true"></i> Academic Ranks</a>'
  . '<button type="button" class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#createModal"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Add account</button>') ?>

<?= page_tabs([
    'accounts' => ['Accounts', 'admin/manage_faculty.php', 'fa-users'],
    'deleted'  => ['Deleted accounts', 'admin/manage_faculty.php?view=deleted', 'fa-user-xmark', count($deleted)],
], $view) ?>

<?php if (!empty($_SESSION['temp_password_notice'])):
  // Temporary password from the last create / reset: shown this once, then removed from the session
  $notice = $_SESSION['temp_password_notice'];
  unset($_SESSION['temp_password_notice']); ?>
<div class="alert alert-warning">
  <div class="fw-semibold mb-1"><i class="fa-solid fa-key"></i> Temporary password for <?= h($notice['name']) ?> (username <strong><?= h($notice['username'] ?? '') ?></strong>)</div>
  <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
    <code class="fs-5 px-2 py-1 bg-white border rounded user-select-all" id="tempPassword"><?= h($notice['password']) ?></code>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="copyTempPassword"><i class="fa-regular fa-copy"></i> Copy</button>
  </div>
  <div class="small">This is shown only once -- give it to the account holder now, with their username. When they log in they'll choose their own password, and can set up an email for two-factor authentication.</div>
</div>
<script>
document.getElementById('copyTempPassword').addEventListener('click', function () {
  var btn = this;
  navigator.clipboard.writeText(document.getElementById('tempPassword').textContent).then(function () {
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied';
  });
});
</script>
<?php endif; ?>

<?php if ($view === 'deleted'): ?>
<section class="ui-card" aria-label="Deleted accounts">
  <div class="ui-toolbar">
    <span class="ui-filter-note">Deleted accounts can't log in. Restore one within <?= (int)DELETED_RETENTION_DAYS ?> days of deleting it; after that it is removed for good and its 201-file documents move to Archived Docs under the person's name.</span>
    <span class="ui-count"><?= count($deleted) ?> account<?= count($deleted) === 1 ? '' : 's' ?></span>
  </div>
  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>Name</th><th>Role</th><th>Deleted</th><th>Reason</th><th>Removed in</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr></thead>
      <tbody>
        <?php if (!$deleted): ?><?= admin_empty_row(6, 'No deleted accounts', 'Deleted accounts stay here for ' . (int)DELETED_RETENTION_DAYS . ' days, so they can be restored.', null, 'fa-user-xmark') ?><?php endif; ?>
        <?php foreach ($deleted as $u): $days = deleted_days_left($u['deleted_at']); ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <?= user_avatar($u, 32) ?>
              <div class="min-w-0">
                <a href="<?= BASE_URL ?>/profile.php?id=<?= (int)$u['user_id'] ?>" class="text-body fw-semibold"><?= h($u['full_name']) ?></a>
                <div class="text-muted small"><?= h((string)$u['username']) ?></div>
              </div>
            </div>
          </td>
          <td><?= h(user_position_label($u)) ?></td>
          <td class="text-nowrap"><?= h(date('M j, Y', strtotime($u['deleted_at']))) ?>
            <div class="ui-cell-sub"><?= $u['deleted_by_name'] ? 'by ' . h($u['deleted_by_name']) : '' ?></div></td>
          <td class="text-break" style="max-width:18rem"><?= h((string)$u['delete_reason']) ?></td>
          <td><span class="badge <?= $days <= 7 ? 'bg-danger' : 'bg-secondary' ?>" title="Deleted permanently on <?= h(deleted_purge_at($u['deleted_at'])->format('F j, Y')) ?>"><?= $days <= 0 ? 'Today' : $days . ' day' . ($days === 1 ? '' : 's') ?></span></td>
          <td><div class="ui-actions">
            <form method="POST" action="?view=deleted" data-confirm="<?= h($u['full_name'] . '\'s account comes back as it was before it was deleted' . ($u['active_before_delete'] === '0' || $u['active_before_delete'] === 0 ? ' (deactivated).' : ': they can log in again.')) ?>"
                  data-confirm-title="Restore account?" data-confirm-ok="Restore">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="restore_account">
              <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
              <button class="btn btn-sm btn-outline-success" data-tooltip title="Restore account" aria-label="Restore account <?= h($u['full_name']) ?>"><i class="fa-solid fa-rotate-left"></i></button>
            </form>
          </div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php else: ?>
<section class="ui-card" aria-label="All accounts">
      <form method="GET" class="ui-toolbar" data-autosubmit>
        <label for="fRole" class="visually-hidden">Role</label>
        <select name="role" id="fRole" class="form-select">
          <?php foreach (['' => 'All roles', 'faculty' => 'Faculty', 'program_chair' => 'Program Chair', 'dean' => 'Dean', 'admin' => 'Admin'] as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $role_filter === $key ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <label for="fStatus" class="visually-hidden">Status</label>
        <select name="status" id="fStatus" class="form-select">
          <?php foreach (['' => ['All statuses', array_sum($status_counts)], 'active' => ['Active', $status_counts[1] ?? 0], 'deactivated' => ['Deactivated', $status_counts[0] ?? 0]] as $key => [$label, $n]): ?>
            <option value="<?= h($key) ?>" <?= $status_filter === $key ? 'selected' : '' ?>><?= h($label) ?> (<?= (int)$n ?>)</option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-brand ui-apply" data-auto><i class="fa-solid fa-filter" aria-hidden="true"></i> Filter</button>
        <?php if ($role_filter !== '' || $status_filter !== ''): ?><a href="manage_faculty.php" class="btn btn-sm btn-outline-secondary">Clear filters</a><?php endif; ?>
        <span class="ui-count"><?= count($users) ?> account<?= count($users) === 1 ? '' : 's' ?></span>
      </form>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>Name</th><th>Role</th><th>Program / College</th><th>Status</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr></thead>
          <tbody>
            <?php if (!$users): ?><?= admin_empty_row(5, 'No accounts match', 'Try a different filter, or clear the filters to see everyone.', 'manage_faculty.php', 'fa-users') ?><?php endif; ?>
            <?php foreach ($users as $u): $parts = person_name_parts($u); ?>
            <tr>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <?= user_avatar($u, 32) ?>
                  <div class="min-w-0">
                    <a href="<?= BASE_URL ?>/profile.php?id=<?= (int)$u['user_id'] ?>" class="text-body fw-semibold"><?= h($u['full_name']) ?></a>
                    <div class="text-muted small" title="Username"><i class="fa-solid fa-at" aria-hidden="true"></i> <?= h((string)$u['username']) ?></div>
                    <div class="text-muted small"><?= account_email_line($u) ?></div>
                    <?php if ($u['academic_rank']): ?><div class="text-muted small"><?= h($u['academic_rank']) ?></div><?php endif; ?>
                  </div>
                </div>
              </td>
              <td class="text-capitalize">
                <?= h(str_replace('_',' ',$u['role'])) ?>
                <?php if ($u['employment_type']): ?>
                  <div class="text-muted small text-capitalize"><?= h(str_replace('_',' ',$u['employment_type'])) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($u['role'] === 'admin'): ?>
                  <span class="text-muted small">—</span>
                <?php elseif ($u['role'] === 'dean'): ?>
                  <?= $u['college'] ? h(COLLEGES[$u['college']] ?? $u['college']) : '' ?>
                <?php else: ?>
                  <?= $u['program'] ? h($u['program']) . ' <span class="text-muted small">(' . h(PROGRAMS[$u['program']]['college'] ?? ($u['college'] ?? '')) . ')</span>' : '' ?>
                <?php endif; ?>
                <?php if (($u['role'] === 'dean' && !$u['college']) || (in_array($u['role'], ['faculty', 'program_chair'], true) && !$u['program'])): ?>
                  <div class="small text-danger">Not set — no upload notifications</div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= $u['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $u['is_active'] ? 'Active' : 'Deactivated' ?></span>
                <?php if (isset($locked[(string)$u['username']])): ?>
                  <div class="mt-1 d-flex align-items-center gap-1 flex-wrap small text-muted">
                    <span title="Too many failed login attempts -- unlocks by itself"><i class="fa-solid fa-lock"></i> Login locked until <?= h(date('g:i A', time() + $locked[(string)$u['username']])) ?></span>
                    <form method="POST" action="?<?= h($list_query) ?>" class="d-inline" data-confirm="This account can try logging in again right away." data-confirm-title="Unlock login?" data-confirm-ok="Unlock">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="unlock_login">
                      <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                      <button class="btn btn-link btn-sm p-0 align-baseline small">Unlock</button>
                    </form>
                  </div>
                <?php endif; ?>
                <?php if (!empty($u['must_change_password'])): ?>
                  <div class="small text-muted mt-1" title="Must choose a new password at next login"><i class="fa-solid fa-key"></i> Temporary password</div>
                <?php endif; ?>
                <?php if ($u['role'] === 'faculty' && $u['employment_status'] === 'paused'): ?>
                  <div class="small text-muted mt-1" title="Employment paused: no FTA / IPCR expected this semester. The account can still log in."><i class="fa-solid fa-pause"></i> Employment paused</div>
                <?php endif; ?>
              </td>
              <td><div class="ui-actions">
                <?php
                  // The profile pop-up (api/modal.php?view=person) for faculty, Chairs and Deans; an Admin
                  // account has no 201 file, so its full page. Ctrl / middle click still opens the full page.
                  $profile_modal = $u['role'] !== 'admin'
                      ? 'data-app-modal="' . h(BASE_URL . '/api/modal.php?view=person&id=' . (int)$u['user_id']) . '" data-app-modal-size="lg" data-app-modal-title="Faculty profile"'
                      : '';
                ?>
                <?= admin_icon_link(BASE_URL . '/profile.php?id=' . (int)$u['user_id'], 'fa-eye', 'View profile', $profile_modal) ?>
                <?php
                  // Tooltip / screen-reader text of each icon button. Pause only marks the employment as paused
                  // (the account can still log in); Deactivate is what blocks the login.
                  $self = (int)$u['user_id'] === (int)$me['user_id'];
                  $last_admin = $u['role'] === 'admin' && $admins_left <= 1;
                  $pause_label = $u['employment_status'] === 'active' ? 'Pause employment (account stays active)' : 'Resume employment';
                  $active_label = $u['is_active'] ? 'Deactivate account (cannot log in)' : 'Activate account (can log in again)';
                ?>
                <?php if ($u['role'] === 'faculty'): ?>
                  <form method="POST" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $u['employment_status'] === 'active' ? 'pause' : 'resume' ?>">
                    <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                    <button class="btn btn-sm btn-outline-brand" data-tooltip title="<?= h($pause_label) ?>" aria-label="<?= h($pause_label) ?>">
                      <i class="fa-solid <?= $u['employment_status'] === 'active' ? 'fa-pause' : 'fa-play' ?>"></i>
                    </button>
                  </form>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-brand" data-tooltip title="Edit account" aria-label="Edit account of <?= h($u['full_name']) ?>"
                        data-bs-toggle="modal" data-bs-target="#editModal" data-edit-id="<?= (int)$u['user_id'] ?>"
                        data-name="<?= h($u['full_name']) ?>" data-role="<?= h($u['role']) ?>" data-role-label="<?= h(user_position_label(['role' => $u['role'], 'employment_type' => null])) ?>"
                        data-title="<?= h($parts['name_title']) ?>" data-first="<?= h($parts['first_name']) ?>" data-middle="<?= h($parts['middle_name']) ?>"
                        data-last="<?= h($parts['last_name']) ?>" data-suffix="<?= h($parts['name_suffix']) ?>" data-username="<?= h((string)$u['username']) ?>"
                        data-email2fa="<?= h((!empty($u['email_verified_at']) ? $u['email'] . ' (verified)' : 'No email set up') . ' -- 2FA: ' . account_2fa_status($u)[0]) ?>"
                        data-emp="<?= h((string)$u['employment_type']) ?>" data-program="<?= h((string)$u['program']) ?>" data-college="<?= h((string)$u['college']) ?>"
                        data-rank="<?= h((string)$u['academic_rank']) ?>" data-hired="<?= h((string)$u['date_engaged']) ?>" data-empid="<?= h((string)$u['employee_id']) ?>">
                  <i class="fa-solid fa-user-pen"></i>
                </button>
                <?php // A disabled button gets no mouse events, so on your own row the tooltip sits on a wrapper ?>
                <?php if ($self): ?><span class="d-inline-block" tabindex="0" data-tooltip title="Use Change Password to change your own password"><?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-brand" <?= $self ? 'disabled' : 'data-tooltip' ?> title="Reset password" aria-label="Reset password"
                        data-bs-toggle="modal" data-bs-target="#resetPasswordModal"
                        data-id="<?= (int)$u['user_id'] ?>" data-name="<?= h($u['full_name']) ?>" data-username="<?= h((string)$u['username']) ?>">
                  <i class="fa-solid fa-key"></i>
                </button>
                <?php if ($self): ?></span><?php endif; ?>
                <?php $no_2fa_reset = $self ? 'Change your own email and 2FA in Profile Preference' : (empty($u['email_verified_at']) ? 'No verified email to reset' : ''); ?>
                <form method="POST" action="?<?= h($list_query) ?>" class="d-inline" data-confirm="<?= h($u['full_name'] . '\'s verified email (' . mask_email((string)$u['email']) . ') is removed and two-factor authentication turned off. At their next login they can set up a new email. Use this when they lost access to it.') ?>"
                      data-confirm-title="Reset email and 2FA?" data-confirm-ok="Reset" data-confirm-danger="1">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="reset_2fa_email">
                  <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                  <?php if ($no_2fa_reset): ?><span class="d-inline-block" tabindex="0" data-tooltip title="<?= h($no_2fa_reset) ?>"><?php endif; ?>
                  <button class="btn btn-sm btn-outline-brand" <?= $no_2fa_reset ? 'disabled' : 'data-tooltip' ?> title="Reset email / 2FA" aria-label="Reset email and two-factor authentication of <?= h($u['full_name']) ?>">
                    <i class="fa-solid fa-envelope-circle-check"></i>
                  </button>
                  <?php if ($no_2fa_reset): ?></span><?php endif; ?>
                </form>
                <form method="POST" class="d-inline" data-confirm="<?= h($u['is_active'] ? $u['full_name'] . ' won\'t be able to log in until the account is activated again. Their 201 file and documents stay in the system.' : $u['full_name'] . ' will be able to log in again.') ?>"
                      data-confirm-title="<?= $u['is_active'] ? 'Deactivate account?' : 'Activate account?' ?>" data-confirm-ok="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>" data-confirm-danger="<?= $u['is_active'] ? '1' : '' ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                  <input type="hidden" name="active" value="<?= $u['is_active'] ? '0' : '1' ?>">
                  <?php if ($self): ?><span class="d-inline-block" tabindex="0" data-tooltip title="You cannot deactivate your own account"><?php endif; ?>
                  <button class="btn btn-sm btn-outline-danger" <?= $self ? 'disabled' : 'data-tooltip' ?> title="<?= h($active_label) ?>" aria-label="<?= h($active_label) ?>">
                    <i class="fa-solid <?= $u['is_active'] ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
                  </button>
                  <?php if ($self): ?></span><?php endif; ?>
                </form>
                <?php $no_delete = $self ? 'You cannot delete your own account' : ($last_admin ? 'You cannot delete the last Admin account' : ''); ?>
                <?php if ($no_delete): ?><span class="d-inline-block" tabindex="0" data-tooltip title="<?= h($no_delete) ?>"><?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-danger" <?= $no_delete ? 'disabled' : 'data-tooltip' ?> title="Delete account" aria-label="Delete account of <?= h($u['full_name']) ?>"
                        data-bs-toggle="modal" data-bs-target="#deleteAccountModal"
                        data-id="<?= (int)$u['user_id'] ?>" data-name="<?= h($u['full_name']) ?>" data-username="<?= h((string)$u['username']) ?>" data-role-label="<?= h(user_position_label($u)) ?>">
                  <i class="fa-solid fa-trash-can"></i>
                </button>
                <?php if ($no_delete): ?></span><?php endif; ?>
              </div></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
</section>
<?php endif; ?>

<!-- Add account (the create form) -->
<div class="modal fade" id="createModal" tabindex="-1" aria-labelledby="createTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title" id="createTitle">Add account</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <fieldset class="mb-3">
            <legend class="form-label mb-2">Name</legend>
            <div class="row g-2">
              <div class="col-4 col-sm-3">
                <label for="cNameTitle" class="form-label small">Title</label>
                <select name="name_title" id="cNameTitle" class="form-select" data-name-part>
                  <option value="">—</option>
                  <?php foreach (NAME_TITLES as $t): ?><option><?= h($t) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="col-8 col-sm-9">
                <label for="cNameFirst" class="form-label small">First name <span class="text-danger">*</span></label>
                <input type="text" name="first_name" id="cNameFirst" class="form-control" maxlength="60" required autocomplete="off" data-name-part>
              </div>
              <div class="col-sm-5">
                <label for="cNameMiddle" class="form-label small">Middle name</label>
                <input type="text" name="middle_name" id="cNameMiddle" class="form-control" maxlength="60" autocomplete="off" data-name-part>
              </div>
              <div class="col-8 col-sm-5">
                <label for="cNameLast" class="form-label small">Last name <span class="text-danger">*</span></label>
                <input type="text" name="last_name" id="cNameLast" class="form-control" maxlength="60" required autocomplete="off" data-name-part>
              </div>
              <div class="col-4 col-sm-2">
                <label for="cNameSuffix" class="form-label small">Suffix</label>
                <select name="name_suffix" id="cNameSuffix" class="form-select" data-name-part>
                  <option value="">—</option>
                  <?php foreach (NAME_SUFFIXES as $t): ?><option><?= h($t) ?></option><?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="form-text">Shown as: <strong data-name-preview>—</strong></div>
          </fieldset>
          <div class="mb-3">
            <label for="cUsername" class="form-label">Username <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="text" name="username" id="cUsername" class="form-control" minlength="4" maxlength="30" pattern="[A-Za-z0-9._]{4,30}"
                     required autocomplete="off" autocapitalize="none" spellcheck="false">
              <button type="button" class="btn btn-outline-secondary username-suggest"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Suggest</button>
            </div>
            <div class="form-text" data-suggest-msg>What they log in with: 4-30 letters, numbers, dots and underscores (saved in lowercase). Suggest uses the name.</div>
          </div>
          <div class="mb-3">
            <label for="cEmail" class="form-label">Email <span class="text-muted small">(optional)</span></label>
            <input type="email" name="email" id="cEmail" class="form-control" maxlength="150">
            <div class="form-text">Optional. They can set up and verify their own email at login or in Profile Preference; codes go only to a verified email.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Role</label>
            <select name="role" id="roleSelect" class="form-select" required onchange="
                document.getElementById('empTypeWrap').style.display = ['faculty','program_chair','dean'].includes(this.value) ? 'block' : 'none';
                document.getElementById('programWrap').style.display = ['faculty','program_chair'].includes(this.value) ? 'block' : 'none';
                document.getElementById('collegeWrap').style.display = this.value === 'dean' ? 'block' : 'none';">
              <option value="">-- Select role --</option>
              <option value="faculty">Faculty</option>
              <option value="program_chair">Program Chair</option>
              <option value="dean">Dean</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div class="mb-3" id="empTypeWrap" style="display:none;">
            <label class="form-label">Employment Type</label>
            <select name="employment_type" class="form-select">
              <option value="full_time">Full-Time</option>
              <option value="part_time">Part-Time</option>
            </select>
          </div>
          <div class="mb-3" id="programWrap" style="display:none;">
            <?php if ($only = only_program()): /* one program: set automatically */ ?>
              <span class="form-label d-block">Program</span>
              <input type="hidden" name="program" value="<?= h($only) ?>">
              <div class="form-control-plaintext py-1"><?= h(PROGRAMS[$only]['label']) ?> (<?= h(PROGRAMS[$only]['college']) ?>)</div>
            <?php else: ?>
            <label class="form-label">Program</label>
            <select name="program" class="form-select">
              <option value="">-- Select program --</option>
              <?php foreach (PROGRAMS as $key => $p): ?>
                <option value="<?= h($key) ?>"><?= h($p['label']) ?> (<?= h($p['college']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <div class="form-text">The Program Chair of this program is notified of this person's uploads.</div>
          </div>
          <div class="mb-3" id="collegeWrap" style="display:none;">
            <label class="form-label">College</label>
            <select name="college" class="form-select">
              <?php foreach (COLLEGES as $key => $label): ?>
                <option value="<?= h($key) ?>"><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Temporary Password</label>
            <div class="input-group">
              <input type="text" name="password" id="createPassword" class="form-control" minlength="8" autocomplete="off" required>
              <button type="button" class="btn btn-outline-secondary pw-generate" data-target="createPassword"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button>
            </div>
            <div class="form-text">At least 8 characters with an uppercase letter, a lowercase letter and a number. The account holder must change it at their first login.</div>
          </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button class="btn btn-brand"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Create account</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit account: name, username, assignment and profile details only the Admin sets -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
    <form method="POST" action="?<?= h($list_query) ?>" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit_account">
      <input type="hidden" name="user_id" id="editUserId">
      <div class="modal-header">
        <h2 class="modal-title" id="editTitle">Edit account</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
          <fieldset class="mb-3">
            <legend class="form-label mb-2">Name</legend>
            <div class="row g-2">
              <div class="col-4 col-sm-3">
                <label for="eNameTitle" class="form-label small">Title</label>
                <select name="name_title" id="eNameTitle" class="form-select" data-name-part>
                  <option value="">—</option>
                  <?php foreach (NAME_TITLES as $t): ?><option><?= h($t) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="col-8 col-sm-9">
                <label for="eNameFirst" class="form-label small">First name <span class="text-danger">*</span></label>
                <input type="text" name="first_name" id="eNameFirst" class="form-control" maxlength="60" required autocomplete="off" data-name-part>
              </div>
              <div class="col-sm-5">
                <label for="eNameMiddle" class="form-label small">Middle name</label>
                <input type="text" name="middle_name" id="eNameMiddle" class="form-control" maxlength="60" autocomplete="off" data-name-part>
              </div>
              <div class="col-8 col-sm-5">
                <label for="eNameLast" class="form-label small">Last name <span class="text-danger">*</span></label>
                <input type="text" name="last_name" id="eNameLast" class="form-control" maxlength="60" required autocomplete="off" data-name-part>
              </div>
              <div class="col-4 col-sm-2">
                <label for="eNameSuffix" class="form-label small">Suffix</label>
                <select name="name_suffix" id="eNameSuffix" class="form-select" data-name-part>
                  <option value="">—</option>
                  <?php foreach (NAME_SUFFIXES as $t): ?><option><?= h($t) ?></option><?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="form-text">Shown as: <strong data-name-preview>—</strong></div>
          </fieldset>
        <div class="row g-2 mb-2">
          <div class="col-sm-8">
            <label for="editUsername" class="form-label small fw-semibold">Username <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="text" name="username" id="editUsername" class="form-control" minlength="4" maxlength="30" pattern="[A-Za-z0-9._]{4,30}"
                     required autocomplete="off" autocapitalize="none" spellcheck="false">
              <button type="button" class="btn btn-outline-secondary username-suggest"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Suggest</button>
            </div>
            <div class="form-text" data-suggest-msg>Used to log in; it can't be another account's. A change is logged and they're notified.</div>
          </div>
          <div class="col-sm-4">
            <span class="form-label small fw-semibold d-block">Role</span>
            <div class="form-control-plaintext" id="editRole"></div>
          </div>
          <div class="col-12">
            <span class="form-label small fw-semibold d-block mb-0">Email / two-factor authentication</span>
            <div class="form-control-plaintext py-1" id="editEmail2fa"></div>
            <div class="form-text mt-0">Set by the account holder. If they lost access to the email, use <i class="fa-solid fa-envelope-circle-check" aria-hidden="true"></i> Reset email / 2FA in the list.</div>
          </div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-sm-6" id="editEmpWrap">
            <label for="editEmp" class="form-label small fw-semibold">Employment type <span class="text-danger" id="editEmpReq">*</span></label>
            <select name="employment_type" id="editEmp" class="form-select">
              <option value="">-- Not set --</option>
              <option value="full_time">Full-Time</option>
              <option value="part_time">Part-Time</option>
            </select>
            <div class="form-text">For faculty this is their position (Full-time / Part-time Faculty).</div>
          </div>
          <div class="col-sm-6" id="editProgramWrap">
            <?php if ($only = only_program()): /* one program: set automatically (also replaces an old BSCS on save) */ ?>
              <span class="form-label small fw-semibold d-block">Program</span>
              <input type="hidden" name="program" id="editProgram" value="<?= h($only) ?>">
              <div class="form-control-plaintext py-1"><?= h(PROGRAMS[$only]['label']) ?> (<?= h(PROGRAMS[$only]['college']) ?>)</div>
            <?php else: ?>
            <label for="editProgram" class="form-label small fw-semibold">Program <span class="text-danger">*</span></label>
            <select name="program" id="editProgram" class="form-select">
              <option value="">-- Select program --</option>
              <?php foreach (PROGRAMS as $key => $p): ?><option value="<?= h($key) ?>"><?= h($p['label']) ?> (<?= h($p['college']) ?>)</option><?php endforeach; ?>
            </select>
            <?php endif; ?>
            <div class="form-text">Its Program Chair is notified of this person's uploads.</div>
          </div>
          <div class="col-sm-6" id="editCollegeWrap">
            <label for="editCollege" class="form-label small fw-semibold">College <span class="text-danger">*</span></label>
            <select name="college" id="editCollege" class="form-select">
              <option value="">-- Select college --</option>
              <?php foreach (COLLEGES as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="row g-2">
          <div class="col-sm-6">
            <label for="editRank" class="form-label small fw-semibold">Academic rank</label>
            <select name="academic_rank" id="editRank" class="form-select">
              <option value="">-- Not set --</option>
              <?php foreach (academic_rank_options($pdo) as $rank): ?><option><?= h($rank) ?></option><?php endforeach; ?>
            </select>
            <div class="form-text"><a href="<?= BASE_URL ?>/admin/academic_ranks.php">Add a rank to this list</a></div>
          </div>
          <div class="col-6 col-sm-3">
            <label for="editHired" class="form-label small fw-semibold">Date hired</label>
            <input type="date" name="date_engaged" id="editHired" class="form-control">
          </div>
          <div class="col-6 col-sm-3">
            <label for="editEmpId" class="form-label small fw-semibold">Employee ID</label>
            <input type="text" name="employee_id" id="editEmpId" class="form-control" maxlength="30">
          </div>
        </div>
        <p class="small text-muted mt-3 mb-0">Employment status is set with Pause / Resume, and access with Deactivate. The new name shows everywhere right away, and the account holder is notified of the changes.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete account: explains what happens, needs a reason and the typed confirmation -->
<div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-labelledby="deleteAccountTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <form method="POST" action="?<?= h($list_query) ?>" class="modal-content" id="deleteAccountForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_account">
      <input type="hidden" name="user_id" id="deleteUserId">
      <div class="modal-header">
        <h2 class="modal-title" id="deleteAccountTitle">Delete account?</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1"><strong id="deleteUserName"></strong> <span class="text-muted small" id="deleteUserRole"></span></p>
        <p class="small text-muted" id="deleteUserEmail"></p>
        <ul class="small ps-3">
          <li>They are signed out and can't log in from now on.</li>
          <li>The account moves to <strong>Deleted accounts</strong>, where you can restore it within <?= (int)DELETED_RETENTION_DAYS ?> days.</li>
          <li>After <?= (int)DELETED_RETENTION_DAYS ?> days it is deleted for good. Their 201-file documents are kept as official records and move to <strong>Archived Docs</strong> under their name.</li>
        </ul>
        <div class="alert alert-warning small py-2"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>
          Only removing someone for a while? Use <strong>Deactivate</strong> instead. Delete only accounts created by mistake or of people who have left.</div>
        <label for="deleteReason" class="form-label small fw-semibold">Reason <span class="text-danger">*</span></label>
        <textarea name="reason" id="deleteReason" class="form-control mb-3" rows="2" minlength="5" maxlength="255" required placeholder="e.g. Created by mistake (duplicate account); Resigned in June 2026"></textarea>
        <label for="deleteConfirm" class="form-label small fw-semibold">Type <strong>DELETE</strong> or their full name to confirm</label>
        <input type="text" name="confirm_text" id="deleteConfirm" class="form-control" autocomplete="off" required>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-danger" id="deleteAccountBtn" disabled><i class="fa-solid fa-trash-can" aria-hidden="true"></i> Delete account</button>
      </div>
    </form>
  </div>
</div>

<!-- Reset password: confirmation dialog -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" action="?<?= h($list_query) ?>" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="resetUserId">
      <div class="modal-header">
        <h5 class="modal-title" id="resetPasswordTitle">Reset Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1">Reset the password of <strong id="resetUserName"></strong>?</p>
        <p class="small text-muted" id="resetUserEmail"></p>
        <p class="small">Their current password stops working right away. They'll log in with the temporary password below and must then choose their own.</p>
        <label for="resetPassword" class="form-label small fw-semibold">Temporary Password</label>
        <div class="input-group">
          <input type="text" name="temp_password" id="resetPassword" class="form-control" autocomplete="off" placeholder="Leave blank to generate one">
          <button type="button" class="btn btn-outline-secondary pw-generate" data-target="resetPassword"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button>
        </div>
        <div class="form-text">At least 8 characters with an uppercase letter, a lowercase letter and a number. It's shown once after the reset so you can give it to them.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger"><i class="fa-solid fa-key"></i> Reset Password</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  // Random temporary password (same character sets as generate_temporary_password() on the server)
  function generatePassword(length) {
    var sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789'], all = sets.join('');
    function pick(s) { var r = new Uint32Array(1); crypto.getRandomValues(r); return s[r[0] % s.length]; }
    var chars = sets.map(pick);
    while (chars.length < length) chars.push(pick(all));
    for (var i = chars.length - 1; i > 0; i--) {
      var r = new Uint32Array(1); crypto.getRandomValues(r);
      var j = r[0] % (i + 1), t = chars[i]; chars[i] = chars[j]; chars[j] = t;
    }
    return chars.join('');
  }
  document.querySelectorAll('.pw-generate').forEach(function (btn) {
    btn.addEventListener('click', function () { document.getElementById(btn.dataset.target).value = generatePassword(12); });
  });
  // Name parts -> "Shown as" (the same format as person_full_name() on the server)
  function namePreview(form) {
    var v = function (n) { var el = form.querySelector('[name="' + n + '"]'); return el ? el.value.trim().replace(/\s+/g, ' ') : ''; };
    var middle = v('middle_name');
    var full = [v('name_title'), v('first_name'), middle ? middle.charAt(0).toUpperCase() + '.' : '', v('last_name'), v('name_suffix')]
      .filter(Boolean).join(' ');
    form.querySelector('[data-name-preview]').textContent = full || '—';
  }
  document.querySelectorAll('form').forEach(function (form) {
    if (!form.querySelector('[data-name-preview]')) return;
    form.addEventListener('input', function () { namePreview(form); });
    form.addEventListener('change', function () { namePreview(form); });
  });

  var editModal = document.getElementById('editModal');
  editModal.addEventListener('show.bs.modal', function (e) {
    var b = e.relatedTarget, d = b.dataset, rank = document.getElementById('editRank'), role = d.role;
    document.getElementById('editUserId').value = d.editId;
    document.getElementById('editTitle').textContent = 'Edit account — ' + d.name;
    ['Title', 'First', 'Middle', 'Last', 'Suffix'].forEach(function (k) { document.getElementById('eName' + k).value = d[k.toLowerCase()] || ''; });
    document.getElementById('editUsername').value = d.username;
    document.getElementById('editEmail2fa').textContent = d.email2fa;
    document.getElementById('editRole').textContent = d.roleLabel;
    document.getElementById('editEmpWrap').hidden = role === 'admin';
    document.getElementById('editEmpReq').hidden = role !== 'faculty';
    document.getElementById('editProgramWrap').hidden = !(role === 'faculty' || role === 'program_chair');
    document.getElementById('editCollegeWrap').hidden = role !== 'dean';
    document.getElementById('editEmp').value = d.emp;
    var editProgram = document.getElementById('editProgram');
    if (editProgram.tagName === 'SELECT') editProgram.value = d.program;   // one program: a fixed hidden value
    document.getElementById('editCollege').value = d.college;
    if (d.rank && !Array.prototype.some.call(rank.options, function (o) { return o.value === d.rank; })) {
      rank.add(new Option(d.rank + ' (hidden)', d.rank));   // a rank no longer offered stays selectable for its holder
    }
    rank.value = d.rank;
    document.getElementById('editHired').value = d.hired;
    document.getElementById('editEmpId').value = d.empid;
    namePreview(editModal.querySelector('form'));
  });
  // "Edit in Manage Faculty" on a profile links here with ?edit=ID (Bootstrap loads in the footer, hence DOMContentLoaded)
  var editBtn = document.querySelector('[data-edit-id="' + parseInt(new URLSearchParams(location.search).get('edit'), 10) + '"]');
  if (editBtn) {
    window.addEventListener('DOMContentLoaded', function () {
      editBtn.scrollIntoView({ block: 'center' });
      bootstrap.Modal.getOrCreateInstance(editModal).show(editBtn);
    });
  }

  // Delete: the button works once DELETE or the full name is typed (the server checks it again)
  var delModal = document.getElementById('deleteAccountModal'), delName = '';
  var delConfirm = document.getElementById('deleteConfirm'), delReason = document.getElementById('deleteReason'), delBtn = document.getElementById('deleteAccountBtn');
  function delReady() {
    var typed = delConfirm.value.trim().replace(/\s+/g, ' ');
    delBtn.disabled = !((typed === 'DELETE' || typed.toLowerCase() === delName.toLowerCase()) && delReason.value.trim().length >= 5);
  }
  delModal.addEventListener('show.bs.modal', function (e) {
    var d = e.relatedTarget.dataset;
    delName = d.name;
    document.getElementById('deleteUserId').value = d.id;
    document.getElementById('deleteUserName').textContent = d.name;
    document.getElementById('deleteUserRole').textContent = d.roleLabel;
    document.getElementById('deleteUserEmail').textContent = 'Username: ' + d.username;
    delConfirm.value = ''; delReason.value = ''; delReady();
  });
  delConfirm.addEventListener('input', delReady);

  // Suggest: a free username from the name (or employee ID); clicking again goes through the others
  document.querySelectorAll('.username-suggest').forEach(function (btn) {
    var list = [], at = 0, asked = '';
    btn.addEventListener('click', function () {
      var form = btn.closest('form');
      var v = function (n) { var el = form.querySelector('[name="' + n + '"]'); return el ? el.value.trim() : ''; };
      var input = form.querySelector('[name="username"]'), msg = form.querySelector('[data-suggest-msg]');
      var query = new URLSearchParams({ first: v('first_name'), last: v('last_name'), employee_id: v('employee_id'), except: v('user_id') }).toString();
      if (query === asked && list.length > 1) {
        at = (at + 1) % list.length;
        input.value = list[at];
        return;
      }
      btn.disabled = true;
      fetch('<?= BASE_URL ?>/api/username_suggest.php?' + query, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          list = data.suggestions || []; at = 0; asked = query;
          if (list.length) {
            input.value = list[0];
            msg.textContent = list.length > 1 ? 'Free: ' + list.join(', ') + ' -- click Suggest again for the next one.' : list[0] + ' is free.';
          } else {
            msg.textContent = data.error || 'No suggestion: enter the first and last name first.';
          }
        })
        .catch(function () { msg.textContent = 'Could not get a suggestion. Type a username instead.'; })
        .then(function () { btn.disabled = false; });
    });
  });
  delReason.addEventListener('input', delReady);
  document.getElementById('resetPasswordModal').addEventListener('show.bs.modal', function (e) {
    var b = e.relatedTarget;
    document.getElementById('resetUserId').value = b.dataset.id;
    document.getElementById('resetUserName').textContent = b.dataset.name;
    document.getElementById('resetUserEmail').textContent = 'Username: ' + b.dataset.username;
    document.getElementById('resetPassword').value = '';
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
