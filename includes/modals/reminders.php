<?php
/**
 * Faculty Needing Follow-up pop-up (api/modal.php?view=reminders&type=...),
 * opened from the dashboard summary card -- Admin, Program Chairs and Deans,
 * within their scope (reminder_scope_list() uses profile_scope_sql()). The
 * tabs reload the pop-up with another type. Expects $me, $pdo.
 */
$types = personal_reminder_types();
$type = (string)($_GET['type'] ?? '');
if (!isset($types[$type])) { $type = array_key_first($types); }
$counts = reminder_scope_counts($pdo, $me);
try {
    $rows = reminder_scope_list($pdo, $me, $type);
} catch (PDOException $e) {
    $rows = [];   // migration not applied yet
}
?>
<div data-modal-title="Faculty Needing Follow-up"></div>
<p class="small text-muted mb-3">From the daily reminder check. Each person listed has already been reminded on their dashboard<?= $types[$type]['notify'] ? ' and in their notifications' : '' ?>; they leave the list once their 201 file is updated.
  <?php if ($me['role'] === 'program_chair'): ?>You see the faculty of your program.<?php elseif ($me['role'] === 'dean'): ?>You see the faculty and Program Chairs of your college.<?php endif; ?></p>
<ul class="nav nav-pills flex-wrap gap-1 mb-3">
  <?php foreach ($types as $key => $meta): ?>
    <li class="nav-item">
      <a class="nav-link py-1 px-2 small <?= $key === $type ? 'active' : '' ?>" href="<?= BASE_URL ?>/reminder_list.php?type=<?= h($key) ?>"
         data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=reminders&type=' . $key) ?>" data-app-modal-size="lg">
        <i class="fa-solid <?= h($meta['icon']) ?>"></i> <?= h($meta['label']) ?>
        <span class="badge <?= $key === $type ? 'bg-light text-dark' : 'bg-secondary' ?> ms-1"><?= (int)($counts[$key] ?? 0) ?></span>
      </a>
    </li>
  <?php endforeach; ?>
</ul>
<?php include __DIR__ . '/../reminder_list_table.php'; ?>
