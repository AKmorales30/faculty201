<?php
/**
 * Faculty Needing Follow-up -- Admin, Program Chairs and Deans. The faculty
 * in the viewer's scope (profile_scope_sql()) with an open reminder of one
 * type, with the rule-based reason (not the AI-worded message). The
 * dashboard summary card (includes/reminders_summary_card.php) opens the
 * same list in a pop-up (api/modal.php?view=reminders); this page is the
 * full-page version of it.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['admin', 'program_chair', 'dean']);

$me = current_user();
$types = personal_reminder_types();
$type = $_GET['type'] ?? '';
if (!isset($types[$type])) { $type = array_key_first($types); }
$page_title = 'Faculty Needing Follow-up';

$counts = reminder_scope_counts($pdo, $me);
try {
    $rows = reminder_scope_list($pdo, $me, $type);
} catch (PDOException $e) {
    $rows = [];   // migration not applied yet
}

include __DIR__ . '/includes/header.php';
?>
<h3 class="fw-bold mb-1">Faculty Needing Follow-up</h3>
<p class="text-muted mb-3">From the daily reminder check. Each person listed has already been reminded on their dashboard<?= $types[$type]['notify'] ? ' and in their notifications' : '' ?>; they leave the list once their 201 file is updated.
  <?php if ($me['role'] === 'program_chair'): ?>You see the faculty of your program.<?php elseif ($me['role'] === 'dean'): ?>You see the faculty and Program Chairs of your college.<?php endif; ?></p>

<ul class="nav nav-pills flex-wrap gap-1 mb-3">
  <?php foreach ($types as $key => $meta): ?>
    <li class="nav-item">
      <a class="nav-link <?= $key === $type ? 'active' : '' ?>" href="?type=<?= h($key) ?>">
        <i class="fa-solid <?= h($meta['icon']) ?>"></i> <?= h($meta['label']) ?>
        <span class="badge <?= $key === $type ? 'bg-light text-dark' : 'bg-secondary' ?> ms-1"><?= (int)($counts[$key] ?? 0) ?></span>
      </a>
    </li>
  <?php endforeach; ?>
</ul>

<?php include __DIR__ . '/includes/reminder_list_table.php'; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
