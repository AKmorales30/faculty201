<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Manage Faculty & Accounts';
$me = current_user();
$action = $_POST['action'] ?? '';

// Every action is a POST with the CSRF token (create / reset / unlock also say so in their own messages)
if (in_array($action, ['assign', 'toggle_active', 'pause', 'resume', 'profile_details'], true) && !csrf_valid()) {
    $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    header('Location: ' . BASE_URL . '/admin/manage_faculty.php');
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
    $program = $in['program'] ?? '';
    if (!isset(PROGRAMS[$program])) { return 'Please select the ' . ($role === 'faculty' ? 'faculty member\'s' : 'Program Chair\'s') . ' program.'; }
    return [$emp, $program, PROGRAMS[$program]['college']];
}

/** "account #12 Juan Dela Cruz (faculty, juan@um.edu.ph)" -- which account an activity-log entry is about. */
function account_log_name(array $u): string {
    return "account #{$u['user_id']} {$u['full_name']} (" . str_replace('_', ' ', $u['role']) . ", {$u['email']})";
}

/** users row for an activity-log entry, or null. */
function account_for_log(PDO $pdo, int $user_id): ?array {
    $stmt = $pdo->prepare("SELECT user_id, full_name, role, email, is_active FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch() ?: null;
}

// ---------------------------------------------------------------------
// Set program / college (and employment type for Chairs / Deans) of an
// existing account
// ---------------------------------------------------------------------
if ($action === 'assign') {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([(int)($_POST['user_id'] ?? 0)]);
    $target = $stmt->fetch();
    if ($target && $target['role'] !== 'admin') {
        $in = $_POST;
        if ($target['role'] === 'faculty') { $in['employment_type'] = $target['employment_type']; }  // unchanged here
        $result = account_assignment($target['role'], $in);
        if (is_string($result)) {
            $_SESSION['flash_error'] = $result;
        } else {
            [$emp, $program, $college] = $result;
            $pdo->prepare("UPDATE users SET employment_type = ?, program = ?, college = ? WHERE user_id = ?")
                ->execute([$emp, $program, $college, $target['user_id']]);
            log_my_activity($pdo, 'ACCOUNT_UPDATE', 'Updated ' . account_log_name($target) . ' -- ' . describe_filters([
                'Employment type' => employment_type_label($emp), 'Program' => PROGRAMS[$program]['label'] ?? '', 'College' => COLLEGES[$college] ?? '',
            ]) . '.');
            $_SESSION['flash_success'] = "Updated {$target['full_name']}.";
        }
    }
    header('Location: ' . BASE_URL . '/admin/manage_faculty.php' . (isset($_GET['role']) ? '?role=' . urlencode($_GET['role']) : ''));
    exit;
}

// ---------------------------------------------------------------------
// Profile details only the Admin sets: employment type (the position of a
// faculty member: full-time / part-time), academic rank, date hired and
// employee ID. Shown read-only on the account's own profile.
// ---------------------------------------------------------------------
if ($action === 'profile_details') {
    $target = user_row($pdo, (int)($_POST['user_id'] ?? 0));
    $back = BASE_URL . '/admin/manage_faculty.php' . (isset($_GET['role']) ? '?role=' . urlencode($_GET['role']) : '');
    if (!$target) {
        $_SESSION['flash_error'] = 'That account could not be found.';
        header('Location: ' . $back);
        exit;
    }
    $emp = $_POST['employment_type'] ?? '';
    $emp = $target['role'] === 'admin' ? null : (in_array($emp, ['full_time', 'part_time'], true) ? $emp : null);
    $rank = trim((string)($_POST['academic_rank'] ?? ''));
    $hired = trim((string)($_POST['date_engaged'] ?? ''));
    $hired_dt = DateTime::createFromFormat('!Y-m-d', $hired);
    $employee_id = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['employee_id'] ?? ''))), 0, 30);

    $error = null;
    if ($target['role'] === 'faculty' && $emp === null) {
        $error = 'Please select an employment type for a faculty account.';
    } elseif ($rank !== '' && !in_array($rank, academic_rank_options($pdo, $target['academic_rank']), true)) {
        $error = 'Please choose an academic rank from the list.';
    } elseif ($hired !== '' && (!$hired_dt || $hired_dt->format('Y-m-d') !== $hired || $hired > date('Y-m-d', strtotime('+1 year')))) {
        $error = 'Please enter a valid date hired.';
    } elseif ($employee_id !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]*$/', $employee_id)) {
        $error = 'The employee ID may only contain letters, digits, spaces and hyphens.';
    }
    if ($error !== null) {
        $_SESSION['flash_error'] = $error;
        header('Location: ' . $back);
        exit;
    }

    $new = ['employment_type' => $emp, 'academic_rank' => $rank !== '' ? $rank : null,
            'date_engaged' => $hired !== '' ? $hired : null, 'employee_id' => $employee_id !== '' ? $employee_id : null];
    $labels = ['employment_type' => 'Employment type', 'academic_rank' => 'Academic rank', 'date_engaged' => 'Date hired', 'employee_id' => 'Employee ID'];
    $changes = [];
    foreach ($new as $col => $value) {
        if ((string)$target[$col] !== (string)$value) {
            $show = fn($v) => $v === null || $v === '' ? '(none)' : ($col === 'employment_type' ? employment_type_label($v) : $v);
            $changes[$labels[$col]] = $show($target[$col]) . ' -> ' . $show($value);
        }
    }
    if ($changes) {
        $pdo->prepare("UPDATE users SET employment_type = ?, academic_rank = ?, date_engaged = ?, employee_id = ? WHERE user_id = ?")
            ->execute([...array_values($new), $target['user_id']]);
        log_my_activity($pdo, 'ACCOUNT_UPDATE', 'Updated the profile details of ' . account_log_name($target) . ' -- ' . describe_filters($changes) . '.');
        notify($pdo, (int)$target['user_id'], 'The Admin updated your profile: ' . implode('; ', array_map(fn($k, $v) => "{$k}: " . explode(' -> ', $v)[1], array_keys($changes), $changes)) . '.');
        $_SESSION['flash_success'] = "Updated the profile details of {$target['full_name']}.";
    } else {
        $_SESSION['flash_success'] = 'Nothing was changed.';
    }
    header('Location: ' . $back);
    exit;
}

