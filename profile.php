<?php
/**
 * My Profile -- every role. profile.php shows your own profile;
 * profile.php?id=N someone else's, if profile_scope_sql() allows it
 * (Admin: anyone; Dean: their college's faculty and Program Chairs;
 * Program Chair: their program's faculty; Faculty: only themselves).
 *
 * On your own profile you can change your picture, contact number,
 * field of specialization, and in the Security card your email and
 * two-factor authentication (POST + CSRF token; always your own account,
 * whatever the URL says). A new email needs your password, then a code sent
 * to it (includes/two_factor.php); the old address is told. Turning 2FA on
 * needs a code sent to the verified email, turning it off your password. The username is set by the Admin. Position, academic rank, employment status,
 * program and date hired are set by the Admin in Manage Faculty & Accounts;
 * other personal details are edited in the PDS.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pds.php';
require_login();

$me = current_user();
$my_id = (int)$me['user_id'];

// ---------------------------------------------------------------------
// Changes to your own profile
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $done = function (string $key, string $message) {
        $_SESSION[$key] = $message;
        header('Location: ' . BASE_URL . '/profile.php');
        exit;
    };
    // A request bigger than post_max_size arrives with $_POST and $_FILES empty
    if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $done('flash_error', 'That picture is too large. The maximum size is ' . (PROFILE_PICTURE_MAX_BYTES / 1024 / 1024) . ' MB.');
    }
    if (!csrf_valid()) {
        $done('flash_error', 'Your session expired before the form was sent. Please try again.');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_picture') {
        $result = profile_picture_from_upload($_FILES['picture'] ?? []);
        if (!$result['ok']) {
            $done('flash_error', $result['error']);
        }
        $_SESSION['user']['profile_picture'] = save_profile_picture($pdo, $my_id, $result['data']);
        log_my_activity($pdo, 'PROFILE_PICTURE', 'Uploaded a new profile picture.');
        $done('flash_success', 'Your profile picture has been updated.');
    }

    if ($action === 'remove_picture') {
        remove_profile_picture($pdo, $my_id);
        $_SESSION['user']['profile_picture'] = null;
        log_my_activity($pdo, 'PROFILE_PICTURE', 'Removed their profile picture.');
        $done('flash_success', 'Your profile picture has been removed.');
    }

    if ($action === 'update_details') {
        $current = user_row($pdo, $my_id);
        $contact = trim(preg_replace('/\s+/', ' ', (string)($_POST['contact_number'] ?? '')));
        $specialization = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['specialization'] ?? ''))), 0, 150);
        if ($contact !== '' && !valid_contact_number($contact)) {
            $done('flash_error', 'Please enter a valid contact number, e.g. 09171234567 or (02) 8123 4567.');
        }
        $changes = [];
        if ($contact !== (string)$current['contact_number']) {
            profile_save_contact($pdo, $my_id, $contact !== '' ? $contact : null);
            $changes['Contact number'] = ($current['contact_number'] ?: '(none)') . ' -> ' . ($contact ?: '(none)');
        }
        if ($specialization !== (string)$current['specialization']) {
            $pdo->prepare("UPDATE users SET specialization = ? WHERE user_id = ?")->execute([$specialization !== '' ? $specialization : null, $my_id]);
            $changes['Specialization'] = ($current['specialization'] ?: '(none)') . ' -> ' . ($specialization ?: '(none)');
        }
        if (!$changes) {
            $done('flash_success', 'Nothing was changed.');
        }
        log_my_activity($pdo, 'PROFILE_UPDATE', 'Updated their profile -- ' . describe_filters($changes) . '.');
        $done('flash_success', 'Your profile has been updated.');
    }

    // ----- Security (the Security card): email and two-factor authentication -----
    // Answers go back to the card (profile.php#security), with the message shown there
    $secure = function (string $key, string $message) {
        $_SESSION['security_flash'] = [$key === 'flash_success' ? 'success' : 'danger', $message];
        header('Location: ' . BASE_URL . '/profile.php#security');
        exit;
    };

    // The dashboard reminder (includes/header.php) was closed: hidden for the rest of this login
    if ($action === 'hide_security_banner') {
        $_SESSION['hide_security_banner'] = true;
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            http_response_code(204);
            exit;
        }
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }

    // Add or change the email: password, then a code to the new address
    $change = session_flow('email_change');
    $current = user_row($pdo, $my_id);
    if ($action === 'email_change_start') {
        $email = email_normalize((string)($_POST['new_email'] ?? ''));
        if (!password_verify((string)($_POST['current_password'] ?? ''), $current['password_hash'])) {
            log_my_activity($pdo, 'EMAIL_CHANGE', 'Email change refused: wrong password.');
            $secure('flash_error', 'Your current password is not correct.');
        }
        if (!email_valid($email)) {
            $secure('flash_error', 'Please enter a valid email address.');
        }
        if (verified_email($current) === $email) {
            $secure('flash_error', 'That is already your email.');
        }
        if (email_taken($pdo, $email, $my_id)) {
            $secure('flash_error', 'That email is already used by another account. Please use a different one.');
        }
        unset($_SESSION['two_factor_enable']);   // one code at a time in the card
        $sent = otp_issue($pdo, $current, 'change_email', $email);
        if (!$sent['ok']) {
            $secure('flash_error', $sent['error']);
        }
        $_SESSION['email_change'] = ['otp_id' => $sent['otp_id'], 'email' => $email, 'sent_at' => time(), 'started' => time()];
        log_my_activity($pdo, 'EMAIL_CODE_SENT', (verified_email($current) ? 'Changing' : 'Adding') . ' the email: verification code sent to ' . mask_email($email) . '.');
        $secure('flash_success', 'We sent a 6-digit code to ' . mask_email($email) . '. Enter it below to verify the address.');
    }
    if ($action === 'email_change_cancel') {
        if ($change) { otp_cancel($pdo, (int)$change['otp_id']); }
        unset($_SESSION['email_change']);
        $secure('flash_success', 'Your email was not changed.');
    }
    if (in_array($action, ['email_change_verify', 'email_change_resend'], true) && !$change) {
        $secure('flash_error', 'The email change timed out. Please start again.');
    }
    if ($action === 'email_change_resend') {
        $result = otp_resend($pdo, $current, (int)$change['otp_id']);
        if (!$result['ok']) {
            $secure('flash_error', $result['error']);
        }
        $_SESSION['email_change']['sent_at'] = time();
        log_my_activity($pdo, 'EMAIL_CODE_SENT', 'New email verification code sent to ' . mask_email($change['email']) . ' (resend).');
        $secure('flash_success', 'We sent a new code to ' . mask_email($change['email']) . '.');
    }
    if ($action === 'email_change_verify') {
        $left = null;
        $result = otp_check($pdo, (int)$change['otp_id'], $my_id, 'change_email', (string)($_POST['code'] ?? ''), $left);
        if ($result === 'ok') {
            unset($_SESSION['email_change']);
            if (email_taken($pdo, $change['email'], $my_id)) {
                $secure('flash_error', 'That email is already used by another account. Please use a different one.');
            }
            $old = verified_email($current);
            set_verified_email($pdo, $my_id, $change['email']);
            $_SESSION['user']['email'] = $change['email'];
            log_my_activity($pdo, $old ? 'EMAIL_CHANGE' : 'EMAIL_VERIFIED', ($old ? 'Changed the email from ' . mask_email($old) . ' to ' : 'Added and verified the email ')
                . mask_email($change['email']) . '.');
            if ($old) {
                send_account_notice($old, 'Your email was changed', [
                    "The email of {$current['username']} on the " . SYSTEM_NAME . ' was changed to ' . mask_email($change['email'])
                        . '. Login codes and password reset codes now go there.',
                    "If you didn't make this change, contact the administrator right away.",
                ]);
            }
            $secure('flash_success', 'Your email is now ' . $change['email'] . '.'
                . (two_factor_on($current) ? ' Login codes will be sent there.' : ' You can now turn on two-factor authentication.'));
        }
        $message = otp_retry_message($result, $left);
        if ($message === null) {
            unset($_SESSION['email_change']);
            log_my_activity($pdo, 'LOGIN_CODE_FAILED', 'Too many wrong codes while verifying an email -- the change was stopped.');
            $message = 'Too many wrong codes. Your email was not changed; please start again.';
        }
        $secure('flash_error', $message);
    }

    // Turn two-factor authentication on: a code to the verified email
    $enable = session_flow('two_factor_enable');
    if ($action === 'two_factor_on_start') {
        $email = verified_email($current);
        if ($email === null) {
            $secure('flash_error', 'Add and verify an email first.');
        }
        if (two_factor_on($current)) {
            $secure('flash_success', 'Two-factor authentication is already on.');
        }
        unset($_SESSION['email_change']);   // one code at a time in the card
        $sent = otp_issue($pdo, $current, 'enable_2fa', $email);
        if (!$sent['ok']) {
            $secure('flash_error', $sent['error']);
        }
        $_SESSION['two_factor_enable'] = ['otp_id' => $sent['otp_id'], 'email' => $email, 'sent_at' => time(), 'started' => time()];
        log_my_activity($pdo, 'LOGIN_CODE_SENT', 'Turning on two-factor authentication: code sent to ' . mask_email($email) . '.');
        $secure('flash_success', 'We sent a 6-digit code to ' . mask_email($email) . '. Enter it below to turn on two-factor authentication.');
    }
    if ($action === 'two_factor_on_cancel') {
        if ($enable) { otp_cancel($pdo, (int)$enable['otp_id']); }
        unset($_SESSION['two_factor_enable']);
        $secure('flash_success', 'Two-factor authentication is still off.');
    }
    if (in_array($action, ['two_factor_on_verify', 'two_factor_on_resend'], true) && !$enable) {
        $secure('flash_error', 'That timed out. Please start again.');
    }
    if ($action === 'two_factor_on_resend') {
        $result = otp_resend($pdo, $current, (int)$enable['otp_id']);
        if (!$result['ok']) {
            $secure('flash_error', $result['error']);
        }
        $_SESSION['two_factor_enable']['sent_at'] = time();
        log_my_activity($pdo, 'LOGIN_CODE_SENT', 'New two-factor code sent to ' . mask_email($enable['email']) . ' (resend).');
        $secure('flash_success', 'We sent a new code to ' . mask_email($enable['email']) . '.');
    }
    if ($action === 'two_factor_on_verify') {
        $left = null;
        $result = otp_check($pdo, (int)$enable['otp_id'], $my_id, 'enable_2fa', (string)($_POST['code'] ?? ''), $left);
        if ($result === 'ok') {
            unset($_SESSION['two_factor_enable']);
            set_two_factor($pdo, $my_id, true);
            log_my_activity($pdo, 'TWO_FACTOR_ON', 'Turned on two-factor authentication (codes go to ' . mask_email($enable['email']) . ').');
            send_account_notice($enable['email'], 'Two-factor authentication is on', [
                "Two-factor authentication was turned on for {$current['username']} on the " . SYSTEM_NAME . '. Each login now needs a code sent to this email.',
                "If you didn't make this change, contact the administrator right away.",
            ]);
            $secure('flash_success', 'Two-factor authentication is on. Each login now needs a code sent to ' . mask_email($enable['email']) . '.');
        }
        $message = otp_retry_message($result, $left);
        if ($message === null) {
            unset($_SESSION['two_factor_enable']);
            log_my_activity($pdo, 'LOGIN_CODE_FAILED', 'Too many wrong codes while turning on two-factor authentication -- stopped.');
            $message = 'Too many wrong codes. Two-factor authentication is still off; please start again.';
        }
        $secure('flash_error', $message);
    }

    // Turn two-factor authentication off: the current password
    if ($action === 'two_factor_off') {
        if (two_factor_required($current)) {
            $secure('flash_error', 'Two-factor authentication is required for Admin accounts and can\'t be turned off.');
        }
        if (!password_verify((string)($_POST['current_password'] ?? ''), $current['password_hash'])) {
            log_my_activity($pdo, 'TWO_FACTOR_OFF', 'Turning off two-factor authentication refused: wrong password.');
            $secure('flash_error', 'Your current password is not correct.');
        }
        if (!two_factor_on($current)) {
            $secure('flash_success', 'Two-factor authentication is already off.');
        }
        set_two_factor($pdo, $my_id, false);
        log_my_activity($pdo, 'TWO_FACTOR_OFF', 'Turned off two-factor authentication.');
        if ($email = verified_email($current)) {
            send_account_notice($email, 'Two-factor authentication is off', [
                "Two-factor authentication was turned off for {$current['username']} on the " . SYSTEM_NAME . '. Logging in now needs only the username and password.',
                "If you didn't make this change, change your password and contact the administrator right away.",
            ]);
        }
        $secure('flash_success', 'Two-factor authentication is off. You log in with your username and password only.');
    }
    $done('flash_error', 'Unknown action.');
}

// ---------------------------------------------------------------------
// View
// ---------------------------------------------------------------------
$target_id = isset($_GET['id']) ? (int)$_GET['id'] : $my_id;
$is_own = $target_id === $my_id;
$u = user_row($pdo, $target_id);
if (!$u || !can_view_profile($pdo, $me, $target_id)) {
    $_SESSION['flash_error'] = 'You do not have access to that profile.';
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$has_201 = in_array($u['role'], ['faculty', 'program_chair', 'dean'], true);   // accounts with a 201 file and a PDS
$pds_data = $has_201 ? pds_load($pdo, $target_id)['data'] : [];
$from_pds = fn(string $key): string => !in_array(trim((string)($pds_data[$key] ?? '')), ['', 'N/A'], true) ? trim($pds_data[$key]) : '';
$employee_id = $u['employee_id'] ?: $from_pds('agency_employee_no');
$contact = $u['contact_number'] ?: $from_pds('mobile_no');
$education = $has_201 ? highest_education($pdo, $target_id, $pds_data) : null;
$summary = $has_201 ? profile_201_summary($pdo, $target_id) : null;
$logins = recent_logins($pdo, $target_id);
$last_login = $is_own ? ($logins[1] ?? null) : ($logins[0] ?? null);   // your latest login is this session
$categories = document_categories();

// Where the 201-file links go: your own pages, or the Admin's. Program Chairs
// and Deans see the counts and the archive list, but can't open faculty files.
$viewer_admin = $me['role'] === 'admin';
$category_link = function (string $type) use ($is_own, $viewer_admin, $target_id): ?string {
    if ($is_own) { return BASE_URL . '/faculty/my_documents.php?type=' . urlencode($type); }
    if ($viewer_admin) { return BASE_URL . '/admin/faculty_documents.php?id=' . $target_id . '&type=' . urlencode($type); }
    return null;
};
$archive_link = BASE_URL . '/archive.php' . ($is_own ? '' : '?id=' . $target_id);
$expiring_link = $is_own
    ? BASE_URL . ($me['role'] === 'faculty' ? '/faculty/dashboard.php' : '/approval/dashboard.php')
    : ($viewer_admin ? BASE_URL . '/admin/expiring_documents.php?faculty=' . $target_id : null);

$unset = '<span class="profile-unset">Not set</span>';
$show = fn(?string $v): string => ($v !== null && trim($v) !== '') ? h($v) : $unset;
$fmt_date = fn(?string $d, string $f = 'F j, Y'): ?string => $d ? date($f, strtotime($d)) : null;

$page_title = $is_own ? 'Profile Preference' : $u['full_name'] . ' — Profile';
include __DIR__ . '/includes/header.php';
?>

<?php if ($is_own): ?>
  <?= profile_tabs('profile') ?>
<?php endif; ?>

<?php if (!$is_own): ?>
  <a href="<?= BASE_URL ?>/<?= $viewer_admin ? 'admin/view_records.php' : 'faculty_directory.php' ?>" class="small text-muted d-inline-block mb-3">
    <i class="fa-solid fa-arrow-left"></i> Back to <?= $viewer_admin ? '201-Files &rsaquo; By faculty' : 'Faculty Profiles' ?>
  </a>
<?php endif; ?>

<div class="card stat-card mb-4">
  <div class="card-body d-flex flex-column flex-sm-row align-items-center align-items-sm-start gap-4">
    <div class="text-center flex-shrink-0">
      <?= user_avatar($u, 128, 'avatar-lg', 'Profile picture of ' . $u['full_name']) ?>
      <?php if ($is_own): ?>
        <div class="mt-2 d-flex flex-column gap-1">
          <button type="button" class="btn btn-sm btn-outline-brand" data-bs-toggle="collapse" data-bs-target="#pictureForm" aria-expanded="false" aria-controls="pictureForm">
            <i class="fa-solid fa-camera"></i> <?= $u['profile_picture'] ? 'Change picture' : 'Add picture' ?>
          </button>
          <?php if ($u['profile_picture']): ?>
            <form method="POST" onsubmit="return confirm('Remove your profile picture? Your initials will be shown instead.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="remove_picture">
              <button class="btn btn-sm btn-link text-danger p-0"><i class="fa-solid fa-trash-can"></i> Remove picture</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="flex-grow-1 min-w-0 text-center text-sm-start">
      <<?= $is_own ? 'h4' : 'h3' ?> class="fw-bold mb-1"><?= h($u['full_name']) ?></<?= $is_own ? 'h4' : 'h3' ?>>
      <div class="text-muted mb-2"><?= h(user_position_line($u)) ?><?= $u['academic_rank'] && $u['academic_rank'] !== 'N/A' ? ' · ' . h($u['academic_rank']) : '' ?></div>
      <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-sm-start">
        <?php [$status_label, $status_class] = account_status_pill($u); ?>
        <span class="status-pill <?= $status_class ?>"><?= h($status_label) ?></span>
        <?php if ($u['employment_status'] === 'paused'): ?>
          <span class="small text-muted"><i class="fa-solid fa-pause"></i> Employment paused</span>
        <?php endif; ?>
      </div>
      <?php if (!$is_own && ($viewer_admin || $has_201)): ?>
        <div class="d-flex flex-wrap gap-2 mt-3 justify-content-center justify-content-sm-start">
          <?php if ($viewer_admin && $has_201): ?>
            <a href="<?= BASE_URL ?>/admin/faculty_documents.php?id=<?= $target_id ?>" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-folder-open"></i> 201 File</a>
          <?php endif; ?>
          <?php if ($has_201): ?>
            <a href="<?= h($archive_link) ?>" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-box-archive"></i> Archive (<?= (int)$summary['archived'] ?>)</a>
            <a href="<?= BASE_URL ?>/training_report.php?faculty=<?= $target_id ?>&amp;generate=1" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-chalkboard-user"></i> Seminars &amp; Trainings</a>
          <?php endif; ?>
          <?php if ($viewer_admin): ?>
            <a href="<?= BASE_URL ?>/admin/manage_faculty.php?edit=<?= $target_id ?>" class="btn btn-sm btn-brand"><i class="fa-solid fa-user-pen"></i> Edit in Manage Faculty</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($is_own): ?>
      <div class="collapse mt-3" id="pictureForm">
        <form method="POST" enctype="multipart/form-data" class="border rounded p-3 bg-light text-start" id="pictureUpload">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload_picture">
          <label for="pictureInput" class="form-label small fw-semibold">New profile picture</label>
          <div class="d-flex align-items-center gap-3 flex-wrap">
            <img id="picturePreview" alt="" class="avatar" style="width:64px;height:64px" hidden>
            <input type="file" name="picture" id="pictureInput" class="form-control form-control-sm" style="max-width:320px" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
            <button class="btn btn-sm btn-brand" id="pictureSubmit"><i class="fa-solid fa-upload"></i> Upload</button>
          </div>
          <div class="form-text" id="pictureHelp">JPG or PNG, up to <?= PROFILE_PICTURE_MAX_BYTES / 1024 / 1024 ?> MB. It is cropped to a square from the middle.</div>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card stat-card mb-4">
      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-address-card text-brand"></i> Personal &amp; Contact</span>
        <?php if ($is_own): ?>
          <button type="button" class="btn btn-sm btn-outline-brand" data-bs-toggle="collapse" data-bs-target="#detailsForm" aria-expanded="false" aria-controls="detailsForm"><i class="fa-solid fa-pen"></i> Edit</button>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <dl class="profile-dl">
          <dt>Full name</dt><dd><?= h($u['full_name']) ?></dd>
          <dt>Username</dt><dd><?= $show($u['username']) ?><?php if ($is_own): ?> <span class="small text-muted">(set by the Admin)</span><?php endif; ?></dd>
          <dt>Employee ID</dt><dd><?= $show($employee_id) ?></dd>
          <dt>Email</dt><dd><?= $show($u['email']) ?><?php if (!empty($u['email_verified_at'])): ?> <span class="badge bg-success-subtle text-success-emphasis">Verified</span><?php endif; ?></dd>
          <dt>Contact number</dt><dd><?= $show($contact) ?></dd>
        </dl>
        <?php if ($is_own): ?>
        <div class="collapse" id="detailsForm">
          <form method="POST" class="border-top mt-3 pt-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_details">
            <div class="mb-2">
              <label for="fContact" class="form-label small fw-semibold">Contact number</label>
              <input type="tel" name="contact_number" id="fContact" class="form-control form-control-sm" maxlength="30" value="<?= h($u['contact_number'] ?: $contact) ?>" placeholder="e.g. 09171234567">
              <?php if ($has_201): ?><div class="form-text">A mobile number is also copied to your PDS (Mobile No.).</div><?php endif; ?>
            </div>
            <div class="mb-3">
              <label for="fSpecialization" class="form-label small fw-semibold">Field of specialization</label>
              <input type="text" name="specialization" id="fSpecialization" class="form-control form-control-sm" maxlength="150" value="<?= h($u['specialization']) ?>" placeholder="e.g. Data Science, Networking">
            </div>
            <button class="btn btn-sm btn-brand"><i class="fa-solid fa-floppy-disk"></i> Save</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#detailsForm">Cancel</button>
          </form>
        </div>
        <p class="small text-muted mt-3 mb-0">
          <?php if ($has_201): ?>
            Your address, employee number and other personal details are edited in the <a href="<?= BASE_URL ?>/faculty/pds.php">My PDS</a> tab.
          <?php endif; ?>
          Your employee ID is set by the Admin<?= $has_201 ? ' (until then, the Agency Employee No. in your PDS is shown)' : '' ?>.
        </p>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($is_own):
      // Security: the email (add / change, verified with a code) and two-factor authentication (on with a code, off with the password)
      $email_change = session_flow('email_change');
      $two_factor_enable = session_flow('two_factor_enable');
      $verified = verified_email($u);
      $tfa_on = two_factor_on($u);
      $tfa_required = two_factor_required($u);
      $security_flash = $_SESSION['security_flash'] ?? null;
      unset($_SESSION['security_flash']);
      $code_minutes = (int)ceil(OTP_TTL_SECONDS / 60);
      $pending_code = $email_change ?? $two_factor_enable;   // at most one code waits in this card
      $code_wait = $pending_code ? max(0, (int)$pending_code['sent_at'] + OTP_RESEND_SECONDS - time()) : 0;
      $code_prefix = $email_change ? 'email_change' : 'two_factor_on';
    ?>
    <div class="card stat-card mb-4" id="security">
      <div class="card-header bg-white fw-semibold"><i class="fa-solid fa-shield-halved text-brand"></i> Security</div>
      <div class="card-body">
        <?php if ($security_flash): ?>
          <div class="alert alert-<?= h($security_flash[0]) ?> py-2 small" role="<?= $security_flash[0] === 'danger' ? 'alert' : 'status' ?>"><?= h($security_flash[1]) ?></div>
        <?php endif; ?>

        <dl class="profile-dl mb-2">
          <dt>Email</dt>
          <dd>
            <?php if ($verified): ?>
              <strong><?= h(mask_email($verified)) ?></strong> <span class="badge bg-success-subtle text-success-emphasis">Verified</span>
            <?php else: ?>
              <span class="profile-unset">Not set</span>
            <?php endif; ?>
          </dd>
          <dt>Two-factor authentication</dt>
          <dd>
            <?php if ($tfa_on): ?>
              <span class="badge bg-success">On</span><?php if ($tfa_required): ?> <span class="small text-muted">(required for Admin accounts)</span><?php endif; ?>
            <?php else: ?>
              <span class="badge bg-secondary">Off</span>
            <?php endif; ?>
          </dd>
        </dl>
        <p class="small text-muted">Your verified email is used for password recovery. With two-factor authentication on, each login also needs a 6-digit code sent to it.</p>

        <?php if ($pending_code): ?>
          <form method="POST" class="border rounded p-3 bg-light">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $code_prefix ?>_verify">
            <label for="fSecurityCode" class="form-label small fw-semibold">
              <?= $email_change ? 'Code sent to ' . h(mask_email($email_change['email'])) . ' (new email)' : 'Code sent to ' . h(mask_email($two_factor_enable['email'])) . ' (turn on two-factor authentication)' ?>
            </label>
            <div class="d-flex flex-wrap gap-2">
              <input type="text" name="code" id="fSecurityCode" class="form-control form-control-sm" style="max-width:10rem" inputmode="numeric"
                     autocomplete="one-time-code" maxlength="7" placeholder="000000" required autofocus>
              <button class="btn btn-sm btn-brand"><i class="fa-solid fa-check"></i> Verify</button>
            </div>
            <div class="form-text">The code expires in <?= $code_minutes ?> minutes.<?= $email_change && $verified ? ' Your current email stays until the new one is verified.' : '' ?></div>
          </form>
          <div class="d-flex flex-wrap gap-3 mt-2">
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="<?= $code_prefix ?>_resend">
              <button class="btn btn-sm btn-link p-0" data-wait="<?= $code_wait ?>">Resend code</button>
            </form>
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="<?= $code_prefix ?>_cancel">
              <button class="btn btn-sm btn-link p-0 text-muted">Cancel</button>
            </form>
          </div>
          <script>
          // "Resend code" waits OTP_RESEND_SECONDS after the last one
          document.querySelectorAll('#security [data-wait]').forEach(function (button) {
            var left = parseInt(button.dataset.wait, 10) || 0, label = button.textContent;
            (function tick() {
              button.disabled = left > 0;
              button.textContent = left > 0 ? label + ' (' + left + 's)' : label;
              if (left-- > 0) { setTimeout(tick, 1000); }
            })();
          });
          </script>
        <?php else: ?>
          <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-outline-brand" data-bs-toggle="collapse" data-bs-target="#emailChangeForm" aria-expanded="false" aria-controls="emailChangeForm">
              <i class="fa-solid fa-<?= $verified ? 'pen' : 'plus' ?>"></i> <?= $verified ? 'Change email' : 'Add email' ?>
            </button>
            <?php if (!$verified): ?>
              <span class="d-inline-block" tabindex="0" data-tooltip title="Add and verify an email first">
                <button type="button" class="btn btn-sm btn-outline-secondary" disabled><i class="fa-solid fa-toggle-off"></i> Turn on two-factor authentication</button>
              </span>
            <?php elseif (!$tfa_on): ?>
              <form method="POST" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="two_factor_on_start">
                <button class="btn btn-sm btn-brand"><i class="fa-solid fa-toggle-on"></i> Turn on two-factor authentication</button>
              </form>
            <?php elseif (!$tfa_required): ?>
              <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#twoFactorOffForm" aria-expanded="false" aria-controls="twoFactorOffForm">
                <i class="fa-solid fa-toggle-off"></i> Turn off two-factor authentication
              </button>
            <?php endif; ?>
          </div>

          <div class="collapse" id="emailChangeForm">
            <form method="POST" class="border-top mt-3 pt-3">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="email_change_start">
              <div class="mb-2">
                <label for="fNewEmail" class="form-label small fw-semibold"><?= $verified ? 'New email' : 'Email' ?></label>
                <input type="email" name="new_email" id="fNewEmail" class="form-control form-control-sm" maxlength="150" autocomplete="email" required placeholder="you@gmail.com">
              </div>
              <div class="mb-3">
                <label for="fEmailPassword" class="form-label small fw-semibold">Your current password</label>
                <input type="password" name="current_password" id="fEmailPassword" class="form-control form-control-sm" autocomplete="current-password" required>
              </div>
              <button class="btn btn-sm btn-brand"><i class="fa-solid fa-paper-plane"></i> Send code</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#emailChangeForm">Cancel</button>
              <div class="form-text">We'll send a 6-digit code to this address; it's saved once you enter the code<?= $verified ? ', and ' . h(mask_email($verified)) . ' is told about the change' : '' ?>.</div>
            </form>
          </div>

          <?php if ($tfa_on && !$tfa_required): ?>
          <div class="collapse" id="twoFactorOffForm">
            <form method="POST" class="border-top mt-3 pt-3">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="two_factor_off">
              <div class="mb-3">
                <label for="fTwoFactorPassword" class="form-label small fw-semibold">Your current password</label>
                <input type="password" name="current_password" id="fTwoFactorPassword" class="form-control form-control-sm" autocomplete="current-password" required>
              </div>
              <button class="btn btn-sm btn-danger"><i class="fa-solid fa-toggle-off"></i> Turn off</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#twoFactorOffForm">Cancel</button>
              <div class="form-text">Logging in will need only your username and password. Your email stays for password recovery.</div>
            </form>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card stat-card mb-4">
      <div class="card-header bg-white fw-semibold"><i class="fa-solid fa-graduation-cap text-brand"></i> Education</div>
      <div class="card-body">
        <dl class="profile-dl">
          <?php if ($has_201): ?>
            <dt>Highest educational attainment</dt>
            <dd><?= $education !== null ? h($education) : '<span class="profile-unset">Not yet in the PDS</span>' ?></dd>
          <?php endif; ?>
          <dt>Field of specialization</dt><dd><?= $show($u['specialization']) ?></dd>
        </dl>
      </div>
    </div>

    <div class="card stat-card mb-4">
      <div class="card-header bg-white fw-semibold"><i class="fa-solid fa-user-shield text-brand"></i> Account</div>
      <div class="card-body">
        <dl class="profile-dl">
          <dt><?= $is_own ? 'Previous login' : 'Last login' ?></dt>
          <dd><?= $last_login ? h($fmt_date($last_login, 'F j, Y, g:i A')) . ' <span class="text-muted small">(' . h(time_ago($last_login)) . ')</span>' : '<span class="profile-unset">' . ($is_own ? 'This is your first login' : 'Never') . '</span>' ?></dd>
          <dt>Account created</dt><dd><?= $show($fmt_date($u['created_at'])) ?></dd>
          <dt>Role</dt><dd class="text-capitalize"><?= h(str_replace('_', ' ', $u['role'])) ?></dd>
        </dl>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card stat-card mb-4">
      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-briefcase text-brand"></i> Employment</span>
        <?php if ($is_own && $viewer_admin): ?>
          <a href="<?= BASE_URL ?>/admin/manage_faculty.php?edit=<?= $target_id ?>" class="btn btn-sm btn-outline-brand fw-normal"><i class="fa-solid fa-user-pen"></i> Edit</a>
        <?php elseif ($is_own): ?><span class="small text-muted fw-normal"><i class="fa-solid fa-lock"></i> Set by the Admin</span><?php endif; ?>
      </div>
      <div class="card-body">
        <dl class="profile-dl">
          <dt>Position</dt><dd><?= h(user_position_label($u)) ?></dd>
          <dt>Academic rank</dt><dd><?= $show($u['academic_rank']) ?></dd>
          <?php if ($u['role'] !== 'admin'): ?>
            <dt>College / Department</dt><dd><?= $show(COLLEGES[$u['college'] ?? ''] ?? null) ?></dd>
            <?php if ($u['role'] !== 'dean'): ?>
              <dt>Program</dt><dd><?= $show(PROGRAMS[$u['program'] ?? '']['label'] ?? null) ?></dd>
            <?php endif; ?>
            <dt>Employment type</dt><dd><?= $show(employment_type_label($u['employment_type'])) ?></dd>
            <dt>Employment status</dt><dd class="text-capitalize"><?= $show($u['employment_status']) ?></dd>
          <?php endif; ?>
          <dt>Date hired</dt><dd><?= $show($fmt_date($u['date_engaged'])) ?></dd>
          <dt>Years of service</dt><dd><?= $u['date_engaged'] ? h(years_of_service_label($u['date_engaged'])) : $unset ?></dd>
        </dl>
      </div>
    </div>

    <?php if ($summary): ?>
    <div class="card stat-card mb-4">
      <div class="card-header bg-white fw-semibold"><i class="fa-solid fa-folder-open text-brand"></i> 201 File Summary</div>
      <div class="card-body">
        <div class="row g-2 text-center mb-3">
          <div class="col-6 col-md-3">
            <div class="border rounded p-2 h-100"><div class="fs-4 fw-bold text-brand"><?= (int)$summary['total'] ?></div><div class="small text-muted">Active documents</div></div>
          </div>
          <div class="col-6 col-md-3">
            <div class="border rounded p-2 h-100"><div class="fs-4 fw-bold text-accent-teal"><?= (int)$summary['trainings'] ?></div><div class="small text-muted">Seminars / trainings</div></div>
          </div>
          <div class="col-6 col-md-3">
            <?php $exp_total = $summary['expired'] + $summary['expiring']; ?>
            <div class="border rounded p-2 h-100">
              <div class="fs-4 fw-bold <?= $summary['expired'] ? 'text-danger' : 'text-accent-gold' ?>"><?= (int)$exp_total ?></div>
              <div class="small text-muted"><?= $expiring_link && $exp_total ? '<a href="' . h($expiring_link) . '">Expiring / expired</a>' : 'Expiring / expired' ?></div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="border rounded p-2 h-100"><div class="fs-4 fw-bold text-secondary"><?= (int)$summary['archived'] ?></div><div class="small"><a href="<?= h($archive_link) ?>">Archived</a></div></div>
          </div>
        </div>
        <?php if ($summary['trainings_archived'] || $exp_total): ?>
          <p class="small text-muted">
            <?php if ($summary['trainings_archived']): ?><?= (int)$summary['trainings_archived'] ?> of the seminars / trainings  are in Archived Docs. <?php endif; ?>
            <?php if ($exp_total): ?><?= (int)$summary['expired'] ?> expired, <?= (int)$summary['expiring'] ?> expiring within 60 days.<?php endif; ?>
          </p>
        <?php endif; ?>
        <?= pds_version_card($pdo, $target_id, $me, true) /* the digital PDS: version, last updated, history */ ?>
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light"><tr><th>Category</th><th class="text-end">Documents</th></tr></thead>
          <tbody>
            <?php foreach ($categories as $key => $cat):
              if ($cat['applies_to'] !== null && $cat['applies_to'] !== $u['employment_type'] && empty($summary['counts'][$key])) { continue; }
              $link = $category_link($key); $n = (int)($summary['counts'][$key] ?? 0); ?>
              <tr>
                <td><i class="fa-solid <?= h($cat['icon']) ?> text-muted me-1"></i> <?= $link ? '<a href="' . h($link) . '">' . h($cat['label']) . '</a>' : h($cat['label']) ?></td>
                <td class="text-end fw-semibold <?= $n ? '' : 'text-muted' ?>"><?= $n ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (!$is_own && !$viewer_admin): ?>
          <p class="small text-muted mt-2 mb-0"><i class="fa-solid fa-circle-info"></i> Counts only -- faculty files can be opened by their owner and the Admin.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($is_own): ?>
<script>
// Picture: preview the square crop and stop a file over the size limit before it's sent (the server checks both again)
(function () {
  var input = document.getElementById('pictureInput'), preview = document.getElementById('picturePreview');
  var help = document.getElementById('pictureHelp'), submit = document.getElementById('pictureSubmit');
  var MAX = <?= (int)PROFILE_PICTURE_MAX_BYTES ?>, helpText = help.textContent;
  input.addEventListener('change', function () {
    var f = input.files && input.files[0];
    preview.hidden = true;
    help.textContent = helpText;
    help.classList.remove('text-danger');
    submit.disabled = false;
    if (!f) return;
    var bad = !/^image\/(jpeg|png)$/.test(f.type) ? 'Only JPG and PNG pictures are allowed.'
            : f.size > MAX ? 'That picture is too large (' + (f.size / 1048576).toFixed(1) + ' MB). The maximum is ' + (MAX / 1048576) + ' MB.' : '';
    if (bad) { help.textContent = bad; help.classList.add('text-danger'); submit.disabled = true; return; }
    preview.src = URL.createObjectURL(f);
    preview.hidden = false;
  });
  document.getElementById('pictureUpload').addEventListener('submit', function () { setTimeout(function () { submit.disabled = true; }, 0); });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
