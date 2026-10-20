<?php
/**
 * Faculty Profiles -- the accounts whose profile the viewer may open
 * (profile_scope_sql()): a Dean sees their college's faculty and Program
 * Chairs, a Program Chair their program's faculty, the Admin everyone with
 * a 201 file. Each opens the profile pop-up (api/modal.php?view=person),
 * or profile.php?id=N without JavaScript / with Ctrl-click.
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

<?= admin_intro(
    $scope_label
        ? 'Faculty in <strong>' . h($scope_label) . '</strong>. Open a profile to see their details, 201-file summary and archive.'
        : 'Your ' . ($me['role'] === 'dean' ? 'college' : 'program') . ' hasn\'t been set yet, so no faculty are listed. Please ask the Admin to set it.',
    $people ? '<label class="ui-search ui-search-wide"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>'
            . '<input type="search" id="directoryFilter" placeholder="Filter by name, position or rank" aria-label="Filter faculty" autocomplete="off"></label>' : ''
) ?>

<?php if (!$people): ?>
  <section class="ui-card"><?= admin_empty('No faculty to show', $scope_label ? 'Faculty in ' . $scope_label . ' will appear here once their accounts are added.' : '', null, 'fa-users') ?></section>
<?php endif; ?>

<div class="ui-people" id="directoryList">
  <?php foreach ($people as $p): ?>
    <a class="ui-person-card directory-item" href="<?= BASE_URL ?>/profile.php?id=<?= (int)$p['user_id'] ?>"
       data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=person&id=' . (int)$p['user_id']) ?>" data-app-modal-size="lg" data-app-modal-title="Faculty profile"
       data-search="<?= h(mb_strtolower($p['full_name'] . ' ' . user_position_line($p) . ' ' . $p['academic_rank'])) ?>">
      <span class="ui-person-avatar<?= $p['is_active'] ? '' : ' is-off' ?>"><?= user_avatar($p, 52) ?></span>
      <span class="ui-person-body">
        <span class="ui-person-name"><?= h($p['full_name']) ?></span>
        <span class="ui-cell-sub text-truncate"><?= h(user_position_line($p)) ?></span>
        <span class="ui-cell-sub">
          <?= $p['academic_rank'] ? h($p['academic_rank']) : 'Rank not set' ?>
          <?php if ((int)$p['archived']): ?> · <?= (int)$p['archived'] ?> archived<?php endif; ?>
          <?php if (!$p['is_active']): ?> · <span class="text-danger">Deactivated</span><?php endif; ?>
        </span>
      </span>
      <i class="fa-solid fa-chevron-right ui-person-chevron" aria-hidden="true"></i>
    </a>
  <?php endforeach; ?>
</div>
<section class="ui-card" id="directoryNone" hidden>
  <div class="ui-empty">
    <span class="ui-empty-icon" aria-hidden="true"><i class="fa-solid fa-users"></i></span>
    <div class="ui-empty-title">No faculty match that filter</div>
    <button type="button" class="btn btn-sm btn-outline-brand" id="directoryClear">Clear filter</button>
  </div>
</section>

<script>
(function () {
  var input = document.getElementById('directoryFilter'), items = document.querySelectorAll('.directory-item');
  if (!input) return;
  var apply = function () {
    var terms = input.value.toLowerCase().split(/\s+/).filter(Boolean), shown = 0;
    items.forEach(function (el) {
      var ok = terms.every(function (t) { return el.dataset.search.indexOf(t) !== -1; });
      el.hidden = !ok;
      if (ok) shown++;
    });
    document.getElementById('directoryNone').hidden = shown > 0 || !items.length;
  };
  input.addEventListener('input', apply);
  document.getElementById('directoryClear').addEventListener('click', function () { input.value = ''; apply(); input.focus(); });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