// ---------------------------------------------------------------------
// Create account -- "Accounts are created by the Admin" (every login page)
// ---------------------------------------------------------------------
if ($action === 'create') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $role      = $_POST['role'] ?? '';
    $emp_type  = $_POST['employment_type'] ?? null;
    $password  = $_POST['password'] ?? '';

    $valid_roles = ['admin', 'faculty', 'program_chair', 'dean'];
    $assignment = in_array($role, $valid_roles, true) ? account_assignment($role, $_POST) : null;
    if (!csrf_valid()) {
        $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    } elseif ($full_name === '' || $email === '' || !in_array($role, $valid_roles, true) || $password === '') {
        $_SESSION['flash_error'] = 'Please fill in all fields.';
    } elseif ($rules = password_rule_errors($password)) {
        $_SESSION['flash_error'] = 'Temporary password: ' . lcfirst(password_rule_message($rules));
    } elseif (is_string($assignment)) {
        $_SESSION['flash_error'] = $assignment;
    } else {
        [$emp_type, $program, $college] = $assignment;
        $dupe = $pdo->prepare("SELECT 1 FROM users WHERE email = ?");
        $dupe->execute([$email]);
        if ($dupe->fetchColumn()) {
            $_SESSION['flash_error'] = 'An account with that email already exists.';
        } else {
            // A temporary password: the user must replace it at their first login
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                "INSERT INTO users (role, full_name, email, password_hash, must_change_password, employment_type, program, college, employment_status, date_engaged)
                 VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $role, $full_name, $email, $hash,
                $emp_type, $program, $college,
                $role === 'faculty' ? 'active' : null,
                $role === 'faculty' ? date('Y-m-d') : null,
            ]);
            $new_id = (int)$pdo->lastInsertId();
            log_my_activity($pdo, 'ACCOUNT_CREATE', 'Created ' . account_log_name(['user_id' => $new_id, 'full_name' => $full_name, 'role' => $role, 'email' => $email])
                . ($role === 'admin' ? '' : ' -- ' . describe_filters([
                    'Employment type' => employment_type_label($emp_type), 'Program' => PROGRAMS[$program]['label'] ?? '', 'College' => COLLEGES[$college] ?? '',
                ])) . '.');
            if ($role === 'faculty') {
                $pdo->prepare("INSERT INTO employment_history (faculty_id, event_type, event_date, remarks) VALUES (?, 'engaged', CURDATE(), 'Account created by Admin')")
                    ->execute([$new_id]);
            }
            $_SESSION['flash_success'] = "Account created for {$full_name}.";
            // Shown once on the next page load, then forgotten (never logged or stored in plain text)
            $_SESSION['temp_password_notice'] = ['name' => $full_name, 'email' => $email, 'password' => $password, 'what' => 'created'];
        }
    }
    header('Location: ' . BASE_URL . '/admin/manage_faculty.php');
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
        $_SESSION['temp_password_notice'] = ['name' => $target['full_name'], 'email' => $target['email'], 'password' => $password, 'what' => 'reset'];
    }
    header('Location: ' . BASE_URL . '/admin/manage_faculty.php' . (isset($_GET['role']) ? '?role=' . urlencode($_GET['role']) : ''));
    exit;
}

