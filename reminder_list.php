<?php
/**
 * Faculty Needing Follow-up -- Admin, Program Chairs and Deans. The faculty
 * in the viewer's scope (profile_scope_sql()) with an open reminder of one
 * type, with the rule-based reason (not the AI-worded message). Linked
 * from the dashboard summary card (includes/reminders_summary_card.php).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_role(['admin', 'program_chair', 'dean']);

$me = current_user();
$types = reminder_types();
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

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold"><?= count($rows) ?> faculty <?= count($rows) === 1 ? 'member has' : 'have' ?> <?= h($types[$type]['summary']) ?></div>
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light"><tr><th>Faculty</th><th>Reason</th><th class="text-nowrap">Last Reminded</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="3" class="text-center text-muted py-4">No one in your scope needs this reminder right now.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <a href="<?= BASE_URL ?>/profile.php?id=<?= (int)$r['user_id'] ?>"><?= h($r['full_name']) ?></a>
              <div class="small text-muted"><?= h(user_position_line($r)) ?></div>
            </td>
            <td class="small"><?= h($r['reason']) ?></td>
            <td class="small text-nowrap"><?= h(date('M j, Y', strtotime($r['created_at']))) ?><?= $r['dismissed_at'] ? '<div class="text-muted">Dismissed by them</div>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
