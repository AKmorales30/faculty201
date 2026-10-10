<?php
/**
 * "Reminders" card at the top of every dashboard (faculty, Program Chair,
 * Dean, Admin): the user's own open reminders (includes/ai_reminders.php)
 * -- about their 201 file, or the follow-up of the people in their scope --
 * each with a link to the page that fixes it and a Dismiss button
 * (api/reminders.php, POST + CSRF token). With none open, a status card
 * instead: "You're all set" once the account has been checked.
 */
$reminder_viewer = current_user();
$my_reminders = open_reminders_for_user($pdo, (int)$reminder_viewer['user_id']);
$reminder_meta = reminder_types();
if (!$my_reminders):
  $reminder_status = reminder_check_status($pdo, (int)$reminder_viewer['user_id']);
  if ($reminder_status['checked_at'] === null) { return; }   // not checked yet: nothing to claim
  $all_set = match ($reminder_viewer['role']) {
      'admin'         => 'No faculty need follow-up on their 201 files right now.',
      'program_chair' => 'Your 201 file is up to date, and no one in your program needs follow-up.',
      'dean'          => 'Your 201 file is up to date, and no one in your college needs follow-up.',
      default         => 'Your 201 file is up to date.',
  };
?>
<div class="card stat-card mb-4 reminder-card">
  <div class="card-body d-flex align-items-start gap-3">
    <?php if ($reminder_status['dismissed']): ?>
      <i class="fa-solid fa-bell-slash fa-lg text-muted mt-1" aria-hidden="true"></i>
      <div>
        <div class="fw-semibold">No new reminders</div>
        <div class="small text-muted">You hid <?= (int)$reminder_status['dismissed'] ?> reminder<?= $reminder_status['dismissed'] === 1 ? '' : 's' ?>; <?= $reminder_status['dismissed'] === 1 ? 'it comes' : 'they come' ?> back in <?= (int)REMINDER_REPEAT_DAYS ?> days if still pending. Checked <?= h(time_ago($reminder_status['checked_at'])) ?>.</div>
      </div>
    <?php else: ?>
      <i class="fa-solid fa-circle-check fa-lg text-success mt-1" aria-hidden="true"></i>
      <div>
        <div class="fw-semibold">You're all set!</div>
        <div class="small text-muted"><?= h($all_set) ?> Checked <?= h(time_ago($reminder_status['checked_at'])) ?>.</div>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php return; endif; ?>
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
