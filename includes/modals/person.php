<?php
/**
 * Faculty profile pop-up (api/modal.php?view=person&id=N) for Faculty
 * Profiles and the Program Chair / Dean Home: who they are, their 201-file
 * summary (counts and the checklist -- never the files) and their open
 * follow-up reminders. "Open full profile" and "View archive" go to
 * profile.php?id=N and archive.php?id=N, which check access again.
 *
 * Expects $pdo, $me (api/modal.php) and $person (users row the viewer may
 * see -- can_view_profile(), checked by api/modal.php). Every value shown
 * is escaped (h()).
 */
$pid = (int)$person['user_id'];
$summary = profile_201_summary($pdo, $pid);
$checklist = faculty_201_checklist($pdo, $pid, $person['employment_type'] ?? null);
$open_issues = [];
try {
    $stmt = $pdo->prepare("SELECT DISTINCT reminder_type FROM ai_reminders WHERE user_id = ? AND resolved_at IS NULL AND reminder_type <> 'follow_up'");
    $stmt->execute([$pid]);
    $types = reminder_types();
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if ($t === 'pds' && !faculty_has_pds($person)) { continue; }   // part-time faculty have no PDS
        if (isset($types[$t])) { $open_issues[] = $types[$t]['label']; }
    }
} catch (PDOException $e) {
    // migration not applied yet: no reminders to show
}
$rank = $person['academic_rank'] && $person['academic_rank'] !== 'N/A' ? $person['academic_rank'] : null;
$fields = [
    'Academic rank' => $rank ?? 'Not set',
    'Date hired'    => $person['date_engaged'] ? date('F j, Y', strtotime($person['date_engaged'])) : 'Not set',
    'Email'         => $person['email'],
    'Archived docs' => (string)$summary['archived'],
];
?>
<div data-modal-title="Faculty profile"></div>
<div class="ui-person-head">
  <?= user_avatar($person, 64) ?>
  <div class="min-w-0">
    <div class="ui-person-title"><?= h($person['full_name']) ?></div>
    <div class="ui-cell-sub"><?= h(user_position_line($person)) ?></div>
    <?php [$status_label, $status_class] = account_status_pill($person); ?>
    <span class="status-pill mt-1 <?= $status_class ?>"><?= h($status_label) ?></span>
  </div>
</div>

<dl class="ui-person-fields">
  <?php foreach ($fields as $k => $v): ?>
    <div><dt><?= h($k) ?></dt><dd><?= h($v) ?></dd></div>
  <?php endforeach; ?>
</dl>

<?php require_once __DIR__ . '/../pds.php'; ?>
<div class="mb-3"><?= pds_version_card($pdo, $pid, $me, true) ?></div>
<div class="ui-person-section">201 file summary</div>
<div class="ui-person-counts">
  <span><strong><?= (int)$summary['total'] ?></strong> posted document<?= $summary['total'] === 1 ? '' : 's' ?></span>
  <span><strong><?= (int)$summary['trainings'] ?></strong> seminar<?= $summary['trainings'] === 1 ? '' : 's' ?> / trainings</span>
  <?php if ($summary['expired'] || $summary['expiring']): ?>
    <span><strong><?= (int)$summary['expired'] ?></strong> expired · <strong><?= (int)$summary['expiring'] ?></strong> expiring soon</span>
  <?php endif; ?>
</div>
<ul class="ui-check-list ui-check-list-compact">
  <?php foreach ($checklist as $item): ?>
    <li class="ui-check-row">
      <span class="ui-check-icon <?= $item['done'] ? 'tone-teal' : 'tone-gold' ?>" aria-hidden="true"><i class="fa-solid <?= $item['done'] ? 'fa-check' : 'fa-exclamation' ?>"></i></span>
      <span class="ui-check-label"><?= h($item['label']) ?><span class="visually-hidden"><?= $item['done'] ? ' (on file)' : ' (missing)' ?></span></span>
    </li>
  <?php endforeach; ?>
</ul>

<?php if ($open_issues): ?>
  <div class="ui-person-section">Needs follow-up</div>
  <div class="ui-pillset"><?php foreach ($open_issues as $i): ?><span class="ui-pill tone-gold"><?= h($i) ?></span><?php endforeach; ?></div>
<?php endif; ?>

<div data-modal-footer class="d-flex flex-wrap gap-2">
  <?php if ($me['role'] === 'admin'): ?>
    <a href="<?= BASE_URL ?>/admin/faculty_documents.php?id=<?= $pid ?>" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-folder-open" aria-hidden="true"></i> 201 File</a>
  <?php endif; ?>
  <a href="<?= BASE_URL ?>/profile.php?id=<?= $pid ?>" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-circle-user" aria-hidden="true"></i> Open full profile</a>
  <a href="<?= BASE_URL ?>/archive.php?id=<?= $pid ?>" class="btn btn-sm btn-brand"><i class="fa-solid fa-box-archive" aria-hidden="true"></i> View archive</a>
</div>
