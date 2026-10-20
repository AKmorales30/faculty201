<?php
/**
 * "Reminders" card on the Faculty, Program Chair and Dean Home: the
 * user's own open reminders (includes/ai_reminders.php, AI-worded when AI
 * is on) -- each with a link to the page that fixes it and Dismiss
 * (api/reminders.php, POST + CSRF token). With none open, a status line
 * instead ("You're all set" only once the account has been checked).
 */
$reminder_meta = reminder_types();
$my_reminders = array_values(array_filter(open_reminders_for_user($pdo, (int)current_user()['user_id']), fn($r) => isset($reminder_meta[$r['reminder_type']])));
$reminder_status = reminder_check_status($pdo, (int)current_user()['user_id']);
?>
<section class="ui-card" aria-labelledby="remTitle">
  <div class="ui-card-head">
    <span class="ui-head-icon tone-gold" aria-hidden="true"><i class="fa-solid fa-bell"></i></span>
    <h2 class="ui-card-title" id="remTitle">Reminders</h2>
    <?php if ($my_reminders): ?><span class="ui-pill tone-gold"><?= count($my_reminders) ?></span><?php endif; ?>
  </div>
  <?php if ($my_reminders): ?>
    <?php foreach ($my_reminders as $r): $meta = $reminder_meta[$r['reminder_type']]; ?>
      <div class="ui-rem-row">
        <span class="ui-head-icon tone-navy" aria-hidden="true"><i class="fa-solid <?= h($meta['icon']) ?>"></i></span>
        <div class="ui-rem-text">
          <div class="ui-rem-msg"><?= h($r['message']) ?></div>
          <div class="ui-cell-sub"><?= h($meta['label']) ?> · <?= h(time_ago($r['created_at'])) ?></div>
        </div>
        <div class="ui-rem-actions">
          <?php if ($url = notification_link_url($r['link'])): ?>
            <a href="<?= h($url) ?>" class="btn btn-sm btn-brand text-nowrap"><?= h($meta['link_text']) ?></a>
          <?php endif; ?>
          <form method="POST" action="<?= BASE_URL ?>/api/reminders.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="dismiss">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary text-nowrap" title="Hide this reminder. It comes back in <?= (int)REMINDER_REPEAT_DAYS ?> days if it still applies.">Dismiss</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (array_filter(array_column($my_reminders, 'ai_generated'))): ?>
      <div class="ui-card-foot"><?= ai_disclaimer_html() ?></div>
    <?php endif; ?>
  <?php else: ?>
    <div class="ui-rem-row">
      <?php if ($reminder_status['checked_at'] === null): ?>
        <span class="ui-head-icon tone-navy" aria-hidden="true"><i class="fa-solid fa-clock"></i></span>
        <div class="ui-rem-text"><div class="fw-bold">No reminders yet</div><div class="ui-cell-sub">Your 201 file is checked once a day; reminders appear here.</div></div>
      <?php elseif ($reminder_status['dismissed']): ?>
        <span class="ui-head-icon tone-teal" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></span>
        <div class="ui-rem-text"><div class="fw-bold">No new reminders</div><div class="ui-cell-sub">Hidden reminders come back in <?= (int)REMINDER_REPEAT_DAYS ?> days if still pending. Checked <?= h(time_ago($reminder_status['checked_at'])) ?>.</div></div>
      <?php else: ?>
        <span class="ui-head-icon tone-teal" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></span>
        <div class="ui-rem-text"><div class="fw-bold">You're all set</div><div class="ui-cell-sub">Your 201 file is up to date. Checked <?= h(time_ago($reminder_status['checked_at'])) ?>.</div></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
