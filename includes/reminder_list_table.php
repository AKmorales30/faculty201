<?php
/**
 * Faculty Needing Follow-up table of reminder_list.php. Expects $rows
 * (reminder_scope_list()), $types (personal_reminder_types()) and $type.
 */
?>
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