// ---------------------------------------------------------------------
// Unlock: end a brute-force login lock on the account before it expires
// ---------------------------------------------------------------------
if ($action === 'unlock_login') {
    $target = account_for_log($pdo, (int)($_POST['user_id'] ?? 0));
    if (!csrf_valid()) {
        $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    } elseif ($target && clear_login_lock($pdo, 'account', mb_strtolower($target['email']), (int)$me['user_id'])) {
        $_SESSION['flash_success'] = "Unlocked login for {$target['full_name']}.";
    } else {
        $_SESSION['flash_error'] = 'That account is not locked.';
    }
    header('Location: ' . BASE_URL . '/admin/manage_faculty.php' . (isset($_GET['role']) ? '?role=' . urlencode($_GET['role']) : ''));
    exit;
}

// ---------------------------------------------------------------------
// Toggle active / inactive
// ---------------------------------------------------------------------
if ($action === 'toggle_active') {
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid !== (int)$me['user_id']) {
        $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE user_id = ?")->execute([$uid]);
        $target = account_for_log($pdo, $uid);
        if ($target) {
            log_my_activity($pdo, $target['is_active'] ? 'ACCOUNT_ACTIVATE' : 'ACCOUNT_DEACTIVATE',
                ($target['is_active'] ? 'Activated ' : 'Deactivated ') . account_log_name($target) . '.');
        }
        $_SESSION['flash_success'] = 'Account status updated.';
    } else {
        $_SESSION['flash_error'] = 'You cannot deactivate your own account.';
    }
    header('Location: ' . BASE_URL . '/admin/manage_faculty.php');
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
    $pdo->prepare("INSERT INTO employment_history (faculty_id, event_type, event_date, remarks) VALUES (?, ?, CURDATE(), ?)")
        ->execute([$uid, $action === 'pause' ? 'paused' : 'resumed', $_POST['remarks'] ?? null]);
    $target = account_for_log($pdo, $uid);
    if ($target) {
        log_my_activity($pdo, 'ACCOUNT_UPDATE', ($action === 'pause' ? 'Paused' : 'Resumed') . ' employment of ' . account_log_name($target)
            . (trim($_POST['remarks'] ?? '') !== '' ? ' -- Remarks: ' . trim($_POST['remarks']) : '') . '.');
    }
    $_SESSION['flash_success'] = $action === 'pause' ? 'Faculty employment paused.' : 'Faculty employment resumed.';
    header('Location: ' . BASE_URL . '/admin/manage_faculty.php');
    exit;
}

