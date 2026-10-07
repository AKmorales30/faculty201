<?php
/**
 * Faculty Profiles -- the accounts whose profile the viewer may open
 * (profile_scope_sql()): a Dean sees their college's faculty and Program
 * Chairs, a Program Chair their program's faculty, the Admin everyone with
 * a 201 file. Each opens profile.php?id=N.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['admin', 'program_chair', 'dean']);

$me = current_user();
[$scope, $params] = profile_scope_sql($pdo, $me);
$stmt = $pdo->prepare(
    "SELECT u.user_id, u.full_name, u.role, u.employment_type, u.program, u.college, u.academic_rank, u.profile_picture, u.is_active,
            (SELECT COUNT(*) FROM documents d WHERE d.faculty_id = u.user_id AND d.status = 'archived') AS archived
     FROM users u
     WHERE $scope AND u.role IN ('faculty', 'program_chair', 'dean') AND u.user_id <> ?
     ORDER BY FIELD(u.role, 'dean', 'program_chair', 'faculty'), u.full_name"
);
$stmt->execute([...$params, (int)$me['user_id']]);
$people = $stmt->fetchAll();

$viewer = user_row($pdo, (int)$me['user_id']);
$scope_label = match ($me['role']) {
    'dean'          => COLLEGES[$viewer['college'] ?? ''] ?? null,
    'program_chair' => PROGRAMS[$viewer['program'] ?? '']['label'] ?? null,
    default         => 'all programs',
};

$page_title = 'Faculty Profiles';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
  <h3 class="fw-bold mb-0">Faculty Profiles</h3>
  <input type="search" id="directoryFilter" class="form-control form-control-sm directory-filter" placeholder="Filter by name, position or rank..." aria-label="Filter faculty">
</div>
<p class="text-muted mb-4">
  <?php if ($scope_label): ?>
    Faculty in <strong><?= h($scope_label) ?></strong>. Open a profile to see their details, 201-file summary and archive.
  <?php else: ?>
    Your <?= $me['role'] === 'dean' ? 'college' : 'program' ?> hasn't been set yet, so no faculty are listed -- please ask the Admin to set it.
  <?php endif; ?>
</p>

<?php if (!$people): ?>
  <div class="text-center text-muted py-5">No faculty to show.</div>
<?php endif; ?>

<div class="row g-3" id="directoryList">
  <?php foreach ($people as $p): ?>
  <div class="col-md-6 col-xl-4 directory-item" data-search="<?= h(mb_strtolower($p['full_name'] . ' ' . user_position_line($p) . ' ' . $p['academic_rank'])) ?>">
    <a href="<?= BASE_URL ?>/profile.php?id=<?= (int)$p['user_id'] ?>" class="text-decoration-none">
      <div class="card folder-card h-100">
        <div class="card-body d-flex align-items-center gap-3">
          <?= user_avatar($p, 52) ?>
          <div class="min-w-0">
            <div class="fw-semibold text-charcoal text-truncate"><?= h($p['full_name']) ?></div>
            <div class="small text-muted text-truncate"><?= h(user_position_line($p)) ?></div>
            <div class="small text-muted">
              <?= $p['academic_rank'] ? h($p['academic_rank']) : 'Rank not set' ?>
              <?php if ((int)$p['archived']): ?> · <?= (int)$p['archived'] ?> archived<?php endif; ?>
              <?php if (!$p['is_active']): ?> · <span class="text-danger">Deactivated</span><?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<p class="text-center text-muted py-4" id="directoryNone" hidden>No faculty match that filter.</p>

<script>
(function () {
  var input = document.getElementById('directoryFilter'), items = document.querySelectorAll('.directory-item');
  input.addEventListener('input', function () {
    var terms = input.value.toLowerCase().split(/\s+/).filter(Boolean), shown = 0;
    items.forEach(function (el) {
      var ok = terms.every(function (t) { return el.dataset.search.indexOf(t) !== -1; });
      el.hidden = !ok;
      if (ok) shown++;
    });
    document.getElementById('directoryNone').hidden = shown > 0 || !items.length;
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
