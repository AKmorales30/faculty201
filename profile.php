<?php
/**
 * My Profile -- every role. profile.php shows your own profile;
 * profile.php?id=N someone else's, if profile_scope_sql() allows it
 * (Admin: anyone; Dean: their college's faculty and Program Chairs;
 * Program Chair: their program's faculty; Faculty: only themselves).
 *
 * On your own profile you can change your picture, contact number and
 * field of specialization (POST + CSRF token; always your own account,
 * whatever the URL says). Position, academic rank, employment status,
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

$page_title = $is_own ? 'My Profile' : $u['full_name'] . ' — Profile';
include __DIR__ . '/includes/header.php';
?>

<?php if (!$is_own): ?>
  <a href="<?= BASE_URL ?>/<?= $viewer_admin ? 'admin/view_records.php' : 'faculty_directory.php' ?>" class="small text-muted d-inline-block mb-3">
    <i class="fa-solid fa-arrow-left"></i> Back to <?= $viewer_admin ? 'Faculty Records' : 'Faculty Profiles' ?>
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
      <h3 class="fw-bold mb-1"><?= h($u['full_name']) ?></h3>
      <div class="text-muted mb-2"><?= h(user_position_line($u)) ?><?= $u['academic_rank'] && $u['academic_rank'] !== 'N/A' ? ' · ' . h($u['academic_rank']) : '' ?></div>
      <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-sm-start">
        <span class="badge <?= $u['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $u['is_active'] ? 'Active account' : 'Deactivated account' ?></span>
        <?php if ($u['employment_status']): ?>
          <span class="badge <?= $u['employment_status'] === 'active' ? 'bg-info' : 'bg-warning' ?> text-capitalize">Employment: <?= h($u['employment_status']) ?></span>
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
          <dt>Employee ID</dt><dd><?= $show($employee_id) ?></dd>
          <dt>Email</dt><dd><?= h($u['email']) ?></dd>
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
            Your address, employee number and other personal details are edited in <a href="<?= BASE_URL ?>/faculty/pds.php">My PDS</a>.
          <?php endif; ?>
          Your employee ID is set by the Admin<?= $has_201 ? ' (until then, the Agency Employee No. in your PDS is shown)' : '' ?>.
        </p>
        <?php endif; ?>
      </div>
    </div>

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
            <?php if ($summary['trainings_archived']): ?><?= (int)$summary['trainings_archived'] ?> of the seminars / trainings are in the archive (more than <?= (int)ARCHIVE_AFTER_YEARS ?> years old). <?php endif; ?>
            <?php if ($exp_total): ?><?= (int)$summary['expired'] ?> expired, <?= (int)$summary['expiring'] ?> expiring within 60 days.<?php endif; ?>
          </p>
        <?php endif; ?>
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
