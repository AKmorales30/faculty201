<?php
/**
 * "Reminders" card at the top of the faculty (and Program Chair / Dean)
 * dashboard: the user's own open reminders (includes/ai_reminders.php),
 * each with a link to the page that fixes it and a Dismiss button
 * (api/reminders.php, POST + CSRF token). Shows nothing when there are none.
 */
$my_reminders = open_reminders_for_user($pdo, (int)current_user()['user_id']);
if (!$my_reminders) { return; }
$reminder_meta = reminder_types();
?>
<div class="card stat-card mb-4 reminder-card">
  <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="fa-solid fa-bell text-accent-gold"></i> Reminders</span>
    <span class="badge bg-warning"><?= count($my_reminders) ?></span>
  </div>
  <ul class="list-group list-group-flush">
    <?php foreach ($my_reminders as $r): $meta = $reminder_meta[$r['reminder_type']] ?? null; if (!$meta) continue; ?>
      <li class="list-group-item d-flex flex-wrap flex-md-nowrap align-items-start gap-2 gap-md-3">
        <i class="fa-solid <?= h($meta['icon']) ?> text-brand mt-1" aria-hidden="true"></i>
        <div class="flex-grow-1 min-w-0">
          <div><?= h($r['message']) ?></div>
          <div class="small text-muted"><?= h($meta['label']) ?> · <?= h(time_ago($r['created_at'])) ?></div>
        </div>
        <div class="d-flex gap-2 flex-shrink-0">
          <?php if ($url = notification_link_url($r['link'])): ?>
            <a href="<?= h($url) ?>" class="btn btn-sm btn-brand text-nowrap"><?= h($meta['link_text']) ?></a>
          <?php endif; ?>
          <form method="POST" action="<?= BASE_URL ?>/api/reminders.php" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="dismiss">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary text-nowrap" title="Hide this reminder (it comes back only if it still applies in <?= (int)REMINDER_REPEAT_DAYS ?> days)">Dismiss</button>
          </form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if (array_filter(array_column($my_reminders, 'ai_generated'))): ?>
    <div class="card-footer bg-white py-2"><?= ai_disclaimer_html() ?></div>
  <?php endif; ?>
</div>
