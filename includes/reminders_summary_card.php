<?php
/**
 * Admin / Program Chair / Dean dashboard: how many faculty in the viewer's
 * scope have each kind of open reminder ("12 faculty have not uploaded a
 * seminar or training certificate in 6 months"), each linking to the list
 * (reminder_list.php). From the daily reminder check (includes/ai_reminders.php).
 */
$scope_counts = reminder_scope_counts($pdo, current_user());
?>
<div class="card stat-card mb-4">
  <div class="card-header bg-white fw-semibold"><i class="fa-solid fa-user-clock text-brand"></i> Faculty Needing Follow-up</div>
  <ul class="list-group list-group-flush">
    <?php foreach (personal_reminder_types() as $type => $meta): $n = $scope_counts[$type] ?? 0; ?>
      <li class="list-group-item d-flex justify-content-between align-items-center gap-2 small">
        <span>
          <i class="fa-solid <?= h($meta['icon']) ?> <?= $n ? 'text-accent-gold' : 'text-muted' ?> me-1" aria-hidden="true"></i>
          <strong><?= $n ?></strong> <?= $n === 1 ? 'faculty member has' : 'faculty have' ?> <?= h($meta['summary']) ?>
        </span>
        <?php if ($n): ?>
          <a href="<?= BASE_URL ?>/reminder_list.php?type=<?= h($type) ?>" class="text-nowrap">View list <i class="fa-solid fa-arrow-right"></i></a>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <div class="card-footer bg-white py-2 small text-muted d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span>Checked once a day. Each faculty member gets a reminder on their dashboard; the list clears as they update their 201 file.</span>
    <?php if (current_user()['role'] === 'admin'): ?>
      <form method="POST" action="<?= BASE_URL ?>/api/reminders.php" class="m-0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="run_check">
        <button class="btn btn-sm btn-outline-brand text-nowrap" title="Check every account now instead of waiting for tomorrow (already-sent reminders are not repeated)">
          <i class="fa-solid fa-rotate"></i> Run reminder check now
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>
