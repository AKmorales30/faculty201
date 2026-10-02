<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Security Alerts';

if (($_POST['action'] ?? '') === 'mark_reviewed') {
    $alert_id = (int)($_POST['alert_id'] ?? 0);
    $pdo->prepare("UPDATE security_alerts SET is_reviewed = 1 WHERE alert_id = ?")->execute([$alert_id]);
    header('Location: ' . BASE_URL . '/admin/security_alerts.php');
    exit;
}

// End a brute-force login lock (an email or an IP address) before it expires
if (($_POST['action'] ?? '') === 'unlock_login') {
    $type = ($_POST['lock_type'] ?? '') === 'ip' ? 'ip' : 'account';
    if (!csrf_valid()) {
        $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    } elseif (clear_login_lock($pdo, $type, (string)($_POST['lock_key'] ?? ''), (int)current_user()['user_id'])) {
        $_SESSION['flash_success'] = 'Unlocked ' . ($type === 'ip' ? 'IP address ' : '') . ($_POST['lock_key'] ?? '') . '.';
    } else {
        $_SESSION['flash_error'] = 'That lock has already ended.';
    }
    header('Location: ' . BASE_URL . '/admin/security_alerts.php');
    exit;
}

$locks = active_login_locks($pdo);
$lock_accounts = [];   // email => full name, for locks on existing accounts
if ($emails = array_column(array_filter($locks, fn($l) => $l['lock_type'] === 'account'), 'lock_key')) {
    $stmt = $pdo->prepare("SELECT LOWER(email), full_name FROM users WHERE email IN (" . implode(',', array_fill(0, count($emails), '?')) . ")");
    $stmt->execute($emails);
    $lock_accounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

$alerts = $pdo->query(
    "SELECT sa.*, u.full_name, u.role, u.email
     FROM security_alerts sa
     JOIN users u ON u.user_id = sa.user_id
     ORDER BY sa.is_reviewed ASC, sa.created_at DESC"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<h3 class="fw-bold mb-1">Security Alerts</h3>
<p class="text-muted mb-4">Logins flagged as unusual — a new IP address for the account, or several failed attempts right before a successful one.</p>

<div class="card stat-card mb-4">
  <div class="card-header bg-white fw-semibold">
    <i class="fa-solid fa-lock text-danger"></i> Active Login Locks
    <span class="text-muted small fw-normal">
      -- <?= (int)MAX_FAILED_ATTEMPTS ?> failed attempts for one email, or <?= (int)MAX_IP_FAILED_ATTEMPTS ?> from one IP address, within
      <?= (int)FAILED_ATTEMPT_WINDOW_MINUTES ?> minutes lock logins for <?= (int)LOCKOUT_MINUTES ?> minutes. Locks end by themselves.
    </span>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light"><tr><th>Locked</th><th>Failed Attempts</th><th>Since</th><th>Until</th><th></th></tr></thead>
      <tbody>
        <?php if (!$locks): ?>
          <tr><td colspan="5" class="text-center text-muted py-3">No account or IP address is locked right now.</td></tr>
        <?php endif; ?>
        <?php foreach ($locks as $l): ?>
        <tr class="table-danger">
          <td>
            <?php if ($l['lock_type'] === 'ip'): ?>
              <i class="fa-solid fa-network-wired"></i> IP address <code><?= h($l['lock_key']) ?></code>
            <?php else: ?>
              <i class="fa-solid fa-user"></i> <?= h($lock_accounts[$l['lock_key']] ?? '') ?: '<span class="text-muted">No such account</span>' ?>
              <div class="small text-muted"><?= h($l['lock_key']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= (int)$l['failed_count'] ?></td>
          <td class="small"><?= h(date('M j, g:i A', strtotime($l['locked_at']))) ?></td>
          <td class="small"><?= h(date('g:i A', time() + (int)$l['seconds_left'])) ?> <span class="text-muted">(<?= h(login_lock_minutes_label((int)$l['seconds_left'])) ?>)</span></td>
          <td>
            <form method="POST" class="d-inline" onsubmit="return confirm('Unlock this now?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="unlock_login">
              <input type="hidden" name="lock_type" value="<?= h($l['lock_type']) ?>">
              <input type="hidden" name="lock_key" value="<?= h($l['lock_key']) ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-lock-open"></i> Unlock</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card stat-card">
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Account</th><th>Reason</th><th>IP Address</th><th>Flagged</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$alerts): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No security alerts on record.</td></tr>
        <?php endif; ?>
        <?php foreach ($alerts as $a): ?>
        <tr class="<?= $a['is_reviewed'] ? '' : 'table-warning' ?>">
          <td>
            <?= h($a['full_name']) ?>
            <div class="text-muted small text-capitalize"><?= h(str_replace('_',' ',$a['role'])) ?> · <?= h($a['email']) ?></div>
          </td>
          <td><?= h($a['reason']) ?></td>
          <td><code><?= h($a['ip_address'] ?? '—') ?></code></td>
          <td><?= time_ago($a['created_at']) ?></td>
          <td>
            <span class="badge <?= $a['is_reviewed'] ? 'bg-secondary' : 'bg-danger' ?>">
              <?= $a['is_reviewed'] ? 'Reviewed' : 'Needs Review' ?>
            </span>
          </td>
          <td>
            <?php if (!$a['is_reviewed']): ?>
            <form method="POST" class="d-inline">
              <input type="hidden" name="action" value="mark_reviewed">
              <input type="hidden" name="alert_id" value="<?= (int)$a['alert_id'] ?>">
              <button class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-check"></i> Mark Reviewed</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
