<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Manage Faculty & Accounts';
$me = current_user();
$action = $_POST['action'] ?? '';

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
            $_SESSION['flash_success'] = "Updated {$target['full_name']}.";
        }
    }
    header('Location: ' . BASE_URL . '/admin/manage_faculty.php' . (isset($_GET['role']) ? '?role=' . urlencode($_GET['role']) : ''));
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
    if ($full_name === '' || $email === '' || !in_array($role, $valid_roles, true) || strlen($password) < 8) {
        $_SESSION['flash_error'] = 'Please fill in all fields. Password must be at least 8 characters.';
    } elseif (is_string($assignment)) {
        $_SESSION['flash_error'] = $assignment;
    } else {
        [$emp_type, $program, $college] = $assignment;
        $dupe = $pdo->prepare("SELECT 1 FROM users WHERE email = ?");
        $dupe->execute([$email]);
        if ($dupe->fetchColumn()) {
            $_SESSION['flash_error'] = 'An account with that email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                "INSERT INTO users (role, full_name, email, password_hash, employment_type, program, college, employment_status, date_engaged)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $role, $full_name, $email, $hash,
                $emp_type, $program, $college,
                $role === 'faculty' ? 'active' : null,
                $role === 'faculty' ? date('Y-m-d') : null,
            ]);
            if ($role === 'faculty') {
                $new_id = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO employment_history (faculty_id, event_type, event_date, remarks) VALUES (?, 'engaged', CURDATE(), 'Account created by Admin')")
                    ->execute([$new_id]);
            }
            $_SESSION['flash_success'] = "Account created for {$full_name}.";
        }
    }
    header('Location: ' . BASE_URL . '/admin/manage_faculty.php');
    exit;
}

// ---------------------------------------------------------------------
// Toggle active / inactive
// ---------------------------------------------------------------------
if ($action === 'toggle_active') {
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid !== (int)$me['user_id']) {
        $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE user_id = ?")->execute([$uid]);
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

include __DIR__ . '/../includes/header.php';
?>

<h3 class="fw-bold mb-4">Manage Faculty &amp; Accounts</h3>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card stat-card">
      <div class="card-header bg-white fw-semibold"><i class="fa-solid fa-user-plus text-brand"></i> Create Account</div>
      <div class="card-body">
        <form method="POST">
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
            <input type="text" name="password" class="form-control form-control-sm" minlength="8" required>
            <div class="form-text">At least 8 characters. Share this with the account holder securely.</div>
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
                <?= h($u['full_name']) ?>
                <div class="text-muted small"><?= h($u['email']) ?></div>
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
                  <button class="btn btn-sm btn-outline-brand" title="Save"><i class="fa-solid fa-floppy-disk"></i></button>
                </form>
                <?php if (($u['role'] === 'dean' && !$u['college']) || ($u['role'] !== 'dean' && !$u['program'])): ?>
                  <div class="small text-danger mt-1">Not set -- no upload notifications</div>
                <?php endif; ?>
                <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
              </td>
              <td>
                <span class="badge <?= $u['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $u['is_active'] ? 'Active' : 'Deactivated' ?></span>
                <?php if ($u['role'] === 'faculty'): ?>
                  <div class="mt-1">
                    <span class="badge <?= $u['employment_status'] === 'active' ? 'bg-info text-dark' : 'bg-warning text-dark' ?> text-capitalize"><?= h($u['employment_status']) ?></span>
                  </div>
                <?php endif; ?>
              </td>
              <td class="text-nowrap">
                <?php if ($u['role'] === 'faculty'): ?>
                  <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="<?= $u['employment_status'] === 'active' ? 'pause' : 'resume' ?>">
                    <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                    <button class="btn btn-sm btn-outline-brand" title="<?= $u['employment_status'] === 'active' ? 'Pause employment' : 'Resume employment' ?>">
                      <i class="fa-solid <?= $u['employment_status'] === 'active' ? 'fa-pause' : 'fa-play' ?>"></i>
                    </button>
                  </form>
                <?php endif; ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure?');">
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" <?= (int)$u['user_id'] === (int)$me['user_id'] ? 'disabled' : '' ?>>
                    <i class="fa-solid <?= $u['is_active'] ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
                  </button>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