$role_filter = $_GET['role'] ?? '';
$sql = "SELECT * FROM users";
$params = [];
if (in_array($role_filter, ['admin','faculty','program_chair','dean'], true)) {
    $sql .= " WHERE role = ?";
    $params[] = $role_filter;
}
$sql .= " ORDER BY role, full_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Accounts locked out by failed logins right now: email => seconds left
$locked = [];
foreach (active_login_locks($pdo) as $lock) {
    if ($lock['lock_type'] === 'account') {
        $locked[$lock['lock_key']] = max($locked[$lock['lock_key']] ?? 0, (int)$lock['seconds_left']);
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <h3 class="fw-bold mb-0">Manage Faculty &amp; Accounts</h3>
  <a href="<?= BASE_URL ?>/admin/academic_ranks.php" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-list-ol"></i> Academic Ranks</a>
</div>

<?php if (!empty($_SESSION['temp_password_notice'])):
  // Temporary password from the last create / reset: shown this once, then removed from the session
  $notice = $_SESSION['temp_password_notice'];
  unset($_SESSION['temp_password_notice']); ?>
<div class="alert alert-warning">
  <div class="fw-semibold mb-1"><i class="fa-solid fa-key"></i> Temporary password for <?= h($notice['name']) ?> (<?= h($notice['email']) ?>)</div>
  <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
    <code class="fs-5 px-2 py-1 bg-white border rounded user-select-all" id="tempPassword"><?= h($notice['password']) ?></code>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="copyTempPassword"><i class="fa-regular fa-copy"></i> Copy</button>
  </div>
  <div class="small">This is shown only once -- give it to the account holder now. They'll be asked to choose their own password when they log in.</div>
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

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card stat-card">
      <div class="card-header bg-white fw-semibold"><i class="fa-solid fa-user-plus text-brand"></i> Create Account</div>
      <div class="card-body">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <div class="mb-2">
            <label class="form-label small">Full Name</label>
            <input type="text" name="full_name" class="form-control form-control-sm" required>
          </div>
          <div class="mb-2">
            <label class="form-label small">Email</label>
            <input type="email" name="email" class="form-control form-control-sm" required>
          </div>
          <div class="mb-2">
            <label class="form-label small">Role</label>
            <select name="role" id="roleSelect" class="form-select form-select-sm" required onchange="
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
          <div class="mb-2" id="empTypeWrap" style="display:none;">
            <label class="form-label small">Employment Type</label>
            <select name="employment_type" class="form-select form-select-sm">
              <option value="full_time">Full-Time</option>
              <option value="part_time">Part-Time</option>
            </select>
          </div>
          <div class="mb-2" id="programWrap" style="display:none;">
            <label class="form-label small">Program</label>
            <select name="program" class="form-select form-select-sm">
              <option value="">-- Select program --</option>
              <?php foreach (PROGRAMS as $key => $p): ?>
                <option value="<?= h($key) ?>"><?= h($p['label']) ?> (<?= h($p['college']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">The Program Chair of this program is notified of this person's uploads.</div>
          </div>
          <div class="mb-2" id="collegeWrap" style="display:none;">
            <label class="form-label small">College</label>
            <select name="college" class="form-select form-select-sm">
              <?php foreach (COLLEGES as $key => $label): ?>
                <option value="<?= h($key) ?>"><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label small">Temporary Password</label>
            <div class="input-group input-group-sm">
              <input type="text" name="password" id="createPassword" class="form-control" minlength="8" autocomplete="off" required>
              <button type="button" class="btn btn-outline-secondary pw-generate" data-target="createPassword"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button>
            </div>
            <div class="form-text">At least 8 characters with an uppercase letter, a lowercase letter and a number. The account holder must change it at their first login.</div>
          </div>
          <button class="btn btn-brand btn-sm w-100"><i class="fa-solid fa-user-plus"></i> Create Account</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card stat-card">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">All Accounts</span>
        <div class="btn-group btn-group-sm">
          <a href="?role=" class="btn btn-outline-brand <?= $role_filter === '' ? 'active' : '' ?>">All</a>
          <a href="?role=faculty" class="btn btn-outline-brand <?= $role_filter === 'faculty' ? 'active' : '' ?>">Faculty</a>
          <a href="?role=program_chair" class="btn btn-outline-brand <?= $role_filter === 'program_chair' ? 'active' : '' ?>">Chair</a>
          <a href="?role=dean" class="btn btn-outline-brand <?= $role_filter === 'dean' ? 'active' : '' ?>">Dean</a>
          <a href="?role=admin" class="btn btn-outline-brand <?= $role_filter === 'admin' ? 'active' : '' ?>">Admin</a>
        </div>
      </div>
      <div class="card-body p-0 table-responsive">
        <table class="table mb-0 align-middle">
          <thead class="table-light"><tr><th>Name</th><th>Role</th><th>Program / College</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <?= user_avatar($u, 32) ?>
                  <div class="min-w-0">
                    <a href="<?= BASE_URL ?>/profile.php?id=<?= (int)$u['user_id'] ?>" class="text-body"><?= h($u['full_name']) ?></a>
                    <div class="text-muted small"><?= h($u['email']) ?></div>
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
                <?php if ($u['role'] !== 'admin'): ?>
                <form method="POST" action="?<?= $role_filter !== '' ? 'role=' . h($role_filter) : '' ?>" class="d-flex gap-1 align-items-center">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="assign">
                  <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                  <?php if ($u['role'] === 'dean'): ?>
                    <select name="college" class="form-select form-select-sm" style="min-width:120px" aria-label="College">
                      <option value="">-- College --</option>
                      <?php foreach (COLLEGES as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= $u['college'] === $key ? 'selected' : '' ?>><?= h($key) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php else: ?>
                    <select name="program" class="form-select form-select-sm" style="min-width:120px" aria-label="Program">
                      <option value="">-- Program --</option>
                      <?php foreach (PROGRAMS as $key => $p): ?>
                        <option value="<?= h($key) ?>" <?= $u['program'] === $key ? 'selected' : '' ?>><?= h($key) ?> (<?= h($p['college']) ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  <?php endif; ?>
                  <?php if ($u['role'] !== 'faculty'): ?>
                    <select name="employment_type" class="form-select form-select-sm" style="min-width:105px" aria-label="Employment type">
                      <option value="">-- Type --</option>
                      <option value="full_time" <?= $u['employment_type'] === 'full_time' ? 'selected' : '' ?>>Full-Time</option>
                      <option value="part_time" <?= $u['employment_type'] === 'part_time' ? 'selected' : '' ?>>Part-Time</option>
                    </select>
                  <?php endif; ?>
                  <?php $save_label = 'Save ' . ($u['role'] === 'dean' ? 'college' : 'program') . ($u['role'] !== 'faculty' ? ' & employment type' : ''); ?>
                  <button class="btn btn-sm btn-outline-brand" data-tooltip title="<?= h($save_label) ?>" aria-label="<?= h($save_label) ?>"><i class="fa-solid fa-floppy-disk"></i></button>
                </form>
                <?php if (($u['role'] === 'dean' && !$u['college']) || ($u['role'] !== 'dean' && !$u['program'])): ?>
                  <div class="small text-danger mt-1">Not set -- no upload notifications</div>
                <?php endif; ?>
                <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
              </td>
              <td>
                <span class="badge <?= $u['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $u['is_active'] ? 'Active' : 'Deactivated' ?></span>
                <?php if (!empty($u['must_change_password'])): ?>
                  <div class="mt-1"><span class="badge bg-warning" title="Must choose a new password at next login"><i class="fa-solid fa-key"></i> Temporary password</span></div>
                <?php endif; ?>
                <?php if (isset($locked[mb_strtolower($u['email'])])): ?>
                  <div class="mt-1 d-flex align-items-center gap-1 flex-wrap">
                    <span class="badge bg-danger" title="Too many failed login attempts"><i class="fa-solid fa-lock"></i> Locked until <?= h(date('g:i A', time() + $locked[mb_strtolower($u['email'])])) ?></span>
                    <form method="POST" action="?<?= $role_filter !== '' ? 'role=' . h($role_filter) : '' ?>" class="d-inline" onsubmit="return confirm('Unlock login for this account now?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="unlock_login">
                      <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                      <button class="btn btn-sm btn-outline-danger py-0"><i class="fa-solid fa-lock-open"></i> Unlock</button>
                    </form>
                  </div>
                <?php endif; ?>
                <?php if ($u['role'] === 'faculty'): ?>
                  <div class="mt-1">
                    <span class="badge <?= $u['employment_status'] === 'active' ? 'bg-info text-dark' : 'bg-warning text-dark' ?> text-capitalize"><?= h($u['employment_status']) ?></span>
                  </div>
                <?php endif; ?>
              </td>
              <td class="text-nowrap">
                <?php
                  // Tooltip / screen-reader text of each icon button. Pause only marks the employment as paused
                  // (the account can still log in); Deactivate is what blocks the login.
                  $self = (int)$u['user_id'] === (int)$me['user_id'];
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
                <button type="button" class="btn btn-sm btn-outline-brand" data-tooltip title="Edit profile details" aria-label="Edit profile details"
                        data-bs-toggle="modal" data-bs-target="#detailsModal" data-edit-id="<?= (int)$u['user_id'] ?>"
                        data-name="<?= h($u['full_name']) ?>" data-role="<?= h($u['role']) ?>" data-emp="<?= h((string)$u['employment_type']) ?>"
                        data-rank="<?= h((string)$u['academic_rank']) ?>" data-hired="<?= h((string)$u['date_engaged']) ?>" data-empid="<?= h((string)$u['employee_id']) ?>">
                  <i class="fa-solid fa-user-pen"></i>
                </button>
                <?php // A disabled button gets no mouse events, so on your own row the tooltip sits on a wrapper ?>
                <?php if ($self): ?><span class="d-inline-block" tabindex="0" data-tooltip title="Use Change Password to change your own password"><?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-brand" <?= $self ? 'disabled' : 'data-tooltip' ?> title="Reset password" aria-label="Reset password"
                        data-bs-toggle="modal" data-bs-target="#resetPasswordModal"
                        data-id="<?= (int)$u['user_id'] ?>" data-name="<?= h($u['full_name']) ?>" data-email="<?= h($u['email']) ?>">
                  <i class="fa-solid fa-key"></i>
                </button>
                <?php if ($self): ?></span><?php endif; ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                  <?php if ($self): ?><span class="d-inline-block" tabindex="0" data-tooltip title="You cannot deactivate your own account"><?php endif; ?>
                  <button class="btn btn-sm btn-outline-danger" <?= $self ? 'disabled' : 'data-tooltip' ?> title="<?= h($active_label) ?>" aria-label="<?= h($active_label) ?>">
                    <i class="fa-solid <?= $u['is_active'] ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
                  </button>
                  <?php if ($self): ?></span><?php endif; ?>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Profile details only the Admin sets -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" action="?<?= $role_filter !== '' ? 'role=' . h($role_filter) : '' ?>" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="profile_details">
      <input type="hidden" name="user_id" id="detailsUserId">
      <div class="modal-header">
        <h5 class="modal-title" id="detailsTitle">Edit Profile Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="fw-semibold mb-3" id="detailsUserName"></p>
        <div class="mb-2" id="detailsEmpWrap">
          <label for="detailsEmp" class="form-label small fw-semibold">Employment Type</label>
          <select name="employment_type" id="detailsEmp" class="form-select form-select-sm">
            <option value="">-- Not set --</option>
            <option value="full_time">Full-Time</option>
            <option value="part_time">Part-Time</option>
          </select>
          <div class="form-text">For faculty this is their position (Full-time / Part-time Faculty).</div>
        </div>
        <div class="mb-2">
          <label for="detailsRank" class="form-label small fw-semibold">Academic Rank</label>
          <select name="academic_rank" id="detailsRank" class="form-select form-select-sm">
            <option value="">-- Not set --</option>
            <?php foreach (academic_rank_options($pdo) as $rank): ?><option><?= h($rank) ?></option><?php endforeach; ?>
          </select>
          <div class="form-text"><a href="<?= BASE_URL ?>/admin/academic_ranks.php">Add a rank to this list</a></div>
        </div>
        <div class="row g-2">
          <div class="col-6">
            <label for="detailsHired" class="form-label small fw-semibold">Date Hired</label>
            <input type="date" name="date_engaged" id="detailsHired" class="form-control form-control-sm">
          </div>
          <div class="col-6">
            <label for="detailsEmpId" class="form-label small fw-semibold">Employee ID</label>
            <input type="text" name="employee_id" id="detailsEmpId" class="form-control form-control-sm" maxlength="30">
          </div>
        </div>
        <p class="small text-muted mt-3 mb-0">Program / college are set in the table; employment status with pause / resume. The account holder is notified of changes.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk"></i> Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Reset password: confirmation dialog -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" action="?<?= $role_filter !== '' ? 'role=' . h($role_filter) : '' ?>" class="modal-content">
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
  var detailsModal = document.getElementById('detailsModal');
  detailsModal.addEventListener('show.bs.modal', function (e) {
    var b = e.relatedTarget, rank = document.getElementById('detailsRank');
    document.getElementById('detailsUserId').value = b.dataset.editId;
    document.getElementById('detailsUserName').textContent = b.dataset.name;
    document.getElementById('detailsEmpWrap').hidden = b.dataset.role === 'admin';
    document.getElementById('detailsEmp').value = b.dataset.emp;
    if (b.dataset.rank && !Array.prototype.some.call(rank.options, function (o) { return o.value === b.dataset.rank; })) {
      rank.add(new Option(b.dataset.rank + ' (hidden)', b.dataset.rank));   // a rank no longer offered stays selectable for its holder
    }
    rank.value = b.dataset.rank;
    document.getElementById('detailsHired').value = b.dataset.hired;
    document.getElementById('detailsEmpId').value = b.dataset.empid;
  });
  // "Edit in Manage Faculty" on a profile links here with ?edit=ID (Bootstrap loads in the footer, hence DOMContentLoaded)
  var editBtn = document.querySelector('[data-edit-id="' + parseInt(new URLSearchParams(location.search).get('edit'), 10) + '"]');
  if (editBtn) {
    window.addEventListener('DOMContentLoaded', function () {
      editBtn.scrollIntoView({ block: 'center' });
      bootstrap.Modal.getOrCreateInstance(detailsModal).show(editBtn);
    });
  }
  document.getElementById('resetPasswordModal').addEventListener('show.bs.modal', function (e) {
    var b = e.relatedTarget;
    document.getElementById('resetUserId').value = b.dataset.id;
    document.getElementById('resetUserName').textContent = b.dataset.name;
    document.getElementById('resetUserEmail').textContent = b.dataset.email;
    document.getElementById('resetPassword').value = '';
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
